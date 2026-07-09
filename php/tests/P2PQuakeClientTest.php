<?php
declare(strict_types=1);

register_test('P2PQuake status parser uses last HTTP status line', static function (): void {
    $client = (new ReflectionClass(P2PQuakeClient::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(P2PQuakeClient::class, 'statusCodeFromHeaders');
    $method->setAccessible(true);

    assertTrue(
        $method->invoke($client, ['HTTP/1.1 302 Found', 'HTTP/1.1 200 OK']) === 200,
        'P2PQuake status parser must use the last HTTP status line.'
    );
});

register_test('P2PQuake client rejects limit 1', static function (): void {
    try {
        new P2PQuakeClient('https://example.invalid/quake', 1);
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('P2PQuake client must reject limit 1.');
});
