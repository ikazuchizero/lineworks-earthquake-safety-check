<?php
declare(strict_types=1);

// このファイルを php/config.php にコピーし、実値は config.php 側へ記入する。
// php/config.php、token、room_id、bot_id、service_account、private key などの
// 秘密情報や実IDは絶対にGitへ入れない。このサンプルには本番値を書かない。
return [
    // P2PQuake/JMAの震度コードによる通知しきい値。
    // 0 はテスト用。取得した地震を広く検証するための値なので、
    // 本番相当では通常、震度5弱相当の45以上にすること。
    'notify_scale' => 45,

    // 震源地が空のUNKNOWN情報は即通知せず、震源地名ありの続報を待つ秒数。
    // デフォルトは600秒。短すぎるとUNKNOWNで先に通知され、長すぎると安否確認が遅れる。
    'unknown_hypocenter_hold_seconds' => 600,

    // 全体向け地震通知を保留する時間帯。開始を含み、終了を含まない。
    // 0-6 は0時から6時まで。開始が終了より大きい場合は日跨ぎ。
    'quiet_hours_start' => 0,
    'quiet_hours_end' => 6,

    // P2PQuakeの取得先。php/bin/check.php では limit=10 で取得する。
    // 最新1件だけを見ると、対象外の続報で通知対象地震を見落とす可能性がある。
    'p2pquake_api_url' => 'https://api.p2pquake.net/v2/jma/quake',

    // LINE WORKS OAuth token の取得先。
    'lineworks_token_url' => 'https://auth.worksmobile.com/oauth2/v2.0/token',

    // LINE WORKSの認証情報と、安否確認通知を送るチャットルーム。
    // 本番前にBotが対象roomへ参加済みか確認すること。
    // 実値は php/config.php にだけ書く。
    'client_id' => '',
    'client_secret' => '',
    'service_account' => '',
    'bot_id' => '',
    'room_id' => '',

    // private key の実体はGit管理外の php/secrets/private.key に置く。
    // ここにはファイルパスだけを書く。
    'private_key_path' => __DIR__ . '/secrets/private.key',

    // 個人GoogleアカウントのユーザーOAuth offline refresh tokenを使う。
    // Google側はサービスアカウントではない。実値はGit管理外の config.php にだけ置く。
    // publishSettings対応のテンプレートが必要。匿名回答では確認済みメール収集を無効にする。
    'google_oauth_client_id' => 'REPLACE_WITH_GOOGLE_OAUTH_CLIENT_ID',
    'google_oauth_client_secret' => 'REPLACE_WITH_GOOGLE_OAUTH_CLIENT_SECRET',
    'google_oauth_refresh_token' => 'REPLACE_WITH_GOOGLE_OAUTH_REFRESH_TOKEN',
    'google_forms_template_id' => 'REPLACE_WITH_GOOGLE_FORM_TEMPLATE_ID',
];
