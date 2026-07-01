# lineworks-earthquake-safety-check

地震情報を確認し、設定した条件に一致した場合にLINE WORKSへ安否確認通知を送るためのリポジトリです。

## 構成

- `src/`: 既存のGoogle Apps Script版です。
- `php/`: PHP版です。Xserverなどでcron実行する想定です。
- `php/OPERATIONS.md`: PHP版の設定・運用手順です。

## 現在の方針

- 既存GAS版は残したまま、PHP版を別実装として追加しています。
- PHP版では、安否確認通知先とフォーム補充などの保守通知先を分ける想定です。
- PHP版の詳細な設定・実行・運用手順は `php/OPERATIONS.md` を参照してください。

## 注意事項

- 本番設定では `php/config.example.php` をコピーして `php/config.php` を作成し、必要項目を設定します。
- `php/config.php`、秘密鍵、状態ファイル、フォーム在庫ファイル、取り込みCSV、ログなどの実行時ファイルはGitに含めません。
- 秘匿値、ID、トークン、秘密鍵、room_id、bot_idなどの実値はREADMEやログへ書かないでください。
