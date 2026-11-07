<?php
declare(strict_types=1);

final class EarthquakeChecker
{
    // PHP版BOTの本体フローをまとめるクラス。
    //
    // メソッド内で Config の各値を毎回判定せず、実行に必要な値は constructor で確定してから使う。
    // これにより通知閾値や静穏時間などの前提を
    // run() 以降で再解釈しない。
    private P2PQuakeClient $p2pQuakeClient;
    private LineWorksClient $lineWorksClient;
    private StateStore $stateStore;
    private GoogleFormsClient $googleFormsClient;
    private Logger $logger;
    private int $notifyScale;
    private int $unknownHypocenterHoldSeconds;
    private int $quietHoursStart;
    private int $quietHoursEnd;
    private DateTimeImmutable $now;
    /** @var array<string, mixed>|null */
    private ?array $quietTargetThisRun = null;

    /** @var array<int, string> */
    private array $scaleNames = [
        10 => '1',
        20 => '2',
        30 => '3',
        40 => '4',
        45 => '5弱',
        50 => '5強',
        55 => '6弱',
        60 => '6強',
        70 => '7',
    ];

    public function __construct(
        Config $config,
        P2PQuakeClient $p2pQuakeClient,
        LineWorksClient $lineWorksClient,
        StateStore $stateStore,
        Logger $logger,
        GoogleFormsClient $googleFormsClient,
        ?DateTimeImmutable $now = null
    ) {
        $this->p2pQuakeClient = $p2pQuakeClient;
        $this->lineWorksClient = $lineWorksClient;
        $this->stateStore = $stateStore;
        $this->logger = $logger;
        $this->googleFormsClient = $googleFormsClient;
        $this->notifyScale = $config->notifyScale();
        $this->unknownHypocenterHoldSeconds = $config->unknownHypocenterHoldSeconds();
        $this->quietHoursStart = $config->quietHoursStart();
        $this->quietHoursEnd = $config->quietHoursEnd();
        $this->now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function run(): void
    {
        $events = $this->p2pQuakeClient->fetchEarthquakes();
        $targets = $this->selectNotificationTargets($events);

        if ($this->quietTargetThisRun !== null) {
            array_unshift($targets, $this->quietTargetThisRun);
        }

        usort($targets, static function (array $a, array $b): int {
            $timeA = strtotime((string) $a['earthquake_time']);
            $timeB = strtotime((string) $b['earthquake_time']);

            if ($timeA === false || $timeB === false) {
                return strcmp((string) $a['earthquake_time'], (string) $b['earthquake_time']);
            }

            return $timeA <=> $timeB;
        });

        foreach ($targets as $target) {
            $formUrl = $this->resolveFormForNotification($target);

            try {
                $this->lineWorksClient->sendMessage($this->createMessage($target, $formUrl));
            } catch (Throwable $e) {
                $this->logger->error('LINE WORKS send failed.', $this->logContext($target, [
                    'error' => $e->getMessage(),
                ]));
                throw $e;
            }

            try {
                $notifiedAt = gmdate('c');
                $record = [
                    'dedupe_key' => $target['dedupe_key'],
                    'event_id' => $target['event_id'],
                    'earthquake_time' => $target['earthquake_time'],
                    'hypocenter_name' => $target['hypocenter_name'],
                    'max_scale' => $target['max_scale'],
                    'notified_at' => $notifiedAt,
                ];

                $this->stateStore->markNotified($target['dedupe_key'], $record);
                $this->stateStore->markNotifiedByEarthquakeTime((string) $target['earthquake_time'], [
                    'dedupe_key' => $target['dedupe_key'],
                    'notified_at' => $notifiedAt,
                ]);
                $this->stateStore->removePendingUnknown((string) $target['earthquake_time']);
                $quietWindowEnd = (string) ($target['quiet_window_end'] ?? $this->quietWindowEndForKnownContinuation($target));
                if ($quietWindowEnd !== '') {
                    $this->stateStore->markQuietCompletedThrough($quietWindowEnd);
                    $this->stateStore->clearQuietBatch();
                }
                $this->stateStore->save();

                $completionContext = $this->logContext($target);
                $this->logger->info('notification_completed', $completionContext);
            } catch (Throwable $e) {
                $failureContext = [
                    'error' => $this->safeErrorSummary($e),
                ];

                $this->logger->error('State save failed after LINE WORKS send succeeded.', $this->logContext($target, $failureContext));
                throw $e;
            }
        }

    }

    /** @param array<string, mixed> $target */
    private function resolveFormForNotification(array $target): string
    {
        $earthquakeTime = (string) $target['earthquake_time'];
        $record = $this->stateStore->googleFormForEarthquakeTime($earthquakeTime);
        if (is_array($record) && is_string($record['responder_url'] ?? null) && $record['responder_url'] !== '') {
            return $record['responder_url'];
        }
        if (!is_array($record) || !is_string($record['form_id'] ?? null) || $record['form_id'] === '') {
            $this->googleFormsClient->assertTemplateCanPublish();
            $record = ['form_id' => $this->googleFormsClient->copyTemplate($earthquakeTime)];
            // Persist the authoritative copied ID before publishing or LINE WORKS.
            $this->stateStore->saveGoogleFormForEarthquakeTime($earthquakeTime, $record);
            $this->stateStore->save();
        }
        $record['responder_url'] = $this->googleFormsClient->prepareResponderUrl($record['form_id']);
        // Persist the ready responder URL before LINE WORKS so a retry never creates another form.
        $this->stateStore->saveGoogleFormForEarthquakeTime($earthquakeTime, $record);
        $this->stateStore->save();
        return $record['responder_url'];
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function selectNotificationTargets(array $events): array
    {
        $candidatesByTime = [];
        $quietCandidates = [];
        $stateChanged = false;
        $this->quietTargetThisRun = null;

        foreach ($events as $event) {
            if (!is_array($event)) {
                $this->logger->info('Skipped earthquake event.', [
                    'reason' => 'invalid_event_shape',
                ]);
                continue;
            }

            $candidate = $this->buildCandidate($event);
            if ($candidate === null || $candidate['max_scale'] < $this->notifyScale) {
                continue;
            }
            if ($this->stateStore->has($candidate['dedupe_key'])) {
                continue;
            }
            if ($this->stateStore->hasNotifiedEarthquakeTime((string) $candidate['earthquake_time'])) {
                continue;
            }
            $quietWindow = $this->quietWindowFor((string) $candidate['earthquake_time']);
            if ($quietWindow !== null) {
                if ($candidate['hypocenter_name'] === 'UNKNOWN') {
                    $pending = $this->stateStore->getPendingUnknown((string) $candidate['earthquake_time']);
                    if ($pending === null) {
                        $this->stateStore->markPendingUnknown((string) $candidate['earthquake_time'], $this->pendingUnknownRecord($candidate));
                        $stateChanged = true;
                    } elseif ($this->isBetterQuietCandidate($candidate, $pending)) {
                        $this->stateStore->markPendingUnknown((string) $candidate['earthquake_time'], array_merge($pending, $this->pendingUnknownRecord($candidate), [
                            'first_seen_at' => $pending['first_seen_at'] ?? gmdate('c'),
                        ]));
                        $stateChanged = true;
                    }
                }
                $quietCandidates[] = [
                    'candidate' => $candidate,
                    'window' => $quietWindow,
                ];
                continue;
            }
            $candidatesByTime[(string) $candidate['earthquake_time']][] = $candidate;
        }

        if ($quietCandidates !== []) {
            usort($quietCandidates, function (array $left, array $right): int {
                return $this->compareTimes(
                    (string) $right['window']['window_start'],
                    (string) $left['window']['window_start']
                );
            });
            $latestWindow = $quietCandidates[0]['window'];
            $quietCandidate = null;
            foreach ($quietCandidates as $quietEntry) {
                if ($quietEntry['window']['window_start'] !== $latestWindow['window_start']) {
                    break;
                }
                if ($quietCandidate === null || $this->isBetterQuietCandidate($quietEntry['candidate'], $quietCandidate)) {
                    $quietCandidate = $quietEntry['candidate'];
                }
            }
            if ($quietCandidate !== null) {
                $stateChanged = $this->captureQuietCandidate($quietCandidate, $latestWindow) || $stateChanged;
            }
        }

        $quietBatch = $this->stateStore->quietBatch();
        if ($quietBatch !== null && $this->quietBatchHasEnded($quietBatch)) {
            $completedThrough = $this->stateStore->quietCompletedThrough();
            if ($completedThrough === null || $this->compareTimes((string) $quietBatch['window_end'], $completedThrough) > 0) {
                $quietTarget = $this->candidateFromQuietBatch($quietBatch);
                if ($quietTarget !== null && $quietTarget['hypocenter_name'] === 'UNKNOWN') {
                    $pending = $this->stateStore->getPendingUnknown((string) $quietTarget['earthquake_time']);
                    if ($pending === null || !$this->isPendingUnknownExpired($pending)) {
                        $quietTarget = null;
                    }
                }
                if (
                    $quietTarget !== null
                    && !$this->stateStore->has($quietTarget['dedupe_key'])
                    && !$this->stateStore->hasNotifiedEarthquakeTime((string) $quietTarget['earthquake_time'])
                ) {
                    $this->quietTargetThisRun = $quietTarget;
                    $this->quietTargetThisRun['quiet_window_end'] = (string) $quietBatch['window_end'];
                    $stateChanged = $this->removePendingUnknownsForQuietWindow((string) $quietBatch['window_start']) || $stateChanged;
                }
            }
        }

        $targets = [];
        $seenDedupeKeys = [];
        $seenEarthquakeTimes = [];

        foreach ($candidatesByTime as $earthquakeTime => $candidates) {
            $namedCandidates = array_values(array_filter($candidates, static function (array $candidate): bool {
                return $candidate['hypocenter_name'] !== 'UNKNOWN';
            }));

            if ($namedCandidates !== []) {
                $candidate = $namedCandidates[0];
                if ($this->stateStore->getPendingUnknown($earthquakeTime) !== null) {
                    $this->logger->info('Named hypocenter candidate replaces pending UNKNOWN.', $this->logContext($candidate, [
                        'reason' => 'named_hypocenter_replaces_pending_unknown',
                    ]));
                }

                $this->addTargetIfNotSeen($targets, $seenDedupeKeys, $seenEarthquakeTimes, $candidate);
                continue;
            }

            $unknownCandidate = $candidates[0];
            $pending = $this->stateStore->getPendingUnknown($earthquakeTime);

            if ($pending === null) {
                $this->stateStore->markPendingUnknown($earthquakeTime, $this->pendingUnknownRecord($unknownCandidate));
                $stateChanged = true;
                $this->logger->info('Skipped earthquake event.', $this->logContext($unknownCandidate, [
                    'reason' => 'unknown_hypocenter_pending_created',
                ]));
                continue;
            }

            if ($this->isPendingUnknownExpired($pending)) {
                $this->logger->info('UNKNOWN hypocenter pending expired.', $this->logContext($unknownCandidate, [
                    'reason' => 'unknown_hypocenter_pending_expired',
                ]));
                $this->addTargetIfNotSeen($targets, $seenDedupeKeys, $seenEarthquakeTimes, $unknownCandidate);
            }
        }

        foreach ($this->stateStore->pendingUnknowns() as $earthquakeTime => $pending) {
            if ($this->stateStore->hasNotifiedEarthquakeTime($earthquakeTime)) {
                $this->stateStore->removePendingUnknown($earthquakeTime);
                $stateChanged = true;
                continue;
            }
            if (isset($seenEarthquakeTimes[$earthquakeTime]) || !$this->isPendingUnknownExpired($pending)) {
                continue;
            }

            if ($this->pendingUnknownHasUnfinishedQuietBatch($earthquakeTime)) {
                continue;
            }

            $candidate = $this->candidateFromPendingUnknown($earthquakeTime, $pending);
            if ($candidate === null) {
                $this->logger->error('Pending UNKNOWN record is invalid.', [
                    'earthquake_time' => $earthquakeTime,
                    'reason' => 'invalid_pending_unknown_record',
                ]);
                $this->stateStore->removePendingUnknown($earthquakeTime);
                $stateChanged = true;
                continue;
            }
            if ($candidate['max_scale'] < $this->notifyScale) {
                $this->logger->info('Skipped pending UNKNOWN earthquake.', $this->logContext($candidate, [
                    'reason' => 'pending_unknown_below_notify_scale',
                ]));
                $this->stateStore->removePendingUnknown($earthquakeTime);
                $stateChanged = true;
                continue;
            }

            $this->logger->info('UNKNOWN hypocenter pending expired.', $this->logContext($candidate, [
                'reason' => 'unknown_hypocenter_pending_expired_without_current_event',
            ]));
            $this->addTargetIfNotSeen($targets, $seenDedupeKeys, $seenEarthquakeTimes, $candidate);
        }

        if ($stateChanged) {
            $this->stateStore->save();
        }

        return $targets;
    }

    /** @param array<string, mixed> $candidate @param array{window_start: string, window_end: string} $window */
    private function captureQuietCandidate(array $candidate, array $window): bool
    {
        $completedThrough = $this->stateStore->quietCompletedThrough();
        if ($completedThrough !== null && $this->compareTimes($window['window_end'], $completedThrough) <= 0) {
            return false;
        }

        $batch = $this->stateStore->quietBatch();
        if ($batch !== null && (string) ($batch['window_start'] ?? '') !== $window['window_start']) {
            return false;
        }
        if ($batch !== null && !$this->isBetterQuietCandidate($candidate, $batch['candidate'] ?? null)) {
            return false;
        }

        $this->stateStore->setQuietBatch([
            'window_start' => $window['window_start'],
            'window_end' => $window['window_end'],
            'candidate' => $candidate,
        ]);

        return true;
    }

    /** @param array<string, mixed> $candidate @param mixed $existing */
    private function isBetterQuietCandidate(array $candidate, mixed $existing): bool
    {
        if (!is_array($existing)) {
            return true;
        }
        if ((string) $candidate['earthquake_time'] === (string) ($existing['earthquake_time'] ?? '')) {
            return $candidate['hypocenter_name'] !== 'UNKNOWN' && ($existing['hypocenter_name'] ?? 'UNKNOWN') === 'UNKNOWN';
        }

        if ((int) $candidate['max_scale'] !== (int) ($existing['max_scale'] ?? 0)) {
            return (int) $candidate['max_scale'] > (int) ($existing['max_scale'] ?? 0);
        }

        return $this->compareTimes((string) $candidate['earthquake_time'], (string) ($existing['earthquake_time'] ?? '')) > 0;
    }

    /** @param array<string, mixed> $target */
    private function quietWindowEndForKnownContinuation(array $target): ?string
    {
        if (($target['hypocenter_name'] ?? 'UNKNOWN') === 'UNKNOWN') {
            return null;
        }

        $batch = $this->stateStore->quietBatch();
        $candidate = is_array($batch['candidate'] ?? null) ? $batch['candidate'] : null;
        if ($batch === null || $candidate === null) {
            return null;
        }
        if ((string) ($candidate['earthquake_time'] ?? '') !== (string) ($target['earthquake_time'] ?? '')) {
            return null;
        }
        if (!$this->quietBatchHasEnded($batch)) {
            return null;
        }

        return (string) ($batch['window_end'] ?? '');
    }

    private function pendingUnknownHasUnfinishedQuietBatch(string $earthquakeTime): bool
    {
        $batch = $this->stateStore->quietBatch();
        if ($batch === null) {
            return false;
        }

        $candidate = $batch['candidate'] ?? null;
        if (!is_array($candidate) || (string) ($candidate['earthquake_time'] ?? '') !== $earthquakeTime) {
            return false;
        }

        return !$this->quietBatchHasEnded($batch);
    }

    private function removePendingUnknownsForQuietWindow(string $windowStart): bool
    {
        $changed = false;
        foreach ($this->stateStore->pendingUnknowns() as $earthquakeTime => $_pending) {
            $window = $this->quietWindowFor($earthquakeTime);
            if ($window !== null && $window['window_start'] === $windowStart) {
                $this->stateStore->removePendingUnknown($earthquakeTime);
                $changed = true;
            }
        }

        return $changed;
    }

    /** @param array<string, mixed> $batch @return array<string, mixed>|null */
    private function candidateFromQuietBatch(array $batch): ?array
    {
        $candidate = $batch['candidate'] ?? null;

        return is_array($candidate) && trim((string) ($candidate['earthquake_time'] ?? '')) !== '' ? $candidate : null;
    }

    /** @param array<string, mixed> $batch */
    private function quietBatchHasEnded(array $batch): bool
    {
        $end = $this->parseTokyoTime((string) ($batch['window_end'] ?? ''));

        return $end !== null && $end->getTimestamp() <= $this->now->getTimestamp();
    }

    /** @return array{window_start: string, window_end: string}|null */
    private function quietWindowFor(string $value): ?array
    {
        $time = $this->parseTokyoTime($value);
        if ($time === null) {
            return null;
        }

        $hour = (int) $time->format('G');
        $start = $this->quietHoursStart;
        $end = $this->quietHoursEnd;
        $inQuiet = $start < $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
        if (!$inQuiet) {
            return null;
        }

        $date = $time->setTime(0, 0);
        if ($start > $end && $hour < $end) {
            $date = $date->modify('-1 day');
        }
        $windowStart = $date->setTime($start, 0);
        $windowEnd = $start < $end
            ? $date->setTime($end, 0)
            : $date->modify('+1 day')->setTime($end, 0);

        return [
            'window_start' => $windowStart->format(DateTimeInterface::ATOM),
            'window_end' => $windowEnd->format(DateTimeInterface::ATOM),
        ];
    }

    private function parseTokyoTime(string $value): ?DateTimeImmutable
    {
        try {
            $zone = new DateTimeZone('Asia/Tokyo');
            $hasTimeZone = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value) === 1;

            $time = $hasTimeZone ? new DateTimeImmutable($value) : new DateTimeImmutable($value, $zone);

            return $time->setTimezone($zone);
        } catch (Exception) {
            return null;
        }
    }

    private function compareTimes(string $left, string $right): int
    {
        $leftTime = $this->parseTokyoTime($left)?->getTimestamp();
        $rightTime = $this->parseTokyoTime($right)?->getTimestamp();
        if ($leftTime === null || $rightTime === null) {
            return strcmp($left, $right);
        }

        return $leftTime <=> $rightTime;
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     * @param array<string, bool> $seenDedupeKeys
     * @param array<string, bool> $seenEarthquakeTimes
     * @param array<string, mixed> $candidate
     */
    private function addTargetIfNotSeen(array &$targets, array &$seenDedupeKeys, array &$seenEarthquakeTimes, array $candidate): void
    {
        if (isset($seenDedupeKeys[$candidate['dedupe_key']]) || isset($seenEarthquakeTimes[(string) $candidate['earthquake_time']])) {
            return;
        }

        $seenDedupeKeys[$candidate['dedupe_key']] = true;
        $seenEarthquakeTimes[(string) $candidate['earthquake_time']] = true;
        $targets[] = $candidate;
    }

    /** @param array<string, mixed> $candidate */
    private function pendingUnknownRecord(array $candidate): array
    {
        return [
            'dedupe_key' => $candidate['dedupe_key'],
            'event_id' => $candidate['event_id'],
            'earthquake_time' => $candidate['earthquake_time'],
            'hypocenter_name' => $candidate['hypocenter_name'],
            'max_scale' => $candidate['max_scale'],
            'first_seen_at' => $this->now->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $pending
     * @return array<string, mixed>|null
     */
    private function candidateFromPendingUnknown(string $earthquakeTime, array $pending): ?array
    {
        $pendingEarthquakeTime = trim((string) ($pending['earthquake_time'] ?? $earthquakeTime));
        if ($pendingEarthquakeTime === '') {
            $pendingEarthquakeTime = $earthquakeTime;
        }
        if ($pendingEarthquakeTime === '') {
            return null;
        }

        $hypocenterName = trim((string) ($pending['hypocenter_name'] ?? 'UNKNOWN'));
        if ($hypocenterName === '') {
            $hypocenterName = 'UNKNOWN';
        }

        $dedupeKey = trim((string) ($pending['dedupe_key'] ?? ''));
        if ($dedupeKey === '') {
            $dedupeKey = $pendingEarthquakeTime . '|' . $hypocenterName;
        }

        return [
            'event' => null,
            'event_id' => $pending['event_id'] ?? null,
            'earthquake_time' => $pendingEarthquakeTime,
            'hypocenter_name' => $hypocenterName,
            'max_scale' => (int) ($pending['max_scale'] ?? 0),
            'dedupe_key' => $dedupeKey,
        ];
    }

    /** @param array<string, mixed> $pending */
    private function isPendingUnknownExpired(array $pending): bool
    {
        $firstSeenAt = (string) ($pending['first_seen_at'] ?? '');
        $firstSeenTimestamp = strtotime($firstSeenAt);

        if ($firstSeenTimestamp === false) {
            $this->logger->error('Pending UNKNOWN first_seen_at is invalid.', [
                'reason' => 'invalid_pending_unknown_first_seen_at',
            ]);
            return true;
        }

        return ($this->now->getTimestamp() - $firstSeenTimestamp) >= $this->unknownHypocenterHoldSeconds;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function buildCandidate(array $event): ?array
    {
        $eventId = (string) ($event['id'] ?? '');
        $earthquake = $event['earthquake'] ?? null;

        if (!is_array($earthquake)) {
            $this->logger->info('Skipped earthquake event.', [
                'reason' => 'missing_earthquake_data',
                'event_id' => $eventId !== '' ? $eventId : null,
            ]);
            return null;
        }

        $earthquakeTime = trim((string) ($earthquake['time'] ?? ''));
        if ($earthquakeTime === '') {
            $this->logger->info('Skipped earthquake event.', [
                'reason' => 'missing_earthquake_time',
                'event_id' => $eventId !== '' ? $eventId : null,
            ]);
            return null;
        }

        $hypocenter = $earthquake['hypocenter'] ?? [];
        $hypocenterName = is_array($hypocenter) ? trim((string) ($hypocenter['name'] ?? '')) : '';
        if ($hypocenterName === '') {
            $hypocenterName = 'UNKNOWN';
        }

        $maxScale = (int) ($earthquake['maxScale'] ?? 0);

        return [
            'event' => $event,
            'event_id' => $eventId !== '' ? $eventId : null,
            'earthquake_time' => $earthquakeTime,
            'hypocenter_name' => $hypocenterName,
            'max_scale' => $maxScale,
            'dedupe_key' => $earthquakeTime . '|' . $hypocenterName,
        ];
    }

    /** @param array<string, mixed> $target */
    private function createMessage(array $target, string $formUrl): string
    {
        $formattedTime = $this->formatEarthquakeTime((string) $target['earthquake_time']);
        $hypocenterName = (string) $target['hypocenter_name'];
        $scaleText = $this->scaleText((int) $target['max_scale']);

        return <<<TEXT
お疲れ様です。

先ほど発生した地震について安否確認を実施いたします。

【地震情報】
・発生時刻：$formattedTime
・震源地：$hypocenterName
・最大震度：$scaleText

以下のフォームより回答をお願いいたします。

$formUrl

余震の可能性もありますので、引き続き安全確保をお願いいたします。
TEXT;
    }

    private function formatEarthquakeTime(string $value): string
    {
        try {
            $displayTimeZone = new DateTimeZone('Asia/Tokyo');
            $hasTimeZone = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value) === 1;
            $time = $hasTimeZone
                ? new DateTimeImmutable($value)
                : new DateTimeImmutable($value, $displayTimeZone);

            return $time->setTimezone($displayTimeZone)->format('Y/m/d H:i:s');
        } catch (Exception $e) {
            return $value;
        }
    }

    private function scaleText(int $maxScale): string
    {
        return $this->scaleNames[$maxScale] ?? '不明';
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function logContext(array $target, array $extra = []): array
    {
        return array_merge([
            'event_id' => $target['event_id'],
            'earthquake_time' => $target['earthquake_time'],
            'hypocenter_name' => $target['hypocenter_name'],
            'max_scale' => $target['max_scale'],
            'dedupe_key' => $target['dedupe_key'],
        ], $extra);
    }

    private function safeErrorSummary(Throwable $e): string
    {
        $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';

        return substr($message, 0, 200);
    }
}
