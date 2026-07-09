<?php
declare(strict_types=1);

register_test('connectivity checker uses short test message', static function (): void {
    $lineWorks = new FakeLineWorksClient();
    $checker = new ConnectivityChecker($lineWorks, 'maintenance-room-id');

    $checker->run();

    assertTrue(count($lineWorks->messages) === 2, 'connectivity checker must send test messages to both rooms.');
    assertTrue($lineWorks->messages[0]['room_id'] === null, 'connectivity checker must check the normal notification room first.');
    assertTrue($lineWorks->messages[1]['room_id'] === 'maintenance-room-id', 'connectivity checker must check the configured maintenance room.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], '通知用チャット疎通確認'), 'normal room connectivity message must identify the destination purpose.');
    assertTrue(str_contains($lineWorks->messages[1]['text'], '保守通知用チャット疎通確認'), 'maintenance room connectivity message must identify the destination purpose.');
    assertTrue(!str_contains($lineWorks->messages[0]['text'], '【地震情報】'), 'connectivity checker must not send the earthquake safety message body.');
    assertTrue(!str_contains($lineWorks->messages[1]['text'], '【地震情報】'), 'maintenance connectivity checker must not send the earthquake safety message body.');
});

register_test('connectivity checker skips maintenance when room is null', static function (): void {
    $lineWorks = new FakeLineWorksClient();
    $checker = new ConnectivityChecker($lineWorks, null);

    $checker->run();

    assertTrue(count($lineWorks->messages) === 1, 'connectivity checker without maintenance room must send one test message.');
    assertTrue($lineWorks->messages[0]['room_id'] === null, 'connectivity checker without maintenance room must only check the normal notification room.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], '通知用チャット疎通確認'), 'normal-only connectivity message must identify the destination purpose.');
});
