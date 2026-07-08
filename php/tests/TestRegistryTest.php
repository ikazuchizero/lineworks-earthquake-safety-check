<?php
declare(strict_types=1);

register_test('register_test rejects duplicate names', static function (): void {
    $originalRegistry = $GLOBALS['TEST_REGISTRY'];

    try {
        $GLOBALS['TEST_REGISTRY'] = [];

        register_test('duplicate test name', static function (): void {
        });

        try {
            register_test('duplicate test name', static function (): void {
            });
        } catch (RuntimeException $exception) {
            assertTrue(
                $exception->getMessage() === 'Duplicate test name: duplicate test name',
                'duplicate test name must report the conflicting name.'
            );

            return;
        }
    } finally {
        $GLOBALS['TEST_REGISTRY'] = $originalRegistry;
    }

    throw new RuntimeException('duplicate test name must fail.');
});
