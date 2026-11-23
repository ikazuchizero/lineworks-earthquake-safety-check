# 本番構築手順書（LINE WORKS 地震安否確認Bot）

対象: 本番用の Apps Script プロジェクトを新規に作る担当者
前提: **PC へのインストール不要。ブラウザだけで完結します。**

作業は Google Cloud Shell（ブラウザ上の Linux 環境）で行います。Cloud Shell には
Node.js (LTS)・npm・git が最初から入っているため、PC 側の環境を汚さずに `clasp push` を実行できます。
`npx` を使うので Cloud Shell 内にも常駐インストールしません。

所要時間の目安: 30〜45分（うち待ち時間5〜10分）

---

## 0. 全体の流れ

```
[準備]  Apps Script API を ON / LINE WORKS の値を控える
   ↓
[反映]  Cloud Shell で git clone → clasp login → clasp create → push
   ↓
[設定]  スプレッドシート作成 → スクリプトプロパティ登録
   ↓
[確認]  setupCheck → connectivityCheck → runSelfTests
   ↓
[稼働]  ベースライン → トリガー登録 → setupCheck で全OK
```

| 項目 | 値 |
|---|---|
| リポジトリ | `https://github.com/ikazuchizero/lineworks-earthquake-safety-check.git`（public） |
| ブランチ | `codex/deploy-operator-guide`（本番用） |
| 反映ファイル数 | **29**（`appsscript.json` + `.gs` 28） |
| 地震チェック間隔 | 10分（`src/config.gs` の `CHECK_INTERVAL_MINUTES`） |

> ⚠️ `main` は 2026-06-09 時点の旧版です。**`main` を clone しないでください。**

---

## 1. 事前準備

### 1-1. Apps Script API を有効化（初回のみ）

<https://script.google.com/home/usersettings> を開き（**本番アカウント**でログイン）、
「Google Apps Script API」を **オン** にする。反映まで数分かかることがあります。

### 1-2. 必要なアカウントと権限

| 用途 | 必要なもの |
|---|---|
| GAS の所有者・トリガー実行 | 本番用 Google アカウント（例: `ichouningendayo@gmail.com`） |
| LINE WORKS の認証情報 | Developer Console にアクセスできるアカウント |
| Bot のドメイン追加 | LINE WORKS の**管理者権限** |

### 1-3. 取得する ID・値と取得場所

| 値 | 取得場所（URL） | 補足 |
|---|---|---|
| `CLIENT_ID` | <https://developers.worksmobile.com/> → 対象アプリ → OAuth | アプリの識別子 |
| `CLIENT_SECRET` | 同上 | アプリの秘密 |
| `SERVICE_ACCT` | 同上 → Server API 2.0 / 認証情報 | **その Bot のサービスアカウント**。メールアドレス形式が多い |
| `PRIVATE_KEY` | 同上（秘密鍵のダウンロード） | PEM。**改行を消して1行**にすると扱いやすい |
| `BOT_ID` | 同上 → Bot の詳細 | 数字。Client ID とは別物 |
| `ROOM_ID` | 後述「5-4. ROOM_ID の確認」 | Talk のルームURLのIDとは**別物**（channelId） |
| `SPREADSHEET_ID` | スプレッドシートURLの `/d/` と `/edit` の間 | 下記 5-1 で作成 |
| `FORM_TEMPLATE_ID` | フォーム編集URLの `/d/` と `/edit` の間 | 複製元テンプレート |
| スクリプトID | `.clasp.json` の `scriptId`、またはエディタ「プロジェクトの設定」 | 後から参照用 |

参考:

- Bot の管理画面追加: <https://help.worksmobile.com/ja/admin-guides/manage-service/bot/>
- Bot の招待: <https://help.worksmobile.com/ja/use-guides/message/bot/message-bot-2/>

---

## 2. コードを反映する（Cloud Shell）

### 2-1. Cloud Shell を開く

<https://shell.cloud.google.com/> を開いて本番アカウントでログイン。
初回に「Authorize」を求められたら許可。以降は画面下部のターミナルで作業します。

### 2-2. コードを取得

