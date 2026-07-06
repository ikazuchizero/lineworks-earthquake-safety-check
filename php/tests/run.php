<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/StateStore.php';
require_once __DIR__ . '/../src/FormStockStore.php';
require_once __DIR__ . '/../src/P2PQuakeClient.php';
require_once __DIR__ . '/../src/LineWorksClient.php';
require_once __DIR__ . '/../src/EarthquakeChecker.php';
require_once __DIR__ . '/../src/SetupChecker.php';
require_once __DIR__ . '/../src/ConnectivityChecker.php';
require_once __DIR__ . '/../src/ErrorNotificationStore.php';
require_once __DIR__ . '/../src/FailureNotifier.php';
require_once __DIR__ . '/../src/HealthChecker.php';

final class FakeP2PQuakeClient extends P2PQuakeClient
{
    /** @param array<int, array<string, mixed>> $events */
    public function __construct(private array $events)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchEarthquakes(): array
    {
        return $this->events;
    }
}

final class FakeLineWorksClient extends LineWorksClient
{
    /** @var array<int, array{room_id: string|null, text: string}> */
    public array $messages = [];
    public bool $failMaintenance = false;

    public function __construct()
    {
    }

    public function sendMessage(string $text, ?string $roomId = null): void
    {
        $this->messages[] = [
            'room_id' => $roomId,
            'text' => $text,
        ];

        if ($this->failMaintenance && $roomId !== null) {
            throw new RuntimeException('fake maintenance send failure');
        }
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tempDir(): string
{
    $dir = sys_get_temp_dir() . '/lineworks_earthquake_test_' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0775, true)) {
        throw new RuntimeException('Failed to create temporary test directory.');
    }

    return $dir;
}

/** @param array<string, mixed> $overrides */
function writeConfig(string $dir, array $overrides = []): string
{
    $privateKeyPath = $dir . '/private.key';
    file_put_contents($privateKeyPath, 'dummy-key');

    $config = array_merge([
        'notify_scale' => 45,
        'unknown_hypocenter_hold_seconds' => 600,
        'p2pquake_api_url' => 'https://example.invalid/quake',
        'lineworks_token_url' => 'https://example.invalid/token',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'service_account' => 'service-account',
        'bot_id' => 'bot-id',
        'room_id' => 'room-id',
        'private_key_path' => $privateKeyPath,
        'form_stock_enabled' => true,
        'form_url' => '',
        'form_stock_path' => $dir . '/forms.json',
        'form_import_csv_path' => $dir . '/forms.csv',
        'form_import_processed_dir' => $dir . '/processed',
        'form_import_failed_dir' => $dir . '/failed',
        'form_low_stock_threshold' => 10,
        'form_low_stock_room_id' => 'maintenance-room-id',
    ], $overrides);

    $path = $dir . '/config.php';
    file_put_contents($path, '<?php return ' . var_export($config, true) . ';' . PHP_EOL);

    return $path;
}

function createRuntimeDirs(string $dir): void
{
    foreach ([
        $dir . '/storage',
        $dir . '/forms',
        $dir . '/processed',
        $dir . '/failed',
    ] as $path) {
        if (!is_dir($path) && !mkdir($path, 0775, true)) {
            throw new RuntimeException('Failed to create runtime test directory.');
        }
    }
}

function loadConfigForTest(array $overrides = []): Config
{
    $dir = tempDir();
    return Config::load(writeConfig($dir, $overrides));
}

