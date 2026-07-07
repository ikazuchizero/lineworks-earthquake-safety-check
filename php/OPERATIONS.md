# PHP版 運用メモ

このディレクトリは、Google Apps Script版とは別実装のPHP版です。GAS版のファイルはリポジトリ直下の src/ に残し、PHP版は php/ 配下だけで運用します。

## 実行方法

手動確認では、リポジトリ直下から次を実行します。

```powershell
php php/bin/check.php
```

本番ではXserverなどのcronから同じ入口を定期実行する想定です。多重実行は lock ファイルと flock() で防ぎます。

## 本番/テストのフォームURL設定

本番では `form_stock_enabled=true` を推奨します。`forms.json` の `available` フォームURLを1件ずつ使い、LINE WORKS送信成功後にだけ `used` にします。フォーム枯渇通知と低在庫通知もこのモードで動きます。

`form_stock_enabled` が未設定の場合も `true` 扱いです。PHP版の本番運用はフォームストック必須です。既存の `config.php` を使い回す場合は、`form_stock_path`、`form_import_csv_path`、`form_import_processed_dir`、`form_import_failed_dir`、`form_low_stock_threshold`、`form_low_stock_room_id` を追加してください。固定 `form_url` へ自動fallbackはしません。

ローカル/テストで毎回フォームURLを大量に作るのが難しい場合だけ、`form_stock_enabled=false` にできます。この場合は `forms.json` / `forms.csv` を使わず、`config.php` の `form_url` を固定フォームURLとして送信します。フォームは `used` にならず、低在庫通知・枯渇通知も送りません。

`form_stock_enabled=false` は本番のフォーム再利用を許すための機能ではありません。実運用相当のフォーム在庫運用では `form_stock_enabled=true` を前提にします。`false` で `form_url` が空の場合は、安全のため起動時にエラー停止します。

`form_stock_enabled=false` では `form_low_stock_room_id` を使いません。そのため `php/bin/health_check.php` や `check.php` の失敗通知など、保守系通知を実LINE WORKS向けに動かすと、補充通知先ではなく通常の `room_id` 側へ送られる可能性があります。固定URL検証モードのまま保守系通知を検証する場合は、実利用者がいるルームではなく検証用の安全な `room_id` を使うか、実LINE WORKS向けの疎通確認を避けてください。

## PHPファイルの役割

- `php/bin/check.php`: cron / タスクスケジューラから呼ぶ入口です。lock取得、設定読み込み、各Store/Client生成、`EarthquakeChecker` 実行を担当します。
- `php/bin/setup_check.php`: 設定ファイル、秘密鍵ファイル、保存先ディレクトリ、フォーム在庫関連ディレクトリなどの準備状態を確認します。
- `php/bin/connectivity_check.php`: LINE WORKS API への疎通と、検証用メッセージ送信を確認します。安否確認本文は送りません。
- `php/bin/health_check.php`: アプリの状態ファイル、フォーム在庫、直近ログなどを確認し、必要に応じて保守通知を送ります。
- `php/src/Config.php`: `config.php` の読み込みと必須設定の検証を担当します。`form_stock_enabled` の本番/テスト切り替えもここで検証します。
- `php/src/EarthquakeChecker.php`: 地震取得後の通知対象抽出、重複判定、フォームURL解決、LINE WORKS送信、state/form更新順序を管理します。
- `php/src/P2PQuakeClient.php`: P2PQuake APIから地震情報を取得します。通知漏れ防止のため、最新1件だけにしない方針です。
- `php/src/LineWorksClient.php`: LINE WORKSのtoken取得とBotメッセージ送信を担当します。tokenやsecretはログに出しません。
- `php/src/StateStore.php`: `state.json` に通知済み `dedupe_key` を保存し、二重通知を防ぎます。
- `php/src/FormStockStore.php`: `forms.csv` の取り込み、`forms.json` の保存、フォームURLのavailable/used管理を担当します。
- `php/src/Logger.php`: `app.log` へ運用ログを追記します。外部共有前には秘密値や実URLが含まれていないか確認してください。
- `php/src/SetupChecker.php`: `setup_check.php` の実体です。秘密値そのものは出さず、存在・読込可否・空でないことを中心に確認します。
- `php/src/HealthChecker.php`: `health_check.php` の実体です。状態、フォーム在庫、直近ログを点検して保守通知向けの要約を作ります。
- `php/src/FailureNotifier.php`: `check.php` 実行失敗時の保守通知を担当します。同じ失敗が続く場合の通知過多を抑えます。
- `php/src/ErrorNotificationStore.php`: 失敗通知の前回状態を保存し、同じ失敗通知を繰り返し送らないために使います。本体の地震通知stateとは別管理です。

## フォームURL補充手順

