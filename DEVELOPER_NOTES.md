# 開発メモ

基準日: 2026-11-06。ここはコードを追うためのメモであり、稼働中の本番環境・送信成功・障害の有無は確認していない。

## 対象範囲

現在の開発対象は [`php/`](php/) 側だけ。リポジトリ直下の [`src/`](src/) は旧Google Apps Script版を保存している未使用prototypeで、現行PHP処理から呼ばれない。使用手順・cron想定の運用説明は [`README.md`](README.md) と [`php/OPERATIONS.md`](php/OPERATIONS.md) を参照し、以下ではコード上の対応、状態遷移、変更時の注意を補う。

## PHPの入口と依存関係

- [`php/bin/check.php`](php/bin/check.php) が定期実行の入口。`storage/`、`app.log`、`check.lock`の`flock(LOCK_EX|LOCK_NB)`、[`Config::load`](php/src/Config.php)、[`StateStore`](php/src/StateStore.php)、[`P2PQuakeClient`](php/src/P2PQuakeClient.php)、[`LineWorksClient`](php/src/LineWorksClient.php)、[`GoogleFormsClient`](php/src/GoogleFormsClient.php)を組み立て、[`EarthquakeChecker::run`](php/src/EarthquakeChecker.php)へ渡す。二重起動時はログだけを残して終了し、例外時は終了コード1、ログ、可能なら[`FailureNotifier::notify`](php/src/FailureNotifier.php)を実行する。
- [`php/bin/connectivity_check.php`](php/bin/connectivity_check.php) は[`ConnectivityChecker::run`](php/src/ConnectivityChecker.php)を呼ぶ検証入口。地震判定はせず、通常通知先へ短い検証文を送る。静穏時間帯は送信しない。
- [`php/bin/health_check.php`](php/bin/health_check.php) は[`HealthChecker::summarize`](php/src/HealthChecker.php)と`message`で`app.log`を要約し、[`Config::maintenanceRoomId`](php/src/Config.php)へ送る。ログ本文・実設定値をこのメモには転記しない。
- [`php/bin/setup_check.php`](php/bin/setup_check.php) は[`SetupChecker::run`](php/src/SetupChecker.php)で設定ファイル、秘密鍵ファイル、保存先の `php/storage/`、必要PHPファイルの存在/読込/書込状態を分類し、[`SetupChecker::isOk`](php/src/SetupChecker.php)で終了コードを決める。

## 設定・外部I/O

- [`Config`](php/src/Config.php) は`config.php`の配列から、地震通知閾値、P2PQuake URL、LINE WORKS OAuth情報とroom、Google OAuthとテンプレートID、UNKNOWN保留秒数、東京時間の静穏時間を読み込む。Googleの必須値が不足すれば起動時に停止する。`notify_scale`は許可コードのみ、静穏開始/終了は0..23かつ同値不可。保守通知先は通常roomと同じ。秘密鍵は値を表示せず読込可能性だけ確認する。変更時はgetterだけでなく各入口の依存も確認する。
- [`GoogleFormsClient`](php/src/GoogleFormsClient.php) はユーザーOAuthでtokenを更新し、テンプレートの `publishSettings` 対応と確認済みメール収集の有無を確認してからDriveコピーを作る。`prepareResponderUrl`は公開と回答受付を確認し、公開用の `anyone` reader 権限をリンク限定にして `responderUri` を返す。`allowFileDiscovery` がtrueまたは不明なら同じ権限IDへfalseだけをPATCHし、編集用の一般権限を追加しない。設問・メール収集設定は変更しない。
- [`P2PQuakeClient::fetchEarthquakes`](php/src/P2PQuakeClient.php) はURLへ`limit`（入口では10）を付け、20秒タイムアウトでGETする。HTTP非2xx、通信失敗、JSON不正、配列でない応答は空配列にせず例外。`buildUrl`は既存queryの`limit`を上書きするため、最新1件へ縮めない。
- [`LineWorksClient::sendMessage`](php/src/LineWorksClient.php) はJWTを[`createJwt`](php/src/LineWorksClient.php)で作り、OAuth token取得後にBot message APIへ送る。送信先は引数roomまたは通常room。HTTP statusは最後のステータス行を使い、token/secret/秘密鍵/response本文をログへ出さない。ここを変更する場合、認証・HTTP失敗の例外境界とテスト用fake clientの契約を維持する。
- [`Logger`](php/src/Logger.php) は時刻・level・messageをtext prefixで出力し、contextがあれば末尾にJSONを付けて追記する。`HealthChecker`は直近N日（既定7日）の行を件数/圧縮要約し、全文を外部送信しない。

