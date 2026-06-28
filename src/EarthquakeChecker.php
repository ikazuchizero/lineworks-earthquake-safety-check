<?php
declare(strict_types=1);

final class EarthquakeChecker
{
    private Config $config;
    private P2PQuakeClient $p2pQuakeClient;
    private LineWorksClient $lineWorksClient;
    private StateStore $stateStore;
    private Logger $logger;

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
        Logger $logger
    ) {
        $this->config = $config;
        $this->p2pQuakeClient = $p2pQuakeClient;
        $this->lineWorksClient = $lineWorksClient;
        $this->stateStore = $stateStore;
        $this->logger = $logger;
    }

    public function run(): void
    {
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

        foreach ($targets as $target) {
            try {
                $this->lineWorksClient->sendMessage($this->createMessage($target));
            } catch (Throwable $e) {
                $this->logger->error('LINE WORKS send failed.', $this->logContext($target, [
                    'error' => $e->getMessage(),
                ]));
                throw $e;
            }

            $this->logger->info('LINE WORKS send succeeded.', $this->logContext($target));

            try {
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
                $this->logger->error('State save failed after LINE WORKS send succeeded.', $this->logContext($target, [
                    'error' => $e->getMessage(),
                ]));
                throw $e;
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function selectNotificationTargets(array $events): array
    {
        $targets = [];
        $seenDedupeKeys = [];

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
    private function createMessage(array $target): string
    {
        $formattedTime = $this->formatEarthquakeTime((string) $target['earthquake_time']);
        $hypocenterName = (string) $target['hypocenter_name'];
        $scaleText = $this->scaleText((int) $target['max_scale']);
        $formUrl = $this->config->formUrl();

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
            $time = new DateTimeImmutable($value);
            return $time->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i:s');
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
