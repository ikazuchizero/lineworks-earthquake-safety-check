# lineworks-earthquake-safety-check

P2PQuake の地震情報を確認し、設定した震度以上の場合に LINE WORKS へ安否確認通知を送る Google Apps Script（GAS）です。通知対象の地震ごとに Google フォームのテンプレートを複製し、回答者 URL を通知本文に載せます。

このブランチ（`codex/gas-forms`）は、PHP 版の機能を GAS へ移植した実装です。Google Cloud の OAuth クライアントや refresh token は不要で、GAS 標準の `FormApp` / `DriveApp` を使います。

## 構成

- `src/`: GAS 版の本体
- `src/appsscript.json`: タイムゾーン（Asia/Tokyo）と OAuth スコープ

PHP 版は `main` には含まれていません。PHP 版の実装は `origin/codex/google-forms` の `php/` にあります。

## 必要なもの

1. スプレッドシート（このスクリプトをバインドするか、`SPREADSHEET_ID` を設定）
2. 安否確認フォームのテンプレート（Google フォーム）
3. LINE WORKS の Bot と、通知先チャットルーム
4. LINE WORKS API 用の証明書・秘密鍵

### テンプレートフォームの条件

- `FormApp.Form.supportsAdvancedResponderPermissions()` が `true` を返す形式
- 「メールアドレスを収集する」が無効（`collectsEmail()` が `false`）
- スクリプト実行者が編集権限を持っていること
- 設問・デザインはテンプレート側で管理（スクリプトは複製・公開のみ行う）

GAS の `setPublished(true)` で回答者 URL を公開します。組織のポリシーで匿名回答が制限されている場合は、実際の受信者で回答できることを確認してください。

## セットアップ

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
| `FORM_TEMPLATE_ID` | 必須 | 複製元の Google フォーム ID |
| `SPREADSHEET_ID` | 任意 | スプレッドシートにバインドしない場合に設定 |
| `NOTIFY_SCALE` | 任意 | 通知する震度コード。既定 `45`（5弱）。`0` はテスト用 |
| `UNKNOWN_HYPOCENTER_HOLD_SECONDS` | 任意 | 震源不明の続報を待つ秒数。既定 `600` |
| `QUIET_HOURS_START` | 任意 | 静穏時間の開始（0〜23）。既定 `0` |
| `QUIET_HOURS_END` | 任意 | 静穏時間の終了（0〜23）。既定 `6` |

震度コードは `0, 10, 20, 30, 40, 45, 50, 55, 60, 70` のいずれかです。

### 2. 初回実行と確認

GAS エディタで次の関数を順に実行します。

1. `setupCheck` … 設定・シート・テンプレート・秘密鍵・トリガーを確認
2. `setupTriggers` … 1分ごとの地震確認と1時間ごとのヘルスチェックを登録
3. `connectivityCheck` … LINE WORKS へ疎通確認メッセージを送信（静穏時間は送信しません）
4. `runSelfTests` … 外部通信なしで判定ロジックを確認

`setupCheck` がすべて `OK` になるまで本番トリガーを有効にしないでください。

## シート

スクリプトが自動作成します。

| シート | 内容 |
|---|---|
| `地震ログ` | 記録時刻・イベントID・発生時刻・震源地・最大震度・結果・詳細 |
| `ヘルスチェック` | 1時間ごとの成功数・失敗数・正常動作率など |
| `状態` | 通知済み・UNKNOWN保留・静穏バッチ・フォーム情報 |

`状態` シートは JSON を保存しています。手で編集すると JSON が壊れ、重複通知を避けるため処理が停止します。編集しないでください。テスト時は `resetState` で消去できます。

## 処理の流れ

```
checkAndNotifyEarthquake
  LockService 取得（1秒待ち。取れなければスキップ）
  → 設定検証
  → P2PQuake から最大10件取得
  → 震度しきい値・通知済み・UNKNOWN保留・静穏時間で選別
  → 静穏バッチの終了判定
  → 発生時刻順に
      フォーム解決（テンプレート複製→公開→回答者URL）
      → 回答者URLを状態シートへ保存
      → LINE WORKS送信
      → 通知済み状態を保存
  → 成功/失敗カウントを更新
```

### 重複防止

- 重複判定キーは `発生時刻|震源地` です。`event.id` は続報で変わるため使いません。
- 同じ発生時刻は、UNKNOWN・震源地あり・続報の別に関係なく再通知しません。
- 通知済み状態は LINE WORKS 送信成功後に保存します。送信前に保存すると通知漏れになるためです。
- フォームのコピー ID は公開前に、回答者 URL は送信前に保存します。再実行時は同じフォームを再利用します。

### 震源不明（UNKNOWN）

- 震源地名が空の情報は即通知せず、`UNKNOWN_HYPOCENTER_HOLD_SECONDS` 秒だけ待ちます。
- 待機中に同じ発生時刻の震源地名あり情報が来たら、そちらを通知します。
- 期限を過ぎても震源地名あり情報が来ない場合だけ、UNKNOWN のまま1回通知します。

### 静穏時間

- 東京時間で `QUIET_HOURS_START` 以上 `QUIET_HOURS_END` 未満は通知を保留します。
- 開始が終了より大きい場合は日をまたぎます（例: 22〜6）。
- 静穏時間帯の候補は、震度が最大のもの、同震度なら後発を1件だけ採用します。
- 静穏時間が終了した次の実行で1件通知します。

## 定期実行

`setupTriggers` で以下を登録します。

- `checkAndNotifyEarthquake`: 1分ごと
- `writeHourlyHealthCheck`: 1時間ごと

GAS の最短間隔は1分です。`ヘルスチェック` の「予定実行回数」は1分トリガー前提で `60` 固定です。トリガー間隔を変える場合は `src/config.gs` の `expectedRunsPerHour` も変更してください。

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
| `recordExecutionStatus.gs` | 成功・失敗カウント |
| `writeHourlyHealthCheck.gs` | ヘルスチェック記録 |
| `setupCheck.gs` | 初期設定確認 |
| `setupTriggers.gs` | トリガー登録 |
| `connectivityCheck.gs` | LINE WORKS 疎通確認 |
| `resetState.gs` | 状態リセット |
| `selfTest.gs` | 外部通信なしのロジックテスト |

## 旧版からの移行

- 旧 GAS 版の `FORM_URL` と `LAST_NOTIFIED_ID` は使いません。
- `FORM_TEMPLATE_ID` を新たに設定してください。
- 既存の `地震ログ` の見出し行は、初回実行時に 7 列の見出しへ更新されます（データ行はそのまま残ります）。
- 移行直後に同じ地震を再通知したくない場合は、`状態` シートを確認するか、必要に応じて `resetState` で初期化してください。
- 旧版の `resetLastNotifiedId` は `resetState` の別名として残しています。

## 注意事項

- 実フォーム URL、room_id、bot_id、token、secret、秘密鍵はログや README に書かないでください。
- 地震ごとにフォームのコピーが Drive に増えます。定期的に不要なコピーを整理してください。
- 送信成功後に状態保存が失敗した場合、次回に同じ地震を再送する可能性があります（PHP 版と同じ非 exactly-once）。
- ロック取得に失敗した実行は成功・失敗どちらにもカウントされません。
- `NOTIFY_SCALE=0` はテスト専用です。本番は震度5弱相当の `45` 以上を推奨します。
- 障害通知は既定で通常の通知先と同じルームへ送られます。分ける場合は `MAINTENANCE_ROOM_ID` を設定してください。
