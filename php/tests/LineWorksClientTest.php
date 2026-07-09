<?php
declare(strict_types=1);

register_test('LINE WORKS status parser uses last HTTP status line', static function (): void {
    $client = (new ReflectionClass(LineWorksClient::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(LineWorksClient::class, 'statusCodeFromHeaders');
    $method->setAccessible(true);

    assertTrue(
        $method->invoke($client, ['HTTP/1.1 100 Continue', 'HTTP/1.1 200 OK']) === 200,
        'LINE WORKS status parser must use the last HTTP status line.'
    );
});
