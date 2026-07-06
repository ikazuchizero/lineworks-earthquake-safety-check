#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineWorksClient.php';
require_once __DIR__ . '/../src/HealthChecker.php';

$rootDir = dirname(__DIR__);
$storageDir = $rootDir . '/storage';

try {
    $config = Config::load($rootDir . '/config.php');
    $healthChecker = new HealthChecker($storageDir . '/app.log');
    $summary = $healthChecker->summarize(7);
    $message = $healthChecker->message($summary);

    $lineWorksClient = new LineWorksClient($config);
    $lineWorksClient->sendMessage($message, $config->formStockEnabled() ? $config->formLowStockRoomId() : null);

    echo 'OK health_check' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo 'NG health_check failed' . PHP_EOL;
    exit(1);
}