function expectConfigFailure(array $overrides, string $message): void
{
    try {
        loadConfigForTest($overrides);
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

function testNotifyScaleValidation(): void
{
    loadConfigForTest(['notify_scale' => 0]);
    loadConfigForTest(['notify_scale' => 45]);
    expectConfigFailure(['notify_scale' => -1], 'negative notify_scale must fail.');
    expectConfigFailure(['notify_scale' => 45.5], 'decimal notify_scale must fail.');
    expectConfigFailure(['notify_scale' => 999], 'unknown notify_scale must fail.');
}

function testPlaceholderValidation(): void
{
    expectConfigFailure(
        ['form_low_stock_room_id' => 'REPLACE_WITH_FORM_LOW_STOCK_ROOM_ID'],
        'placeholder form_low_stock_room_id must fail.'
    );
    expectConfigFailure(
        ['client_id' => 'REPLACE_WITH_CLIENT_ID'],
        'placeholder client_id must fail.'
    );
    expectConfigFailure(
        [
            'form_stock_enabled' => false,
            'form_url' => 'REPLACE_WITH_FORM_URL',
        ],
        'placeholder form_url must fail when form stock is disabled.'
    );
}

function testLastHttpStatusCodeWins(): void
{
    $lineWorks = (new ReflectionClass(LineWorksClient::class))->newInstanceWithoutConstructor();
    $lineWorksMethod = new ReflectionMethod(LineWorksClient::class, 'statusCodeFromHeaders');
    $lineWorksMethod->setAccessible(true);
    assertTrue(
        $lineWorksMethod->invoke($lineWorks, ['HTTP/1.1 100 Continue', 'HTTP/1.1 200 OK']) === 200,
        'LINE WORKS status parser must use the last HTTP status line.'
    );

    $p2p = (new ReflectionClass(P2PQuakeClient::class))->newInstanceWithoutConstructor();
    $p2pMethod = new ReflectionMethod(P2PQuakeClient::class, 'statusCodeFromHeaders');
    $p2pMethod->setAccessible(true);
    assertTrue(
        $p2pMethod->invoke($p2p, ['HTTP/1.1 302 Found', 'HTTP/1.1 200 OK']) === 200,
        'P2PQuake status parser must use the last HTTP status line.'
    );
}

/** @return array<string, mixed> */
function earthquakeEvent(string $id = 'event-1'): array
{
    return [
        'id' => $id,
        'earthquake' => [
            'time' => '2026-07-05T12:00:00',
            'maxScale' => 45,
            'hypocenter' => [
                'name' => 'Test Hypocenter',
            ],
        ],
    ];
}

/** @return array<string, mixed> */
function unknownEarthquakeEvent(string $id = 'unknown-event-1', string $time = '2026-07-05T12:00:00'): array
{
    return [
        'id' => $id,
        'earthquake' => [
            'time' => $time,
            'maxScale' => 45,
            'hypocenter' => [
                'name' => '',
            ],
        ],
    ];
}

/** @return array<string, mixed> */
function namedEarthquakeEvent(string $id = 'named-event-1', string $time = '2026-07-05T12:00:00', string $name = 'Named Hypocenter'): array
{
    return [
        'id' => $id,
        'earthquake' => [
            'time' => $time,
            'maxScale' => 45,
            'hypocenter' => [
                'name' => $name,
            ],
        ],
    ];
}

/** @param array<int, array<string, mixed>>|null $events */
function buildChecker(string $dir, FakeLineWorksClient $lineWorks, ?array $events = null): EarthquakeChecker
{
    $config = Config::load(writeConfig($dir));
    $stateStore = new StateStore($dir . '/state.json');
    $formStockStore = new FormStockStore(
        $dir . '/forms.json',
        $dir . '/forms.csv',
        $dir . '/processed',
        $dir . '/failed'
    );

    return new EarthquakeChecker(
        $config,
        new FakeP2PQuakeClient($events ?? [earthquakeEvent()]),
        $lineWorks,
        $stateStore,
        $formStockStore,
        new Logger($dir . '/app.log')
    );
}

function readState(string $dir): array
{
    $json = file_get_contents($dir . '/state.json');
    assertTrue($json !== false, 'state.json must exist.');
    $state = json_decode($json, true);
    assertTrue(is_array($state), 'state.json must decode to an array.');

    return $state;
}

function writeState(string $dir, array $state): void
{
    file_put_contents($dir . '/state.json', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
}

function writeAvailableForms(string $dir, int $count = 1): void
{
    $forms = [];
    for ($i = 1; $i <= $count; $i++) {
        $forms[] = [
            'url' => 'https://example.invalid/form/' . $i,
            'status' => 'available',
            'imported_at' => gmdate('c'),
            'used_at' => null,
            'dedupe_key' => null,
        ];
    }

    file_put_contents($dir . '/forms.json', json_encode([
        'forms' => $forms,
        'low_stock_notified' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
}

function mainMessageCount(FakeLineWorksClient $lineWorks): int
{
    $count = 0;
    foreach ($lineWorks->messages as $message) {
        if ($message['room_id'] === null) {
            $count++;
        }
    }

    return $count;
}

function testStockOutNoticeSuccessIsNotRepeated(): void
{
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks)->run();
    assertTrue(count($lineWorks->messages) === 1, 'stock out notice must be sent once.');
    $state = readState($dir);
    $record = reset($state['skipped_due_to_form_stock_out']);
    assertTrue(($record['notice_status'] ?? null) === 'sent', 'stock out notice status must be sent.');

    buildChecker($dir, $lineWorks)->run();
    assertTrue(count($lineWorks->messages) === 1, 'sent stock out notice must not be repeated.');
}

function testStockOutNoticeFailureIsRetriedWithoutBodyNotification(): void
{
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
}

function testStockOutNoticeFailureIsRetriedAfterEventDisappears(): void
{
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
}

function testStockOutSkippedEventIsNotSentAfterFormsAreReplenished(): void
{
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
}

function testUnknownHypocenterInitialPending(): void
{
    $dir = tempDir();
    writeAvailableForms($dir);
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent()])->run();

    assertTrue(mainMessageCount($lineWorks) === 0, 'initial UNKNOWN hypocenter must not send the main safety message.');
    $state = readState($dir);
    assertTrue(isset($state['pending_unknown_by_earthquake_time']['2026-07-05T12:00:00']), 'initial UNKNOWN hypocenter must be stored as pending.');
    assertTrue($state['notified'] === [], 'initial UNKNOWN hypocenter must not be marked as notified.');
}

function testUnknownHypocenterFallbackAfterHold(): void
{
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
}

function testNamedHypocenterReplacesPendingUnknown(): void
{
    $dir = tempDir();
    writeAvailableForms($dir, 2);
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent('unknown-event-1', '2026-07-05T12:30:00')])->run();
    buildChecker($dir, $lineWorks, [namedEarthquakeEvent('named-event-1', '2026-07-05T12:30:00', 'Named Hypocenter')])->run();

    assertTrue(mainMessageCount($lineWorks) === 1, 'named hypocenter continuation must send exactly one main safety message.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], 'Named Hypocenter'), 'named hypocenter continuation must use the named hypocenter candidate.');
    assertTrue(!str_contains($lineWorks->messages[0]['text'], 'UNKNOWN'), 'named hypocenter continuation must not send the UNKNOWN candidate.');
}

function testNotifiedEarthquakeIsNotRepeated(): void
{
    $dir = tempDir();
    writeAvailableForms($dir, 2);
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'first named earthquake must send one main safety message.');

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'notified earthquake must not be sent again.');
}

