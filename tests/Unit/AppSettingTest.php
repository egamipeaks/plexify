<?php

use App\Models\Setting;
use App\Support\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the default density when no setting is stored', function () {
    expect(AppSetting::density())->toBe('comfortable');
});

it('returns the stored density value', function () {
    Setting::set('density', 'compact');

    expect(AppSetting::density())->toBe('compact');
});

it('persists density via setDensity', function () {
    AppSetting::setDensity('compact');

    expect(Setting::get('density'))->toBe('compact');
    expect(AppSetting::density())->toBe('compact');
});

it('rejects an invalid density value', function () {
    AppSetting::setDensity('massive');
})->throws(InvalidArgumentException::class);

it('overwrites an existing density value', function () {
    AppSetting::setDensity('compact');
    AppSetting::setDensity('comfortable');

    expect(AppSetting::density())->toBe('comfortable');
});

it('defaults scrobbleEnabled to true', function () {
    expect(AppSetting::scrobbleEnabled())->toBeTrue();
});

it('round-trips scrobbleEnabled', function () {
    AppSetting::setScrobbleEnabled(false);
    expect(AppSetting::scrobbleEnabled())->toBeFalse();

    AppSetting::setScrobbleEnabled(true);
    expect(AppSetting::scrobbleEnabled())->toBeTrue();
});

it('defaults albumHeaderCollapsed to false', function () {
    expect(AppSetting::albumHeaderCollapsed())->toBeFalse();
});

it('round-trips albumHeaderCollapsed', function () {
    AppSetting::setAlbumHeaderCollapsed(true);
    expect(AppSetting::albumHeaderCollapsed())->toBeTrue();

    AppSetting::setAlbumHeaderCollapsed(false);
    expect(AppSetting::albumHeaderCollapsed())->toBeFalse();
});