1. Excelなどで1列だけのCSVを作成します。
2. 1行目のヘッダーは `URL` にします。
3. 2行目以降にフォームURLを1件ずつ入れます。
4. ファイル名を `forms.csv` にします。
5. `php/forms/forms.csv` にアップロードします。
6. 次回cron実行時に取り込まれます。

CSV例です。これは形式例であり、実URLはここへ書かないでください。

```csv
URL
https://example.com/form/001
https://example.com/form/002
```

取り込みに成功したCSVは `php/forms/processed/` に日時付きで移動します。取り込みに失敗したCSVは `php/forms/failed/` に移動します。URL本文はログに出さず、件数だけを確認します。

サーバーへCSVをアップロードする場合は、最初から `forms.csv` という名前で転送せず、一時ファイル名でアップロードしてから最後に `forms.csv` へリネームしてください。転送途中のCSVをcronが読み始める事故を避けるためです。

`php/bin/check.php` はCSV取り込み専用コマンドではありません。CSVを取り込んだ後、そのままP2PQuake API取得、通知対象判定、LINE WORKS送信、state/form更新まで進みます。補充CSVを置いた状態で手動実行すると、条件に合う地震があれば実通知まで進む点に注意してください。

## フォームURLの安全ルール

- `available` のフォームURLだけが通知に使われます。
- LINE WORKS送信が成功した後だけ `used` になります。
- `used` のURLは再利用しません。
- 使用済みURLをCSVで再投入しても `available` には戻りません。
- `form_stock_enabled=true` では、フォームURLが0件のときに固定URLへfallbackしません。対象地震がある場合は安否確認通知を送らず、`skipped_due_to_form_stock_out` として手動対応扱いにします。今回対象となった地震はフォーム補充後もbotから後追い自動送信しません。
- 対象地震がある場合のフォーム枯渇通知は、新規に `skipped_due_to_form_stock_out` として記録された対象地震ごとに送ります。同じ地震はstateにより次回以降繰り返し通知せず、フォーム補充後もbotから後追い自動送信しません。同じ実行内で枯渇通知を送った場合、低在庫通知は重ねません。対象地震がない平時の在庫0件リマインドは別扱いで、最短6時間に1回だけ送ります。
- `form_stock_enabled=false` のテスト時だけ、固定 `form_url` を使います。このモードではフォーム消費・低在庫通知・枯渇通知は行いません。

`php/storage/forms.json` は実運用データです。削除・初期化すると、どのフォームURLが `used` だったかの判定も消えます。やむを得ずリセットする場合は、CSV側も未使用URLだけに整理してから再投入してください。使用済みURLを混ぜると、過去に使ったフォームURLを再利用する事故につながります。

## 地震通知の重複防止

P2PQuake/JMAでは、同じ地震でも震度速報、震源情報、続報などで別の `event.id` になることがあります。そのため `event.id` 単体では重複判定しません。

PHP版では原則として `earthquake.time|hypocenter.name` を重複判定キーにします。`maxScale` は含めません。続報で最大震度が変わっても、同じ地震をもう一度通知しないためです。

同じ取得バッチ内で、同じ発生時刻の `UNKNOWN` と震源地名あり候補が混在した場合は、震源地名あり候補を優先します。`UNKNOWN` しかない場合は即通知せず、UNKNOWN震源地の保留ルールに従います。


### UNKNOWN震源地の保留

`UNKNOWN` 震源地は即通知せず、`unknown_hypocenter_hold_seconds` 秒だけ保留します。デフォルトは600秒です。保留中に同じ `earthquake_time` の震源地名あり情報が来た場合は、震源地名あり情報を通知します。保留時間を過ぎても震源地名あり情報が来ない場合だけ、`UNKNOWN` のまま1回通知します。pending stateに残っている `UNKNOWN` も、API取得結果に同じ地震が残っていなくても期限切れ判定の対象にします。一度通知した `earthquake_time` は、`UNKNOWN`・震源地名あり・続報の別に関係なく再通知しません。
## 低在庫通知

低在庫通知は通常cronのたびには送りません。地震通知でフォームURLを1件以上消費した実行の最後に、残数が `form_low_stock_threshold` 以下なら補充通知先へ最大1回だけ送ります。

低在庫状態が続いていても、次回以降に地震通知でフォームURLを消費した場合は再通知してよい設計です。1回の通知見落としで枯渇まで気づけない事故を防ぐためです。

## 本番前チェックリスト