function testInvalidStateJsonStops(): void
{
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
}

function testInvalidFormsJsonStops(): void
{
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
}

function testSetupCheckerSuccess(): void
{
    $dir = tempDir();
    createRuntimeDirs($dir);
    writeConfig($dir);

    $checker = new SetupChecker($dir);
    $results = $checker->run();

    assertTrue($checker->isOk($results), 'setup checker must pass when required files and directories exist.');
}

function testSetupCheckerMissingConfig(): void
{
    $dir = tempDir();
    createRuntimeDirs($dir);

    $checker = new SetupChecker($dir);
    $results = $checker->run();

    assertTrue(!$checker->isOk($results), 'setup checker must fail when config.php is missing.');
    assertTrue(($results[0]['status'] ?? null) === 'NG', 'missing config.php must be reported as NG.');
}

function testSetupCheckerMissingDirectory(): void
{
    $dir = tempDir();
    writeConfig($dir);
    mkdir($dir . '/storage', 0775, true);

    $checker = new SetupChecker($dir);
    $results = $checker->run();
    $byName = [];
    foreach ($results as $result) {
        $byName[$result['name']] = $result;
    }

    assertTrue(($byName['forms_dir']['status'] ?? null) === 'NG', 'setup checker must fail when forms directory is missing.');
}

function testSetupCheckerNotWritableDirectory(): void
{
    $dir = tempDir();
    createRuntimeDirs($dir);
    writeConfig($dir);
    chmod($dir . '/storage', 0555);
    clearstatcache(true, $dir . '/storage');

    if (is_writable($dir . '/storage')) {
        chmod($dir . '/storage', 0775);
        return;
    }

    $checker = new SetupChecker($dir);
    $results = $checker->run();
    chmod($dir . '/storage', 0775);

    $byName = [];
    foreach ($results as $result) {
        $byName[$result['name']] = $result;
    }

    assertTrue(($byName['storage_dir']['reason'] ?? null) === 'not_writable', 'setup checker must detect not writable storage directory when the OS enforces permissions.');
}

function testConnectivityCheckerUsesShortTestMessage(): void
{
    $lineWorks = new FakeLineWorksClient();
    $checker = new ConnectivityChecker($lineWorks, 'maintenance-room-id');

    $checker->run();

    assertTrue(count($lineWorks->messages) === 1, 'connectivity checker must send one test message.');
    assertTrue($lineWorks->messages[0]['room_id'] === 'maintenance-room-id', 'connectivity checker must use the configured maintenance room.');
    assertTrue(!str_contains($lineWorks->messages[0]['text'], '【地震情報】'), 'connectivity checker must not send the earthquake safety message body.');
}