```bash
cd ~
git clone -b codex/deploy-operator-guide https://github.com/ikazuchizero/lineworks-earthquake-safety-check.git gas-repo
cd ~/gas-repo
git branch --show-current    # codex/deploy-operator-guide
ls src | wc -l               # 29
```

- `gas-repo` は `git clone` が自動で作るので `mkdir` は不要
- 既に clone 済みなら `cd ~/gas-repo && git pull`
- `fatal: destination path 'gas-repo' already exists` → そのまま `cd ~/gas-repo`
- 動作確認済みの版に固定するなら `git checkout <コミットID>`（例: `c6e0355`）

### 2-3. 退避コピー（重要）

```bash
mkdir -p /tmp/gas_backup
cp -r src/. /tmp/gas_backup/
ls /tmp/gas_backup | wc -l   # 29
```

**なぜ必要か**: 次の `clasp create` は内部でリモートの初期ファイルを pull するため、
`appsscript.json` を既定のマニフェストで**上書き**します（`oauthScopes` と `timeZone` が消える）。
2-6 で戻します。

### 2-4. ログイン

```bash
npx --yes @google/clasp login --no-localhost
```

1. 表示された URL を開く
2. 本番アカウントで許可
3. **「このサイトにアクセスできません（localhost）」ページのアドレスバーからURL全体をコピー**
4. ターミナルに貼り付けて Enter

`You are now logged in as ...` が出れば成功。**メールアドレスが本番用か確認**してください。

### 2-5. プロジェクト作成

```bash
npx --yes @google/clasp create --title "LINE WORKS 地震安否確認Bot" --type standalone --rootDir src
```

期待する出力:

```
Created new script: https://script.google.com/d/1AbC.../edit
Cloned 2 files.
```

この URL が本番プロジェクトです。控えてください。

### 2-6. マニフェスト復元 → 一括反映

```bash
cp /tmp/gas_backup/appsscript.json src/appsscript.json

# create が生成した既定の Code.gs だけ削除（自作コードは消さない）
if [ -f src/Code.gs ] && grep -q "function myFunction" src/Code.gs; then rm src/Code.gs; fi

npx --yes @google/clasp status     # 29ファイルが並ぶ
npx --yes @google/clasp push -f    # Pushed 29 files.
```

### 2-7. マニフェストの確認

エディタを開き、**⚙「プロジェクトの設定」→「`appsscript.json` マニフェスト ファイルをエディタで表示する」** にチェック。
`appsscript.json` を開いて以下を確認します。

```json
{
  "timeZone": "Asia/Tokyo",
  "oauthScopes": [
    "https://www.googleapis.com/auth/spreadsheets",
    "https://www.googleapis.com/auth/forms",
    "https://www.googleapis.com/auth/drive",
    "https://www.googleapis.com/auth/script.external_request",
    "https://www.googleapis.com/auth/script.scriptapp"
  ]
}
```

---

## 3. スプレッドシートを用意

スタンドアロンなので**バインド先がありません**。普通に新規作成して ID を渡します。

1. <https://sheets.new> を開く（即座に新規作成）
2. 名前を付ける（例: `地震安否確認ログ`）
3. URL の `/d/` と `/edit` の間をコピー

```
https://docs.google.com/spreadsheets/d/1AbCdEfGh.../edit
                                      └─ SPREADSHEET_ID ─┘
```

- 所有者は**スクリプトと同じアカウント**にする（別アカウントだと `openById` が権限エラー）
- シートは自動作成されます（`地震ログ` / `ヘルスチェック` / `状態`）。手動で作らないでください

---

## 4. LINE WORKS 側の準備

### 4-1. Bot をドメインに追加（見落としやすい）

Developer Console に Bot を作っただけでは Talk の招待一覧に出ません。

1. <https://admin.worksmobile.com/> に管理者でログイン
2. 上部メニュー **「サービス」→「Bot」**
3. **「Bot追加」** → Developer Console に登録済みの Bot を選択
4. **使用範囲**を確認（「全て」= 全メンバー。「制限」だと連絡先にも出ません）

### 4-2. Bot をルームに招待

1. LINE WORKS Talk で**グループトーク**を作成（1対1では招待できません）
2. 「招待」→「Bot」タブ → 対象 Bot を追加

