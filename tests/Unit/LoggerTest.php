<?php

use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/web/app/themes/dfn-theme/inc/core/dfn-logger.php';

test('dfn_log_get_client_ip resolves client IP correctly', function () {
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.42';
    expect(dfn_log_get_client_ip())->toBe('198.51.100.42');
    unset($_SERVER['HTTP_CF_CONNECTING_IP']);

    $_SERVER['REMOTE_ADDR'] = '203.0.113.195';
    expect(dfn_log_get_client_ip())->toBe('203.0.113.195');
    unset($_SERVER['REMOTE_ADDR']);
});

test('dfn_log_get_user_roles_label maps native and custom roles', function () {
    Functions\stubs([
        '__' => function ($text) { return $text; },
    ]);

    $wp_user = Mockery::mock('WP_User');
    $wp_user->roles = ['administrator'];

    expect(dfn_log_get_user_roles_label($wp_user))->toBe('Amministratore');

    $wp_user->roles = ['customer'];
    expect(dfn_log_get_user_roles_label($wp_user))->toBe('Cliente');
});
