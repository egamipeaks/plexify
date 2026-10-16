<?php

use App\Services\Albums\PlexPathMapper;

it('maps a Plex host path to the local mount', function () {
    $mapper = new PlexPathMapper('M:\\', '/Volumes/music_ssd/');

    expect($mapper->toLocal('M:\\Music\\David Bowie\\01 Song.flac'))->toBe('/Volumes/music_ssd/Music/David Bowie/01 Song.flac');
});

it('matches the remote prefix case insensitively', function () {
    $mapper = new PlexPathMapper('M:\\', '/Volumes/music_ssd');

    expect($mapper->toLocal('m:\\Music\\a.mp3'))->toBe('/Volumes/music_ssd/Music/a.mp3');
});

it('returns null for a path outside the mapped prefix', function () {
    expect((new PlexPathMapper('M:\\', '/Volumes/music_ssd/'))->toLocal('D:\\Other\\a.mp3'))->toBeNull();
});

it('does not map a sibling folder that only shares the prefix text', function () {
    expect((new PlexPathMapper('M:\\Music', '/Volumes/m'))->toLocal('M:\\Music2\\a.flac'))->toBeNull();
});

it('maps a prefix without a trailing separator', function () {
    expect((new PlexPathMapper('M:\\Music', '/Volumes/m'))->toLocal('M:\\Music\\A\\a.flac'))->toBe('/Volumes/m/A/a.flac');
});

it('maps the prefix itself to the local root without a trailing slash', function () {
    $mapper = new PlexPathMapper('M:\\', '/Volumes/music_ssd/');

    expect($mapper->toLocal('M:\\'))->toBe('/Volumes/music_ssd')
        ->and($mapper->toRemote('/Volumes/music_ssd/Various Artists/X'))->toBe('M:\\Various Artists\\X');
});

it('maps a local path back to the Plex host form', function () {
    $mapper = new PlexPathMapper('M:\\', '/Volumes/music_ssd/');

    expect($mapper->toRemote('/Volumes/music_ssd/Music/Various Artists/Road Trip'))->toBe('M:\\Music\\Various Artists\\Road Trip');
});

it('returns null mapping back a path outside the mount', function () {
    expect((new PlexPathMapper('M:\\', '/Volumes/music_ssd/'))->toRemote('/tmp/x'))->toBeNull();
});

it('is unconfigured when either prefix is empty', function () {
    $mapper = new PlexPathMapper('', '/Volumes/music_ssd/');

    expect($mapper->isConfigured())->toBeFalse()
        ->and($mapper->toLocal('M:\\a'))->toBeNull();
});

it('resolves from config through the container', function () {
    config()->set('services.plex.path_from', 'X:\\');
    config()->set('services.plex.path_to', '/mnt/x/');

    expect(app(PlexPathMapper::class)->toLocal('X:\\a\\b.mp3'))->toBe('/mnt/x/a/b.mp3')
        ->and(app(PlexPathMapper::class)->localRoot())->toBe('/mnt/x');
});
