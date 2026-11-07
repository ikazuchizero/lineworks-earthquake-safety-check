# PHP版 運用メモ

このディレクトリは、Google Apps Script版とは別実装のPHP版です。GAS版のファイルはリポジトリ直下の src/ に残し、PHP版は php/ 配下だけで運用します。

## 実行方法

手動確認では、リポジトリ直下から次を実行します。

```powershell
php php/bin/check.php
```

本番ではXserverなどのcronから同じ入口を定期実行する想定です。多重実行は lock ファイルと flock() で防ぎます。

## Google Forms自動生成

個人Googleアカウント所有のテンプレートを通知対象の地震ごとにDrive APIでコピーし、Forms APIで公開状態を確認・設定して、published viewの `anyone` reader を付けた回答者URLだけをLINE WORKSへ送ります。

Google OAuthとテンプレート設定は常に必須です。不足している場合は起動時に停止します。

Git管理外の `config.php` にユーザーOAuthのclient ID、client secret、offline refresh token、template IDを置きます。サービスアカウントではありません。手作業で作ったテンプレートへは `https://www.googleapis.com/auth/drive` の所有者同意が必要です。回答収集、回答者の本人対応付け、設問変更はしません。

`publishSettings` に対応したテンプレートが必須です。対応していないテンプレートはコピー前に拒否します。確認済みメールアドレスの収集（`VERIFIED`）はGoogleログインを必要とするため、匿名回答では無効にしてください。この設定もコピー前に拒否し、設問やメール収集設定を自動変更しません。

回答者公開はリンクを知っている人が使える設定で、転送先の人も回答できます。公開用権限の `allowFileDiscovery` は `false` にし、既存権限の値が `true` または不明なら同じ公開用権限を更新します。編集権限・結果閲覧権限とは別です。実フォームの匿名アクセスは未検証で、APIの公開設定だけでは全てのログイン制限を証明できません。テンプレート所有者はGoogleログイン不要で回答できることを実利用者で確認してください。OAuth testing modeのrefresh token失効・撤回も運用者が公式OAuth案内に従って管理します。

コピーIDは公開前に、回答者URLはLINE WORKS送信前に `state.json` へ原子的に保存します。LINE送信失敗や準備途中の再実行では同じコピーを再利用します。ただしコピー成功直後に状態保存が永続化できなかった場合は孤児または重複コピーがあり得るため、完全なexactly-once保証ではありません。

## PHPファイルの役割

- `php/bin/check.php`: cron / タスクスケジューラから呼ぶ入口です。lock取得、設定読み込み、各Store/Client生成、`EarthquakeChecker` 実行を担当します。
- `php/bin/setup_check.php`: 設定、秘密鍵、保存先、主要PHPファイルの配置を確認します。保存先として必要なのは `php/storage/` です。
- `php/bin/connectivity_check.php`: LINE WORKS APIへの疎通確認として短い検証文を送ります。送信先は通常通知先だけで、静穏時間帯は送信を省きます。Googleフォーム生成や安否確認本文の送信は行いません。
- `php/bin/health_check.php`: `app.log` を要約し、保守通知を送ります。状態ファイルを直接読み取る検査ではありません。
- `php/src/Config.php`: `config.php` の読み込みと必須設定を検証します。Google OAuthとテンプレート設定を必須とします。
- `php/src/EarthquakeChecker.php`: 地震取得後の通知対象抽出、重複判定、フォームURL解決、LINE WORKS送信、state更新順序を管理します。
- `php/src/P2PQuakeClient.php`: P2PQuake APIから地震情報を取得します。通知漏れ防止のため、最新1件だけにしない方針です。
- `php/src/LineWorksClient.php`: LINE WORKSのtoken取得とBotメッセージ送信を担当します。tokenやsecretはログに出しません。
- `php/src/GoogleFormsClient.php`: テンプレート確認、複製、公開、リンク限定の回答者権限設定と回答者URL取得を担当します。
- `php/src/StateStore.php`: `state.json` に通知済み `dedupe_key` を保存し、二重通知を防ぎます。GoogleのコピーIDは公開前、回答者URLはLINE WORKS送信前に保存します。
- `php/src/Logger.php`: `app.log` へ運用ログを追記します。外部共有前には秘密値や実URLが含まれていないか確認してください。
- `php/src/SetupChecker.php`: `setup_check.php` の実体です。秘密値そのものは出さず、設定・秘密鍵・保存先ディレクトリ・主要PHPファイルについて、存在・読込可否・空でないことを中心に確認します。
- `php/src/HealthChecker.php`: `health_check.php` の実体です。直近ログから保守通知向けの要約を作ります。
- `php/src/FailureNotifier.php`: `check.php` 実行失敗時の保守通知を担当します。同じ失敗が続く場合の通知過多を抑えます。
- `php/src/ErrorNotificationStore.php`: 失敗通知の前回状態を保存し、同じ失敗通知を繰り返し送らないために使います。本体の地震通知stateとは別管理です。