---

## 5. スクリプトプロパティを登録

### 5-1. 一括登録（推奨）

プロパティ登録用の `setProperties.gs` は**プログラムに含まれています**（手動で作る必要はありません）。

1. エディタ左の一覧から **`setProperties.gs`** を開く
2. `SETUP_VALUES` の各項目を自分の値に書き換える（空欄のキーはスキップされます）
3. **Ctrl+S** → 関数選択で **`setAllProperties`** →「実行」
4. 実行ログの「設定したキー: …」を確認
5. 必要なら **`showPropertyStatus`** を実行して必須キーの過不足を確認（値は表示されません）
6. 終わったら **`setProperties.gs` を削除**（秘密鍵がソースに残るため。プロパティはサーバー側に残ります）

```js
const SETUP_VALUES = {
    SPREADSHEET_ID: "ここにスプレッドシートID",
    CLIENT_ID: "ここにClient ID",
    CLIENT_SECRET: "ここにClient Secret",
    SERVICE_ACCT: "ここにサービスアカウント",
    BOT_ID: "ここにBot ID",
    ROOM_ID: "ここに通知先のchannelId",
    MAINTENANCE_ROOM_ID: "ここに障害通知用channelId（任意）",

    // 改行を消して1行で貼る（コード側で64文字に組み直します）
    PRIVATE_KEY: "-----BEGIN PRIVATE KEY-----MIIE...-----END PRIVATE KEY-----",

    // テスト用は 0。本番は 40（震度4以上）など
    NOTIFY_SCALE: "40",

    // テンプレート複製方式ならこれ。固定URL方式なら FIXED_FORM_URL に置き換え
    FORM_TEMPLATE_ID: "ここにフォームID",
    // FIXED_FORM_URL: "https://docs.google.com/forms/d/e/.../viewform",
};
```

- 値は**すべて文字列**で書く（`"0"`、`"40"`）
- 実行ログに「設定したキー: …」が出れば登録成功。**保存しただけでは反映されません**
- `ROOM_ID` は 8-2 の `diagnoseBot` で確認した channelId を入れる
- 後から `clasp push` すると、削除した `setProperties.gs` が**空の状態で復活**します。使うときだけ値を入れてください

### 5-2. 主要プロパティ

| キー | 必須 | 内容 |
|---|---|---|
| `SPREADSHEET_ID` | 実質必須 | スタンドアロンでは必須 |
| `CLIENT_ID` / `CLIENT_SECRET` / `SERVICE_ACCT` / `PRIVATE_KEY` / `BOT_ID` | 必須 | LINE WORKS の認証情報 |
| `ROOM_ID` | 必須 | 通知先 channelId |
| `NOTIFY_SCALE` | 必須 | 通知する震度コード（`0,10,20,30,40,45,50,55,60,70`） |
| `FORM_TEMPLATE_ID` または `FIXED_FORM_URL` | どちらか必須 | フォームの供給方法 |
| `MAINTENANCE_ROOM_ID` | 任意 | 障害通知先。未設定なら `ROOM_ID` |
| `QUIET_HOURS_ENABLED` / `QUIET_HOURS_START` / `QUIET_HOURS_END` | 任意 | 既定 `true` / `0` / `6` |

その他のキーは `README.md` の表を参照。

### 5-3. ROOM_ID の確認（403 が出たとき）

`diagnoseBot` を一時ファイルに貼って実行すると、Bot が参加しているルームの `channelId` 一覧が出ます。

