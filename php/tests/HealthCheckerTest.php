<?php
declare(strict_types=1);

register_test('health checker summarizes log', static function (): void {
    $dir = tempDir();
    $now = date('c');
    file_put_contents($dir . '/app.log', implode(PHP_EOL, [
        '[' . $now . '] INFO check_completed',
        '[' . $now . '] INFO form_low_stock_notice_sent {"available_count":1}',
        '[' . $now . '] ERROR Check failed. {"error":"safe"}',
    ]) . PHP_EOL);

    $checker = new HealthChecker($dir . '/app.log');
    $summary = $checker->summarize(7);
    $message = $checker->message($summary);

    assertTrue($summary['normal_log'] === 1, 'health checker must count normal log lines.');
    assertTrue($summary['warning'] === 1, 'health checker must count warning-like lines.');
    assertTrue($summary['error'] === 1, 'health checker must count error lines.');
    assertTrue(str_contains($message, 'normal_log: 1件'), 'health check message must include normal log count.');
    assertTrue(str_contains($message, 'error: 1件'), 'health check message must include error count.');
});
