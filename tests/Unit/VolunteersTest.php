<?php

use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/web/app/themes/dfn-theme/inc/core/dfn-database.php';

test('dfn_is_user_volunteer returns true for administrator', function () {
    Functions\expect('user_can')->with(1, 'manage_options')->andReturn(true);

    expect(dfn_is_user_volunteer(1))->toBeTrue();
});

test('dfn_is_user_volunteer returns true if user has assigned FAI roles in meta', function () {
    Functions\expect('user_can')->with(10, 'manage_options')->andReturn(false);
    Functions\expect('get_user_meta')->with(10, '_dfn_assigned_fai_roles', true)->andReturn(['dfn_segreteria']);

    expect(dfn_is_user_volunteer(10))->toBeTrue();
});
