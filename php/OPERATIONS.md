# PHP版 運用メモ

このディレクトリは、Google Apps Script版とは別実装のPHP版です。GAS版のファイルはリポジトリ直下の src/ に残し、PHP版は php/ 配下だけで運用します。

## 実行方法

手動確認では、リポジトリ直下から次を実行します。

```powershell
php php/bin/check.php
```

本番ではXserverなどのcronから同じ入口を定期実行する想定です。多重実行は lock ファイルと flock() で防ぎます。

## フォームURL補充手順

1. Excelなどで1列だけのCSVを作成します。
2. 1行目のヘッダーは `URL` にします。
3. 2行目以降にフォームURLを1件ずつ入れます。
4. ファイル名を `forms.csv` にします。
5. `php/storage/import/forms.csv` にアップロードします。
6. 次回cron実行時に取り込まれます。

CSV例です。これは形式例であり、実URLはここへ書かないでください。

```csv
URL
https://example.com/form/001
https://example.com/form/002
```

取り込みに成功したCSVは `php/storage/import/processed/` に日時付きで移動します。取り込みに失敗したCSVは `php/storage/import/failed/` に移動します。URL本文はログに出さず、件数だけを確認します。

## フォームURLの安全ルール

- `available` のフォームURLだけが通知に使われます。
- LINE WORKS送信が成功した後だけ `used` になります。
- `used` のURLは再利用しません。
- 使用済みURLをCSVで再投入しても `available` には戻りません。
- フォームURLが0件のときは、固定URLへfallbackせず、安否確認通知を送らずに補充通知先へ枯渇通知します。

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
- `notify_scale` が本番条件、通常は震度5弱相当の45以上になっている。
- Botが安否確認通知先ルームに参加している。
- Botが補充通知先ルームに参加している。
- `form_low_stock_room_id` が安否確認通知先とは別の補充通知先になっている。
- `php/storage/forms.json` に十分な `available` がある。
- `php/storage/` と `php/storage/import/` 以下にPHPから書き込み権限がある。

## 障害時に見る場所

- `php/storage/app.log`: 実行結果、skip理由、送信失敗、CSV取り込み件数を確認します。秘密値やURL本文は出さない方針です。
- `php/storage/state.json`: 通知済み地震の重複判定状態を確認します。壊れている場合は空扱いせず停止します。
- `php/storage/forms.json`: フォームURLの `available` / `used` 状態を確認します。実URLを外部に貼らないでください。
- `php/storage/import/processed/`: 取り込み済みCSVを確認します。
- `php/storage/import/failed/`: 失敗CSVを確認します。ヘッダーが `URL` か、URL列が正しいかを確認します。

## 絶対にGitへ入れないもの

- `php/config.php`
- `php/secrets/private.key`
- `php/storage/state.json`
- `php/storage/forms.json`
- `php/storage/import/forms.csv`
- `php/storage/import/processed/*.csv`
- `php/storage/import/failed/*.csv`
- `php/storage/app.log`
- access token、JWT、room_id、bot_id、client_secretなどの秘密値やID