- `php/config.php` が配置されている。
- `php/secrets/private.key` が配置されている。
- `notify_scale` が本番条件、通常は震度5弱相当の45以上になっている。テスト用の0のままにしない。
- 検証で `notify_scale=0` を使う場合はテスト専用です。本番相当では45以上を想定してください。設定値は `0, 10, 20, 30, 40, 45, 50, 55, 60, 70` のみ有効です。
- Botが安否確認通知先ルームに参加している。
- Botが補充通知先ルームに参加している。
- `form_low_stock_room_id` が安否確認通知先とは別の補充通知先になっている。
- 本番では `form_stock_enabled=true` になっている。
- `php/storage/forms.json` に十分な `available` がある。
- `php/storage/` と `php/forms/` 以下にPHPから書き込み権限がある。

## 障害時に見る場所

`php/storage/` はstate/log/forms.jsonなどの内部状態・ログ用です。通常のフォーム補充担当者は `php/forms/` だけを触る運用にしてください。

- `php/storage/app.log`: 実行結果、skip理由、送信失敗、CSV取り込み件数を確認します。秘密値やURL本文は出さない方針です。
- `php/storage/state.json`: 通知済み地震の重複判定状態を確認します。壊れている場合は空扱いせず停止します。
- `php/storage/forms.json`: フォームURLの `available` / `used` 状態を確認します。実URLを外部に貼らないでください。
- `php/forms/processed/`: 取り込み済みCSVを確認します。
- `php/forms/failed/`: 失敗CSVを確認します。ヘッダーが `URL` か、URL列が正しいかを確認します。

## app.logの見方

`app.log` は「対象地震について、送った／送っていない／なぜ送れなかった」を後から確認するためのログです。対象地震がない通常cronでは、毎回INFOログを増やさない方針です。何も起きていない通常運転をログで埋めると、肝心な送信失敗やフォーム枯渇を見落としやすくなるためです。

障害時はまず `ERROR` を見ます。ただし、フォーム枯渇による `skipped_due_to_form_stock_out` は想定内のINFOでも、利用者向け安否確認が送れていない重要な運用イベントです。対象地震を追うときは `earthquake_time` と `dedupe_key` を確認してください。`event_id` は続報で変わることがあるため、重複判定の主キーとしては扱いません。

- `notification_completed`: LINE WORKS送信、フォームURL消費、state保存まで完了しています。フォーム在庫機能が有効な場合は `remaining_forms` で残数も確認できます。
- `skipped_due_to_form_stock_out`: フォーム枯渇により、利用者向け安否確認は送られていません。この地震は手動判断・手動送信対象で、フォーム補充後もbotから後追い自動送信しません。
- `form_stock_out_notice_sent`: 対象地震がある状態でフォームが0件だったため、補充通知先へ枯渇通知を送れたことを示します。新規に `skipped_due_to_form_stock_out` として記録された対象地震ごとに送ります。
- `form_stock_empty_idle_reminder_sent`: 対象地震がない平時にフォーム在庫0件を知らせる保守リマインドです。対象地震ありの枯渇通知とは別扱いで、最短6時間に1回だけ送ります。
- `form_low_stock_notice_sent`: 地震通知でフォームURLを1件以上消費した実行の最後に、残数が閾値以下だったため低在庫通知を送れたことを示します。

フォーム枯渇で送れなかった地震は `state.json` の `skipped_due_to_form_stock_out` に残ります。`notice_status` が `sent` なら補充通知先への管理通知は送信済みです。`failed` または `pending` の場合、P2PQuakeの次回取得結果から対象地震が消えていても、state上の未達レコードから管理通知だけを再試行します。再試行されるのは管理通知だけで、フォーム補充後も安否確認本体をbotから後追い自動送信しません。

`State or form save failed after LINE WORKS send succeeded.` が出た場合、LINE WORKSへの送信自体は成功した後に `forms.json` または `state.json` の保存で失敗しています。完全なトランザクションではないため、二重通知やフォーム消費状態の不整合が起きていないか、`earthquake_time`、`dedupe_key`、`form_index` を見て手動確認してください。ログには実フォームURLは出しません。

ログや運用メモには、実フォームURL、room_id、bot_id、token、secret、秘密鍵、Authorization header、APIレスポンス全文を書かないでください。外部共有が必要な場合は、先にこれらが含まれていないことを確認してください。

## テスト

外部APIへ実送信しない最小テストは次で実行します。

```powershell
php php/tests/run.php
```

このテストは設定検証、HTTPステータス判定、フォーム枯渇時の管理通知再試行など、PHP版の事故りやすい分岐だけを確認します。実 `config.php`、実秘密鍵、実フォームURL、LINE WORKS APIは使いません。

## 絶対にGitへ入れないもの

- `php/config.php`
- `php/secrets/private.key`
- `php/storage/state.json`
- `php/storage/forms.json`
- `php/forms/forms.csv`
- `php/forms/processed/*.csv`
- `php/forms/failed/*.csv`
- `php/storage/app.log`
- アクセストークン、JWT、room_id、bot_id、client_secretなどの秘密値やID
