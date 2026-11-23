# lineworks-earthquake-safety-check

P2PQuake の地震情報を確認し、設定した震度以上の場合に LINE WORKS へ安否確認通知を送る Google Apps Script（GAS）です。既定では通知対象の地震ごとに Google フォームのテンプレートを複製し、回答者 URL を通知本文に載せます。`FIXED_FORM_URL` を設定すると、複製せず固定 URL を使います。

このブランチ（`codex/gas-forms`）は、PHP 版の機能を GAS へ移植した実装です。Google Cloud の OAuth クライアントや refresh token は不要で、GAS 標準の `FormApp` / `DriveApp` を使います。

## 構成

- `src/`: GAS 版の本体
- `src/appsscript.json`: タイムゾーン（Asia/Tokyo）と OAuth スコープ

PHP 版は `main` には含まれていません。最新の PHP 版は `origin/codex/setup-check` の `php/` にあり、以前の実装は `origin/codex/google-forms` の `php/` にあります。

## 必要なもの

1. スプレッドシート（このスクリプトをバインドするか、`SPREADSHEET_ID` を設定）
2. 安否確認フォームのテンプレート（Google フォーム）または固定フォーム URL
3. LINE WORKS の Bot と、通知先チャットルーム
4. LINE WORKS API 用の証明書・秘密鍵

### テンプレートフォームの条件（自動複製モード）

- `FormApp.Form.supportsAdvancedResponderPermissions()` が `true` を返す形式
- 「メールアドレスを収集する」が無効（`collectsEmail()` が `false`）
- スクリプト実行者が編集権限を持っていること
- 設問・デザインはテンプレート側で管理（スクリプトは複製・公開のみ行う）

GAS の `setPublished(true)` で回答者 URL を公開します。組織のポリシーで匿名回答が制限されている場合は、実際の受信者で回答できることを確認してください。

### フォームの2つのモード

| モード | 設定 | 動作 |
|---|---|---|
| 自動複製（既定） | `FORM_TEMPLATE_ID` | 地震ごとにテンプレートを複製し、公開して回答者 URL を使う |
| 固定 URL | `FIXED_FORM_URL` | 複製・公開を行わず、設定した URL を毎回使う。`FORM_TEMPLATE_ID` は不要 |

固定 URL モードは PHP 版の `form_stock_enabled=false`（検証用の固定フォーム）に相当します。両方設定した場合は固定 URL を優先します。URL は `https://` のみ許可します。`docs.google.com/forms/d/.../edit` の編集URLなら `setupCheck` が回答受付中かまで確認しますが、公開URL（`/viewform`）や外部URLは手動確認となります。

## セットアップ

### 0. コードを Apps Script プロジェクトへ反映（初回のみ）

新規に GAS プロジェクトを作る場合は [docs/DEPLOY_OPERATOR.md](docs/DEPLOY_OPERATOR.md) の手順で 28 ファイルを一括反映できます（ブラウザのみで完結。PC へのインストール・ファイルのアップロードは不要）。既にエディタにコードがある場合は不要です。

### 1. スクリプトプロパティを設定

GAS エディタの「プロジェクトの設定」→「スクリプト プロパティ」に設定します。

