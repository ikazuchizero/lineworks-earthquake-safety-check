#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/StateStore.php';
require_once __DIR__ . '/../src/P2PQuakeClient.php';
require_once __DIR__ . '/../src/LineWorksClient.php';
require_once __DIR__ . '/../src/EarthquakeChecker.php';

$rootDir = dirname(__DIR__);
$storageDir = $rootDir . '/storage';

if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true)) {
    fwrite(STDERR, "Failed to create storage directory.
");
    exit(1);
}

$logger = new Logger($storageDir . '/app.log');
$lockPath = $storageDir . '/check.lock';
$lockHandle = fopen($lockPath, 'c');

if ($lockHandle === false) {
    fwrite(STDERR, "Failed to open lock file.
");
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $logger->info('Another check process is already running.');
    fclose($lockHandle);
    exit(0);
}

$exitCode = 0;

try {
    $config = Config::load($rootDir . '/config.php');

    $stateStore = new StateStore($storageDir . '/state.json');
    $p2pQuakeClient = new P2PQuakeClient($config->p2pquakeApiUrl(), 10);
    $lineWorksClient = new LineWorksClient($config);
    $checker = new EarthquakeChecker(
        $config,
        $p2pQuakeClient,
        $lineWorksClient,
        $stateStore,
        $logger
    );

    $checker->run();
} catch (Throwable $e) {
    $exitCode = 1;
    $logger->error('Check failed.', ['error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit($exitCode);
