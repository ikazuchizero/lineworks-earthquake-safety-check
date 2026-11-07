<?php
declare(strict_types=1);

register_test('UNKNOWN hypocenter initial pending', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent()])->run();

    assertTrue(mainMessageCount($lineWorks) === 0, 'initial UNKNOWN hypocenter must not send the main safety message.');
    $state = readState($dir);
    assertTrue(isset($state['pending_unknown_by_earthquake_time']['2026-07-05T12:00:00']), 'initial UNKNOWN hypocenter must be stored as pending.');
    assertTrue($state['notified'] === [], 'initial UNKNOWN hypocenter must not be marked as notified.');
});

register_test('quiet UNKNOWN waits through quiet end and hold before notification', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $eventDate = '2026-11-07';
    $event = unknownEarthquakeEvent('quiet-unknown', $eventDate . 'T05:59:00');
    $atCapture = new DateTimeImmutable($eventDate . 'T05:59:00+09:00');
    $atQuietEnd = new DateTimeImmutable($eventDate . 'T06:00:00+09:00');
    $afterHold = new DateTimeImmutable($eventDate . 'T06:10:00+09:00');

    buildChecker($dir, $lineWorks, [$event], [], null, $atCapture)->run();
    $state = readState($dir);
    assertTrue(mainMessageCount($lineWorks) === 0, 'quiet UNKNOWN capture must not send LINE WORKS.');
    assertTrue(($state['google_forms_by_earthquake_time'] ?? []) === [], 'quiet UNKNOWN capture must not copy a Google Form.');
    assertTrue(isset($state['pending_unknown_by_earthquake_time'][$eventDate . 'T05:59:00']), 'quiet UNKNOWN capture must persist pending state.');
    assertTrue(($state['quiet_batch']['candidate']['hypocenter_name'] ?? '') === 'UNKNOWN', 'quiet UNKNOWN capture must retain the quiet candidate.');

    buildChecker($dir, $lineWorks, [$event], [], null, $atQuietEnd)->run();
    assertTrue(mainMessageCount($lineWorks) === 0, 'quiet UNKNOWN must remain pending before the hold expires after quiet end.');
    assertTrue(readState($dir)['quiet_batch'] !== null, 'quiet UNKNOWN batch must remain for a later retry.');

    buildChecker($dir, $lineWorks, [$event], [], null, $afterHold)->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'quiet UNKNOWN must send after both quiet end and hold expiry.');
});

register_test('quiet named continuation replaces same-time same-scale UNKNOWN', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $eventDate = '2026-11-07';
    $unknown = unknownEarthquakeEvent('quiet-unknown-replaced', $eventDate . 'T05:59:00');
    $named = namedEarthquakeEvent('quiet-named-continuation', $eventDate . 'T05:59:00', 'Known Quiet Hypocenter', 45);

    buildChecker($dir, $lineWorks, [$unknown], [], null, new DateTimeImmutable($eventDate . 'T05:59:00+09:00'))->run();

    buildChecker($dir, $lineWorks, [$named], [], null, new DateTimeImmutable($eventDate . 'T06:00:00+09:00'))->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'named quiet continuation must send one main message.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], 'Known Quiet Hypocenter'), 'named quiet continuation must use the known hypocenter.');
    assertTrue(!str_contains($lineWorks->messages[0]['text'], 'UNKNOWN'), 'named quiet continuation must replace UNKNOWN.');
});

register_test('normal named continuation completes the matching quiet batch after send', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $eventDate = '2026-11-07';
    $unknown = unknownEarthquakeEvent('normal-path-unknown', $eventDate . 'T05:59:00');
    $named = namedEarthquakeEvent('normal-path-named', $eventDate . 'T05:59:00', 'Known Normal Path', 45);

    buildChecker($dir, $lineWorks, [$unknown], [], null, new DateTimeImmutable($eventDate . 'T05:59:00+09:00'))->run();
    buildChecker($dir, $lineWorks, [$named], ['quiet_hours_end' => 5], null, new DateTimeImmutable($eventDate . 'T06:00:00+09:00'))->run();

    assertTrue(mainMessageCount($lineWorks) === 1, 'normal named continuation must send one main message.');
    $state = readState($dir);
    assertTrue($state['quiet_batch'] === null, 'successful normal named continuation must clear the matching quiet batch.');
    assertTrue(($state['quiet_completed_through'] ?? '') === $eventDate . 'T06:00:00+09:00', 'successful normal named continuation must complete the quiet window.');

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent('next-night', '2026-11-08T04:00:00')], ['quiet_hours_end' => 5], null, new DateTimeImmutable('2026-11-08T04:00:00+09:00'))->run();
    $nextState = readState($dir);
    assertTrue(($nextState['quiet_batch']['candidate']['event_id'] ?? '') === 'next-night', 'the next quiet window must be capturable after the prior batch is completed.');
    assertTrue(mainMessageCount($lineWorks) === 1, 'the next quiet UNKNOWN must remain unnotified during the quiet window.');
});