| キー | 必須 | 内容 |
|---|---|---|
| `CLIENT_ID` | 必須 | LINE WORKS の Client ID |
| `CLIENT_SECRET` | 必須 | LINE WORKS の Client Secret |
| `SERVICE_ACCT` | 必須 | LINE WORKS の Service Account |
| `PRIVATE_KEY` | 必須 | LINE WORKS の秘密鍵（PEM。`\n` のままでも可） |
| `BOT_ID` | 必須 | LINE WORKS の Bot ID |
| `ROOM_ID` | 必須 | 安否確認通知を送るチャットルーム ID |
| `MAINTENANCE_ROOM_ID` | 任意 | 障害通知先。未設定なら `ROOM_ID` |
| `NOTIFY_SCALE` | 必須 | 通知する震度コード（既定値なし）。`0` はテスト用 |
| `FORM_TEMPLATE_ID` | 条件付き必須 | 複製元の Google フォーム ID。`FIXED_FORM_URL` 未設定なら必須 |
| `FIXED_FORM_URL` | 任意 | 固定フォーム URL モード。設定時はテンプレートを複製せず、この URL を毎回使う |
| `SPREADSHEET_ID` | 任意 | スプレッドシートにバインドしない場合に設定 |
| `P2PQUAKE_API_URL` | 任意 | P2PQuake API の URL。既定 `https://api.p2pquake.net/v2/jma/quake` |
| `LINEWORKS_TOKEN_URL` | 任意 | LINE WORKS のトークン URL。既定 `https://auth.worksmobile.com/oauth2/v2.0/token` |
| `UNKNOWN_HYPOCENTER_HOLD_SECONDS` | 任意 | 震源不明の続報を待つ秒数。既定 `600` |
| `QUIET_HOURS_ENABLED` | 任意 | 静穏時間の有効/無効。既定 `true`。`false` で24時間通知 |
| `QUIET_HOURS_START` | 任意 | 静穏時間の開始（0〜23）。既定 `0` |
| `QUIET_HOURS_END` | 任意 | 静穏時間の終了（0〜23）。既定 `6` |
| `MAX_NOTIFICATION_AGE_HOURS` | 任意 | 発生からこの時間を超えた地震は通知しない。既定 `24` |
| `LOG_RETENTION_DAYS` | 任意 | `地震ログ` を残す日数。既定 `90` |
| `ALLOW_BASELINE_WITH_TRIGGERS` | 任意 | 稼働中トリガーがある状態でベースラインを実行する場合だけ `true` |

震度コードは `0, 10, 20, 30, 40, 45, 50, 55, 60, 70` のいずれかです。`NOTIFY_SCALE` は PHP 版と同じく必須で、未設定なら処理を開始しません。

`REPLACE_WITH_` で始まる値が残っている場合も、PHP 版と同じく設定エラーとして停止します。URL を設定する項目は **`https://` で始まる必要があります**（トークンやAPIの平文通信を防ぐため）。

### 2. 初回実行と確認

GAS エディタで次の関数を順に実行します。

1. `setupCheck` … 設定・シート・テンプレート（または固定 URL）・秘密鍵を確認（トリガー未登録のため `triggers` はNGで構いません）
2. `initializeNotificationBaseline` … **初回導入時のみ**。現在APIが返す地震を「通知済み」として記録（送信なし）
3. `setupTriggers` … 5分ごとの地震確認と1時間ごとのヘルスチェックを登録
4. `setupCheck` … もう一度実行し、すべて `OK` になることを確認
5. `connectivityCheck` … 通知用チャットへ疎通確認メッセージを送信。`MAINTENANCE_ROOM_ID` が別なら保守通知用にも送信
6. `runSelfTests` … 外部通信なしで判定ロジックを確認

### 初回ベースライン

`initializeNotificationBaseline` は、セットアップ直前に発生していた地震を「先ほど発生」として通知しないための関数です。

- 現在のP2PQuake取得結果（最大10件）を、送信せずに通知済みとして記録します
- 既存の保留UNKNOWN・静穏バッチ・解放待ちもクリアします
- 稼働中のトリガーがある場合は、既定で実行を拒否します。直前に起きた地震を通知済みにしてしまう事故を防ぐためです
- 意図的に実行する場合だけ `ALLOW_BASELINE_WITH_TRIGGERS=true` を設定してください
- **通常運用では実行しないでください**。実行すると、その時点の最新地震が通知されなくなります
- 移行時に過去地震を通知したい場合は実行せず、`setupCheck` 後にそのまま `setupTriggers` へ進みます

`connectivityCheck` は PHP 版と同じく、静穏時間でも送信します（手動の設置確認を妨げないため）。

`setupCheck` がすべて `OK` になるまで本番トリガーを有効にしないでください。

## シート

スクリプトが自動作成します。

| シート | 内容 |
|---|---|
| `地震ログ` | 記録時刻・イベントID・発生時刻・震源地・最大震度・結果・詳細。`LOG_RETENTION_DAYS` を超えた行は日次で削除 |
| `ヘルスチェック` | 1時間ごとの成功数・失敗数・スキップ数・正常動作率・実行成功率 |
| `状態` | 通知済み・送信中・UNKNOWN保留・静穏バッチ・静穏解放待ち・フォーム情報 |

`状態` シートは JSON を保存しています。手で編集すると JSON が壊れ、重複通知を避けるため処理が停止します。編集しないでください。テスト時は `resetState` で消去できます。

## 処理の流れ

