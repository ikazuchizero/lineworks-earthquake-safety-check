<?php
declare(strict_types=1);

register_test('notify_scale validation', static function (): void {
    loadConfigForTest(['notify_scale' => 0]);
    loadConfigForTest(['notify_scale' => 45]);
    expectConfigFailure(['notify_scale' => -1], 'negative notify_scale must fail.');
    expectConfigFailure(['notify_scale' => 45.5], 'decimal notify_scale must fail.');
    expectConfigFailure(['notify_scale' => 999], 'unknown notify_scale must fail.');
});

register_test('notify_scale is required', static function (): void {
    $dir = tempDir();
    $values = defaultConfigValues($dir);
    unset($values['notify_scale']);

    try {
        Config::load(writeConfigValues($dir, $values));
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException('missing notify_scale must fail.');
});

register_test('placeholder validation', static function (): void {
    foreach (['client_id', 'google_oauth_client_id', 'google_oauth_client_secret', 'google_oauth_refresh_token', 'google_forms_template_id'] as $key) {
        expectConfigFailure([$key => 'REPLACE_WITH_VALUE'], 'placeholder must fail: ' . $key);
    }
});

register_test('Google Forms configuration uses the normal room for maintenance', static function (): void {
    $config = loadConfigForTest();
    assertTrue($config->googleFormsTemplateId() === 'template-id', 'Google template must be configured without a mode selector.');
    assertTrue($config->maintenanceRoomId() === $config->roomId(), 'maintenance notifications must use the normal room.');
});

register_test('Google Forms rejects each missing Google setting', static function (): void {
    $dir = tempDir();
    $values = array_merge(defaultConfigValues($dir), [
        'google_oauth_client_id' => 'test-google-client',
        'google_oauth_client_secret' => 'test-google-secret',
        'google_oauth_refresh_token' => 'test-google-refresh',
        'google_forms_template_id' => 'template-id',
    ]);
    foreach (['google_oauth_client_id', 'google_oauth_client_secret', 'google_oauth_refresh_token', 'google_forms_template_id'] as $key) {
        $missing = $values;
        unset($missing[$key]);
        $message = '';
        try {
            Config::load(writeConfigValues($dir, $missing));
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }
        assertTrue(str_contains($message, $key), 'Missing Google setting must fail: ' . $key);
    }
});

register_test('quiet hours defaults and validation', static function (): void {
    $config = loadConfigForTest();
    assertTrue($config->quietHoursStart() === 0, 'quiet hours start must default to 0.');
    assertTrue($config->quietHoursEnd() === 6, 'quiet hours end must default to 6.');
    expectConfigFailure(['quiet_hours_start' => -1], 'quiet hours start below 0 must fail.');
    expectConfigFailure(['quiet_hours_end' => 24], 'quiet hours end above 23 must fail.');
    expectConfigFailure(['quiet_hours_start' => 6, 'quiet_hours_end' => 6], 'equal quiet hours must fail.');
});
