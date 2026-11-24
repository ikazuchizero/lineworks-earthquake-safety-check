# 管理者向け作業手順書（そのまま上から実行）

この文書は、**操作する人が初めてでも完了できる**ように書いています。
判断が必要な場面はありません。各ステップの「✅ 確認」の文字が出たら次へ進んでください。

- 所要時間: 40〜60分（待ち時間を含む）
- 必要なもの: 本番の Google アカウント、LINE WORKS の管理者権限
- 作業場所: すべてブラウザ（PC へのインストールなし）

> 困ったときは、**その時点の画面の文字をそのままコピーして担当者に送ってください**。
> 自己判断で先に進まないでください。

---

## 記録シート（作業しながら埋める）

| 項目 | 値 |
|---|---|
| 作業日時 | |
| Google アカウント | |
| LINE WORKS テナント | |
| 通知先ルーム名 | |
| `ROOM_ID`（channelId） | |
| スプレッドシート URL | |
| GAS プロジェクト URL | |
| フォーム（テンプレート）URL | |
| 反映コミット | |

---

# Part 1. LINE WORKS 側（10〜15分）

## 1-1. Bot を会社の管理画面に追加

1. <https://admin.worksmobile.com/> を開いて管理者でログイン
2. 上部メニュー **「サービス」→「Bot」**
3. 右上の **「Bot追加」** をクリック
4. 一覧から対象の Bot を選んで追加
5. **使用範囲** を **「全て」** にする（「制限」だとメンバーの連絡先にも表示されません）

✅ **確認**: Bot 一覧に対象の Bot が表示されている

> Bot が一覧に無い場合: Developer Console（<https://developers.worksmobile.com/>）に
> Bot が登録されているか確認してください。無ければ担当者に連絡してください。

## 1-2. グループトークを作って Bot を招待

1. LINE WORKS のトーク画面を開く
2. **新しいグループトーク**を作成（メンバーは自分＋担当者など2名以上）
   - ※ 1対1のトークには Bot を追加できません
3. ルーム右上のメニュー → **「招待」**
4. **「Bot」タブ** → 対象の Bot を選択 → 招待

✅ **確認**: ルームのメンバーに Bot が表示される

## 1-3. ルームの channelId を控える

この値は Part 3 の手順で自動取得します。ここでは**ルーム名だけ**控えてください。

✅ **確認**: 記録シートに「通知先ルーム名」を記入

---

# Part 2. Google 側の準備（10分）

## 2-1. Apps Script API を有効化

1. <https://script.google.com/home/usersettings> を開く
2. 「Google Apps Script API」を **オン** にする

✅ **確認**: トグルがオン（青色）になっている

> 反映に数分かかります。先に進んでエラーが出たら、戻って数分待ってください。

## 2-2. スプレッドシートを作る

1. <https://sheets.new> を開く（自動で新規作成されます）
2. 名前を変更（例: `地震安否確認ログ`）
3. URL をコピーして記録シートに貼る

```
https://docs.google.com/spreadsheets/d/【ここがID】/edit
```

✅ **確認**: URL に `/d/` と `/edit` が含まれている

## 2-3. フォームのテンプレートを用意（複製方式の場合）

1. <https://forms.new> で新規フォームを作成
2. タイトル例: `安否確認`
3. 質問を1つ追加（例: 「無事ですか？」・選択式「無事 / 被害あり」）
4. 右上 **「設定」→「回答」** で **「メールアドレスを収集する」をオフ**
5. 編集URL（`/edit` で終わるURL）を記録シートに貼る

✅ **確認**: 設定でメールアドレス収集がオフ

> 固定URL方式（1つのフォームを使い回す）でよい場合は、この手順の代わりに
> 「送信」→ URLを短縮 で得た `https://docs.google.com/forms/d/e/.../viewform` を控えてください。
> その場合、Part 4 で `FIXED_FORM_URL` に設定します（`FORM_TEMPLATE_ID` は不要）。
>
> **LINE WORKS のフォームを使う場合も同じです。** フォームの回答URL（`https://` で始まるもの）を控え、
> Part 4 で `FIXED_FORM_URL` に設定してください。Google フォームの作成は不要になります。
> この場合、地震ごとにフォームを複製せず**全地震で同じURL**を使うため、回答は1つのフォームに集まります。
> 回答受付中の自動チェックは行われないので、事前に回答できることを手動で確認してください。

---

# Part 3. プログラムの反映（10分）

## 3-1. Cloud Shell を開く

1. <https://shell.cloud.google.com/> を開く
2. 本番の Google アカウントでログイン
3. 「Authorize」を求められたら許可
4. 画面下部の黒い入力欄（ターミナル）で作業します

✅ **確認**: プロンプト（`xxx@cloudshell:~$`）が表示されている

## 3-2. プログラムを取得

以下を**1行ずつ**コピーして貼り、Enter。