```
checkAndNotifyEarthquake
  LockService 取得（1秒待ち。取れなければスキップ）
  → 設定検証
  → P2PQuake から最大10件取得
  → 震度しきい値・古さ・通知済み・UNKNOWN保留・静穏時間で選別
  → 静穏バッチの終了判定（送信前に「解放待ち」へ移す）
  → 発生時刻順に
      フォーム解決（固定URL、またはテンプレート複製→公開→回答者URL）
      → 回答者URLを状態シートへ保存
      → 送信直前に「送信中」を保存
      → LINE WORKS送信
      → 通知済み状態を保存（「送信中」を解除）
  → 成功/失敗/スキップカウントを更新
```

状態シートへの全行書き換えは、保留・静穏・通知など**変更があった場合だけ**行います。地震がない時間帯の毎分実行では書き込みません。

### 重複防止

- 重複判定キーは `発生時刻|震源地` です。`event.id` は続報で変わるため使いません。
- 同じ発生時刻は、UNKNOWN・震源地あり・続報の別に関係なく再通知しません。
- 通知済み状態は LINE WORKS 送信成功後に保存します。送信前に保存すると通知漏れになるためです。
- `MAX_NOTIFICATION_AGE_HOURS` を超えた地震は通知しません。長期間トリガーが止まった後の再開時に、数日前の地震を「先ほど発生」として送らないためです。
- フォームのコピー名は `安否確認 yyyyMMdd-HHmmss` で地震ごとに一意です。コピー直後に状態保存が失敗しても、次回は同じ名前のフォームを Drive から探して再利用します。
- 保存済みフォームが開けない場合は一度再試行し、それでも失敗したときだけ削除・複製し直します。一時的なAPIエラーで不要なコピーを増やさないためです。

### 送信結果が不明な場合（再送ポリシー）

- 送信直前に `状態` シートへ「送信中」を保存し、送信成功後に通知済みへ切り替えます。
- 送信後に状態保存が失敗した場合、次回実行では「送信中」が残っているため、同じ通知を**再送**します（at-least-once）。地震通知の取りこぼしを避けることを優先した方針です。
- 再送時はログの詳細に `attempt=2` のように試行回数が残ります。
- 送信そのものが失敗した場合は「送信中」を消してから例外を投げ、次回改めて試行します。
- 外部サービスへの送信とシート保存を完全な一体処理にはできないため、exactly-once にはしていません。重複より未通知を避けたい場合に適した設定です。

### 震源不明（UNKNOWN）

- 震源地名が空の情報は即通知せず、`UNKNOWN_HYPOCENTER_HOLD_SECONDS` 秒だけ待ちます。
- 待機中に同じ発生時刻の震源地名あり情報が来たら、そちらを通知します。
- 期限を過ぎても震源地名あり情報が来ない場合だけ、UNKNOWN のまま1回通知します。
- `UNKNOWN_HYPOCENTER_HOLD_SECONDS` は `MAX_NOTIFICATION_AGE_HOURS` より短くしてください。長いと、保留期限が来る前に古い情報として整理されます。

### 静穏時間

- `QUIET_HOURS_ENABLED=false` で無効化でき、24時間通知になります。
- 東京時間で `QUIET_HOURS_START` 以上 `QUIET_HOURS_END` 未満は通知を保留します。
- 開始が終了より大きい場合は日をまたぎます（例: 22〜6）。
- 静穏時間帯の候補は、震度が最大のもの、同震度なら後発を1件だけ採用します。
- 静穏時間が終了した次の実行で1件通知します。
- 送信前に「解放待ち」として状態シートへ保存するため、送信失敗時は次回同じ内容で再試行します。解放待ちと次の時間帯のバッチは別々に保持されます。
- 同じ静穏時間帯のUNKNOWN保留は、バッチ側の保留期限が切れるまで通知しません（1時間帯1件）。

## 定期実行

`setupTriggers` で以下を登録します。

- `checkAndNotifyEarthquake`: `CHECK_INTERVAL_MINUTES` ごと（既定5分）
- `writeHourlyHealthCheck`: 1時間ごと

GAS で指定できる間隔は 1 / 5 / 10 / 15 / 30 分です。既定は `src/config.gs` の `CHECK_INTERVAL_MINUTES = 5` で、`ヘルスチェック` の「予定実行回数」はここから自動算出されます（5分なら12）。間隔を変えるときは `CHECK_INTERVAL_MINUTES` を書き換えて `setupTriggers` を再実行してください。

