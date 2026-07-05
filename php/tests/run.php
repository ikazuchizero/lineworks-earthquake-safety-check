<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/StateStore.php';
require_once __DIR__ . '/../src/FormStockStore.php';
require_once __DIR__ . '/../src/P2PQuakeClient.php';
require_once __DIR__ . '/../src/LineWorksClient.php';
require_once __DIR__ . '/../src/EarthquakeChecker.php';

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

function writeAvailableForms(string $dir): void
{
    file_put_contents($dir . '/forms.json', json_encode([
        'forms' => [
            [
                'url' => 'https://example.invalid/form',
                'status' => 'available',
                'imported_at' => gmdate('c'),
                'used_at' => null,
                'dedupe_key' => null,
            ],
        ],
        'low_stock_notified' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
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

$tests = [
    'notify_scale validation' => 'testNotifyScaleValidation',
    'placeholder validation' => 'testPlaceholderValidation',
    'last HTTP status code wins' => 'testLastHttpStatusCodeWins',
    'stock out notice success is not repeated' => 'testStockOutNoticeSuccessIsNotRepeated',
    'stock out notice failure is retried without body notification' => 'testStockOutNoticeFailureIsRetriedWithoutBodyNotification',
    'stock out notice failure is retried after event disappears' => 'testStockOutNoticeFailureIsRetriedAfterEventDisappears',
    'stock out skipped event is not sent after forms are replenished' => 'testStockOutSkippedEventIsNotSentAfterFormsAreReplenished',
];

foreach ($tests as $name => $test) {
    $test();
    echo 'OK: ' . $name . PHP_EOL;
}

echo 'ALL TESTS PASSED' . PHP_EOL;
