<?php
declare(strict_types=1);

final class EarthquakeChecker
{
    // PHP版BOTの本体フローをまとめるクラス。
    //
    // メソッド内で Config の各値を毎回判定せず、実行に必要な値は constructor で確定してから使う。
    // これにより fixed URL モードかフォーム在庫モードか、通知閾値はいくつか、といった前提を
    // run() 以降で再解釈しない。
    private const STOCK_OUT_REMINDER_INTERVAL_SECONDS = 21600;

    private P2PQuakeClient $p2pQuakeClient;
    private LineWorksClient $lineWorksClient;
    private StateStore $stateStore;
    private FormStockStore $formStockStore;
    private Logger $logger;
    private int $notifyScale;
    private int $unknownHypocenterHoldSeconds;
    private bool $formStockEnabled;
    private string $formUrl;
    private int $formLowStockThreshold;
    private string $maintenanceRoomId;
    private bool $lowStockNotifiedThisRun = false;
    private bool $stockOutNotifiedThisRun = false;
    private bool $skippedStockOutTargetThisRun = false;

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
        FormStockStore $formStockStore,
        Logger $logger
    ) {
        $this->p2pQuakeClient = $p2pQuakeClient;
        $this->lineWorksClient = $lineWorksClient;
        $this->stateStore = $stateStore;
        $this->formStockStore = $formStockStore;
        $this->logger = $logger;
        $this->notifyScale = $config->notifyScale();
        $this->unknownHypocenterHoldSeconds = $config->unknownHypocenterHoldSeconds();
        $this->formStockEnabled = $config->formStockEnabled();
        $this->formUrl = $config->formUrl();
        $this->formLowStockThreshold = $config->formLowStockThreshold();
        $this->maintenanceRoomId = $config->maintenanceRoomId();
    }

    public function run(): void
    {
        if ($this->formStockEnabled) {
            $this->importForms();
        }

        $events = $this->p2pQuakeClient->fetchEarthquakes();
        $targets = $this->selectNotificationTargets($events);
        $this->retryUndeliveredStockOutNotices();

        if ($targets === []) {
            $this->notifyStockOutReminderIfNeeded();
        }

        usort($targets, static function (array $a, array $b): int {
            $timeA = strtotime((string) $a['earthquake_time']);
            $timeB = strtotime((string) $b['earthquake_time']);

            if ($timeA === false || $timeB === false) {
                return strcmp((string) $a['earthquake_time'], (string) $b['earthquake_time']);
            }

            return $timeA <=> $timeB;
        });

        $usedFormCount = 0;

        foreach ($targets as $target) {
            $form = $this->resolveFormForNotification($target);
            if ($form === null) {
                continue;
            }

            try {
                $this->lineWorksClient->sendMessage($this->createMessage($target, $form['url']));
            } catch (Throwable $e) {
                $this->logger->error('LINE WORKS send failed.', $this->logContext($target, [
                    'error' => $e->getMessage(),
                ]));
                throw $e;
            }

            try {
                if ($form['index'] !== null) {
                    $this->formStockStore->markUsed($form['index'], $target['dedupe_key']);
                    $this->formStockStore->save();
                    $usedFormCount++;
                }

                $notifiedAt = gmdate('c');
                $record = [
                    'dedupe_key' => $target['dedupe_key'],
                    'event_id' => $target['event_id'],
                    'earthquake_time' => $target['earthquake_time'],
                    'hypocenter_name' => $target['hypocenter_name'],
                    'max_scale' => $target['max_scale'],
                    'notified_at' => $notifiedAt,
                ];
                if ($form['index'] !== null) {
                    $record['form_index'] = $form['index'];
                }

                $this->stateStore->markNotified($target['dedupe_key'], $record);
                $this->stateStore->markNotifiedByEarthquakeTime((string) $target['earthquake_time'], [
                    'dedupe_key' => $target['dedupe_key'],
                    'notified_at' => $notifiedAt,
                ]);
                $this->stateStore->removePendingUnknown((string) $target['earthquake_time']);
                $this->stateStore->save();

                $completionContext = $this->logContext($target);
                if ($form['index'] !== null) {
                    $completionContext['remaining_forms'] = $this->formStockStore->availableCount();
                }
                $this->logger->info('notification_completed', $completionContext);
            } catch (Throwable $e) {
                $failureContext = [
                    'error' => $this->safeErrorSummary($e),
                ];
                if ($form['index'] !== null) {
                    $failureContext['form_index'] = $form['index'];
                }

                $this->logger->error('State or form save failed after LINE WORKS send succeeded.', $this->logContext($target, $failureContext));
                throw $e;
            }
        }

        if ($usedFormCount > 0) {
            $this->notifyLowStockAfterConsumption();
        }
    }

    /** @param array<string, mixed> $target */
    private function resolveFormForNotification(array $target): ?array
    {
        if (!$this->formStockEnabled) {
            return [
                'index' => null,
                'url' => $this->formUrl,
            ];
        }

        $form = $this->formStockStore->takeAvailable();
        if ($form !== null) {
            return $form;
        }

        $this->logger->error('Skipped earthquake notification because no form URL is available.', $this->logContext($target));
        $this->markSkippedDueToFormStockOut($target);
        $this->notifyStockOutForTarget($target);

        return null;
    }

    private function importForms(): void
    {
        try {
            $result = $this->formStockStore->importCsvIfExists();
        } catch (FormImportException $e) {
            $this->logger->error('Form CSV import failed.', [
                'error' => $e->getMessage(),
            ]);

            try {
                $this->notifyMaintenance('フォームURL CSVの取り込みに失敗しました。CSVのヘッダーと内容を確認してください。');
            } catch (Throwable $notifyError) {
                $this->logger->error('Form CSV failure notification failed.', [
                    'error' => $notifyError->getMessage(),
                ]);
            }
            return;
        } catch (Throwable $e) {
            $this->logger->error('Form CSV import failed unexpectedly.', [
                'error' => $this->safeErrorSummary($e),
            ]);

            try {
                $this->notifyMaintenance('フォームURL CSVの取り込み処理で予期しないエラーが発生しました。forms.json、processed/failed ディレクトリの配置・権限を確認してください。地震チェック本体は既存のフォーム在庫で継続します。');
            } catch (Throwable $notifyError) {
                $this->logger->error('Unexpected form CSV failure notification failed.', [
                    'error' => $this->safeErrorSummary($notifyError),
                ]);
            }
            return;
        }

        if ($result['processed']) {
            $this->logger->info('Form CSV import succeeded.', [
                'imported' => $result['imported'],
                'duplicate_skipped' => $result['duplicate_skipped'],
                'invalid_rows' => $result['invalid_rows'],
            ]);
        }
    }

    private function notifyLowStockAfterConsumption(): void
    {
        $availableCount = $this->formStockStore->availableCount();
        if ($availableCount > $this->formLowStockThreshold || $this->lowStockNotifiedThisRun || $this->stockOutNotifiedThisRun) {
            return;
        }

        try {
            $this->notifyMaintenance(
                '安否確認フォームURLの残数が少なくなっています。残り未使用フォームURL数: ' . $availableCount . '件。フォームURLを補充してください。'
            );
            $this->lowStockNotifiedThisRun = true;
            $this->logger->info('form_low_stock_notice_sent', [
                'available_count' => $availableCount,
                'threshold' => $this->formLowStockThreshold,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Form low stock notification failed.', [
                'available_count' => $availableCount,
                'threshold' => $this->formLowStockThreshold,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $target */
    private function markSkippedDueToFormStockOut(array $target): void
    {
        $skippedAt = gmdate('c');
        $record = [
            'dedupe_key' => $target['dedupe_key'],
            'event_id' => $target['event_id'],
            'earthquake_time' => $target['earthquake_time'],
            'hypocenter_name' => $target['hypocenter_name'],
            'max_scale' => $target['max_scale'],
            'skipped_at' => $skippedAt,
            'reason' => 'form_stock_out',
            'notice_status' => 'pending',
            'notice_last_attempted_at' => null,
            'notice_sent_at' => null,
            'notice_error' => null,
        ];

        $this->stateStore->markSkippedDueToFormStockOut($target['dedupe_key'], $record);
        $this->stateStore->removePendingUnknown((string) $target['earthquake_time']);
        $this->stateStore->save();
        $this->logger->info('skipped_due_to_form_stock_out', $this->logContext($target, [
            'available_count' => 0,
        ]));
    }

    /** @param array<string, mixed> $target */
    private function notifyStockOutForTarget(array $target, ?string $noticeDedupeKey = null): void
    {
        $this->stockOutNotifiedThisRun = true;
        $noticeDedupeKey ??= (string) $target['dedupe_key'];

        try {
            $this->notifyMaintenance(
                '安否確認フォームURLが枯渇しているため、対象地震の安否確認通知を自動送信できませんでした。今回対象となった地震についてはbotからの自動再送は行いません。必要に応じて手動で安否確認を送信し、フォームURLを補充してください。'
            );
            $this->stateStore->markStockOutNoticeResult($noticeDedupeKey, 'sent');
            $this->stateStore->save();
            $this->logger->info('form_stock_out_notice_sent', $this->logContext($target, [
                'available_count' => 0,
            ]));
        } catch (Throwable $e) {
            try {
                $this->stateStore->markStockOutNoticeResult($noticeDedupeKey, 'failed', $this->safeErrorSummary($e));
                $this->stateStore->save();
            } catch (Throwable $stateError) {
                $this->logger->error('form_stock_out_notice_status_save_failed', $this->logContext($target, [
                    'error' => $this->safeErrorSummary($stateError),
                ]));
            }

            $this->logger->error('form_stock_out_notice_failed', $this->logContext($target, [
                'available_count' => 0,
                'error' => $this->safeErrorSummary($e),
            ]));
        }
    }

    private function retryUndeliveredStockOutNotices(): void
    {
        if (!$this->formStockEnabled) {
            return;
        }

        foreach ($this->stateStore->stockOutNoticeRetryRecords() as $dedupeKey => $record) {
            $target = $this->targetFromStockOutRecord($dedupeKey, $record);
            if ($target === null) {
                $this->logger->error('Stock out skipped record is invalid.', [
                    'dedupe_key' => $dedupeKey,
                    'reason' => 'invalid_stock_out_skipped_record',
                ]);
                continue;
            }

            $this->skippedStockOutTargetThisRun = true;
            $this->logger->info('form_stock_out_notice_retrying', $this->logContext($target, [
                'notice_status' => (string) ($record['notice_status'] ?? 'pending'),
            ]));
            $this->notifyStockOutForTarget($target, $dedupeKey);
        }
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private function targetFromStockOutRecord(string $dedupeKey, array $record): ?array
    {
        $earthquakeTime = trim((string) ($record['earthquake_time'] ?? ''));
        if ($earthquakeTime === '') {
            return null;
        }

        $hypocenterName = trim((string) ($record['hypocenter_name'] ?? ''));
        if ($hypocenterName === '') {
            $hypocenterName = 'UNKNOWN';
        }

        $recordDedupeKey = trim((string) ($record['dedupe_key'] ?? $dedupeKey));
        if ($recordDedupeKey === '') {
            $recordDedupeKey = $earthquakeTime . '|' . $hypocenterName;
        }

        return [
            'event' => null,
            'event_id' => $record['event_id'] ?? null,
            'earthquake_time' => $earthquakeTime,
            'hypocenter_name' => $hypocenterName,
            'max_scale' => (int) ($record['max_scale'] ?? 0),
            'dedupe_key' => $recordDedupeKey,
        ];
    }

    private function notifyStockOutReminderIfNeeded(): void
    {
        if (!$this->formStockEnabled || $this->skippedStockOutTargetThisRun) {
            return;
        }

        if ($this->formStockStore->availableCount() > 0) {
            if ($this->stateStore->stockOutReminderLastAlertedAt() !== null) {
                $this->stateStore->clearStockOutReminderAlerted();
                $this->stateStore->save();
            }
            return;
        }

        $lastAlertedAt = $this->stateStore->stockOutReminderLastAlertedAt();
        if ($lastAlertedAt !== null) {
            $lastAlertedTimestamp = strtotime($lastAlertedAt);
            if ($lastAlertedTimestamp !== false && (time() - $lastAlertedTimestamp) < self::STOCK_OUT_REMINDER_INTERVAL_SECONDS) {
                return;
            }
        }

        $remindedAt = gmdate('c');

        try {
            $this->notifyMaintenance('安否確認フォームURLの未使用在庫が0件です。対象地震発生時に自動送信できなくなるため、フォームURLを補充してください。');
            $this->stateStore->markStockOutReminderAlerted($remindedAt);
            $this->stateStore->save();
            $this->logger->info('form_stock_empty_idle_reminder_sent', [
                'available_count' => 0,
                'reminded_at' => $remindedAt,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('form_stock_empty_idle_reminder_failed', [
                'available_count' => 0,
                'reminded_at' => $remindedAt,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyMaintenance(string $message): void
    {
        $this->lineWorksClient->sendMessage($message, $this->maintenanceRoomId);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function selectNotificationTargets(array $events): array
    {
        $candidatesByTime = [];
        $stateChanged = false;
        $this->skippedStockOutTargetThisRun = false;

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
            if (
                $this->stateStore->hasSkippedDueToFormStockOut($candidate['dedupe_key'])
                || $this->stateStore->hasSkippedDueToFormStockOutEarthquakeTime((string) $candidate['earthquake_time'])
            ) {
                $this->skippedStockOutTargetThisRun = true;
                continue;
            }

            $candidatesByTime[(string) $candidate['earthquake_time']][] = $candidate;
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
            if ($this->stateStore->hasSkippedDueToFormStockOutEarthquakeTime($earthquakeTime)) {
                $this->skippedStockOutTargetThisRun = true;
                $this->stateStore->removePendingUnknown($earthquakeTime);
                $stateChanged = true;
                continue;
            }
            if (isset($seenEarthquakeTimes[$earthquakeTime]) || !$this->isPendingUnknownExpired($pending)) {
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
            if ($this->stateStore->hasSkippedDueToFormStockOut($candidate['dedupe_key'])) {
                $this->skippedStockOutTargetThisRun = true;
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
            'first_seen_at' => gmdate('c'),
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

        return (time() - $firstSeenTimestamp) >= $this->unknownHypocenterHoldSeconds;
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
