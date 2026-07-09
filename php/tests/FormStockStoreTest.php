<?php
declare(strict_types=1);

register_test('form stock store imports CSV and skips duplicates or invalid rows', static function (): void {
    $dir = tempDir();
    mkdir($dir . '/processed', 0775, true);
    mkdir($dir . '/failed', 0775, true);
    writeAvailableForms($dir);
    file_put_contents($dir . '/forms.csv', implode(PHP_EOL, [
        'url',
        'https://example.invalid/form/1',
        'not-a-url',
        'https://example.invalid/form/2',
    ]) . PHP_EOL);

    $store = new FormStockStore(
        $dir . '/forms.json',
        $dir . '/forms.csv',
        $dir . '/processed',
        $dir . '/failed'
    );
    $result = $store->importCsvIfExists();

    assertTrue($result['processed'] === true, 'form CSV must be processed when a file exists.');
    assertTrue($result['imported'] === 1, 'form CSV must import one new URL.');
    assertTrue($result['duplicate_skipped'] === 1, 'form CSV must skip duplicate URLs.');
    assertTrue($result['invalid_rows'] === 1, 'form CSV must count invalid rows.');
    assertTrue($store->availableCount() === 2, 'form stock must include both the original and imported available URLs.');
    assertTrue(count(glob($dir . '/processed/*_forms.csv') ?: []) === 1, 'processed form CSV must be archived.');
});

register_test('form stock store marks selected form used', static function (): void {
    $dir = tempDir();
    writeAvailableForms($dir, 2);
    $store = new FormStockStore(
        $dir . '/forms.json',
        $dir . '/forms.csv',
        $dir . '/processed',
        $dir . '/failed'
    );

    $form = $store->takeAvailable();
    assertTrue($form !== null, 'form stock store must return an available form.');
    $store->markUsed($form['index'], '2026-07-05T12:00:00|Test Hypocenter');
    $store->save();

    $reloaded = new FormStockStore(
        $dir . '/forms.json',
        $dir . '/forms.csv',
        $dir . '/processed',
        $dir . '/failed'
    );
    $next = $reloaded->takeAvailable();

    assertTrue($reloaded->availableCount() === 1, 'used form must no longer count as available.');
    assertTrue($next !== null && $next['url'] !== $form['url'], 'next available form must differ from the used one.');
});