## 地震通知の重複防止

P2PQuake/JMAでは、同じ地震でも震度速報、震源情報、続報などで別の `event.id` になることがあります。そのため `event.id` 単体では重複判定しません。

PHP版では原則として `earthquake.time|hypocenter.name` を重複判定キーにします。`maxScale` は含めません。続報で最大震度が変わっても、同じ地震をもう一度通知しないためです。

同じ取得バッチ内で、同じ発生時刻の `UNKNOWN` と震源地名あり候補が混在した場合は、震源地名あり候補を優先します。`UNKNOWN` しかない場合は即通知せず、UNKNOWN震源地の保留ルールに従います。


### UNKNOWN震源地の保留

`UNKNOWN` 震源地は即通知せず、`unknown_hypocenter_hold_seconds` 秒だけ保留します。デフォルトは600秒です。保留中に同じ `earthquake_time` の震源地名あり情報が来た場合は、震源地名あり情報を通知します。保留時間を過ぎても震源地名あり情報が来ない場合だけ、`UNKNOWN` のまま1回通知します。pending stateに残っている `UNKNOWN` も、API取得結果に同じ地震が残っていなくても期限切れ判定の対象にします。一度通知した `earthquake_time` は、`UNKNOWN`・震源地名あり・続報の別に関係なく再通知しません。
## 本番前チェックリスト

- `php/config.php` が配置されている。
- `php/secrets/private.key` が配置されている。
- `notify_scale` が本番条件、通常は震度5弱相当の45以上になっている。テスト用の0のままにしない。
- 検証で `notify_scale=0` を使う場合はテスト専用です。本番相当では45以上を想定してください。設定値は `0, 10, 20, 30, 40, 45, 50, 55, 60, 70` のみ有効です。
- Botが安否確認通知先ルームに参加している。
- `php/storage/` にPHPから書き込み権限がある。
- Google OAuthのclient ID、client secret、refresh token、テンプレートIDを設定している。
- テンプレートは `publishSettings` に対応し、確認済みメールアドレスの収集（`VERIFIED`）を無効にしている。実際の受信者がGoogleログインなしで回答できることも確認する。

## 障害時に見る場所

`php/storage/` は通知状態・Googleフォーム準備状態・ログの保存先です。

- `php/storage/app.log`: 実行結果、skip理由、送信失敗を確認します。秘密値やURL本文は出さない方針です。
- `php/storage/state.json`: 通知済み地震の重複判定状態を確認します。壊れている場合は空扱いせず停止します。

## app.logの見方

`app.log` は「対象地震について、送った／送っていない／なぜ送れなかった」を後から確認するためのログです。`check.php` は正常終了ごとに `check_completed` をINFOで記録します。対象地震がない実行でも、この完了記録を基準にcronの稼働を確認できます。

障害時はまず `ERROR` を見ます。対象地震を追うときは `earthquake_time` と `dedupe_key` を確認してください。`event_id` は続報で変わることがあるため、重複判定の主キーとしては扱いません。

- `notification_completed`: LINE WORKS送信とstate保存まで完了しています。

`State save failed after LINE WORKS send succeeded.` が出た場合、LINE WORKS送信後に `state.json` の保存で失敗しています。完全なトランザクションではないため、`earthquake_time` と `dedupe_key` で二重通知や通知状態の不整合を確認してください。ログには実フォームURLは出しません。

ログや運用メモには、実フォームURL、room_id、bot_id、token、secret、秘密鍵、Authorization header、APIレスポンス全文を書かないでください。外部共有が必要な場合は、先にこれらが含まれていないことを確認してください。

## テスト

外部APIへ実送信しない最小テストは次で実行します。

```powershell
php php/tests/run.php
```

このテストは設定検証、HTTPステータス判定、Googleフォーム準備・送信失敗後の再試行など、PHP版の事故りやすい分岐だけを確認します。実 `config.php`、実秘密鍵、実フォームURL、LINE WORKS APIは使いません。

## 絶対にGitへ入れないもの

- `php/config.php`
- `php/secrets/private.key`
- `php/storage/state.json`
- `php/storage/app.log`
- アクセストークン、JWT、room_id、bot_id、client_secretなどの秘密値やID