register_test('quiet UNKNOWN pending and replacement survive StateStore reload', static function (): void {
    $dir = tempDir();
    $firstLineWorks = new FakeLineWorksClient();
    $eventDate = '2026-11-07';
    $event = unknownEarthquakeEvent('quiet-reload', $eventDate . 'T05:59:00');

    buildChecker($dir, $firstLineWorks, [$event], [], null, new DateTimeImmutable($eventDate . 'T05:59:00+09:00'))->run();

    $secondLineWorks = new FakeLineWorksClient();
    buildChecker($dir, $secondLineWorks, [$event], [], null, new DateTimeImmutable($eventDate . 'T06:00:00+09:00'))->run();
    assertTrue(mainMessageCount($secondLineWorks) === 0, 'reloaded pending UNKNOWN must remain held before expiry.');

    $thirdLineWorks = new FakeLineWorksClient();
    buildChecker($dir, $thirdLineWorks, [$event], [], null, new DateTimeImmutable($eventDate . 'T06:10:00+09:00'))->run();
    assertTrue(mainMessageCount($thirdLineWorks) === 1, 'reloaded pending UNKNOWN must send after expiry.');
    $finalState = readState($dir);
    assertTrue(!isset($finalState['pending_unknown_by_earthquake_time'][$eventDate . 'T05:59:00']), 'reloaded pending UNKNOWN must be cleared after notification.');
});

register_test('UNKNOWN hypocenter fallback after hold', static function (): void {
    $dir = tempDir();
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
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent('unknown-event-1', '2026-07-05T12:30:00')])->run();
    buildChecker($dir, $lineWorks, [namedEarthquakeEvent('named-event-1', '2026-07-05T12:30:00', 'Named Hypocenter')])->run();

    assertTrue(mainMessageCount($lineWorks) === 1, 'named hypocenter continuation must send exactly one main safety message.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], 'Named Hypocenter'), 'named hypocenter continuation must use the named hypocenter candidate.');
    assertTrue(!str_contains($lineWorks->messages[0]['text'], 'UNKNOWN'), 'named hypocenter continuation must not send the UNKNOWN candidate.');
});

register_test('notified earthquake is not repeated', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'first named earthquake must send one main safety message.');

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'notified earthquake must not be sent again.');
});

register_test('quiet events select the largest qualifying event and send one after the window', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $eventDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('-1 day')->format('Y-m-d');
    $events = [
        namedEarthquakeEvent('quiet-low', $eventDate . 'T03:00:00', 'Low', 50),
        namedEarthquakeEvent('quiet-new', $eventDate . 'T04:00:00', 'New', 55),
        namedEarthquakeEvent('quiet-high', $eventDate . 'T02:00:00', 'High', 60),
    ];

    buildChecker($dir, $lineWorks, $events)->run();

    assertTrue(mainMessageCount($lineWorks) === 1, 'ended quiet window must send exactly one main message.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], 'High'), 'quiet window must select the largest event independent of API order.');
    $state = readState($dir);
    assertTrue($state['quiet_batch'] === null, 'successful quiet delivery must clear the batch.');
    assertTrue(($state['quiet_completed_through'] ?? '') !== '', 'successful quiet delivery must complete the window.');
});

register_test('below-threshold quiet event does not become pending or a candidate', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('-1 day')->format('Y-m-d');

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent('quiet-below', $today . 'T03:00:00', 'Below', 40)])->run();

    assertTrue(mainMessageCount($lineWorks) === 0, 'below-threshold quiet event must not be sent.');
    if (is_file($dir . '/state.json')) {
        $state = readState($dir);
        assertTrue($state['quiet_batch'] === null, 'below-threshold quiet event must not create a batch.');
        assertTrue($state['pending_unknown_by_earthquake_time'] === [], 'quiet event must not enter UNKNOWN pending.');
    }
});