```js
function diagnoseBot() {
  const p = PropertiesService.getScriptProperties();
  const clientId = p.getProperty('CLIENT_ID');
  const clientSecret = p.getProperty('CLIENT_SECRET');
  const serviceAcct = p.getProperty('SERVICE_ACCT');
  const botId = p.getProperty('BOT_ID');
  const roomId = p.getProperty('ROOM_ID');
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
  const call = function (url) {
    const r = UrlFetchApp.fetch(url, { headers: { Authorization: 'Bearer ' + token }, muteHttpExceptions: true });
    console.log(url + ' -> HTTP=' + r.getResponseCode());
    return r;
  };

  call('https://www.worksapis.com/v1.0/bots/' + encodeURIComponent(botId));

  const ch = call('https://www.worksapis.com/v1.0/bots/' + encodeURIComponent(botId) + '/channels');
  if (ch.getResponseCode() === 200) {
    const list = (JSON.parse(ch.getContentText()).channels) || [];
    console.log('channels=' + list.length);
    list.forEach(function (c) {
      console.log('- ' + c.channelId + ' | ' + (c.title || '') + ' | ' + (c.channelType || ''));
    });
    console.log('ROOM_ID一致: ' + list.some(function (c) { return String(c.channelId) === String(roomId); }));
  }
}
```

`-` の後ろの値が `ROOM_ID` に入れる channelId です。

---

## 6. 動作確認（順番どおりに実行）

関数はエディタ上部のプルダウンで選び、「実行」を押します。
目的の関数が一覧に出ないときは、**その関数が入っているファイルを開いてから**プルダウンを開いてください。

### 6-1. 設定と配置の確認

```
setupCheck
```

期待: `triggers` だけ NG（まだトリガー未登録なので正常）

```
OK config_values / OK spreadsheet / OK sheets / OK form_template / OK private_key
NG triggers: …（現在: check=0, health=0）
```

### 6-2. 送信経路の確認

```
connectivityCheck
```

期待: `LINE WORKS HTTP=201` と `疎通確認メッセージを送信しました（通知用・保守用）`

### 6-3. ロジックの確認

```
runSelfTests
```

期待: `セルフテスト: すべて成功`

### 6-4. 本番値に変更

`NOTIFY_SCALE` を `0`（テスト）から本番値（例: `40`）へ。
「プロジェクトの設定」→「スクリプト プロパティ」→ 鉛筆アイコンで編集。

### 6-5. ベースライン → トリガー登録（連続で）

```
initializeNotificationBaseline
```

期待: `初回ベースライン登録: N件（通知は送信していません）`

```
setupTriggers
```

期待: `トリガーを設定しました: checkAndNotifyEarthquake（10分ごと）, writeHourlyHealthCheck（1時間ごと）`

**順番が重要**: `initializeNotificationBaseline` は稼働中トリガーがあると実行を拒否します。
また両者の間隔が空くほど「その間に起きた地震が通知されない」可能性が上がります。

### 6-6. 最終確認

```
setupCheck
```

期待:

```
OK config_values / OK spreadsheet / OK sheets / OK form_template / OK private_key / OK triggers
セットアップ確認: すべてOK
```

### 6-7. 稼働の確認

| タイミング | 見る場所 | 期待 |
|---|---|---|
| 10〜20分後 | `地震ログ` シート | 行が増える（地震がなくてもスキップ記録が入る） |
| 1時間後 | `ヘルスチェック` シート | 1行追加。予定実行回数 `6` |
| 震度閾値以上の地震発生時 | トーク | 安否確認メッセージ + フォームURL |
| 深夜0〜6時 | トーク | 送信されず保留。朝6時以降にまとめて1通 |

---

## 7. 運用

### 7-1. コード更新

```bash
cd ~/gas-repo
git pull
npx --yes @google/clasp push -f
```

間隔などトリガー条件を変えた場合は、push 後に `setupTriggers` → `setupCheck` を実行します。

### 7-2. チェック間隔の変更

`src/config.gs` の 1行を変更します（指定できるのは 1 / 5 / 10 / 15 / 30 分）。

```js
const CHECK_INTERVAL_MINUTES = 10;
```

`expectedRunsPerHour`（ヘルスチェックの予定回数）は自動算出されます。
push → `setupTriggers` → `setupCheck` の順で反映。

**1分は推奨しません。** gmail.com アカウントのトリガー実行時間は 90分/日で、
1分間隔（1,440回）だと1回5秒で 120分/日となり上限を超えます。10分なら約12分/日です。

### 7-3. 一時停止

エディタ左メニュー「トリガー」→ `checkAndNotifyEarthquake` と `writeHourlyHealthCheck` を削除。
再開は `setupTriggers`。

### 7-4. 控えておくもの