```bash
cd ~
git clone -b codex/deploy-operator-guide https://github.com/ikazuchizero/lineworks-earthquake-safety-check.git gas-repo
cd ~/gas-repo
git log -1 --oneline
```

✅ **確認**: `git log` の行が表示され、`ls src | wc -l` を実行すると `29` になる

```bash
ls src | wc -l
```

## 3-3. 退避コピー（必ず実行）

```bash
mkdir -p /tmp/gas_backup
cp -r src/. /tmp/gas_backup/
ls /tmp/gas_backup | wc -l
```

✅ **確認**: `29`

## 3-4. ログイン

```bash
npx --yes @google/clasp login --no-localhost
```

1. 表示された **URL をクリック**して開く
2. Google アカウントで許可
3. **「このサイトにアクセスできません（localhost）」の画面になる**
   → これは正常です
4. **アドレスバーをクリックして URL 全体をコピー**（`Ctrl+L` → `Ctrl+C`）
5. ターミナルに戻って貼り付け → Enter

✅ **確認**: `You are now logged in as ...` と自分のメールアドレスが出る

## 3-5. プロジェクト作成

```bash
npx --yes @google/clasp create --title "LINE WORKS 地震安否確認Bot" --type standalone --rootDir src
```

✅ **確認**: `Created new script: https://script.google.com/d/.../edit` が出る
→ **このURLを記録シートに貼る**

## 3-6. 設定ファイルを戻して反映

```bash
cp /tmp/gas_backup/appsscript.json src/appsscript.json
if [ -f src/Code.gs ] && grep -q "function myFunction" src/Code.gs; then rm src/Code.gs; fi
npx --yes @google/clasp status
npx --yes @google/clasp push -f
```

✅ **確認**: 最後に **`Pushed 29 files.`** と出る

---

# Part 4. 設定値の登録（10分）

## 4-1. エディタを開く

記録シートの GAS プロジェクト URL を開きます。

## 4-2. 値を書き換える

設定用の機能は**プログラムに最初から入っています**。新しく作る必要はありません。

1. 左のファイル一覧から **`setProperties.gs`** を開く
2. `SETUP_VALUES` の `""` の中を自分の値に書き換える
3. **Ctrl+S で保存**

```js
const SETUP_VALUES = {
    SPREADSHEET_ID: "ここにスプレッドシートID",
    CLIENT_ID: "ここにClient ID",
    CLIENT_SECRET: "ここにClient Secret",
    SERVICE_ACCT: "ここにサービスアカウント",
    BOT_ID: "ここにBot ID",
    PRIVATE_KEY: "ここに秘密鍵（改行を消して1行）",
    NOTIFY_SCALE: "40",
    FORM_TEMPLATE_ID: "ここにフォームID",
};
```

- 空欄のままにしたキーは**自動でスキップ**されます（`ROOM_ID` は 4-4 の後で入れます）
- 固定URL方式の場合: `FORM_TEMPLATE_ID` を消して
  `FIXED_FORM_URL: "https://docs.google.com/forms/d/e/.../viewform",` に置き換える

## 4-3. 実行

1. 上部の関数選択で **`setAllProperties`** を選ぶ
2. **「実行」** を押す
3. 初回は権限の確認が出る →「詳細」→「(安全でないページ) に移動」→「許可」
4. 下部の「実行ログ」を確認

✅ **確認**: `設定したキー: SPREADSHEET_ID, CLIENT_ID, ...` が表示される

## 4-4. ROOM_ID を取得（`setProperties.gs` の末尾に追記）

1. `setProperties.gs` の末尾に、以下を**そのまま**貼る
2. **Ctrl+S** → 関数選択で **`diagnoseBot`** →「実行」

```js
function diagnoseBot() {
  const p = PropertiesService.getScriptProperties();
  const clientId = p.getProperty('CLIENT_ID');
  const clientSecret = p.getProperty('CLIENT_SECRET');
  const serviceAcct = p.getProperty('SERVICE_ACCT');
  const botId = p.getProperty('BOT_ID');
  const key = normalizePrivateKey(p.getProperty('PRIVATE_KEY'));
  const tokenUrl = 'https://auth.worksmobile.com/oauth2/v2.0/token';
  const now = Math.floor(Date.now() / 1000);
  const b64 = function (s) { return Utilities.base64EncodeWebSafe(s).replace(/=+$/, ''); };

  const input = b64(JSON.stringify({ alg: 'RS256', typ: 'JWT' })) + '.' + b64(JSON.stringify({
    iss: clientId, sub: serviceAcct, iat: now, exp: now + 3600, aud: tokenUrl
  }));
  const jwt = input + '.' + b64(Utilities.computeRsaSha256Signature(input, key));

  const res = UrlFetchApp.fetch(tokenUrl, {
    method: 'post', muteHttpExceptions: true,
    payload: {
      grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer',
      assertion: jwt, client_id: clientId, client_secret: clientSecret,
      scope: 'bot'
    }
  });
  console.log('token HTTP=' + res.getResponseCode());
  if (res.getResponseCode() !== 200) { console.log(res.getContentText()); return; }

  const token = JSON.parse(res.getContentText()).access_token;
  const bot = UrlFetchApp.fetch('https://www.worksapis.com/v1.0/bots/' + encodeURIComponent(botId), {
    headers: { Authorization: 'Bearer ' + token }, muteHttpExceptions: true
  });
  console.log('bot HTTP=' + bot.getResponseCode());

  const ch = UrlFetchApp.fetch(
    'https://www.worksapis.com/v1.0/bots/' + encodeURIComponent(botId) + '/channels',
    { headers: { Authorization: 'Bearer ' + token }, muteHttpExceptions: true }
  );
  console.log('channels HTTP=' + ch.getResponseCode());
  if (ch.getResponseCode() === 200) {
    const list = (JSON.parse(ch.getContentText()).channels) || [];
    list.forEach(function (c) {
      console.log('- ' + c.channelId + ' | ' + (c.title || ''));
    });
  }
}
```