register_test('quiet delivery failure keeps the candidate for retry', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $lineWorks->failMain = true;
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('-1 day')->format('Y-m-d');

    try {
        buildChecker($dir, $lineWorks, [namedEarthquakeEvent('quiet-retry', $today . 'T03:00:00', 'Retry', 45)])->run();
    } catch (RuntimeException) {
    }
    assertTrue(count($lineWorks->attempts) === 1, 'quiet delivery failure must record one attempt.');
    assertTrue(count($lineWorks->messages) === 0, 'quiet delivery failure must not record a success.');
    assertTrue(readState($dir)['quiet_batch'] !== null, 'failed quiet delivery must retain its batch.');

    $lineWorks->failMain = false;
    buildChecker($dir, $lineWorks, [namedEarthquakeEvent('below-retry-noise', $today . 'T03:00:00', 'Below Retry Noise', 40)])->run();
    assertTrue(count($lineWorks->attempts) === 2, 'quiet delivery must make one retry attempt.');
    assertTrue(mainMessageCount($lineWorks) === 1, 'quiet delivery must record one successful message.');
    assertTrue(readState($dir)['quiet_batch'] === null, 'successful retry must clear the batch.');
});

register_test('quiet hours support a window crossing midnight', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $yesterday = $today->modify('-2 day')->format('Y-m-d');
    $todayDate = $today->modify('-1 day')->format('Y-m-d');

    buildChecker($dir, $lineWorks, [
        namedEarthquakeEvent('cross-midnight-late', $yesterday . 'T23:00:00', 'Late', 50),
        namedEarthquakeEvent('cross-midnight-early', $todayDate . 'T02:00:00', 'Early', 55),
    ], ['quiet_hours_start' => 22, 'quiet_hours_end' => 6])->run();

    assertTrue(mainMessageCount($lineWorks) === 1, 'cross-midnight quiet window must send one message.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], 'Early'), 'cross-midnight quiet window must compare both sides of midnight.');
});

register_test('quiet interval uses exact 0 and 6 boundaries', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('-1 day')->format('Y-m-d');

    buildChecker($dir, $lineWorks, [
        namedEarthquakeEvent('boundary-start', $today . 'T00:00:00', 'Quiet Start', 50),
        namedEarthquakeEvent('boundary-end', $today . 'T06:00:00', 'Daytime End', 50),
    ])->run();

    assertTrue(mainMessageCount($lineWorks) === 2, 'the 0:00 event must be quiet while the 6:00 event is a daytime notification.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], 'Quiet Start'), 'the quiet interval must include its start and exclude its end.');
    assertTrue(str_contains($lineWorks->messages[1]['text'], 'Daytime End'), 'the 6:00 event must be excluded from the quiet interval.');
});

register_test('normal quiet interval keeps the earthquake date for its end', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $yesterday = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('-1 day');
    $date = $yesterday->format('Y-m-d');

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent('past-window', $date . 'T03:00:00', 'Past Window', 50)])->run();

    assertTrue(str_starts_with((string) readState($dir)['quiet_completed_through'], $date . 'T06:00:00'), 'quiet window end must use the earthquake date.');
});

register_test('offset earthquake time is interpreted in Asia/Tokyo for quiet hours', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $yesterday = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('-1 day');
    $utcTime = $yesterday->format('Y-m-d') . 'T18:00:00Z';

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent('offset-quiet', $utcTime, 'Offset Quiet', 50)])->run();

    assertTrue(readState($dir)['quiet_completed_through'] !== null, '18:00Z must be recognized as 03:00 Asia/Tokyo quiet time.');
});

register_test('latest quiet window wins when an older window is returned first', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $yesterday = $today->modify('-2 day')->format('Y-m-d');
    $todayDate = $today->modify('-1 day')->format('Y-m-d');

    buildChecker($dir, $lineWorks, [
        namedEarthquakeEvent('old-window', $yesterday . 'T03:00:00', 'Old Window', 60),
        namedEarthquakeEvent('new-window', $todayDate . 'T04:00:00', 'New Window', 50),
    ])->run();

    assertTrue(str_contains($lineWorks->messages[0]['text'], 'New Window'), 'the newest quiet window must be selected independently of API order.');
});

