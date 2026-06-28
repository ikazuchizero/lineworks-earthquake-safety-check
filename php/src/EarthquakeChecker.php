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
    private bool $lowStockNotifiedThisRun = false;
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

        $events = $this->p2pQuakeClient->fetchEarthquakes();
        $this->logger->info('Fetched earthquake events.', ['count' => count($events)]);

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

        $usedFormCount = 0;

        foreach ($targets as $target) {
            $form = $this->formStockStore->takeAvailable();

            if ($form === null) {
                $this->logger->error('Skipped earthquake notification because no form URL is available.', $this->logContext($target));
                $this->notifyStockOutIfNeeded();
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

            $this->logger->info('LINE WORKS send succeeded.', $this->logContext($target));

            try {
                $this->formStockStore->markUsed($form['index'], $target['dedupe_key']);
                $this->formStockStore->save();

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
        $availableCount = $this->formStockStore->availableCount();
        $threshold = $this->config->formLowStockThreshold();

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