5分にしているのは P2PQuake が Cloudflare のレート制限（HTTP 429 / error 1015）を返すことがあり、GAS の共有IPでは短い間隔ほど巻き込まれやすいためです。地震発生から通知までの遅れは最大で約5分になります。

`setupCheck` は各トリガーが1つだけ存在することと、`setupTriggers` が保存した実行間隔（`CHECK_INTERVAL_MINUTES`・1時間）を確認します。GAS の API からはトリガーの実行間隔を直接取得できないため、実際の間隔は GAS エディタの「トリガー」画面でも確認してください。

### ヘルスチェックの見方

- メイン処理と同じスクリプトロックを使い、最大30秒待ちます。
- ロックが取れずスキップした実行は `スキップ数` に記録します。ヘルスチェック自体がスキップした時間帯は、次回の集計にまとめて含まれます（「対象開始」で判別できます）。
- `正常動作率` は `成功数 / 予定実行回数` です。ロック競合を含む「予定に対する稼働率」で、100%を超えないよう上限を設けています。
- `実行成功率` は `成功数 / (成功数 + 失敗数)` です。実際に実行できた回数に対する成功率で、ロック競合の影響を除いて見たい場合はこちらを使います。
- 予定回数は `CHECK_INTERVAL_MINUTES` から算出されます（5分なら12）。間隔を変えた場合は `setupTriggers` を再実行してください。
- ヘルスチェックの実行時に、前回から24時間以上経過していれば `地震ログ` を `LOG_RETENTION_DAYS` 日で整理します。

## テスト

### GAS 上

`runSelfTests` を実行します。外部通信せずに、静穏時間の有効/無効、重複判定、UNKNOWN の保留・続報置換・期限切れ、静穏バッチの保留・解放・解放待ちの再試行、同じ時間帯のUNKNOWN抑制、古い地震・古い静穏バッチの破棄、しきい値変更時の破棄まで確認します。

### Node 上（再実行可能）

GAS API をスタブして `src/*.gs` をそのまま読み込みます。

```
npm test
# または
node tests/run-tests.js
```

確認内容:

- `runSelfTests` の全項目
- 状態シートの往復・保持期間・件数上限・静穏解放待ち
- 変化がない実行で状態シートを書き換えないこと
- 初回ベースラインが送信せず通知済みにすること
- 稼働中トリガーがあるベースラインを拒否し、フラグ付きなら許可すること
- 古い地震・古い静穏バッチ・古い保留UNKNOWNを通知しないこと
- 保留UNKNOWNの期限切れと同じ実行の続報が来たら震源地ありを優先すること
- 保留時間が最大経過時間以上なら設定エラーにすること
- フォーム複製→公開→送信→状態保存と、再実行時の再送なし
- コピー直後に中断した場合のフォーム再利用
- 保存済みフォームが削除された場合の再作成
- 保存済みフォームの一時的なオープン失敗は再試行し、複製しないこと
- 送信結果不明マークがある場合の再送（attempt の増加）
- 固定フォーム URL モード（複製なし、編集URLの回答受付確認）
- 旧4列ログの見出し更新
- API 失敗時の障害通知・エラーログ・失敗カウント
- ヘルスチェックの正常動作率上限・実行成功率・スキップ数・カウンタロック・ログ整理
- `NOTIFY_SCALE` 必須・プレースホルダー拒否・https以外のURL拒否・API URL 上書き
- 疎通確認の通知用・保守用の2送信
- 障害通知 fingerprint の例外名の反映
- セットアップ確認のトリガー間隔チェック
- 静穏時間の複数候補（最大震度・同震度なら後発）と日跨ぎ
- 静穏通知の解放待ちの再試行と、次の時間帯バッチの保持
- 長すぎるエラーメッセージの切り詰め

## PHP 版（`codex/setup-check`）との対応

### フォーム方式（確定した設計差）

GAS 版は PHP 版の「フォーム URL 在庫管理」を移植せず、**地震ごとの自動複製方式**を正式な仕様とします。GAS には `FormApp` / `DriveApp` があり、在庫 JSON や CSV の補充・移動をファイルシステムで運用する必要がないためです。

