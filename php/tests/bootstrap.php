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

/** @var array<string, callable> */
$GLOBALS['TEST_REGISTRY'] = [];

function register_test(string $name, callable $test): void
{
    $GLOBALS['TEST_REGISTRY'][$name] = $test;
}

/** @return array<string, callable> */
function registered_tests(): array
{
    return $GLOBALS['TEST_REGISTRY'];
}

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

/** @return array<string, string> */
function requiredPhpFileFixtures(): array
{
    return [
        'bin/check.php' => '<?php echo "check";' . PHP_EOL,
        'bin/setup_check.php' => '<?php echo "setup";' . PHP_EOL,
        'bin/connectivity_check.php' => '<?php echo "connectivity";' . PHP_EOL,
        'bin/health_check.php' => '<?php echo "health";' . PHP_EOL,
        'src/Config.php' => '<?php class ConfigFixture {}' . PHP_EOL,
        'src/EarthquakeChecker.php' => '<?php class EarthquakeCheckerFixture {}' . PHP_EOL,
        'src/LineWorksClient.php' => '<?php class LineWorksClientFixture {}' . PHP_EOL,
        'src/P2PQuakeClient.php' => '<?php class P2PQuakeClientFixture {}' . PHP_EOL,
        'src/StateStore.php' => '<?php class StateStoreFixture {}' . PHP_EOL,
        'src/FormStockStore.php' => '<?php class FormStockStoreFixture {}' . PHP_EOL,
        'src/SetupChecker.php' => '<?php class SetupCheckerFixture {}' . PHP_EOL,
        'src/ConnectivityChecker.php' => '<?php class ConnectivityCheckerFixture {}' . PHP_EOL,
        'src/HealthChecker.php' => '<?php class HealthCheckerFixture {}' . PHP_EOL,
        'src/FailureNotifier.php' => '<?php class FailureNotifierFixture {}' . PHP_EOL,
        'src/ErrorNotificationStore.php' => '<?php class ErrorNotificationStoreFixture {}' . PHP_EOL,
    ];
}

/** @param array<string, string> $overrides */
function createRequiredPhpFiles(string $dir, array $overrides = []): void
{
    foreach (array_merge(requiredPhpFileFixtures(), $overrides) as $relativePath => $contents) {
        $path = $dir . '/' . $relativePath;
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0775, true)) {
            throw new RuntimeException('Failed to create PHP fixture directory.');
        }

        file_put_contents($path, $contents);
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
