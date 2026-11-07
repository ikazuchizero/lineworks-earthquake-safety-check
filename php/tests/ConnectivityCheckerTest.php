<?php
declare(strict_types=1);

register_test('connectivity checker uses a short normal-room test message', static function (): void {
    $lineWorks = new FakeLineWorksClient();
    $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('G');
    $checker = new ConnectivityChecker($lineWorks, ($hour + 12) % 24, ($hour + 13) % 24);
    $checker->run();
    assertTrue(count($lineWorks->messages) === 1, 'connectivity checker must send one normal-room test message.');
    assertTrue($lineWorks->messages[0]['room_id'] === null, 'connectivity checker must use the normal notification room.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], '通知用チャット疎通確認'), 'test message must identify its purpose.');
    assertTrue(!str_contains($lineWorks->messages[0]['text'], '【地震情報】'), 'connectivity checker must not send an earthquake safety message.');
});

register_test('connectivity checker suppresses the normal room during quiet hours', static function (): void {
    $lineWorks = new FakeLineWorksClient();
    $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('G');
    $checker = new ConnectivityChecker($lineWorks, ($hour + 23) % 24, ($hour + 3) % 24);
    $checker->run();
    assertTrue($lineWorks->attempts === [], 'quiet connectivity check must not send a message.');
});
