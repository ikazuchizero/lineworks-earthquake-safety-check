<?php
declare(strict_types=1);

register_test('logger writes structured lines', static function (): void {
    $dir = tempDir();
    $logger = new Logger($dir . '/app.log');

    $logger->info('check_completed', ['count' => 1]);
    $logger->error('Check failed.', ['error' => 'safe']);

    $log = file_get_contents($dir . '/app.log');
    assertTrue($log !== false, 'logger must create app.log.');
    assertTrue(str_contains($log, 'INFO check_completed {"count":1}'), 'logger must write INFO lines with JSON context.');
    assertTrue(str_contains($log, 'ERROR Check failed. {"error":"safe"}'), 'logger must write ERROR lines with JSON context.');
});
