<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/StateStore.php';
require_once __DIR__ . '/../src/GoogleFormsClient.php';
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
    if (isset($GLOBALS['TEST_REGISTRY'][$name])) {
        throw new RuntimeException('Duplicate test name: ' . $name);
    }

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
    /** @var array<int, array{room_id: string|null, text: string}> */
    public array $attempts = [];
    public bool $failMaintenance = false;
    public bool $failMain = false;

    public function __construct()
    {
    }

    public function sendMessage(string $text, ?string $roomId = null): void
    {
        $message = [
            'room_id' => $roomId,
            'text' => $text,
        ];
        $this->attempts[] = $message;

        if ($this->failMaintenance && $roomId !== null) {
            throw new RuntimeException('fake maintenance send failure');
        }
        if ($this->failMain && $roomId === null) {
            throw new RuntimeException('fake main send failure');
        }

        $this->messages[] = $message;
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

/** @return array<string, mixed> */
function defaultConfigValues(string $dir): array
{
    $privateKeyPath = $dir . '/private.key';
    file_put_contents($privateKeyPath, 'dummy-key');

    return [
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
        'google_oauth_client_id' => 'test-google-client',
        'google_oauth_client_secret' => 'test-google-secret',
        'google_oauth_refresh_token' => 'test-google-refresh',
        'google_forms_template_id' => 'template-id',
    ];
}

/** @param array<string, mixed> $values */
function writeConfigValues(string $dir, array $values): string
{
    $path = $dir . '/config.php';
    file_put_contents($path, '<?php return ' . var_export($values, true) . ';' . PHP_EOL);

    return $path;
}

/** @param array<string, mixed> $overrides */
function writeConfig(string $dir, array $overrides = []): string
{
    return writeConfigValues($dir, array_merge(defaultConfigValues($dir), $overrides));
}

function createRuntimeDirs(string $dir): void
{
    foreach ([
        $dir . '/storage',
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
        'src/GoogleFormsClient.php' => '<?php class GoogleFormsClientFixture {}' . PHP_EOL,
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
function namedEarthquakeEvent(string $id = 'named-event-1', string $time = '2026-07-05T12:00:00', string $name = 'Named Hypocenter', int $maxScale = 45): array
{
    return [
        'id' => $id,
        'earthquake' => [
            'time' => $time,
            'maxScale' => $maxScale,
            'hypocenter' => [
                'name' => $name,
            ],
        ],
    ];
}

/** @param array<int, array<string, mixed>>|null $events */
function buildChecker(string $dir, FakeLineWorksClient $lineWorks, ?array $events = null, array $configOverrides = [], ?GoogleFormsClient $googleFormsClient = null, ?DateTimeImmutable $now = null): EarthquakeChecker
{
    $config = Config::load(writeConfig($dir, $configOverrides));
    $stateStore = new StateStore($dir . '/state.json');

    return new EarthquakeChecker(
        $config,
        new FakeP2PQuakeClient($events ?? [earthquakeEvent()]),
        $lineWorks,
        $stateStore,
        new Logger($dir . '/app.log'),
        $googleFormsClient ?? googleClientForTest($config),
        $now
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

function googleClientForTest(Config $config): GoogleFormsClient
{
    return new GoogleFormsClient($config, static function (string $method, string $url, array $headers, string $body): array {
        if ($url === 'https://oauth2.googleapis.com/token') {
            $response = ['access_token' => 'synthetic-token'];
        } elseif ($method === 'POST' && str_contains($url, '/copy?')) {
            $response = ['id' => 'synthetic-copy-' . bin2hex(random_bytes(4))];
        } elseif ($method === 'GET' && str_starts_with($url, 'https://forms.googleapis.com/v1/forms/')) {
            $response = [
                'publishSettings' => ['publishState' => ['isPublished' => true, 'isAcceptingResponses' => true]],
                'responderUri' => 'https://respond.invalid/' . basename((string) parse_url($url, PHP_URL_PATH)),
            ];
        } elseif ($method === 'GET' && str_contains($url, '/permissions?')) {
            $response = ['permissions' => [['id' => 'synthetic-acl', 'type' => 'anyone', 'role' => 'reader', 'view' => 'published', 'allowFileDiscovery' => false]]];
        } else {
            throw new RuntimeException('Unexpected synthetic Google request.');
        }
        return ['status' => 200, 'body' => json_encode($response) ?: '{}'];
    });
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
