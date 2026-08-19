<?php

use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/web/app/themes/dfn-theme/inc/core/dfn-roles-manager.php';
require_once dirname(__DIR__, 2) . '/web/app/themes/dfn-theme/inc/core/dfn-security.php';

test('dfn_is_local_environment detects local hosts correctly', function () {
    $_SERVER['HTTP_HOST'] = 'dfn-bedrock.local';
    expect(dfn_is_local_environment())->toBeTrue();

    $_SERVER['HTTP_HOST'] = 'localhost:8000';
    expect(dfn_is_local_environment())->toBeTrue();

    $_SERVER['HTTP_HOST'] = 'dfnprenotazioni.it';
    expect(dfn_is_local_environment())->toBeFalse();
});

test('dfn_user_can returns true for administrator with manage_options', function () {
    Functions\expect('get_current_user_id')->andReturn(1);
    Functions\expect('user_can')->with(1, 'manage_options')->andReturn(true);

    expect(dfn_user_can('dfn_act_boxoffice', 1))->toBeTrue();
});

test('dfn_user_can checks assigned fai roles in user_meta if capability not directly on user', function () {
    Functions\expect('get_current_user_id')->andReturn(10);
    Functions\expect('user_can')->with(10, 'manage_options')->andReturn(false);
    Functions\expect('user_can')->with(10, 'dfn_act_boxoffice')->andReturn(false);

    // Utente 10 ha assegnato il ruolo segreteria_fai (che ha dfn_act_quick_booking ma NON dfn_act_boxoffice)
    Functions\expect('get_user_meta')->with(10, '_dfn_assigned_fai_roles', true)->andReturn(['segreteria_fai']);

    expect(dfn_user_can('dfn_act_boxoffice', 10))->toBeFalse();
    expect(dfn_user_can('dfn_act_quick_booking', 10))->toBeTrue();
});

test('dfn_user_can grants boxoffice access to banchetto_fai role', function () {
    Functions\expect('get_current_user_id')->andReturn(20);
    Functions\expect('user_can')->with(20, 'manage_options')->andReturn(false);
    Functions\expect('user_can')->with(20, 'dfn_act_boxoffice')->andReturn(false);

    // Utente 20 ha assegnato il ruolo banchetto_fai
    Functions\expect('get_user_meta')->with(20, '_dfn_assigned_fai_roles', true)->andReturn(['banchetto_fai']);

    expect(dfn_user_can('dfn_act_boxoffice', 20))->toBeTrue();
});
