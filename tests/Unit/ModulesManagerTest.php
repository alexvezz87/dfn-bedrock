<?php

use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/web/app/themes/dfn-theme/inc/core/dfn-modules-manager.php';

test('dfn_get_available_modules returns prenotazioni and volontari', function () {
    Functions\stubs([
        '__' => function ($text) { return $text; },
    ]);

    $modules = dfn_get_available_modules();

    expect($modules)->toHaveKey('prenotazioni');
    expect($modules)->toHaveKey('volontari');
    expect($modules['prenotazioni']['name'])->toBe('Gestione Prenotazioni');
    expect($modules['volontari']['name'])->toBe('Gestione Volontari');
});

test('dfn_get_modules_status returns defaults when no option saved', function () {
    Functions\expect('get_option')
        ->once()
        ->with('dfn_active_modules', ['prenotazioni' => true, 'volontari' => true])
        ->andReturn(['prenotazioni' => true, 'volontari' => true]);

    $status = dfn_get_modules_status();

    expect($status['prenotazioni'])->toBeTrue();
    expect($status['volontari'])->toBeTrue();
});

test('dfn_is_module_active accurately reports status', function () {
    Functions\expect('get_option')
        ->once()
        ->with('dfn_active_modules', ['prenotazioni' => true, 'volontari' => true])
        ->andReturn(['prenotazioni' => true, 'volontari' => false]);

    expect(dfn_is_module_active('prenotazioni'))->toBeTrue();

    Functions\expect('get_option')
        ->once()
        ->with('dfn_active_modules', ['prenotazioni' => true, 'volontari' => true])
        ->andReturn(['prenotazioni' => true, 'volontari' => false]);

    expect(dfn_is_module_active('volontari'))->toBeFalse();
});

test('dfn_set_module_status updates option', function () {
    Functions\expect('get_option')
        ->once()
        ->with('dfn_active_modules', ['prenotazioni' => true, 'volontari' => true])
        ->andReturn(['prenotazioni' => true, 'volontari' => true]);

    Functions\expect('update_option')
        ->once()
        ->with('dfn_active_modules', ['prenotazioni' => false, 'volontari' => true])
        ->andReturn(true);

    $res = dfn_set_module_status('prenotazioni', false);
    expect($res)->toBeTrue();
});
