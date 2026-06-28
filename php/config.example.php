<?php
declare(strict_types=1);

// このファイルを php/config.php にコピーし、実値は config.php 側へ記入する。
// php/config.php、token、room_id、bot_id、service_account、private key などの
// 秘密情報や実IDは絶対にGitへ入れない。このサンプルには本番値を書かない。
return [
    // P2PQuake/JMAの震度コードによる通知しきい値。
    // 0 はテスト用。取得した地震を広く検証するための値なので、
    // 本番では通常、震度5弱相当の45以上へ戻すこと。
    'notify_scale' => 0,

    // P2PQuakeの取得先。php/bin/check.php では limit=10 で取得する。
    // 最新1件だけを見ると、対象外の続報で通知対象地震を見落とす可能性がある。
    'p2pquake_api_url' => 'https://api.p2pquake.net/v2/jma/quake',

    // LINE WORKS OAuth token の取得先。
    'lineworks_token_url' => 'https://auth.worksmobile.com/oauth2/v2.0/token',

    // LINE WORKSの認証情報と、安否確認通知を送るチャットルーム。
    // 実値は php/config.php にだけ書く。
    'client_id' => '',
    'client_secret' => '',
    'service_account' => '',
    'bot_id' => '',
    'room_id' => '',

    // private key の実体はGit管理外の php/secrets/private.key に置く。
    // ここにはファイルパスだけを書く。
    'private_key_path' => __DIR__ . '/secrets/private.key',

    // フォームストックを使うかどうか。
    // true が本番推奨。forms.json の available URLを1回だけ使い、送信成功後に used にする。
    // false はローカル/テスト専用。forms.json/forms.csvを使わず、下の form_url を固定で使う。
    // false を本番で使うと同じフォームURLを使い回すため、回答が混ざる事故につながる。
    'form_stock_enabled' => true,

    // form_stock_enabled=false のテスト時だけ使う固定フォームURL。
    // 本番では fallback として使わない。実URLは php/config.php にだけ書く。
    'form_url' => '',

    // フォームURL在庫の保存先。実運用データの forms.json はGitへ入れない。
    // available のフォームURLは、LINE WORKS送信成功後にだけ used へ変わる。
    'form_stock_path' => __DIR__ . '/storage/forms.json',

    // フォームURL補充CSVの取り込み先。
    // 1行目を URL、2行目以降をフォームURLにした forms.csv を配置する。
    // 成功したCSVは processed/、失敗したCSVは failed/ へ移動する。
    'form_import_csv_path' => __DIR__ . '/storage/import/forms.csv',
    'form_import_processed_dir' => __DIR__ . '/storage/import/processed',
    'form_import_failed_dir' => __DIR__ . '/storage/import/failed',

    // 低在庫通知のしきい値。判定は「以下」。
    // 10なら、フォーム消費後の残数が10件以下になったとき補充通知する。
    'form_low_stock_threshold' => 10,

    // フォーム補充・枯渇通知を送るメンテナンス用チャットルーム。
    // 安否確認通知先の room_id とは別にすること。
    'form_low_stock_room_id' => '',
];