| PHP 版の機能 | GAS 版 | 扱い |
|---|---|---|
| フォーム URL 在庫（`forms.json`） | 地震ごとのフォーム複製と `状態` シートのフォーム履歴 | 設計変更（在庫管理は行わない） |
| CSV からフォーム URL を補充 | なし | 未移植（自動複製で代替） |
| 使用済みフォーム管理 | 発生時刻ごとの複製フォーム管理 | 設計変更 |
| 在庫枯渇時の通知スキップ | なし（枯渇の概念がない） | 対象外 |
| 枯渇通知の失敗再試行 | なし | 対象外 |
| 在庫0件の6時間ごとのリマインド | なし | 対象外 |
| フォーム残数低下通知 | なし | 対象外 |
| CSV 取り込み失敗時も地震チェックを継続 | CSV 取り込みなし | 対象外 |
| 固定フォーム URL モード | `FIXED_FORM_URL` | 移植 |
| 保守通知先への疎通確認 | `connectivityCheck` が通常・保守の両方へ送信 | 移植 |
| ヘルスチェックのログ集計 | 1時間単位の実行回数・成功率集計 | 設計変更 |
| PHP ログファイルへの構造化ログ | スプレッドシートの `地震ログ` | 設計変更 |

在庫枯渇・残数低下・定期リマインドは、自動複製方式では発生しないため実装していません。必要になった場合は、複製失敗の通知（既存の障害通知）で検知します。

### 設定検証

| PHP 版 | GAS 版 |
|---|---|
| `notify_scale` 未設定はエラー | `NOTIFY_SCALE` 未設定はエラー（既定値なし） |
| プレースホルダー文字列を拒否 | `REPLACE_WITH_` で始まる値を拒否 |
| 固定フォーム URL モード | `FIXED_FORM_URL` で対応 |
| P2PQuake URL を設定可能 | `P2PQUAKE_API_URL` で対応（httpsのみ） |
| LINE WORKS トークン URL を設定可能 | `LINEWORKS_TOKEN_URL` で対応（httpsのみ） |
| フォーム在庫関連の設定検証 | テンプレートの公開対応・メール収集設定・テンプレートファイルへのアクセスを検証 |
| 静穏時間の設定 | GAS版独自。`QUIET_HOURS_ENABLED` で無効化可能 |

### 障害通知・状態管理

- 障害通知の fingerprint は PHP 版と同じく「分類・例外クラス相当・メッセージ」から作ります。GAS の例外には PHP のクラス階層がないため、`Error.name` をクラス相当として使います。
- 状態は `状態` シートに保存します。PHP 版の一時ファイル + rename に相当する厳密な原子性はありませんが、送信中マークとフォーム名の再利用で再実行時の重複を抑えます。
- 不正な状態 JSON は検出して停止します。通知済み・フォーム履歴は30日保持と件数上限、保留・送信中・静穏状態は `MAX_NOTIFICATION_AGE_HOURS` で整理されます。

### テストについて

PHP 版 `codex/setup-check` のテストは 39 件（`ConfigTest` 4、`ConnectivityCheckerTest` 2、`EarthquakeCheckerTest` 11、`ErrorNotificationStoreTest` 2、`FailureNotifierTest` 3、`FormStockStoreTest` 2、`HealthCheckerTest` 1、`LineWorksClientTest` 1、`LoggerTest` 1、`P2PQuakeClientTest` 2、`SetupCheckerTest` 7、`StateStoreTest` 2、`TestRegistryTest` 1）です。GAS 版は PHP のテストをそのまま流用できないため、同等の判定を `runSelfTests` と `tests/run-tests.js`（36件）で個別に検証します。フォーム在庫関連のテストは、在庫管理を行わない設計のため対象外です。

## ファイル構成

