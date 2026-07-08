<?php
declare(strict_types=1);

register_test('stock out notice success is not repeated', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks)->run();
    assertTrue(count($lineWorks->messages) === 1, 'stock out notice must be sent once.');
    $state = readState($dir);
    $record = reset($state['skipped_due_to_form_stock_out']);
    assertTrue(($record['notice_status'] ?? null) === 'sent', 'stock out notice status must be sent.');

    buildChecker($dir, $lineWorks)->run();
    assertTrue(count($lineWorks->messages) === 1, 'sent stock out notice must not be repeated.');
});

register_test('form CSV unexpected failure does not stop earthquake check', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $processedBlocker = $dir . '/processed.blocker';
    file_put_contents($processedBlocker, 'blocker');
    mkdir($dir . '/failed', 0775, true);
    file_put_contents($dir . '/forms.csv', implode(PHP_EOL, [
        'url',
        'https://example.invalid/imported-form',
    ]) . PHP_EOL);

    $config = Config::load(writeConfig($dir, [
        'form_import_processed_dir' => $processedBlocker,
    ]));
    $checker = new EarthquakeChecker(
        $config,
        new FakeP2PQuakeClient([namedEarthquakeEvent()]),
        $lineWorks,
        new StateStore($dir . '/state.json'),
        new FormStockStore(
            $dir . '/forms.json',
            $dir . '/forms.csv',
            $processedBlocker,
            $dir . '/failed'
        ),
        new Logger($dir . '/app.log')
    );

    $checker->run();

    $failureMessages = array_values(array_filter($lineWorks->messages, static function (array $message): bool {
        return $message['room_id'] === 'maintenance-room-id' && str_contains($message['text'], '予期しないエラー');
    }));
    $maintenanceMessages = array_values(array_filter($lineWorks->messages, static function (array $message): bool {
        return $message['room_id'] === 'maintenance-room-id';
    }));

    assertTrue(mainMessageCount($lineWorks) === 1, 'unexpected form CSV failure must not stop the main safety notification.');
    assertTrue(count($failureMessages) === 1, 'unexpected form CSV failure must notify the maintenance room.');
    assertTrue(count($maintenanceMessages) >= 1, 'unexpected form CSV failure must send at least one maintenance message.');

    $forms = json_decode((string) file_get_contents($dir . '/forms.json'), true);
    assertTrue(($forms['forms'][0]['status'] ?? null) === 'used', 'imported form must still be consumed after the earthquake notification succeeds.');
});

register_test('stock out notice failure is retried without body notification', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $lineWorks->failMaintenance = true;

    buildChecker($dir, $lineWorks)->run();
    assertTrue(count($lineWorks->messages) === 1, 'failed stock out notice must be attempted once.');
    $state = readState($dir);
    $record = reset($state['skipped_due_to_form_stock_out']);
    assertTrue(($record['notice_status'] ?? null) === 'failed', 'stock out notice status must be failed.');

    $lineWorks->failMaintenance = false;
    buildChecker($dir, $lineWorks)->run();
    assertTrue(count($lineWorks->messages) === 2, 'failed stock out notice must be retried once.');
    foreach ($lineWorks->messages as $message) {
        assertTrue($message['room_id'] === 'maintenance-room-id', 'stock out scenario must not send the main safety message.');
    }

    $state = readState($dir);
    $record = reset($state['skipped_due_to_form_stock_out']);
    assertTrue(($record['notice_status'] ?? null) === 'sent', 'retried stock out notice status must be sent.');
});

register_test('stock out notice failure is retried after event disappears', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $lineWorks->failMaintenance = true;

    buildChecker($dir, $lineWorks, [earthquakeEvent()])->run();
    assertTrue(count($lineWorks->messages) === 1, 'failed stock out notice must be attempted once.');

    $lineWorks->failMaintenance = false;
    buildChecker($dir, $lineWorks, [])->run();
    assertTrue(count($lineWorks->messages) === 2, 'failed stock out notice must be retried even when the event disappeared.');
    assertTrue($lineWorks->messages[1]['room_id'] === 'maintenance-room-id', 'retry after disappeared event must be maintenance-only.');

    writeAvailableForms($dir);
    buildChecker($dir, $lineWorks, [])->run();
    assertTrue(count($lineWorks->messages) === 2, 'sent stock out notice retry must not be repeated.');

    $state = readState($dir);
    $record = reset($state['skipped_due_to_form_stock_out']);
    assertTrue(($record['notice_status'] ?? null) === 'sent', 'retried disappeared-event notice status must be sent.');
});