function testFailureNotifierSuppressesRepeatedError(): void
{
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $notifier = new FailureNotifier(
        $lineWorks,
        new ErrorNotificationStore($dir . '/error_notifications.json'),
        'maintenance-room-id'
    );

    $error = new RuntimeException('same failure');
    assertTrue($notifier->notify($error, 'check_failed') === true, 'first failure notification must be sent.');
    assertTrue($notifier->notify($error, 'check_failed') === false, 'same failure notification must be suppressed.');
    assertTrue(count($lineWorks->messages) === 1, 'same failure must not be sent repeatedly.');
}

function testFailureNotifierSkipsLineWorksError(): void
{
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $notifier = new FailureNotifier(
        $lineWorks,
        new ErrorNotificationStore($dir . '/error_notifications.json'),
        'maintenance-room-id'
    );

    assertTrue($notifier->notify(new RuntimeException('LINE WORKS message send failed.'), 'check_failed') === false, 'LINE WORKS failures must not trigger recursive LINE WORKS notifications.');
    assertTrue(count($lineWorks->messages) === 0, 'LINE WORKS failure notification must be skipped.');
}

function testHealthCheckerSummarizesLog(): void
{
    $dir = tempDir();
    $now = date('c');
    file_put_contents($dir . '/app.log', implode(PHP_EOL, [
        '[' . $now . '] INFO check_completed',
        '[' . $now . '] INFO form_low_stock_notice_sent {"available_count":1}',
        '[' . $now . '] ERROR Check failed. {"error":"safe"}',
    ]) . PHP_EOL);

    $checker = new HealthChecker($dir . '/app.log');
    $summary = $checker->summarize(7);
    $message = $checker->message($summary);

    assertTrue($summary['normal_log'] === 1, 'health checker must count normal log lines.');
    assertTrue($summary['warning'] === 1, 'health checker must count warning-like lines.');
    assertTrue($summary['error'] === 1, 'health checker must count error lines.');
    assertTrue(str_contains($message, 'normal_log: 1件'), 'health check message must include normal log count.');
    assertTrue(str_contains($message, 'error: 1件'), 'health check message must include error count.');
}

$tests = [
    'notify_scale validation' => 'testNotifyScaleValidation',
    'placeholder validation' => 'testPlaceholderValidation',
    'last HTTP status code wins' => 'testLastHttpStatusCodeWins',
    'stock out notice success is not repeated' => 'testStockOutNoticeSuccessIsNotRepeated',
    'stock out notice failure is retried without body notification' => 'testStockOutNoticeFailureIsRetriedWithoutBodyNotification',
    'stock out notice failure is retried after event disappears' => 'testStockOutNoticeFailureIsRetriedAfterEventDisappears',
    'stock out skipped event is not sent after forms are replenished' => 'testStockOutSkippedEventIsNotSentAfterFormsAreReplenished',
    'UNKNOWN hypocenter initial pending' => 'testUnknownHypocenterInitialPending',
    'UNKNOWN hypocenter fallback after hold' => 'testUnknownHypocenterFallbackAfterHold',
    'named hypocenter replaces pending UNKNOWN' => 'testNamedHypocenterReplacesPendingUnknown',
    'notified earthquake is not repeated' => 'testNotifiedEarthquakeIsNotRepeated',
    'invalid state JSON stops' => 'testInvalidStateJsonStops',
    'invalid forms JSON stops' => 'testInvalidFormsJsonStops',
    'setup checker success' => 'testSetupCheckerSuccess',
    'setup checker missing config' => 'testSetupCheckerMissingConfig',
    'setup checker missing directory' => 'testSetupCheckerMissingDirectory',
    'setup checker not writable directory' => 'testSetupCheckerNotWritableDirectory',
    'connectivity checker uses short test message' => 'testConnectivityCheckerUsesShortTestMessage',
    'failure notifier suppresses repeated error' => 'testFailureNotifierSuppressesRepeatedError',
    'failure notifier skips LINE WORKS error' => 'testFailureNotifierSkipsLineWorksError',
    'health checker summarizes log' => 'testHealthCheckerSummarizesLog',
];

foreach ($tests as $name => $test) {
    $test();
    echo 'OK: ' . $name . PHP_EOL;
}

echo 'ALL TESTS PASSED' . PHP_EOL;
