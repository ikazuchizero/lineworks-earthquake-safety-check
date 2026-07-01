<?php
declare(strict_types=1);

final class EarthquakeChecker
{
    // 地震取得、通知対象抽出、フォームURL解決、LINE WORKS送信、state保存をつなぐ中核クラス。
    // 事故防止のため「送信成功前にstateやフォームを確定しない」順序をここで守る。
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
        // 全体フローの入口。
        // 1. 必要ならCSV取り込み 2. 地震取得 3. 通知対象抽出 4. 送信 5. state/form更新 の順で進める。
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
        // form_stock_enabled=true のときだけ呼ばれる。
        // CSVに実フォームURLが入っていても、ログにはURL本文を出さず件数だけ残す。
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
        // 補充・枯渇など運用担当者向け通知は、安否確認通知先とは別roomへ送る。
        // 利用者向けルームに運用アラートを混ぜないため。
        $this->lineWorksClient->sendMessage($message, $this->config->formLowStockRoomId());
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function selectNotificationTargets(array $events): array
    {
        // event.id単体ではなく、dedupe_key と earthquake_time の両方で重複を抑える。
        // UNKNOWN震源地は即通知せず、震源地名ありの続報を待ってから必要な場合だけ通知する。
        $candidatesByTime = [];
        $stateChanged = false;

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

            if ($this->stateStore->hasNotifiedEarthquakeTime((string) $candidate['earthquake_time'])) {
                $this->logger->info('Skipped earthquake event.', $this->logContext($candidate, [
                    'reason' => 'duplicate_earthquake_time',
                ]));
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

                foreach ($candidates as $skippedCandidate) {
                    if ($skippedCandidate === $candidate) {
                        continue;
                    }

                    $reason = $skippedCandidate['hypocenter_name'] === 'UNKNOWN'
                        ? 'unknown_hypocenter_has_named_candidate_in_current_batch'
                        : 'duplicate_earthquake_time_in_current_batch';

                    $this->logger->info('Skipped earthquake event.', $this->logContext($skippedCandidate, [
                        'reason' => $reason,
                    ]));
                }

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

            if (!$this->isPendingUnknownExpired($pending)) {
                $this->logger->info('Skipped earthquake event.', $this->logContext($unknownCandidate, [
                    'reason' => 'unknown_hypocenter_pending_wait',
                ]));
                continue;
            }

            $this->logger->info('UNKNOWN hypocenter pending expired.', $this->logContext($unknownCandidate, [
                'reason' => 'unknown_hypocenter_pending_expired',
            ]));
            $this->addTargetIfNotSeen($targets, $seenDedupeKeys, $seenEarthquakeTimes, $unknownCandidate);
        }

        // ここからは、今回のP2PQuake取得結果に残っていない pending UNKNOWN も確認する。
        // APIの取得件数には上限があるため、保留中の地震が次回取得結果から消えていても、
        // 保留時間を過ぎたら UNKNOWN のまま1回だけ通知できるようにする。
        foreach ($this->stateStore->pendingUnknowns() as $earthquakeTime => $pending) {
            if ($this->stateStore->hasNotifiedEarthquakeTime($earthquakeTime)) {
                $this->logger->info('Skipped pending UNKNOWN earthquake.', [
                    'earthquake_time' => $earthquakeTime,
                    'reason' => 'duplicate_earthquake_time',
                ]);
                $this->stateStore->removePendingUnknown($earthquakeTime);
                $stateChanged = true;
                continue;
            }

            if (isset($seenEarthquakeTimes[$earthquakeTime])) {
                $this->logger->info('Skipped pending UNKNOWN earthquake.', [
                    'earthquake_time' => $earthquakeTime,
                    'reason' => 'duplicate_earthquake_time_in_current_batch',
                ]);
                continue;
            }

            if (!$this->isPendingUnknownExpired($pending)) {
                $this->logger->info('Skipped pending UNKNOWN earthquake.', [
                    'earthquake_time' => $earthquakeTime,
                    'reason' => 'unknown_hypocenter_pending_wait',
                ]);
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

            if ($candidate['max_scale'] < $this->config->notifyScale()) {
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
        if (isset($seenDedupeKeys[$candidate['dedupe_key']])) {
            $this->logger->info('Skipped earthquake event.', $this->logContext($candidate, [
                'reason' => 'duplicate_dedupe_key_in_current_batch',
            ]));
            return;
        }

        if (isset($seenEarthquakeTimes[(string) $candidate['earthquake_time']])) {
            $this->logger->info('Skipped earthquake event.', $this->logContext($candidate, [
                'reason' => 'duplicate_earthquake_time_in_current_batch',
            ]));
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

        return (time() - $firstSeenTimestamp) >= $this->config->unknownHypocenterHoldSeconds();
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function buildCandidate(array $event): ?array
    {
        // P2PQuakeのレスポンスは外部API由来なので、必須要素が欠ける可能性がある。
        // 欠けたイベントはskip理由を残し、無理に通知対象へしない。
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
        // 安否確認メッセージ本文を作る。
        // formUrlは実フォームURLなので、ここでログ出力せずLINE WORKS本文にだけ入れる。
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
