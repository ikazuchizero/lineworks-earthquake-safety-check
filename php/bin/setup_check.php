#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/SetupChecker.php';

// 初回設置後に、config.php・秘密鍵・storage/forms系ディレクトリの配置漏れを確認する入口。
// 実設定値や秘密鍵本文は表示せず、OK/NGと原因分類だけを標準出力へ出す。
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
