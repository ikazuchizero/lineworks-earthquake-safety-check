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
    'form_url' => '',

    // Store the real private key outside Git.
    'private_key_path' => __DIR__ . '/secrets/private.key',
];
