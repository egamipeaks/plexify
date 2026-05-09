<?php

use App\Models\Setting;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('stores and retrieves a setting value', function () {
    Setting::set('theme', 'dark');

    expect(Setting::get('theme'))->toBe('dark');
});

it('returns default when setting is missing', function () {
    expect(Setting::get('missing_key', 'fallback'))->toBe('fallback');
});

it('overwrites an existing setting', function () {
    Setting::set('density', 'comfortable');
    Setting::set('density', 'compact');

    expect(Setting::get('density'))->toBe('compact');
});

it('stores complex JSON-castable values', function () {
    Setting::set('compact_toggles', ['artists' => true, 'albums' => false]);

    expect(Setting::get('compact_toggles'))->toBe([
        'artists' => true,
        'albums' => false,
    ]);
});
