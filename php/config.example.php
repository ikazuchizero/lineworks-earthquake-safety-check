<?php
declare(strict_types=1);

return [
    // Test environments may use 0. Confirm the production threshold, such as 45 or higher, before release.
    'notify_scale' => 0,

    // The client always forces limit=10 to avoid missing target earthquakes.
    'p2pquake_api_url' => 'https://api.p2pquake.net/v2/jma/quake',
    'lineworks_token_url' => 'https://auth.worksmobile.com/oauth2/v2.0/token',

    'client_id' => '',
    'client_secret' => '',
    'service_account' => '',
    'bot_id' => '',
    'room_id' => '',

    // Store the real private key outside Git.
    'private_key_path' => __DIR__ . '/secrets/private.key',

    'form_stock_path' => __DIR__ . '/storage/forms.json',
    'form_import_csv_path' => __DIR__ . '/storage/import/forms.csv',
    'form_import_processed_dir' => __DIR__ . '/storage/import/processed',
    'form_import_failed_dir' => __DIR__ . '/storage/import/failed',
    'form_low_stock_threshold' => 10,
    'form_low_stock_room_id' => '',
];
