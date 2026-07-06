#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/SetupChecker.php';

$rootDir = dirname(__DIR__);
$checker = new SetupChecker($rootDir);
$results = $checker->run();

foreach ($results as $result) {
    $line = $result['status'] . ' ' . $result['name'];
    if ($result['reason'] !== '') {
        $line .= ' ' . $result['reason'];
    }
    echo $line . PHP_EOL;
}

exit($checker->isOk($results) ? 0 : 1);
