<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$testFiles = glob(__DIR__ . '/*Test.php');
if ($testFiles === false) {
    throw new RuntimeException('Failed to list test files.');
}

sort($testFiles, SORT_STRING);

foreach ($testFiles as $testFile) {
    require $testFile;
}

foreach (registered_tests() as $name => $test) {
    $test();
    echo 'OK: ' . $name . PHP_EOL;
}

echo 'ALL TESTS PASSED' . PHP_EOL;
