<?php
declare(strict_types=1);

final class EarthquakeChecker
{
    // PHP版BOTの本体フローをまとめるクラス。
    //
    // このクラスは、1回のcron実行で次の流れをつなぐ。
    // 1. フォーム補充CSVを取り込み、forms.json の在庫状態へ反映する。
    // 2. P2PQuakeから複数件の地震情報を取得する。
    // 3. notify_scale、dedupe_key、earthquake_time、UNKNOWN保留状態、フォーム枯渇skip状態を見て通知対象を絞る。
    // 4. 通知対象ごとにフォームURLを確保し、LINE WORKSへ安否確認本文を送る。
    // 5. LINE WORKS送信成功後にだけ forms.json の used 化と state.json の notified 確定を行う。
    // 6. フォーム枯渇で送れなかった地震は notified ではなく skipped_due_to_form_stock_out として保存する。
    // 7. 低在庫通知、フォーム枯渇の保守通知、health_check が読む notification_completed ログを残す。
    //
    // 送信・state保存・フォーム消費の順序を変えると、通知漏れ、二重通知、フォームURLの空消費が起きやすい。
    // そのため、ここでは「送信前に確定してよい状態」と「送信成功後にだけ確定する状態」を明確に分ける。
    private const STOCK_OUT_REMINDER_INTERVAL_SECONDS = 21600;
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