| ファイル | 役割 |
|---|---|
| `checkAndNotifyEarthquake.gs` | メイン処理 |
| `config.gs` | 定数・設定検証 |
| `messages.gs` | エラー・ログ文言 |
| `getExecutionContext.gs` | プロパティ・スプレッドシート・シート取得 |
| `sheetUtils.gs` | シートの取得・作成・見出し更新 |
| `fetchEarthquakes.gs` | P2PQuake API 取得 |
| `earthquakeCandidate.gs` | 地震情報の候補化・時刻処理 |
| `quietHours.gs` | 静穏時間・UNKNOWN保留の判定 |
| `stateStore.gs` | 状態シートの読み書きと古い状態の整理 |
| `selectNotificationTargets.gs` | 通知対象の選別 |
| `googleForm.gs` | フォームの複製・公開・回答者URL取得 |
| `createEarthquakeMessage.gs` | 通知本文の作成 |
| `getAccessToken.gs` | LINE WORKS アクセストークン取得 |
| `sendLineWorksMessage.gs` | LINE WORKS 送信 |
| `notifyEarthquake.gs` | 通知実行と状態更新 |
| `writeLog.gs` | 地震ログへの記録 |
| `processError.gs` | エラーログと障害通知 |
| `failureNotifier.gs` | 障害通知の抑止付き送信 |
| `recordExecutionStatus.gs` | 成功・失敗・スキップカウント |
| `writeHourlyHealthCheck.gs` | ヘルスチェック記録 |
| `cleanupLogs.gs` | 地震ログの保持期間整理 |
| `initializeNotificationBaseline.gs` | 初回導入時の通知済みベースライン作成 |
| `setupCheck.gs` | 初期設定確認 |
| `setupTriggers.gs` | トリガー登録 |
| `connectivityCheck.gs` | LINE WORKS 疎通確認 |
| `resetState.gs` | 状態リセット |
| `selfTest.gs` | 外部通信なしのロジックテスト |
| `tests/run-tests.js` | GAS API をスタブした Node テスト |
| `package.json` | `npm test` の定義 |

## 旧版からの移行

- 旧 GAS 版の `FORM_URL` と `LAST_NOTIFIED_ID` は使いません（固定 URL を使う場合は `FIXED_FORM_URL` を設定してください）。
- 自動複製モードでは `FORM_TEMPLATE_ID` を新たに設定してください。
- `NOTIFY_SCALE` は必須です。旧版の既定値（45）に依存していた場合は明示的に設定してください。
- 既存の `地震ログ` の見出し行は、初回実行時に 7 列の見出しへ更新されます（データ行はそのまま残ります）。
- **移行直後に過去の地震を通知したくない場合は、`initializeNotificationBaseline` を一度実行してください。** `resetState` は状態を消すため、過去地震の通知を防ぐ目的では使いません（初期化すると次の実行で通知対象になります）。
- 移行時に直近の地震を通知したい場合だけ、ベースラインを実行せずに `setupTriggers` へ進みます。
- 旧版の `resetLastNotifiedId` は `resetState` の別名として残しています。

## 注意事項

- 実フォーム URL、room_id、bot_id、token、secret、秘密鍵はログや README に書かないでください。
- 自動複製モードでは、地震ごとにフォームのコピーが Drive に増えます。定期的に不要なコピーを整理してください。固定 URL モードではコピーを作りません。
- フォーム複製直後に中断した場合は、次回同じ名前のコピーを再利用します。Drive の検索反映が遅れた場合に限り、未使用のコピーが残ることがあります（通知の重複は発生しません）。
- 保存済みフォームが開けない場合は一度再試行し、それでも失敗したときだけ削除・複製し直します。一時的なAPIエラーで不要なコピーを増やさないためです。
- 送信後に状態保存が失敗した場合は、次回に同じ地震を再送する可能性があります（at-least-once）。ログの `attempt=` で再送かどうかを確認できます。
- `状態` シートの 500 件上限は「通知済み」と「フォーム履歴」にそれぞれ適用されます（状態全体で 500 件ではありません）。保留・送信中・静穏状態は `MAX_NOTIFICATION_AGE_HOURS`（既定24時間）で整理されます。
- ロック取得に失敗した実行は `スキップ数` に記録され、成功・失敗のどちらにもカウントされません。
- `地震ログ` は `LOG_RETENTION_DAYS`（既定90日）を超えた行を、ヘルスチェック時に日次で削除します。
- `NOTIFY_SCALE=0` はテスト専用です。本番は震度5弱相当の `45` 以上を推奨します。
- 障害通知は既定で通常の通知先と同じルームへ送られます。本番では `MAINTENANCE_ROOM_ID` に別ルームを設定してください。未設定の場合、`setupCheck` が警告します。設定した場合、`connectivityCheck` は通常・保守の両方へ確認メッセージを送ります。
- `initializeNotificationBaseline` は初回導入時だけ実行してください。通常運用で実行すると、その時点の最新地震が通知対象から外れます。
- `FIXED_FORM_URL` は `https://` のみ許可します。公開URL（`/viewform`）は回答受付を自動確認できないため、実受信者での回答確認を行ってください。
