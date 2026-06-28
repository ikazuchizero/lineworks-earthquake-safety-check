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
    // 低在庫通知は check.php の1回の実行内だけで抑止する。
    // このフラグを永続化すると、1回見落としただけで補充漏れに気づけないため、
    // 次回以降にフォームを消費した場合は再通知できるようにしている。
    private bool $lowStockNotifiedThisRun = false;

    // フォーム枯渇通知も1回の実行につき最大1回にする。
    // 複数の通知対象地震があっても、枯渇後に補充通知先へ連投しないため。
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
        if ($this->config->formStockEnabled()) {
            $this->importForms();
        }

        // php/bin/check.php 側で P2PQuake は limit=10 で取得する。
        // limit=1 にすると、震度5弱以上の地震直後に震度1などの対象外情報が来た場合、
        // 本来通知すべき地震を見落とす可能性があるため、複数件を確認する。
        $events = $this->p2pQuakeClient->fetchEarthquakes();
        $this->logger->info('Fetched earthquake events.', ['count' => count($events)]);

        // notify_scale は通知対象にする最小震度コード。
        // 0 はテスト用。本番では通常、震度5弱相当の45以上へ戻す必要がある。
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

        // 低在庫チェックは、すべての地震通知処理が終わった最後にまとめて行う。
        // 複数地震を処理する途中で補充通知を挟まず、利用者向け通知を先に完了させるため。
        // form_stock_enabled=false のテストモードではフォーム在庫を消費しないので、この数も増やさない。
        $usedFormCount = 0;

        foreach ($targets as $target) {
            $form = $this->resolveFormForNotification($target);

            if ($form === null) {
                continue;
            }

            try {
                // この時点ではフォームURLはまだ未使用扱いのまま。
                // LINE WORKS送信に失敗した場合は、フォームをusedにせずstateも保存しない。
                // テストモードでは固定 form_url を送るだけで、フォーム在庫のused化は行わない。
                $this->lineWorksClient->sendMessage($this->createMessage($target, $form['url']));
            } catch (Throwable $e) {
                $this->logger->error('LINE WORKS send failed.', $this->logContext($target, [
                    'error' => $e->getMessage(),
                ]));
                throw $e;
            }

            $this->logger->info('LINE WORKS send succeeded.', $this->logContext($target));

            try {
                if ($this->config->formStockEnabled()) {
                    // LINE WORKSがメッセージを受け付けた後にだけフォーム消費を確定する。
                    // 送信前に保存すると、送信失敗時にフォームだけ失われる。
                    $this->formStockStore->markUsed($form['index'], $target['dedupe_key']);
                    $this->formStockStore->save();
                    $usedFormCount++;
                }

                // state保存も送信成功後に行う。
                // ここで保存に失敗すると次回再通知の可能性はあるが、通知漏れより安全側に倒す。
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

        }

        if ($usedFormCount > 0) {
            $this->notifyLowStockAfterConsumption();
        }
    }

    /** @param array<string, mixed> $target */
    private function resolveFormForNotification(array $target): ?array
    {
        if (!$this->config->formStockEnabled()) {
            // form_stock_enabled=false はローカル/テスト専用の簡易モード。
            // forms.json/forms.csvは使わず、固定 form_url を送る。フォームをusedにせず、
            // 低在庫通知や枯渇通知も出さない。本番でのフォーム再利用を許すための機能ではない。
            return [
                'index' => null,
                'url' => $this->config->formUrl(),
            ];
        }

        $form = $this->formStockStore->takeAvailable();

        if ($form !== null) {
            return $form;
        }

        // form_stock_enabled=true の本番運用では固定フォームURLへのfallbackは禁止。
        // 同じフォームを複数地震で使い回すと回答が混ざるため、
        // 安否確認通知は送らず、補充通知先へ枯渇を知らせる。
        $this->logger->error('Skipped earthquake notification because no form URL is available.', $this->logContext($target));
        $this->notifyStockOutIfNeeded();

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
        // 低在庫通知は通常cronごとには送らない。
        // この実行でフォームを1件以上消費した場合だけ、実行末尾で最大1回送る。
        // 低在庫状態が続いていても、次回以降にフォーム消費があれば再通知してよい。
        // 1回の通知見落としで枯渇まで気づけない事故を防ぐため。
        $availableCount = $this->formStockStore->availableCount();
        $threshold = $this->config->formLowStockThreshold();

        // threshold は「以下」判定。threshold=10 なら残り10件以下で補充通知する。
        // 同じ実行で枯渇通知を既に送っている場合は、低在庫通知を重ねて送らない。
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
        // 枯渇通知は低在庫通知より緊急度が高い。
        // 一意のフォームURLを添付できないため地震通知は送らず、
        // 同じ実行内で低在庫通知を重ねない。
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
        // dedupe_key の方針: event.id 単体では同一地震の判定に不十分。
        // P2PQuake/JMAでは同じ地震でも震度速報、震源情報、続報などで別IDになることがある。
        // そのため earthquake.time + hypocenter.name を使う。
        // maxScale は含めない。続報で最大震度が更新されても別通知にしないため。
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
                // 先に震源地なし、後から震源地ありの情報が同じ取得結果に混在することがある。
                // 同じ earthquake.time で震源地名あり候補がある場合は、そちらを優先し、
                // UNKNOWN候補は同一地震として除外する。UNKNOWNしかない場合は通知候補に残す。
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

        // maxScale は dedupe_key に含めない。
        // 同じ地震の続報で最大震度だけ上がった場合に、別キー扱いで二重通知しないため。
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
        // P2PQuake/JMAの earthquake.time は、タイムゾーン指定がない場合でも日本時間として扱う。
        // UTC扱いしてAsia/Tokyoへ変換すると、表示時刻が+9時間ずれるため。
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