    // 対象地震ありのフォーム枯渇管理通知を送った実行では、低在庫通知や平時リマインドを重ねない。
    // 枯渇管理通知そのものの再試行・重複抑制は、stateの notice_status で対象地震ごとに管理する。
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
        $this->config = $config;
        $this->p2pQuakeClient = $p2pQuakeClient;
        $this->lineWorksClient = $lineWorksClient;
        $this->stateStore = $stateStore;
        $this->formStockStore = $formStockStore;
        $this->logger = $logger;
    }

    public function run(): void
    {
        // check.php から呼ばれる1回分のcron処理。
        //
        // 1. form_stock_enabled=true の場合だけ、地震確認前に forms.csv を取り込む。
        // 2. P2PQuakeを limit=10 で取得し、直近1件だけを見ることによる通知漏れを避ける。
        // 3. selectNotificationTargets() で、震度条件・重複・UNKNOWN保留・フォーム枯渇skipをまとめて判定する。
        // 4. 以前のフォーム枯渇管理通知が未達なら、P2PQuakeの最新結果とは独立して保守通知だけ再試行する。
        // 5. 通知対象がない場合だけ、平時のフォーム在庫0件リマインドを必要に応じて送る。
        // 6. 未通知地震は earthquake_time の古い順に並べ、利用者への通知順を発生順に近づける。
        // 7. 通知対象ごとにフォームURLを確保し、LINE WORKS送信成功後にだけ forms/state を確定する。
        // 8. 通知完了時は notification_completed を残す。check.php全体の正常終了ログ check_completed は check.php 側で記録する。
        // 9. この実行でフォームを消費した場合だけ、最後に低在庫通知を判定する。
        //
        // CSV取り込み、保守通知、state/forms更新は check.php の flock() 内で実行される前提。
        // そのため、同時cronによる state/forms の競合をできるだけ避けられる。
        if ($this->config->formStockEnabled()) {
            $this->importForms();
        }

        $events = $this->p2pQuakeClient->fetchEarthquakes();

        // notify_scale はP2PQuakeの震度コードで、45は震度5弱を表す。
        // 検証時に低い値へ変えると通知対象が広がるため、コメントと実値の不一致に注意する。
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

        // 低在庫チェックは、すべての地震通知処理が終わった最後にまとめて行う。
        // 複数地震を処理する途中で補充通知を挟まず、利用者向け通知を先に完了させるため。
        // form_stock_enabled=false のテストモードではフォーム在庫を消費しないので、この数も増やさない。
        $usedFormCount = 0;

        foreach ($targets as $target) {
            // 通知対象1件の送信処理。
            //
            // フォームURLは先に確保するが、この時点ではまだ used にしない。
            // LINE WORKS送信成功後にだけ、forms.json の該当フォームを used にし、
            // state.json へ notified と notified_by_earthquake_time を保存する。
            //
            // この順序を変えると、次の事故が起きる。
            // - 送信前に notified を保存する: 送信失敗時に次回再試行されず通知漏れになる。
            // - 送信前にフォームを used にする: 送信失敗時にフォームURLだけが失われる。
            // - state保存に失敗する: 次回再通知の可能性は残るが、送信前に通知済みにするより安全側。
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
                if ($this->config->formStockEnabled()) {
                    // LINE WORKSが本文を受け付けた後にだけフォーム消費を確定する。
                    $this->formStockStore->markUsed($form['index'], $target['dedupe_key']);
                    $this->formStockStore->save();
                    $usedFormCount++;
                }

                // 通知済みstateもLINE WORKS送信成功後にだけ確定する。
                $notifiedAt = gmdate('c');
                $record = [
                    'dedupe_key' => $target['dedupe_key'],
                    'event_id' => $target['event_id'],
                    'earthquake_time' => $target['earthquake_time'],
                    'hypocenter_name' => $target['hypocenter_name'],
                    'max_scale' => $target['max_scale'],
                    'notified_at' => $notifiedAt,
                ];
                if ($this->config->formStockEnabled()) {
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
                if ($this->config->formStockEnabled()) {
                    $completionContext['remaining_forms'] = $this->formStockStore->availableCount();
                }
                $this->logger->info('notification_completed', $completionContext);
            } catch (Throwable $e) {
                $failureContext = [
                    'error' => $this->safeErrorSummary($e),
                ];
                if ($this->config->formStockEnabled()) {
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
        // 通知本文に載せるフォームURLを決める。
        //
        // form_stock_enabled=true では forms.json の available だけを使い、固定 form_url へはfallbackしない。
        // 同じフォームURLを複数の地震に使うと、回答が混ざり、どの地震への安否回答か分からなくなるため。
        //
        // available がない場合は安否確認本文を送らない。
        // 代わりに skipped_due_to_form_stock_out として state に残し、保守通知先へフォーム枯渇を知らせる。
        // このskipは「正常送信済み」ではないが、補充後にbotが同じ地震を自動後追い送信しないための状態。
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

        $this->logger->error('Skipped earthquake notification because no form URL is available.', $this->logContext($target));
        $this->markSkippedDueToFormStockOut($target);
        $this->notifyStockOutForTarget($target);

        return null;
    }

    private function importForms(): void
    {
        // フォーム補充CSVを地震チェック前に取り込む。
        //
        // この処理は form_stock_enabled=true のときだけ呼ばれる。
        // forms.csv には実フォームURLが入るため、ログにはURL本文を出さず、取り込み件数・重複件数・不正行件数だけを残す。
        // 取り込み成功時は processed、失敗時は failed へ移動されるため、同じCSVを次回cronで繰り返し処理しない。
        // CSV取り込み失敗は安否確認本体とは別の運用問題なので、保守通知先へ短く知らせる。
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
        // 低在庫通知は「この実行でフォームを1件以上消費した後」の最後にだけ判定する。
        //
        // 通常cronごとに送ると連投になるが、フォーム消費後なら在庫が実際に減ったタイミングなので通知する意味がある。
        // 低在庫状態が続いていても、次回以降にまたフォームを消費した場合は再通知してよい。
        // ただし、同じ実行で対象地震ありのフォーム枯渇通知を送っている場合は、低在庫通知を重ねない。
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
            $this->logger->info('form_low_stock_notice_sent', [
                'available_count' => $availableCount,
                'threshold' => $threshold,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Form low stock notification failed.', [
                'available_count' => $availableCount,
                'threshold' => $threshold,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $target */
    private function markSkippedDueToFormStockOut(array $target): void
    {
        // フォーム枯渇で送れなかった地震を、正常送信済みの notified とは別状態で保存する。
        //
        // この状態は「安否確認本文は送れていないが、自動送信対象としては手動対応へ切り替えた」ことを表す。
        // notified に入れないのは、正常送信済みと区別してログ・state上で追えるようにするため。
        // 一方で dedupe_key と earthquake_time の両方を保存し、補充後にbotが同じ地震を自動後追い送信しないようにする。
        // UNKNOWN pending からのfallbackが枯渇skipになった場合も、pendingはここで消す。
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
        // 対象地震をフォーム枯渇で自動送信できなかったことを、保守通知先へ知らせる。
        //
        // 安否確認本文は送らない。ここで送るのは管理通知だけ。
        // 枯渇通知の到達状態は skipped_due_to_form_stock_out の notice_status に保存する。
        // 送信成功なら sent、失敗なら failed とし、failed/pending は後続cronで管理通知だけ再試行する。
        // 再試行されるのは枯渇管理通知であり、フォーム補充後も安否確認本文を自動で後追い送信しない。
        //
        // stockOutNotifiedThisRun は、同じ実行の最後に低在庫通知や平時リマインドを重ねないためのフラグ。
        // 枯渇通知そのものは notice_status により対象地震ごとに管理する。
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
        // フォーム枯渇の管理通知が未達だった地震を、P2PQuakeの最新取得結果とは独立して再試行する。
        //
        // P2PQuakeの取得件数には上限があるため、対象地震が次回取得結果から消えることがある。
        // その場合でも、state上に notice_status=pending/failed が残っていれば管理通知だけ再試行する。
        // ここで安否確認本文は絶対に送らない。フォーム補充後も、枯渇skip済み地震は自動後追い送信しない。
        if (!$this->config->formStockEnabled()) {
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
        // skipped_due_to_form_stock_out のrecordから、ログ出力と管理通知retryに使うtarget形へ戻す。
        // 古いstateや壊れたrecordでは earthquake_time が欠ける可能性があるため、その場合はnullで安全にskipする。
        // ここで復元するtargetは保守通知用であり、安否確認本文の後追い送信には使わない。
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
        // 対象地震がない平時に、フォーム在庫0件を知らせるリマインド。
        //
        // 対象地震ありのフォーム枯渇管理通知とは、状態も文面も抑止条件も分ける。
        // 平時リマインドは「今は地震通知に失敗していないが、このままだと次の対象地震で自動送信できない」ことを知らせるもの。
        // 連投を避けるため、stock_out_reminder の最終通知時刻で6時間に1回までに抑える。
        if (!$this->config->formStockEnabled()) {
            return;
        }

        if ($this->skippedStockOutTargetThisRun) {
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
        // P2PQuakeから取得した複数イベントを、今回送るべき通知候補へ絞り込む。
        //
        // P2PQuakeには速報・震源情報・震源震度情報・続報が混ざり、同じ地震でも event.id が変わる可能性がある。
        // そのため event.id だけでは重複判定しない。
        //
        // notify_scale はP2PQuakeの震度コードで、表示用の「5弱」などの文字列ではない。
        // buildCandidate() で作る dedupe_key は earthquake.time + hypocenter.name。
        // maxScale は続報で変わる可能性があるため、dedupe_key には含めない。
        //
        // stateの役割:
        // - notified: その dedupe_key の安否確認本文をLINE WORKSへ送信済み。
        // - notified_by_earthquake_time: UNKNOWN通知後に震源地名あり続報が来ても、同じ発生時刻なら再通知しないための索引。
        // - skipped_due_to_form_stock_out: 安否確認本文は未送信だが、フォーム枯渇により手動対応へ切り替えた地震。
        // - pending_unknown_by_earthquake_time: UNKNOWN震源を即通知せず、名前確定続報を待つための保留状態。
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

            if ($candidate === null) {
                continue;
            }

            if ($candidate['max_scale'] < $this->config->notifyScale()) {
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
                // UNKNOWN保留ルール: 同じ発生時刻で UNKNOWN と震源地名あり候補が混在したら、震源地名ありを優先する。
                // UNKNOWNを先に送ると、その後の震源地名あり続報と二重通知になりやすいため。
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
                // UNKNOWN保留ルール: UNKNOWNしかない初回は送らず、pending_unknown_by_earthquake_time へ保存する。
                // hold時間内に震源地名あり続報が来れば、UNKNOWNではなく名前あり候補を1件だけ送る。
                $this->stateStore->markPendingUnknown($earthquakeTime, $this->pendingUnknownRecord($unknownCandidate));
                $stateChanged = true;
                $this->logger->info('Skipped earthquake event.', $this->logContext($unknownCandidate, [
                    'reason' => 'unknown_hypocenter_pending_created',
                ]));
                continue;
            }

            if (!$this->isPendingUnknownExpired($pending)) {
                // UNKNOWN保留ルール: hold時間内はまだ送らない。
                // この間はフォームURLも消費せず、通知済みstateにも入れない。
                continue;
            }

            // UNKNOWN保留ルール: hold時間を過ぎても名前確定続報が来なければ、UNKNOWNのままfallback通知する。
            $this->logger->info('UNKNOWN hypocenter pending expired.', $this->logContext($unknownCandidate, [
                'reason' => 'unknown_hypocenter_pending_expired',
            ]));
            $this->addTargetIfNotSeen($targets, $seenDedupeKeys, $seenEarthquakeTimes, $unknownCandidate);
        }

        // UNKNOWN保留ルール: 今回のP2PQuake取得結果に残っていないpendingも確認する。
        //
        // API取得件数には上限があるため、保留中の地震が次回取得結果から消えることがある。
        // その場合でも、pending_unknown_by_earthquake_time に残っていれば、hold超過後にUNKNOWNのままfallbackできる。
        // ただし、すでに notified または skipped_due_to_form_stock_out 済みならpendingを消す。
        // 残しておくと、次回以降も同じ保留recordを見続けて運用判断を誤るため。
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

            if (isset($seenEarthquakeTimes[$earthquakeTime])) {
                continue;
            }

            if (!$this->isPendingUnknownExpired($pending)) {
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
        // 同一実行内の二重追加を止める最後のガード。
        //
        // stateにまだ保存されていない同一レスポンス内の続報は、ここで dedupe_key と earthquake_time の両方を見る。
        // dedupe_key は同じ震源名の重複を、earthquake_time は UNKNOWN と震源地名ありの混在を抑える。
        if (isset($seenDedupeKeys[$candidate['dedupe_key']])) {
            return;
        }

        if (isset($seenEarthquakeTimes[(string) $candidate['earthquake_time']])) {
            return;
        }

        $seenDedupeKeys[$candidate['dedupe_key']] = true;
        $seenEarthquakeTimes[(string) $candidate['earthquake_time']] = true;
        $targets[] = $candidate;
    }

    /** @param array<string, mixed> $candidate */
    private function pendingUnknownRecord(array $candidate): array
    {
        // pending_unknown_by_earthquake_time に保存するUNKNOWN保留recordを作る。
        //
        // 初回検出時刻 first_seen_at を持たせ、hold時間を過ぎたか後続cronで判断できるようにする。
        // このrecord作成だけではフォームURLを確保せず、notifiedにも入れない。
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
        // pending_unknown_by_earthquake_time のrecordから、通知候補candidateを復元する。
        //
        // P2PQuake取得結果から対象地震が消えた後でも、hold超過後にUNKNOWN fallbackできるようにするため。
        // earthquake_time が復元できないrecordは壊れているためnullにし、呼び出し側でpendingを掃除する。
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
        // UNKNOWN保留がhold時間を超えたか判定する。
        //
        // first_seen_at が壊れている場合、永遠に保留されるよりは運用ログを残して期限切れ扱いにする。
        // その後の通知可否は notify_scale や既存stateの判定でさらに絞られる。
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
        // P2PQuakeレスポンス1件を、以降の判定で使うcandidateへ正規化する。
        //
        // 必須要素が欠ける外部APIレスポンスは、無理に補完して送らずskip理由をログに残す。
        // hypocenter.name が空なら UNKNOWN に寄せる。
        // dedupe_key は earthquake.time + hypocenter.name で作り、maxScale は含めない。
        // maxScaleを含めると、同じ地震の続報で最大震度だけ上がった場合に別地震扱いになり二重通知しやすい。
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
        // 利用者へ送る安否確認本文を作る。
        //
        // formUrl は実フォームURLなので、ログ・例外・テスト出力へは出さず、LINE WORKS本文にだけ含める。
        // ここで本文を作るだけでは state/forms は更新しない。更新は送信成功後のrun()側で行う。
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
        // earthquake.time を利用者向け表示へ整える。
        //
        // タイムゾーン指定がない値は、JMA/P2PQuake由来の日本時間として解釈する。
        // UTC扱いしてからAsia/Tokyoへ変換すると、表示時刻が9時間ずれる。
        // Z や +09:00 のようにタイムゾーン指定がある場合は、その指定を尊重してAsia/Tokyo表示にする。
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
        // 地震1件を追跡するためのログcontextを作る。
        //
        // event_id、earthquake_time、hypocenter_name、max_scale、dedupe_key は調査用に残す。
        // 実フォームURL、room_id、bot_id、token、secret、private key はここへ入れない。
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
        // 保守ログやstateに残すエラー要約を短くする。
        //
        // 外部APIレスポンス全文や長い例外文をそのまま残すと、秘匿情報や不要な詳細が広がる可能性がある。
        // ここでは改行を潰し、先頭200文字だけを保存する。
        $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';

        return substr($message, 0, 200);
    }
}
