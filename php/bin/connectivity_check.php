#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineWorksClient.php';
require_once __DIR__ . '/../src/ConnectivityChecker.php';

// 初回設置後のLINE WORKS疎通確認用入口。
// 地震情報取得や安否確認本文送信は行わず、短い検証メッセージだけを送る。
$rootDir = dirname(__DIR__);

try {
    $config = Config::load($rootDir . '/config.php');
    $lineWorksClient = new LineWorksClient($config);
    $checker = new ConnectivityChecker(
        $lineWorksClient,
        $config->formStockEnabled() ? $config->formLowStockRoomId() : null
    );
    $checker->run();
    echo 'OK connectivity_check' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo 'NG connectivity_check failed' . PHP_EOL;
    exit(1);
}