register_test('stock out skipped event is not sent after forms are replenished', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $lineWorks->failMaintenance = true;

    buildChecker($dir, $lineWorks, [earthquakeEvent()])->run();
    writeAvailableForms($dir);

    $lineWorks->failMaintenance = false;
    buildChecker($dir, $lineWorks, [earthquakeEvent()])->run();

    assertTrue(count($lineWorks->messages) === 2, 'stock out skipped event must not be sent as a body notification after forms are replenished.');
    foreach ($lineWorks->messages as $message) {
        assertTrue($message['room_id'] === 'maintenance-room-id', 'replenished stock out retry must remain maintenance-only.');
    }
});

register_test('UNKNOWN hypocenter initial pending', static function (): void {
    $dir = tempDir();
    writeAvailableForms($dir);
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent()])->run();

    assertTrue(mainMessageCount($lineWorks) === 0, 'initial UNKNOWN hypocenter must not send the main safety message.');
    $state = readState($dir);
    assertTrue(isset($state['pending_unknown_by_earthquake_time']['2026-07-05T12:00:00']), 'initial UNKNOWN hypocenter must be stored as pending.');
    assertTrue($state['notified'] === [], 'initial UNKNOWN hypocenter must not be marked as notified.');
});

register_test('UNKNOWN hypocenter fallback after hold', static function (): void {
    $dir = tempDir();
    writeAvailableForms($dir, 2);
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent()])->run();
    $state = readState($dir);
    $state['pending_unknown_by_earthquake_time']['2026-07-05T12:00:00']['first_seen_at'] = gmdate('c', time() - 601);
    writeState($dir, $state);

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'expired UNKNOWN pending must send one fallback main safety message.');

    $state = readState($dir);
    assertTrue($state['notified'] !== [], 'expired UNKNOWN fallback must be marked as notified.');
    assertTrue(!isset($state['pending_unknown_by_earthquake_time']['2026-07-05T12:00:00']), 'expired UNKNOWN fallback must clear pending state after notification.');

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'notified UNKNOWN fallback must not be sent again.');
});

register_test('named hypocenter replaces pending UNKNOWN', static function (): void {
    $dir = tempDir();
    writeAvailableForms($dir, 2);
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent('unknown-event-1', '2026-07-05T12:30:00')])->run();
    buildChecker($dir, $lineWorks, [namedEarthquakeEvent('named-event-1', '2026-07-05T12:30:00', 'Named Hypocenter')])->run();

    assertTrue(mainMessageCount($lineWorks) === 1, 'named hypocenter continuation must send exactly one main safety message.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], 'Named Hypocenter'), 'named hypocenter continuation must use the named hypocenter candidate.');
    assertTrue(!str_contains($lineWorks->messages[0]['text'], 'UNKNOWN'), 'named hypocenter continuation must not send the UNKNOWN candidate.');
});

register_test('notified earthquake is not repeated', static function (): void {
    $dir = tempDir();
    writeAvailableForms($dir, 2);
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'first named earthquake must send one main safety message.');

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'notified earthquake must not be sent again.');
});

register_test('invalid state JSON stops', static function (): void {
    $dir = tempDir();
    writeAvailableForms($dir);
    file_put_contents($dir . '/state.json', '{invalid json');
    $lineWorks = new FakeLineWorksClient();

    try {
        buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    } catch (RuntimeException) {
        assertTrue(mainMessageCount($lineWorks) === 0, 'invalid state.json must stop before sending the main safety message.');
        return;
    }

    throw new RuntimeException('invalid state.json must not be treated as an empty state.');
});

register_test('invalid forms JSON stops', static function (): void {
    $dir = tempDir();
    file_put_contents($dir . '/forms.json', '{invalid json');
    $lineWorks = new FakeLineWorksClient();

    try {
        buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    } catch (RuntimeException) {
        assertTrue(count($lineWorks->messages) === 0, 'invalid forms.json must stop before any LINE WORKS message.');
        return;
    }

    throw new RuntimeException('invalid forms.json must not be treated as empty stock.');
});
