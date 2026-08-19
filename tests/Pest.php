<?php

use Brain\Monkey;

/*
|--------------------------------------------------------------------------
| Pest Setup with Brain Monkey (for WordPress Function Mocking & Unit Tests)
|--------------------------------------------------------------------------
*/

uses()
    ->beforeEach(function () {
        Monkey\setUp();
    })
    ->afterEach(function () {
        Monkey\tearDown();
    })
    ->in('Unit', 'Feature');