register_test('equal quiet scales select the newer earthquake time', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('-1 day')->format('Y-m-d');

    buildChecker($dir, $lineWorks, [
        namedEarthquakeEvent('same-scale-old', $today . 'T02:00:00', 'Older', 50),
        namedEarthquakeEvent('same-scale-new', $today . 'T04:00:00', 'Newer', 50),
    ])->run();

    assertTrue(str_contains($lineWorks->messages[0]['text'], 'Newer'), 'equal scales must select the newer earthquake time.');
});

register_test('morning quiet delivery does not mix with daytime targets', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $yesterdayDate = $today->modify('-1 day')->format('Y-m-d');
    $stateStore = new StateStore($dir . '/state.json');
    $stateStore->setQuietBatch([
        'window_start' => $yesterdayDate . 'T00:00:00+09:00',
        'window_end' => $yesterdayDate . 'T06:00:00+09:00',
        'candidate' => [
            'event' => null,
            'event_id' => 'morning-quiet',
            'earthquake_time' => $yesterdayDate . 'T03:00:00',
            'hypocenter_name' => 'Morning Quiet',
            'max_scale' => 50,
            'dedupe_key' => $yesterdayDate . 'T03:00:00|Morning Quiet',
        ],
    ]);
    $stateStore->save();

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent('daytime', $today->format('Y-m-d') . 'T12:00:00', 'Daytime')])->run();

    assertTrue(mainMessageCount($lineWorks) === 2, 'morning quiet and daytime events must remain separate targets.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], 'Morning Quiet'), 'morning run must send the retained quiet candidate.');
    assertTrue(str_contains($lineWorks->messages[1]['text'], 'Daytime'), 'morning run must still send the daytime candidate.');
});

register_test('quiet event already in old notified state does not resurface', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    $event = namedEarthquakeEvent('old-notified', $today . 'T03:00:00', 'Old Notified', 50);
    writeState($dir, [
        'notified' => [
            $today . 'T03:00:00|Old Notified' => [
                'dedupe_key' => $today . 'T03:00:00|Old Notified',
                'earthquake_time' => $today . 'T03:00:00',
            ],
        ],
    ]);

    buildChecker($dir, $lineWorks, [$event])->run();

    assertTrue(mainMessageCount($lineWorks) === 0, 'legacy notified state must suppress a quiet event.');
});

register_test('completed quiet batch permits the next window', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $oldDate = $today->modify('-2 day')->format('Y-m-d');
    $nextDate = $today->modify('-1 day')->format('Y-m-d');
    $stateStore = new StateStore($dir . '/state.json');
    $stateStore->setQuietBatch([
        'window_start' => $oldDate . 'T00:00:00+09:00',
        'window_end' => $oldDate . 'T06:00:00+09:00',
        'candidate' => [
            'event_id' => 'old-quiet',
            'earthquake_time' => $oldDate . 'T03:00:00',
            'hypocenter_name' => 'Old Quiet',
            'max_scale' => 50,
            'dedupe_key' => $oldDate . 'T03:00:00|Old Quiet',
        ],
    ]);
    $stateStore->save();

    buildChecker($dir, $lineWorks, [])->run();
    $state = readState($dir);
    assertTrue($state['quiet_batch'] === null, 'successfully delivered quiet batch must be cleared.');
    assertTrue(str_starts_with((string) $state['quiet_completed_through'], $oldDate . 'T06:00:00'), 'delivered quiet window must complete through its end.');

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent('next-quiet', $nextDate . 'T03:00:00', 'Next Quiet', 50)])->run();

    assertTrue(mainMessageCount($lineWorks) === 2, 'each completed quiet window must send its own selected event.');
    $state = readState($dir);
    assertTrue(isset($state['notified'][$nextDate . 'T03:00:00|Next Quiet']), 'the next quiet candidate must be sent.');
    assertTrue($state['quiet_batch'] === null, 'sent next quiet batch must be cleared.');
});

register_test('invalid state JSON stops', static function (): void {
    $dir = tempDir();
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
