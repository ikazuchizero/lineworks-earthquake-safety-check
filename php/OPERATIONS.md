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

ローカル/テストで毎回フォームURLを大量に作るのが難しい場合だけ、`form_stock_enabled=false` にできます。この場合は `forms.json` / `forms.csv` を使わず、`config.php` の `form_url` を固定フォームURLとして送信します。フォームは `used` にならず、低在庫通知・枯渇通知も送りません。

`form_stock_enabled=false` は本番のフォーム再利用を許すための機能ではありません。本番で使うと同じフォームURLに複数地震の回答が混ざるため、原則として本番では `true` に戻してください。`false` で `form_url` が空の場合は、安全のため起動時にエラー停止します。

## PHPファイルの役割

- `php/bin/check.php`: cron / タスクスケジューラから呼ぶ入口です。lock取得、設定読み込み、各Store/Client生成、`EarthquakeChecker` 実行を担当します。
- `php/src/Config.php`: `config.php` の読み込みと必須設定の検証を担当します。`form_stock_enabled` の本番/テスト切り替えもここで検証します。
- `php/src/EarthquakeChecker.php`: 地震取得後の通知対象抽出、重複判定、フォームURL解決、LINE WORKS送信、state/form更新順序を管理します。
- `php/src/P2PQuakeClient.php`: P2PQuake APIから地震情報を取得します。通知漏れ防止のため、最新1件だけにしない方針です。
- `php/src/LineWorksClient.php`: LINE WORKSのtoken取得とBotメッセージ送信を担当します。tokenやsecretはログに出しません。
- `php/src/StateStore.php`: `state.json` に通知済み `dedupe_key` を保存し、二重通知を防ぎます。
- `php/src/FormStockStore.php`: `forms.csv` の取り込み、`forms.json` の保存、フォームURLのavailable/used管理を担当します。
- `php/src/Logger.php`: `app.log` へ運用ログを追記します。外部共有前には秘密値や実URLが含まれていないか確認してください。

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

## フォームURLの安全ルール

- `available` のフォームURLだけが通知に使われます。
- LINE WORKS送信が成功した後だけ `used` になります。
- `used` のURLは再利用しません。
- 使用済みURLをCSVで再投入しても `available` には戻りません。
- `form_stock_enabled=true` では、フォームURLが0件のときに固定URLへfallbackせず、安否確認通知を送らずに補充通知先へ枯渇通知します。
- `form_stock_enabled=false` のテスト時だけ、固定 `form_url` を使います。このモードではフォーム消費・低在庫通知・枯渇通知は行いません。

## 地震通知の重複防止

P2PQuake/JMAでは、同じ地震でも震度速報、震源情報、続報などで別の `event.id` になることがあります。そのため `event.id` 単体では重複判定しません。

PHP版では原則として `earthquake.time|hypocenter.name` を重複判定キーにします。`maxScale` は含めません。続報で最大震度が変わっても、同じ地震をもう一度通知しないためです。

同じ取得バッチ内で、同じ発生時刻の `UNKNOWN` と震源地名あり候補が混在した場合は、震源地名あり候補を優先します。`UNKNOWN` しかない場合だけ `UNKNOWN` で通知候補にします。

## 低在庫通知

低在庫通知は通常cronのたびには送りません。地震通知でフォームURLを1件以上消費した実行の最後に、残数が `form_low_stock_threshold` 以下なら補充通知先へ最大1回だけ送ります。

低在庫状態が続いていても、次回以降に地震通知でフォームURLを消費した場合は再通知してよい設計です。1回の通知見落としで枯渇まで気づけない事故を防ぐためです。

## 本番前チェックリスト

- `php/config.php` が配置されている。
- `php/secrets/private.key` が配置されている。
- `notify_scale` が本番条件、通常は震度5弱相当の45以上になっている。テスト用の0のままにしない。
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
