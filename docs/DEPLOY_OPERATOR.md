# 本番 GAS プロジェクト作成手順（PC に Node.js が無い人向け）

対象: 本番用の Apps Script プロジェクトを新規に作る担当者

**PC へのインストールも、ファイルのアップロードも不要です。** ブラウザだけで完結します。

1. Google Cloud Shell（ブラウザ上の Linux 環境）を開く
2. GitHub からコードを `git clone` する
3. `clasp push` で一括反映する

Cloud Shell には **Node.js (LTS)・npm・git が最初から入っている**ため、
PC 側の環境を汚さずに実行できます。`npx` を使うので Cloud Shell 内にも常駐インストールしません。

| 項目 | 値 |
|---|---|
| リポジトリ | `https://github.com/ikazuchizero/lineworks-earthquake-safety-check.git`（public） |
| ブランチ | `codex/deploy-operator-guide`（本番用） |
| 反映されるファイル | `src/` 以下 **`.gs` 27 個 + `appsscript.json`**（計 28） |

> ⚠️ `main` は 2026-06-09 時点の旧版のままです。**`main` を clone しないでください。**
> 必ず下の `-b codex/deploy-operator-guide` を付けて取得してください。

---

## 1. Apps Script API を有効化（初回のみ）

1. <https://script.google.com/home/usersettings> を開く（本番アカウントでログイン）
2. 「Google Apps Script API」を **オン** にする

反映まで数分かかることがあります。オフのまま実行すると
`User has not enabled the Apps Script API` で失敗します。

---

## 2. Cloud Shell を開く

<https://shell.cloud.google.com/> を開いて本番アカウントでログインします。
初回に「Authorize」を求められたら許可してください。

以降は画面下部の黒いターミナルで作業します。

---

## 3. コードを取得

```bash
cd ~
git clone -b codex/deploy-operator-guide https://github.com/ikazuchizero/lineworks-earthquake-safety-check.git gas-repo
cd ~/gas-repo
git branch --show-current    # codex/deploy-operator-guide と出ること
```

- `gas-repo` は `git clone` が自動で作るので、事前に `mkdir` は不要です（`~` も `/tmp` も既にあります）
- 既に clone 済みなら `cd ~/gas-repo && git pull` で最新化
- 動作確認済みの版に固定したい場合は `git checkout <コミットID>`（例: `97dcf04`）
- clone 済みで `fatal: destination path 'gas-repo' already exists` と出たら、そのまま `cd ~/gas-repo` へ

---

## 4. 構成を確認

```bash
ls src | wc -l     # 28 になること
ROOT=src
ls "$ROOT"
```

`appsscript.json` と `.gs` が並べば OK です。

---

## 5. 退避コピーを取る（重要）

```bash
mkdir -p /tmp/gas_backup
cp -r "$ROOT"/. /tmp/gas_backup/
ls /tmp/gas_backup | wc -l    # 28
```

**なぜ必要か**: 次の `clasp create` は内部で「作成 → リモート初期ファイルの pull」を行い、
既存の `appsscript.json` を既定のマニフェストで**上書き**します
（`oauthScopes` や `timeZone` が消える）。手順 7 で戻します。

---

## 6. ログイン

```bash
npx --yes @google/clasp login --no-localhost
```

1. 表示された URL をクリックして開く
2. 本番アカウントで許可
3. 画面に出た認証コードをコピーしてターミナルに貼り付け、Enter

---

## 7. プロジェクト作成 → 一括反映

```bash
npx --yes @google/clasp create --title "LINE WORKS 地震安否確認Bot" --type standalone --rootDir "$ROOT"

# create に上書きされた appsscript.json を元に戻す
cp /tmp/gas_backup/appsscript.json "$ROOT/appsscript.json"

# create が生成した既定の Code.gs だけ削除（自作コードは消さない）
if [ -f "$ROOT/Code.gs" ] && grep -q "function myFunction" "$ROOT/Code.gs"; then rm "$ROOT/Code.gs"; fi

npx --yes @google/clasp status
npx --yes @google/clasp push -f
```

確認ポイント:

- `clasp status` に **28 ファイル**が並ぶ
- `push` の最後に **`Pushed 28 files.`** と出る

---

## 8. エディタを開く

```bash
node -e 'console.log("https://script.google.com/d/"+JSON.parse(require("fs").readFileSync(".clasp.json","utf8")).scriptId+"/edit")'
```

出力された URL を開くと、28 ファイルが反映された状態のエディタが開きます。

ここから先はリポジトリの `README.md`「セットアップ」に進んでください
（スクリプトプロパティ設定 → `setupCheck` → `initializeNotificationBaseline` →
`setupTriggers` → `setupCheck` → `connectivityCheck` → `runSelfTests`）。

---

## コードを更新したとき

```bash
cd ~/gas-repo
git pull
npx --yes @google/clasp push -f
```

`appsscript.json` を書き換えた場合も、そのまま push で反映されます。

---

## うまくいかないとき

| メッセージ | 原因と対処 |
|---|---|
| `User has not enabled the Apps Script API` | 手順 1 がオフ。オンにして数分待ってから再実行 |
| `Project file already exists.` | 既に `.clasp.json` がある。`npx --yes @google/clasp push -f` だけでよい |
| `fatal: destination path ... already exists` | clone 済み。`cd ~/gas-repo && git pull` |
| `Pushed 0 files` / `Could not find appsscript.json` | `$ROOT` の指定ミス。`ls src` を確認して再実行 |
| `clasp: command not found` | `npx --yes @google/clasp ...` の形で実行しているか確認（`clasp` 単体は未インストールで正しい） |
| ファイル数が 28 にならない | `git branch --show-current` が `codex/deploy-operator-guide` か確認（`main` だと旧版）。古い clone なら `git pull` |
| 認証で `Access blocked` | 本番アカウントが組織の制限を受けている。管理者に Apps Script API の許可を確認 |

---

## 補足

- `clasp push` はリモートの内容を**ローカルのファイル構成で置き換える**ため、既定の `Code.gs` は残りません。
- このスクリプトは時間主導トリガーで動くスタンドアロンなので、**デプロイ作業は不要**です。
  Web アプリとして公開する場合だけ `appsscript.json` に `webApp` を追加し、
  `npx --yes @google/clasp create-deployment` が必要になります。
- `create` が作る `.clasp.json` にはスクリプトIDが入ります。`git add -A` でコミットしないよう注意してください。
- Cloud Shell は 1 時間無操作でセッションが切れますが、`$HOME` 配下（`~/gas-repo` と認証情報）は残ります。
