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

register_test('state store persists pending quiet and Google retry records', static function (): void {
    $dir = tempDir();
    $store = new StateStore($dir . '/state.json');
    $store->markPendingUnknown('2026-07-05T12:00:00', [
        'dedupe_key' => '2026-07-05T12:00:00|UNKNOWN',
        'earthquake_time' => '2026-07-05T12:00:00',
        'hypocenter_name' => 'UNKNOWN',
        'max_scale' => 45,
        'first_seen_at' => gmdate('c'),
    ]);
    $store->saveGoogleFormForEarthquakeTime('2026-07-05T12:00:00', ['form_id' => 'saved-copy-id']);
    $store->setQuietBatch(['window_end' => '2026-07-05T06:00:00+09:00']);
    $store->save();

    $reloaded = new StateStore($dir . '/state.json');
    assertTrue($reloaded->getPendingUnknown('2026-07-05T12:00:00') !== null, 'state store must persist pending UNKNOWN records.');
    assertTrue(($reloaded->googleFormForEarthquakeTime('2026-07-05T12:00:00')['form_id'] ?? '') === 'saved-copy-id', 'state store must persist a partial Google preparation for retry.');
    assertTrue(($reloaded->quietBatch()['window_end'] ?? '') === '2026-07-05T06:00:00+09:00', 'state store must persist quiet batches.');
});