```
プロジェクトURL : https://script.google.com/d/<scriptId>/edit
デプロイ元コミット: 例 c6e0355
実行アカウント    : <本番アカウント>
```

`.clasp.json` にはスクリプトIDが入るため、**コミットしないでください**。

---

## 8. トラブルシューティング

### 8-1. 症状別の早見表

| 症状 / メッセージ | 原因 | 対処 |
|---|---|---|
| `User has not enabled the Apps Script API` | API が OFF | 1-1 を実施。反映に数分待つ。`npx --yes @google/clasp list` で確認 |
| `localhost で接続が拒否されました` | `--no-localhost` の正常動作 | **エラーページのアドレスバーURL全体**をコピーしてターミナルに貼る |
| `Project file already exists.` | `.clasp.json` が既にある | `npx --yes @google/clasp push -f` のみ |
| `Pushed 0 files` / `Could not find appsscript.json` | `--rootDir` の指定ミス | `ls src` を確認し `--rootDir src` で再実行 |
| `CLIENT_ID未設定` など | プロパティ未登録／実行していない | `setAllProperties` を**実行**したか確認。キー名の綴りも確認 |
| 構文エラー（PEM貼り付け後） | シングルクォート内に実改行 | 改行を消して1行にする（下記 8-4） |
| `LINE WORKS送信失敗 HTTP:403` | Bot がルーム未参加／ID誤り | 8-2 を参照 |
| Bot が招待一覧に出ない | ドメインに未追加 | 4-1（管理画面「サービス」→「Bot」→「Bot追加」） |
| `地震API取得失敗 HTTP:429 error code: 1015` | P2PQuake の Cloudflare 制限 | 8-5 を参照 |
| `NG triggers` | トリガー未登録／保存値と設定の不一致 | `setupTriggers` を実行 |
| `ベースライン…稼働中トリガーのため実行できません` | トリガー登録後に実行した | トリガーを削除 → 実行 → 再登録。または `ALLOW_BASELINE_WITH_TRIGGERS=true`（非推奨） |
| `runSelfTests` が一覧に無い | 別ファイルを開いている | `selfTest.gs` を開いてから選ぶ |
| ファイル数が29でない | 古いブランチ／clone | `git branch --show-current` が `codex/deploy-operator-guide` か確認 |
| 認証で `Access blocked` | 組織の制限 | 管理者に Apps Script API の許可を確認 |
| `Service using too much computer time for one day` | トリガー実行時間の上限 | 間隔を 10/15/30 分に延ばす |

### 8-2. LINE WORKS 403（送信できない）

1. **Bot がルームに参加しているか**（グループトークのみ。1対1は不可）
2. **管理画面で Bot が追加されているか**（4-1）と **使用範囲**
3. **`SERVICE_ACCT` がその Bot のサービスアカウントか**（別アプリのものだと 403）
4. **`ROOM_ID` が channelId か**（5-3 の `diagnoseBot` で確認）
5. `BOT_ID` が数字の Bot ID か（Client ID と混同していないか）

`diagnoseBot` の読み方:

| 出力 | 意味 |
|---|---|
| `/bots/{id}` が 200 | Bot ID とサービスアカウントは正しい |
| `/bots/{id}` が 404 | `BOT_ID` が違う |
| `/bots/{id}` が 403 | `SERVICE_ACCT` がその Bot のものではない |
| `channels` が 0件 | Bot がどのルームにもいない |
| `ROOM_ID一致: false` | 一覧の `channelId` に差し替える |

### 8-3. プロパティが入らない

- **保存しただけでは反映されません。** 関数を選んで「実行」が必要
- `values` のキー名が正しいか（`CLIENTID` / `client_id` は不可）
- 実行ログに赤いエラーが出ていないか（構文エラーがあるとプロジェクト全体が動きません）
- 確認用:

