<?php
declare(strict_types=1);

// Copy this file to php/config.php and fill in real values there.
// Never commit php/config.php, tokens, room IDs, bot IDs, service account values,
// or private keys. This example intentionally contains no production secrets.
return [
    // P2PQuake/JMA intensity code threshold.
    // 0 is for test environments only so every fetched earthquake can be exercised.
    // Production should normally be 45 or higher, which corresponds to seismic
    // intensity 5-lower or above. Confirm the real threshold before release.
    'notify_scale' => 0,

    // P2PQuake endpoint. The client is constructed with limit=10 in check.php so
    // target earthquakes are not missed when a newer non-target report appears.
    'p2pquake_api_url' => 'https://api.p2pquake.net/v2/jma/quake',

    // LINE WORKS OAuth token endpoint.
    'lineworks_token_url' => 'https://auth.worksmobile.com/oauth2/v2.0/token',

    // LINE WORKS credentials and destination for safety-confirmation messages.
    // Put real values only in config.php.
    'client_id' => '',
    'client_secret' => '',
    'service_account' => '',
    'bot_id' => '',
    'room_id' => '',

    // Store the real private key outside Git. The file path is configured here,
    // but the private key content itself must live in php/secrets/private.key.
    'private_key_path' => __DIR__ . '/secrets/private.key',

    // Form stock database. Runtime data is written to forms.json and must not be
    // committed. Each available form URL is consumed at most once after send success.
    'form_stock_path' => __DIR__ . '/storage/forms.json',

    // CSV import path for non-engineer operators. Upload a CSV with header URL and
    // one form URL per row. Successful imports move to processed/, failures to failed/.
    'form_import_csv_path' => __DIR__ . '/storage/import/forms.csv',
    'form_import_processed_dir' => __DIR__ . '/storage/import/processed',
    'form_import_failed_dir' => __DIR__ . '/storage/import/failed',

    // Low-stock threshold is inclusive. With 10, a refill notice is sent when the
    // remaining available form count is 10 or less after forms were consumed.
    'form_low_stock_threshold' => 10,

    // Maintenance/refill notification room. This must be different from room_id so
    // form-stock problems do not get mixed into the safety-confirmation room.
    'form_low_stock_room_id' => '',
];
