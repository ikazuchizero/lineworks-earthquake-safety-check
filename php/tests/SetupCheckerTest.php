<?php
declare(strict_types=1);

register_test('setup checker success', static function (): void {
    $dir = tempDir();
    createRequiredPhpFiles($dir);
    createRuntimeDirs($dir);
    writeConfig($dir);

    $checker = new SetupChecker($dir);
    $results = $checker->run();

    assertTrue($checker->isOk($results), 'setup checker must pass when required files and directories exist.');
});

register_test('setup checker missing config', static function (): void {
    $dir = tempDir();
    createRequiredPhpFiles($dir);
    createRuntimeDirs($dir);

    $checker = new SetupChecker($dir);
    $results = $checker->run();

    assertTrue(!$checker->isOk($results), 'setup checker must fail when config.php is missing.');
    assertTrue(($results[0]['status'] ?? null) === 'NG', 'missing config.php must be reported as NG.');
});

register_test('setup checker missing directory', static function (): void {
    $dir = tempDir();
    createRequiredPhpFiles($dir);
    writeConfig($dir);
    mkdir($dir . '/storage', 0775, true);

    $checker = new SetupChecker($dir);
    $results = $checker->run();
    $byName = [];
    foreach ($results as $result) {
        $byName[$result['name']] = $result;
    }

    assertTrue(($byName['forms_dir']['status'] ?? null) === 'NG', 'setup checker must fail when forms directory is missing.');
});

register_test('setup checker missing PHP file', static function (): void {
    $dir = tempDir();
    createRequiredPhpFiles($dir);
    unlink($dir . '/bin/check.php');
    createRuntimeDirs($dir);
    writeConfig($dir);

    $checker = new SetupChecker($dir);
    $results = $checker->run();
    $byName = [];
    foreach ($results as $result) {
        $byName[$result['name']] = $result;
    }

    assertTrue(($byName['php_bin_check']['status'] ?? null) === 'NG', 'setup checker must fail when bin/check.php is missing.');
    assertTrue(($byName['php_bin_check']['reason'] ?? null) === 'missing', 'missing PHP file must be reported as missing.');
});

register_test('setup checker empty PHP file', static function (): void {
    $dir = tempDir();
    createRequiredPhpFiles($dir, ['src/EarthquakeChecker.php' => '']);
    createRuntimeDirs($dir);
    writeConfig($dir);

    $checker = new SetupChecker($dir);
    $results = $checker->run();
    $byName = [];
    foreach ($results as $result) {
        $byName[$result['name']] = $result;
    }

    assertTrue(($byName['php_src_EarthquakeChecker']['status'] ?? null) === 'NG', 'setup checker must fail when a major PHP file is empty.');
    assertTrue(($byName['php_src_EarthquakeChecker']['reason'] ?? null) === 'empty', 'empty PHP file must be reported as empty.');
});

register_test('setup checker skips form directories when stock disabled', static function (): void {
    $dir = tempDir();
    createRequiredPhpFiles($dir);
    mkdir($dir . '/storage', 0775, true);
    writeConfig($dir, [
        'form_stock_enabled' => false,
        'form_url' => 'https://example.invalid/fixed-form',
    ]);

    $checker = new SetupChecker($dir);
    $results = $checker->run();

    assertTrue($checker->isOk($results), 'setup checker must pass without form stock directories when form stock is disabled.');
});

register_test('setup checker not writable directory', static function (): void {
    $dir = tempDir();
    createRequiredPhpFiles($dir);
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
});
