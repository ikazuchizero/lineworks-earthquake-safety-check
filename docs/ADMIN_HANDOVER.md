# 当日手順（これだけ見る）

所要 60分。上から順に実行し、✅ が出たら次へ。詰まったら止めて、画面の文字を担当者へ送ってください。

## 記録

| 項目 | 値 |
|---|---|
| ルーム名 | |
| `ROOM_ID` | |
| スプレッドシートID | |
| フォーム回答URL | |
| GAS プロジェクトURL | |

## 1. LINE WORKS（15分）

1. <https://admin.worksmobile.com/> → 上部メニュー **サービス** → **Bot** → **Bot追加**
   - 使用範囲は **「全て」**
2. トークで **グループトーク**を作成（1対1は不可）→ **招待** → **Bot** タブ → 対象を追加

✅ ルームのメンバーに Bot が見える

## 2. Google 準備（5分）

1. <https://script.google.com/home/usersettings> → 「Google Apps Script API」を **オン**
2. <https://sheets.new> で新規作成 → URL の `/d/` と `/edit` の間を控える
3. LINE WORKS フォーム（または Google フォーム）の**回答URL**（`https://`）を控える

✅ 値が2つ控えられた

## 3. プログラム反映（10分）

<https://shell.cloud.google.com/> を開き、下の黒い入力欄で1行ずつ実行。

```bash
cd ~
git clone -b codex/deploy-operator-guide https://github.com/ikazuchizero/lineworks-earthquake-safety-check.git gas-repo
cd ~/gas-repo
mkdir -p /tmp/gas_backup && cp -r src/. /tmp/gas_backup/
npx --yes @google/clasp login --no-localhost
```

- URL を開く → 許可 → **「localhost に接続できません」の画面のアドレスバーURLをコピー** → 貼り付け → Enter

```bash
npx --yes @google/clasp create --title "LINE WORKS 地震安否確認Bot" --type standalone --rootDir src
cp /tmp/gas_backup/appsscript.json src/appsscript.json
npx --yes @google/clasp push -f
```

✅ `Pushed 29 files.` と `https://script.google.com/d/.../edit` が出る → URL を控える

## 4. 設定値（10分）

1. エディタで **`setProperties.gs`** を開く
2. `SETUP_VALUES` を自分の値に書き換える（`ROOM_ID` と `FORM_TEMPLATE_ID` は空のまま）

```js
const SETUP_VALUES = {
    SPREADSHEET_ID: "控えたID",
    CLIENT_ID: "Developer Consoleの値",
    CLIENT_SECRET: "同",
    SERVICE_ACCT: "同",
    BOT_ID: "同",
    PRIVATE_KEY: "-----BEGIN PRIVATE KEY-----MIIE...-----END PRIVATE KEY-----",
    NOTIFY_SCALE: "40",
    FIXED_FORM_URL: "控えた回答URL",
};
```

3. **Ctrl+S** → 関数選択で **`setAllProperties`** → 実行
4. **`diagnoseBot`** を実行 → `- 1234567890 | ルーム名` の**数字**が `ROOM_ID`
5. `SETUP_VALUES` の `ROOM_ID` に入れて、もう一度 **`setAllProperties`**

✅ 実行ログに `ROOM_ID` が含まれる

## 5. 動作確認（5分）

| 関数 | 期待 |
|---|---|
| `setupCheck` | `triggers` 以外が `OK` |
| `connectivityCheck` | `LINE WORKS HTTP=201` + トークに着信 |
| `runSelfTests` | `セルフテスト: すべて成功` |

## 6. 開始（5分）

1. ⚙プロジェクトの設定 → スクリプト プロパティ → `NOTIFY_SCALE` が本番値か確認（`0` はテスト用）
2. `initializeNotificationBaseline` → `初回ベースライン登録: N件`
3. **そのまま** `setupTriggers` → `…（10分ごと）`
4. `setupCheck` → `セットアップ確認: すべてOK`

## 7. 後片付け

1. `setProperties.gs` を削除（秘密鍵がソースに残るため）
2. 10〜20分後、`地震ログ` シートに行が増えているか確認

## 詰まったとき

| 画面の文字 | 対処 |
|---|---|
| `User has not enabled the Apps Script API` | 手順2-1。数分待って再実行 |
| `localhost で接続が拒否されました` | 正常。アドレスバーのURLをコピーして貼る |
| `Project file already exists.` | `npx --yes @google/clasp push -f` のみ |
| `CLIENT_ID未設定` | 手順4-3 を実行したか（保存だけでは反映されない） |
| `LINE WORKS HTTP:403` | 手順1の Bot追加・使用範囲・招待を確認 |
| `NG triggers` | 手順6-3 を実行 |
| `channels HTTP=200` で一覧が空 | Bot がルームに入っていない（手順1-2） |
