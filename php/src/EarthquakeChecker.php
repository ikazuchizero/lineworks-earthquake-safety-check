<?php
declare(strict_types=1);

final class EarthquakeChecker
{
    private Config $config;
    private P2PQuakeClient $p2pQuakeClient;
    private LineWorksClient $lineWorksClient;
    private StateStore $stateStore;
    private FormStockStore $formStockStore;
    private Logger $logger;
    // Low-stock alerts are intentionally scoped to one check.php execution.
    // We do not persist this flag: if a future earthquake consumes another form,
    // operations should be reminded again instead of missing the only warning.
    private bool $lowStockNotifiedThisRun = false;

    // Stock-out alerts are also capped per execution so multiple target earthquakes
    // do not spam the maintenance room after the form pool is already empty.
    private bool $stockOutNotifiedThisRun = false;

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
        $this->config = $config;
        $this->p2pQuakeClient = $p2pQuakeClient;
        $this->lineWorksClient = $lineWorksClient;
        $this->stateStore = $stateStore;
        $this->formStockStore = $formStockStore;
        $this->logger = $logger;
    }

    public function run(): void
    {
        $this->importForms();

        // P2PQuake is fetched with limit=10 by php/bin/check.php. Do not reduce it
        // to limit=1: a non-target event, such as seismic intensity 1 information,
        // can arrive immediately after a stronger earthquake and hide the event that
        // actually needs a safety-confirmation notification.
        $events = $this->p2pQuakeClient->fetchEarthquakes();
        $this->logger->info('Fetched earthquake events.', ['count' => count($events)]);

        // notify_scale is the minimum JMA intensity code to notify. A value of 0 is
        // useful for test rooms, but production should normally be restored to 45
        // or higher, corresponding to seismic intensity 5-lower or above.
        $targets = $this->selectNotificationTargets($events);
        $this->logger->info('Selected notification targets.', ['count' => count($targets)]);

        usort($targets, static function (array $a, array $b): int {
            $timeA = strtotime((string) $a['earthquake_time']);
            $timeB = strtotime((string) $b['earthquake_time']);

            if ($timeA === false || $timeB === false) {
                return strcmp((string) $a['earthquake_time'], (string) $b['earthquake_time']);
            }

            return $timeA <=> $timeB;
        });

        // Low-stock checks are delayed until all earthquake notifications finish.
        // This keeps a multi-earthquake run from sending maintenance alerts midway
        // through user-facing safety notifications.
        $usedFormCount = 0;

        foreach ($targets as $target) {
            $form = $this->formStockStore->takeAvailable();

            if ($form === null) {
                // No fallback fixed URL is allowed here. Reusing one form URL for
                // multiple earthquakes would mix responses and break safety checks,
                // so we skip the user-facing notification and alert maintainers.
                $this->logger->error('Skipped earthquake notification because no form URL is available.', $this->logContext($target));
                $this->notifyStockOutIfNeeded();
                continue;
            }

            try {
                // The form URL is still available at this point. If LINE WORKS send
                // fails, do not mark the form used and do not save notification state.
                $this->lineWorksClient->sendMessage($this->createMessage($target, $form['url']));
            } catch (Throwable $e) {
                $this->logger->error('LINE WORKS send failed.', $this->logContext($target, [
                    'error' => $e->getMessage(),
                ]));
                throw $e;
            }

            $this->logger->info('LINE WORKS send succeeded.', $this->logContext($target));

            try {
                // Commit side effects only after LINE WORKS accepts the message.
                // Saving these before send would cause missed notifications; a send
                // failure would look already handled and the form could be lost.
                $this->formStockStore->markUsed($form['index'], $target['dedupe_key']);
                $this->formStockStore->save();

                // State is also saved after send success. If this save fails, the
                // next run may notify again, but that is safer than silently missing
                // a real safety-confirmation request.
                $this->stateStore->markNotified($target['dedupe_key'], [
                    'dedupe_key' => $target['dedupe_key'],
                    'event_id' => $target['event_id'],
                    'earthquake_time' => $target['earthquake_time'],
                    'hypocenter_name' => $target['hypocenter_name'],
                    'max_scale' => $target['max_scale'],
                    'notified_at' => gmdate('c'),
                ]);
                $this->stateStore->save();
            } catch (Throwable $e) {
                $this->logger->error('State or form save failed after LINE WORKS send succeeded.', $this->logContext($target, [
                    'error' => $e->getMessage(),
                ]));
                throw $e;
            }

            $usedFormCount++;
        }

        if ($usedFormCount > 0) {
            $this->notifyLowStockAfterConsumption();
        }
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
        }

        if (!$result['processed']) {
            return;
        }

        $this->logger->info('Form CSV import succeeded.', [
            'imported' => $result['imported'],
            'duplicate_skipped' => $result['duplicate_skipped'],
            'invalid_rows' => $result['invalid_rows'],
        ]);

    }

    private function notifyLowStockAfterConsumption(): void
    {
        // Low-stock notifications are not sent on every cron execution. They are
        // sent only after at least one form was consumed in this run, and at most
        // once per run. If low stock continues, the next earthquake notification may
        // remind maintainers again, which reduces the risk of one missed alert.
        $availableCount = $this->formStockStore->availableCount();
        $threshold = $this->config->formLowStockThreshold();

        // The threshold is inclusive: threshold=10 means 10 or fewer available forms
        // should trigger a refill reminder. If stock-out was already reported in this
        // run, do not also send a low-stock reminder.
        if ($availableCount > $threshold || $this->lowStockNotifiedThisRun || $this->stockOutNotifiedThisRun) {
            return;
        }

        try {
            $this->notifyMaintenance(
                '安否確認フォームURLの残数が少なくなっています。残り未使用フォームURL数: ' . $availableCount . '件。フォームURLを補充してください。'
            );
            $this->lowStockNotifiedThisRun = true;
        } catch (Throwable $e) {
            $this->logger->error('Form low stock notification failed.', [
                'available_count' => $availableCount,
                'threshold' => $threshold,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyStockOutIfNeeded(): void
    {
        // Stock-out is more urgent than low stock. The earthquake notification is not
        // sent because no unique form URL can be attached, and this execution should
        // not stack an additional low-stock alert on top of the stock-out alert.
        if ($this->stockOutNotifiedThisRun) {
            return;
        }

        $this->stockOutNotifiedThisRun = true;

        try {
            $this->notifyMaintenance('安否確認フォームURLが枯渇しています。地震通知を送信できませんでした。フォームURLを至急補充してください。');
        } catch (Throwable $e) {
            $this->logger->error('Form stock out notification failed.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyMaintenance(string $message): void
    {
        $this->lineWorksClient->sendMessage($message, $this->config->formLowStockRoomId());
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function selectNotificationTargets(array $events): array
    {
        // Dedupe policy: event.id alone is not stable enough for earthquake identity.
        // P2PQuake/JMA can publish intensity reports, hypocenter reports, and follow-ups
        // for the same earthquake with different ids. Use earthquake.time + hypocenter
        // name instead. Do not include maxScale: follow-up reports may update intensity,
        // but that should not create a second safety-confirmation notification.
        $candidates = [];
        $hasNamedHypocenterByTime = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                $this->logger->info('Skipped earthquake event.', [
                    'reason' => 'invalid_event_shape',
                ]);
                continue;
            }

            $candidate = $this->buildCandidate($event);

            if ($candidate === null) {
                continue;
            }

            if ($candidate['max_scale'] < $this->config->notifyScale()) {
                $this->logger->info('Skipped earthquake event.', $this->logContext($candidate, [
                    'reason' => 'below_notify_scale',
                ]));
                continue;
            }

            if ($this->stateStore->has($candidate['dedupe_key'])) {
                $this->logger->info('Skipped earthquake event.', $this->logContext($candidate, [
                    'reason' => 'duplicate_dedupe_key',
                ]));
                continue;
            }

            $candidates[] = $candidate;

            if ($candidate['hypocenter_name'] !== 'UNKNOWN') {
                $hasNamedHypocenterByTime[(string) $candidate['earthquake_time']] = true;
            }
        }

        $targets = [];
        $seenDedupeKeys = [];

        foreach ($candidates as $candidate) {
            if ($candidate['hypocenter_name'] === 'UNKNOWN'
                && isset($hasNamedHypocenterByTime[(string) $candidate['earthquake_time']])) {
                // Some reports first lack a hypocenter and later include one. When a
                // named hypocenter exists in the same fetched batch, prefer that named
                // candidate and drop UNKNOWN to avoid duplicate notifications for the
                // same occurrence time. If UNKNOWN is the only candidate, it remains valid.
                $this->logger->info('Skipped earthquake event.', $this->logContext($candidate, [
                    'reason' => 'unknown_hypocenter_has_named_candidate_in_current_batch',
                ]));
                continue;
            }

            if (isset($seenDedupeKeys[$candidate['dedupe_key']])) {
                $this->logger->info('Skipped earthquake event.', $this->logContext($candidate, [
                    'reason' => 'duplicate_dedupe_key_in_current_batch',
                ]));
                continue;
            }

            $seenDedupeKeys[$candidate['dedupe_key']] = true;
            $targets[] = $candidate;
        }

        return $targets;
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
        $hypocenterName = '';

        if (is_array($hypocenter)) {
            $hypocenterName = trim((string) ($hypocenter['name'] ?? ''));
        }

        if ($hypocenterName === '') {
            $hypocenterName = 'UNKNOWN';
        }

        $maxScale = (int) ($earthquake['maxScale'] ?? 0);

        // Keep maxScale out of the dedupe key. If a follow-up raises the maximum
        // intensity for the same earthquake, including maxScale would make a second
        // key and send a duplicate safety-confirmation message.
        $dedupeKey = $earthquakeTime . '|' . $hypocenterName;

        return [
            'event' => $event,
            'event_id' => $eventId !== '' ? $eventId : null,
            'earthquake_time' => $earthquakeTime,
            'hypocenter_name' => $hypocenterName,
            'max_scale' => $maxScale,
            'dedupe_key' => $dedupeKey,
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
        // P2PQuake/JMA earthquake.time without an explicit timezone is already a
        // Japan-time wall-clock value. Treating it as UTC and converting to Tokyo
        // would shift displayed occurrence time by +9 hours.
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
}
