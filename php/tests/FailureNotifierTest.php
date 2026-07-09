<?php
declare(strict_types=1);

register_test('failure notifier suppresses repeated error', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $notifier = new FailureNotifier(
        $lineWorks,
        new ErrorNotificationStore($dir . '/error_notifications.json'),
        'maintenance-room-id'
    );

    $error = new RuntimeException('same failure');
    assertTrue($notifier->notify($error, 'check_failed') === true, 'first failure notification must be sent.');
    assertTrue($notifier->notify($error, 'check_failed') === false, 'same failure notification must be suppressed.');
    assertTrue(count($lineWorks->messages) === 1, 'same failure must not be sent repeatedly.');
});

register_test('failure notifier skips LINE WORKS error', static function (): void {
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $notifier = new FailureNotifier(
        $lineWorks,
        new ErrorNotificationStore($dir . '/error_notifications.json'),
        'maintenance-room-id'
    );

    assertTrue($notifier->notify(new RuntimeException('LINE WORKS message send failed.'), 'check_failed') === false, 'LINE WORKS failures must not trigger recursive LINE WORKS notifications.');
    assertTrue(count($lineWorks->messages) === 0, 'LINE WORKS failure notification must be skipped.');
});

register_test('failure notifier requires room ID', static function (): void {
    try {
        new FailureNotifier(
            new FakeLineWorksClient(),
            new ErrorNotificationStore(tempDir() . '/error_notifications.json'),
            ' '
        );
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('failure notifier must reject a blank room ID.');
});
