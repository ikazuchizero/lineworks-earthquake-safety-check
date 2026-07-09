<?php
declare(strict_types=1);

register_test('state store tracks notified earthquakes', static function (): void {
    $dir = tempDir();
    $store = new StateStore($dir . '/state.json');

    $store->markNotified('2026-07-05T12:00:00|Named Hypocenter', [
        'earthquake_time' => '2026-07-05T12:00:00',
        'hypocenter_name' => 'Named Hypocenter',
    ]);
    $store->markNotifiedByEarthquakeTime('2026-07-05T12:00:00', [
        'dedupe_key' => '2026-07-05T12:00:00|Named Hypocenter',
    ]);
    $store->save();

    $reloaded = new StateStore($dir . '/state.json');
    assertTrue($reloaded->has('2026-07-05T12:00:00|Named Hypocenter'), 'state store must persist notified dedupe keys.');
    assertTrue($reloaded->hasNotifiedEarthquakeTime('2026-07-05T12:00:00'), 'state store must persist notified earthquake times.');
});

register_test('state store exposes retry and pending records', static function (): void {
    $dir = tempDir();
    $store = new StateStore($dir . '/state.json');

    $store->markSkippedDueToFormStockOut('2026-07-05T12:00:00|UNKNOWN', [
        'earthquake_time' => '2026-07-05T12:00:00',
        'hypocenter_name' => 'UNKNOWN',
        'max_scale' => 45,
        'notice_status' => 'pending',
        'skipped_at' => gmdate('c'),
        'reason' => 'form_stock_out',
    ]);
    $store->markStockOutNoticeResult('2026-07-05T12:00:00|UNKNOWN', 'failed', 'safe error');
    $store->markPendingUnknown('2026-07-05T12:00:00', [
        'dedupe_key' => '2026-07-05T12:00:00|UNKNOWN',
        'earthquake_time' => '2026-07-05T12:00:00',
        'hypocenter_name' => 'UNKNOWN',
        'max_scale' => 45,
        'first_seen_at' => gmdate('c'),
    ]);
    $store->markStockOutReminderAlerted('2026-07-05T13:00:00Z');
    $store->save();

    $reloaded = new StateStore($dir . '/state.json');
    $retryRecords = $reloaded->stockOutNoticeRetryRecords();
    $record = $reloaded->skippedDueToFormStockOutRecord('missing-key', '2026-07-05T12:00:00');

    assertTrue(isset($retryRecords['2026-07-05T12:00:00|UNKNOWN']), 'state store must expose failed stock out notices for retry.');
    assertTrue(($record['notice_status'] ?? null) === 'failed', 'state store must keep the latest stock out notice status.');
    assertTrue($reloaded->getPendingUnknown('2026-07-05T12:00:00') !== null, 'state store must persist pending UNKNOWN records.');
    assertTrue($reloaded->stockOutReminderLastAlertedAt() === '2026-07-05T13:00:00Z', 'state store must persist idle stock reminder timestamps.');
});
