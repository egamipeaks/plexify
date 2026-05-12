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

it('per-section compact flags default to the density default when unset', function () {
    AppSetting::setDensity('comfortable');
    expect(AppSetting::artistsCompact())->toBeFalse();
    expect(AppSetting::albumsCompact())->toBeFalse();
    expect(AppSetting::libraryTracksCompact())->toBeFalse();
    expect(AppSetting::playlistTracksCompact())->toBeFalse();

    AppSetting::setDensity('compact');
    expect(AppSetting::artistsCompact())->toBeTrue();
    expect(AppSetting::albumsCompact())->toBeTrue();
    expect(AppSetting::libraryTracksCompact())->toBeTrue();
    expect(AppSetting::playlistTracksCompact())->toBeTrue();
});

it('an explicitly-set per-section compact flag overrides the density default', function () {
    AppSetting::setDensity('comfortable');
    AppSetting::setArtistsCompact(true);
    expect(AppSetting::artistsCompact())->toBeTrue();

    AppSetting::setDensity('compact');
    AppSetting::setArtistsCompact(false);
    expect(AppSetting::artistsCompact())->toBeFalse();
});

it('round-trips each per-section compact flag', function () {
    foreach (['ArtistsCompact', 'AlbumsCompact', 'LibraryTracksCompact', 'PlaylistTracksCompact'] as $name) {
        $get = lcfirst($name);
        $set = 'set'.$name;
        AppSetting::$set(true);
        expect(AppSetting::$get())->toBeTrue();
        AppSetting::$set(false);
        expect(AppSetting::$get())->toBeFalse();
    }
});
