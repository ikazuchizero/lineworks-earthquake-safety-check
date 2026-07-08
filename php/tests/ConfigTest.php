<?php
declare(strict_types=1);

register_test('notify_scale validation', static function (): void {
    loadConfigForTest(['notify_scale' => 0]);
    loadConfigForTest(['notify_scale' => 45]);
    expectConfigFailure(['notify_scale' => -1], 'negative notify_scale must fail.');
    expectConfigFailure(['notify_scale' => 45.5], 'decimal notify_scale must fail.');
    expectConfigFailure(['notify_scale' => 999], 'unknown notify_scale must fail.');
});

register_test('placeholder validation', static function (): void {
    expectConfigFailure(
        ['form_low_stock_room_id' => 'REPLACE_WITH_FORM_LOW_STOCK_ROOM_ID'],
        'placeholder form_low_stock_room_id must fail.'
    );
    expectConfigFailure(
        ['client_id' => 'REPLACE_WITH_CLIENT_ID'],
        'placeholder client_id must fail.'
    );
    expectConfigFailure(
        [
            'form_stock_enabled' => false,
            'form_url' => 'REPLACE_WITH_FORM_URL',
        ],
        'placeholder form_url must fail when form stock is disabled.'
    );
});

register_test('fixed form mode uses main room as maintenance room', static function (): void {
    $config = loadConfigForTest([
        'form_stock_enabled' => false,
        'form_url' => 'https://example.invalid/fixed-form',
    ]);

    assertTrue($config->maintenanceRoomId() === 'room-id', 'fixed form mode must reuse room_id as maintenance room.');
});