```js
function checkProperties() {
  const keys = ['SPREADSHEET_ID','CLIENT_ID','CLIENT_SECRET','SERVICE_ACCT','PRIVATE_KEY','BOT_ID','ROOM_ID','MAINTENANCE_ROOM_ID','NOTIFY_SCALE'];
  const p = PropertiesService.getScriptProperties().getProperties();
  keys.forEach(function (k) {
    console.log((p[k] ? 'OK   ' : 'MISS ') + k + (p[k] ? ' (' + p[k].length + '文字)' : ''));
  });
  console.log('FORM: ' + (p.FIXED_FORM_URL ? 'FIXED_FORM_URL' : (p.FORM_TEMPLATE_ID ? 'FORM_TEMPLATE_ID' : '未設定')));
}
```

### 8-4. 秘密鍵でエラーになる

JS の文字列は改行を含められません。次のどれかで渡します。

```js
// A. 改行をすべて削除して1行（推奨）
PRIVATE_KEY: '-----BEGIN PRIVATE KEY-----MIIE...-----END PRIVATE KEY-----',

// B. バッククォートなら実改行OK
PRIVATE_KEY: `-----BEGIN PRIVATE KEY-----
MIIE...
-----END PRIVATE KEY-----`,

// C. 配列で連結
PRIVATE_KEY: ['-----BEGIN PRIVATE KEY-----','MIIE...','-----END PRIVATE KEY-----'].join('\n'),
```

`normalizePrivateKey` がヘッダー・改行・空白を除去して64文字で組み直すため、
`-----BEGIN/END-----` は付けても外しても動きます。

### 8-5. P2PQuake 429（error 1015）

Cloudflare のIP単位レート制限です。GAS は共有IPから出るため、自分の回数が少なくても発生します。

- 既定は10分間隔（144回/日）。**5分程度の失敗では地震を取りこぼしません**（`limit=10` で直近10件を取得）
- `地震ログ` の `HTTP:429` の頻度で判断: 1日0〜数回ならそのまま、毎時なら 15/30 分へ延長
- 1分間隔は P2PQuake 以前に **GAS の1日クォータ（90分/日）** に近づくため不可

参考: <https://developers.google.com/apps-script/guides/services/quotas>

### 8-6. GAS のクォータ（目安）

| 項目 | gmail.com | Workspace |
|---|---|---|
| トリガー合計実行時間 | 90分/日 | 6時間/日 |
| 1回の実行時間 | 6分 | 6分 |
| 同時実行 | 30 | 30 |
| トリガー数 | 20/スクリプト | 20/スクリプト |
| URL Fetch | 20,000/日 | 100,000/日 |
| Properties 読み書き | 50,000/日 | 500,000/日 |

10分間隔・1回5秒なら約12分/日（13%）で余裕があります。
90分/日は**アカウント全体で共有**なので、他のトリガー型スクリプトがある場合は合算で考えてください。

### 8-7. その他の落とし穴

| 内容 | 対策 |
|---|---|
| `clasp create` が `appsscript.json` を上書き | 事前に退避し、作成後に戻す（2-3 / 2-6） |
| 1対1トークに Bot を招待できない | グループトークを作る |
| スタンドアロンなのに `SPREADSHEET_ID` 未設定 | `SpreadsheetApp.getActiveSpreadsheet()` が null になりエラー |
| シートを手で作ってしまう | コードが自動作成・ヘッダー同期するので不要 |
| `.clasp.json` をコミット | スクリプトIDが公開される。`.gitignore` 対象にする |
| 静穏時間中に通知が来ない | 仕様。0〜6時は保留し、明けにバッチ送信 |

---

## 9. 参考リンク

| 用途 | URL |
|---|---|
| Apps Script API の有効化 | <https://script.google.com/home/usersettings> |
| Cloud Shell | <https://shell.cloud.google.com/> |
| LINE WORKS Developer Console | <https://developers.worksmobile.com/> |
| LINE WORKS 管理画面（Bot追加） | <https://admin.worksmobile.com/> |
| Bot の管理（ヘルプ） | <https://help.worksmobile.com/ja/admin-guides/manage-service/bot/> |
| Bot の招待（ヘルプ） | <https://help.worksmobile.com/ja/use-guides/message/bot/message-bot-2/> |
| GAS クォータ | <https://developers.google.com/apps-script/guides/services/quotas> |
| リポジトリ | <https://github.com/ikazuchizero/lineworks-earthquake-safety-check> |
| 新規スプレッドシート | <https://sheets.new> |
