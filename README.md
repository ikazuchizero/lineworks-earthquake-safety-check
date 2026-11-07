# lineworks-earthquake-safety-check

地震情報を確認し、設定した条件に一致した場合にLINE WORKSへ安否確認通知を送るためのリポジトリです。

## 構成

- `src/`: 既存のGoogle Apps Script版です。
- `php/`: PHP版です。Xserverなどでcron実行する想定です。
- `php/OPERATIONS.md`: PHP版の設定・運用手順です。

## 現在の方針

- 既存GAS版は残したまま、PHP版を別実装として追加しています。
- フォームはGoogle Formsで自動生成し、LINE WORKSへ通知します。通常の通知先を保守通知にも使います。
- PHP版の詳細な設定・実行・運用手順は `php/OPERATIONS.md` を参照してください。

## Google Formsを自動生成して使う場合

- 通知経路は引き続きLINE WORKSです。Google Formsは通知文に載せる回答フォームとしてだけ使います。
- 所有者の1つのテンプレートを通知対象の地震ごとにコピーし、公開済み回答者URLをLINE WORKSで送ります。回答取得・回答者ID照合は行いません。
- 個人GoogleアカウントのユーザーOAuth（offline refresh token）と、テンプレートIDをGit管理外の `php/config.php` に設定します。テンプレートへの既存アクセスには `https://www.googleapis.com/auth/drive` の本人同意が必要です。実値はこのリポジトリへ入れません。
- `publishSettings` に対応したテンプレートが必要です。確認済みメールアドレスの収集（`VERIFIED`）はGoogleログインを必要とするため、匿名回答では無効にしてください。この設定のテンプレートはコピー前に拒否し、設問やメール収集設定を自動変更しません。
- 回答者公開は、リンクを知っている人が使える設定です。転送されたリンクでも回答できます。公開用権限の `allowFileDiscovery` は `false` にします。テンプレート所有者はGoogleログインなしで回答できることを実際の受信者で確認してください。実フォームの匿名アクセスは未検証で、APIの公開設定だけでは全てのログイン制限を証明できません。編集権限・結果閲覧権限は回答者公開とは別です。
- OAuth testing modeのrefresh tokenには短期失効や取り消しがあります。公式OAuth案内に従って所有者が取得・管理してください。実Google/LINE WORKS接続はこのリポジトリのテストでは行いません。

## 注意事項

- 本番設定では `php/config.example.php` をコピーして `php/config.php` を作成し、必要項目を設定します。
- Google OAuthのclient ID、client secret、refresh token、テンプレートIDは必須です。未設定なら起動時に停止します。
- `php/config.php`、秘密鍵、状態ファイル、ログなどの実行時ファイルはGitに含めません。
- 秘匿値、ID、トークン、秘密鍵、room_id、bot_idなどの実値はREADMEやログへ書かないでください。
