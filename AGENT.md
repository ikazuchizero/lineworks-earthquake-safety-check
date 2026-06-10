# lineworks-earthquake-safety-check

## 概要
- LineWorksで動作する安否確認botのプロジェクト
- Google Apps Script（GAS）の時間主導トリガーで地震情報を監視し、通知対象の地震を検知したらLINE WORKSの指定チャンネルへ安否確認フォーム付きメッセージを送信する。

## 技術スタック
- GAS
- LineWorks API
- P2PQuake API
- Google Spreadsheet
- GAS Script Properties
- Node.js node:test

## 設計

### 全体構成
- メイン処理は `checkAndNotifyEarthquake()`。
- P2PQuake APIから最新の地震情報を1件取得し、最大震度・重複通知の条件を満たす場合だけLINE WORKSへ通知する。
- 通知結果やエラーはスプレッドシートへ記録する。
- 実行成功・失敗の回数はScript Propertiesへ記録し、別処理で1時間ごとにヘルスチェックシートへ集計する。

### 想定トリガー
- `checkAndNotifyEarthquake()`: 1分ごとの時間主導トリガーで実行する想定。
- `writeHourlyHealthCheck()`: 1時間ごとの時間主導トリガーで実行する想定。

### 処理フロー
1. `checkAndNotifyEarthquake()` が `LockService` で多重実行を防止する。
2. `getExecutionContext()` がScript Properties、ログシート、フォームURL、通知先チャンネルID、実行時刻を取得する。
3. `getLatestEarthquake()` が `https://api.p2pquake.net/v2/jma/quake?limit=1` から最新地震情報を取得する。
4. `processEarthquakeValidation()` が通知対象か判定する。
   - 地震データがない場合は通知しない。
   - `event.earthquake` がない場合は通知しない。
   - `event.id` がない場合はエラーにする。
   - 最大震度が `NOTIFY_SCALE` 未満の場合は通知しない。
   - `LAST_NOTIFIED_ID` と同じ地震IDの場合は重複として通知しない。
5. 通知対象の場合、`notifyEarthquake()` が安否確認メッセージを作成してLINE WORKSへ送信する。
6. 通知成功後、Script Propertiesの `LAST_NOTIFIED_ID` に通知済み地震IDを保存する。
7. 成功・スキップ・エラーの結果をログへ記録し、実行成功/失敗カウンタを更新する。

### 通知条件
- 通知対象震度は `src/config.gs` の `NOTIFY_SCALE` で管理する。
- 現在値は `0` のため、地震情報があり重複していなければ原則通知対象になる。
- P2PQuakeの震度値は `SCALE_NAMES` で表示用の震度表記へ変換する。

### LINE WORKS連携
- `getAccessToken()` がScript Propertiesから認証情報を読み込み、JWT Bearerフローでアクセストークンを取得する。
- `sendLineWorksMessage()` がBot APIで指定チャンネルへテキストメッセージを送信する。
- 通知文面は `createEarthquakeMessage()` で生成する。
- メッセージには発生時刻、震源地、最大震度、安否確認フォームURLを含める。

### Script Properties
以下の値を利用する。
- `CLIENT_ID`: LINE WORKS API認証用クライアントID。
- `CLIENT_SECRET`: LINE WORKS API認証用クライアントシークレット。
- `SERVICE_ACCT`: LINE WORKS API認証用サービスアカウント。
- `PRIVATE_KEY`: JWT署名用秘密鍵。
- `BOT_ID`: LINE WORKS Bot ID。
- `ROOM_ID`: 通知先チャンネルID。
- `FORM_URL`: 安否確認フォームURL。
- `LAST_NOTIFIED_ID`: 最後に通知した地震ID。
- `LAST_ROUTINE_LOG_TIME`: 通知対象外・重複ログの間引き用最終記録時刻。
- `HEALTH_SUCCESS_COUNT`: ヘルスチェック用の成功回数。
- `HEALTH_FAILURE_COUNT`: ヘルスチェック用の失敗回数。
- `HEALTH_LAST_SUCCESS_TIME`: 最終成功時刻。
- `HEALTH_LAST_ERROR_TIME`: 最終エラー時刻。
- `HEALTH_LAST_ERROR_MESSAGE`: 最終エラー内容。
- `HEALTH_PERIOD_START_TIME`: ヘルスチェック集計期間の開始時刻。

### スプレッドシート
- 通常ログシート名は `地震ログ`。
- 通常ログは `[時刻, 地震ID, 最大震度, 結果]` の形式で追記する。
- ヘルスチェックシート名は `ヘルスチェック`。
- ヘルスチェックは1時間ごとに予定実行回数、実行回数、成功数、失敗数、正常動作率、最終成功/エラー情報を追記する。

### ログ方針
- 送信成功とエラーは必ずログに記録する。
- 通知対象外と重複パスは毎回記録せず、`LAST_ROUTINE_LOG_TIME` を使って1時間に1回だけ記録する。
- ログシートが存在しない場合、通常ログの書き込みは何もしない。
- ヘルスチェックシートは存在しない場合に自動作成する。

### エラー処理
- 外部APIのHTTPエラーは `createHttpError()` でHTTPステータスとレスポンス本文を含むエラーにする。
- `checkAndNotifyEarthquake()` の例外は `processError()` でログへ記録し、`recordExecutionFailure()` でヘルスチェック用の失敗情報を保存したあと再スローする。

### 主要ファイル
- `src/checkAndNotifyEarthquake.gs`: メイン処理、地震情報取得、通知対象判定、通知文面生成、通知済みIDリセット。
- `src/lineWorks.gs`: LINE WORKSアクセストークン取得とBot API送信。
- `src/logging.gs`: 通常ログ記録とエラーログ記録。
- `src/healthCheck.gs`: 実行成功/失敗の記録とヘルスチェック集計。
- `src/config.gs`: 設定値、Script Propertiesキー、ログ/エラー文言、HTTPエラー生成。

### ファイル分割方針
- GASは複数ファイルに分けてもモジュール境界ができるわけではないため、1関数1ファイルにはしない。
- 外部トリガーから直接呼ぶ関数名は維持し、内部処理は責務単位でまとめる。
- 現在の外部呼び出し想定関数は `checkAndNotifyEarthquake()`、`writeHourlyHealthCheck()`、テスト用の `resetLastNotifiedId()`。

## テスト
- テストは `test/*.test.js` に配置し、`src` のファイル構成に合わせて分割する。
- 共通のGAS実行環境スタブは `test/helpers/gasRuntime.js` に配置する。
- GASコードをNode.jsの `vm` 上で読み込み、`PropertiesService`、`SpreadsheetApp`、`UrlFetchApp`、`Utilities`、`LockService` をスタブ化して実行する。
- 実行コマンドは `npm test`。
- 主な検証対象は地震情報取得、通知対象判定、ログ間引き、LINE WORKS送信、ヘルスチェック集計、メイン処理の通知成功フロー。