✅ **確認**: `- 1234567890 | 安否確認ルーム` のように **channelId とルーム名**が出る
→ 記録シートの `ROOM_ID` に channelId を書く

> `channels HTTP=200` で一覧が空なら、Part 1-2 の招待ができていません。
> `token HTTP=400` ならスコープ名の問題なので担当者に連絡してください。

## 4-5. ROOM_ID を登録

1. `setProperties.gs` の `SETUP_VALUES` に1行追加

```js
    ROOM_ID: "さっきのchannelId",
```

2. **Ctrl+S** → `setAllProperties` を実行
3. 実行ログに `ROOM_ID` が含まれることを確認

---

# Part 5. 動作確認（5分）

エディタ上部の関数選択で選び、「実行」します。

## 5-1. `setupCheck`

✅ **確認**: 次のように出る（`triggers` の NG はまだ正常）

```
OK config_values
OK spreadsheet
OK sheets
OK form_template
OK private_key
NG triggers: ...（現在: check=0, health=0）
```

`form_template` が NG の場合は、Part 2-3 のフォーム設定（メール収集オフ等）を確認してください。

## 5-2. `connectivityCheck`

✅ **確認**: `LINE WORKS HTTP=201` と表示され、**グループトークにメッセージが届く**

> `HTTP:403` の場合は、Part 1 の「Bot 追加」「使用範囲」「招待」を担当者と確認してください。

## 5-3. `runSelfTests`

✅ **確認**: `セルフテスト: すべて成功`

---

# Part 6. 本番稼働の開始（5分）

## 6-1. 通知しきい値を確認

1. 左メニュー **⚙「プロジェクトの設定」** → **「スクリプト プロパティ」**
2. `NOTIFY_SCALE` を確認（例: `40` = 震度4以上）

✅ **確認**: `0` になっていないこと（`0` はテスト専用）

## 6-2. ベースライン作成 → トリガー登録（**この2つは続けて実行**）

**① `initializeNotificationBaseline` を実行**

✅ **確認**: `初回ベースライン登録: N件（通知は送信していません）`

**② すぐに `setupTriggers` を実行**

✅ **確認**: `トリガーを設定しました: checkAndNotifyEarthquake（10分ごと）, writeHourlyHealthCheck（1時間ごと）`

## 6-3. 最終確認

`setupCheck` を実行。

✅ **確認**:

```
OK config_values
OK spreadsheet
OK sheets
OK form_template
OK private_key
OK triggers
セットアップ確認: すべてOK
```

---

# Part 7. 後片付け（3分）

1. `setProperties.gs` を削除（右クリック → 削除）
   - 秘密鍵がソースに残るため必ず削除
2. 記録シートを担当者に送る
3. グループトークに「テスト完了」と投稿して、届くことを確認

---

# 困ったとき（担当者に送る文字）

| 画面に出た文字 | 意味 |
|---|---|
| `User has not enabled the Apps Script API` | Part 2-1 が未反映。数分待って再実行 |
| `localhost で接続が拒否されました` | 正常。アドレスバーのURLをコピーして貼る |
| `Project file already exists.` | 既に作成済み。`npx --yes @google/clasp push -f` のみ実行 |
| `Pushed 0 files` | コピペしたコマンドを確認（`--rootDir src` が必要） |
| `CLIENT_ID未設定` | Part 4-3 を実行したか確認（保存だけでは反映されない） |
| `LINE WORKS送信失敗 HTTP:403` | Part 1 の Bot 追加・招待を確認 |
| `NG triggers` | Part 6-2 の ② を実行 |
| `セルフテスト` で `Assertion failed` | その行をそのまま担当者に送る |

**迷ったら止めてください。** 通知が始まる前なら、何度やり直しても問題ありません。