## 地震通知の本体

### [`EarthquakeChecker`](php/src/EarthquakeChecker.php)

- `run`はP2PQuake取得→`selectNotificationTargets`→静穏バッチ候補の追加→発生時刻順送信、の順。各対象は`resolveFormForNotification`でGoogleフォームを解決する。コピーIDを公開処理前に、回答者URLをLINE WORKS送信前に保存し、再実行では同じコピーを再利用する。LINE WORKS送信成功後だけ通知済みstateを保存する。送信後の保存失敗は状態不整合として例外にする。
- `selectNotificationTargets`はイベントshape、閾値、`dedupe_key`、発生時刻を順に除外する。通常時間帯は発生時刻ごとにまとめ、同時刻で震源地名ありをUNKNOWNより優先する。UNKNOWNは[`StateStore::markPendingUnknown`](php/src/StateStore.php)へ保留し、`unknown_hypocenter_hold_seconds`経過後に通知候補化する。通知済みの発生時刻索引でも抑止する。
- 静穏時間帯は`quietWindowFor`で東京時間の窓を作り、`captureQuietCandidate`/`isBetterQuietCandidate`で同窓の最大震度、同震度なら後発を選ぶ。`quietBatchHasEnded`後に一件だけ通知し、完了時刻/バッチを保存して再通知を抑止する。時刻解釈は`parseTokyoTime`/`compareTimes`に集約される。
- `buildCandidate`はP2PQuakeの`id`、`earthquake.time`、震源地名、最大震度から`event_id`/`earthquake_time`/`hypocenter_name`/`max_scale`/`dedupe_key`を作る。`createMessage`はフォームURLを本文へ埋め、`formatEarthquakeTime`/`scaleText`が表示値を作る。入力shapeや必須時刻が欠ける場合は通知せずログ理由だけ残す。

### 保存とフォーム

- [`StateStore`](php/src/StateStore.php) の`state.json`は`notified`、発生時刻索引、UNKNOWN保留、静穏バッチ、`google_forms_by_earthquake_time`を保持する。GoogleコピーIDと回答者URLは`googleFormForEarthquakeTime`と`saveGoogleFormForEarthquakeTime`で発生時刻ごとに扱う。`load`は不正JSONを空状態にせず停止するが、既存mapの一部が欠けている場合は該当mapだけ初期化し、通知済み情報だけの旧形式には索引とGoogle状態を補完する。未知の既存キーは変更せず保持する。`save`は一時ファイルからrenameする。状態キーを加える場合は`defaultState`、補完、対象判定、テストを同時に更新する。
- [`ErrorNotificationStore`](php/src/ErrorNotificationStore.php) はカテゴリ別fingerprintを一定間隔で抑止し、[`FailureNotifier`](php/src/FailureNotifier.php) が一般化した失敗通知を保守roomへ送る。

## テスト対応

PHPテストは [`php/tests/run.php`](php/tests/run.php) のregistryと、[`php/tests/bootstrap.php`](php/tests/bootstrap.php) の合成P2P/LINE WORKS fake、Google HTTP fixtureを使う。`ConfigTest`は型・閾値・Google必須設定、`GoogleFormsClientTest`は複製・公開・リンク限定権限・送信前保存・コピー再利用、`EarthquakeCheckerTest`は閾値・重複・UNKNOWN保留・静穏時間・送信後保存を確認する。`StateStoreTest`、`P2PQuakeClientTest`、`LineWorksClientTest`、`ConnectivityCheckerTest`、`HealthCheckerTest`、`FailureNotifierTest`、`SetupCheckerTest`、`LoggerTest`、`ErrorNotificationStoreTest`も対応クラスを確認する。実Googleの匿名回答や実送信は検証対象外。外部API・実forms・実configを使わない。

## 変更時の注意と未確認

- 通知対象を追加/除外する変更は、`dedupe_key`と発生時刻の二重抑止、UNKNOWNの期限、静穏バッチ、フォームID・URLを送信前に保存し、送信成功後だけ通知済みにする順序を壊さない。
- `php/config.php`実値、`php/secrets/`、`php/forms/`の実データ、`php/storage/`のruntime、ログ本文は未読。ここで記した動作はソース・サンプル・合成テストから読めた構造だけであり、cron、ネットワーク、LINE WORKS送信、P2PQuake応答、本番設定は未確認。
- コード変更時は該当段落、相対リンク/行位置、対応テストの説明を同じ変更で更新する。README/OPERATIONSの操作説明を複製せず、仕様が変わった場合はそちらとのリンク先も確認する。
