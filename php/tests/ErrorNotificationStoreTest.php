<?php
declare(strict_types=1);

register_test('error notification store suppresses repeated fingerprint', static function (): void {
    $dir = tempDir();
    $store = new ErrorNotificationStore($dir . '/error_notifications.json');

    assertTrue($store->shouldNotify('fingerprint-a', 3600), 'first fingerprint must be notifiable.');
    $store->markNotified('fingerprint-a');
    assertTrue(!$store->shouldNotify('fingerprint-a', 3600), 'same fingerprint must be suppressed within the interval.');
    assertTrue($store->shouldNotify('fingerprint-b', 3600), 'different fingerprint must remain notifiable.');
});

register_test('error notification store rejects invalid JSON', static function (): void {
    $dir = tempDir();
    file_put_contents($dir . '/error_notifications.json', '{invalid json');
    $store = new ErrorNotificationStore($dir . '/error_notifications.json');

    try {
        $store->shouldNotify('fingerprint-a', 3600);
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException('error notification store must reject invalid JSON.');
});
