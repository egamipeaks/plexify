<?php

use App\Services\Albums\AlbumNaming;

it('replaces Windows forbidden characters with underscores', function () {
    expect(AlbumNaming::sanitize('12" Dance: 80s / Groove? <*|>\\', 200))->toBe('12_ Dance_ 80s _ Groove_ _____');
});

it('strips control characters and trailing dots and spaces', function () {
    expect(AlbumNaming::sanitize("Road\tTrip... ", 200))->toBe('RoadTrip');
});

it('caps the length and re-trims after cutting', function () {
    expect(AlbumNaming::sanitize('abcde. fgh', 7))->toBe('abcde');
});

it('uses the sanitized playlist title as the folder name', function () {
    expect(AlbumNaming::folderName('Summer: 2026', '42'))->toBe('Summer_ 2026');
});

it('falls back to the playlist id when the title sanitizes to nothing', function () {
    expect(AlbumNaming::folderName('...', '42'))->toBe('Playlist 42');
});

it('caps the folder name at 100 characters', function () {
    expect(mb_strlen(AlbumNaming::folderName(str_repeat('a', 300), '42')))->toBe(100);
});

it('builds a two digit numbered file name', function () {
    expect(AlbumNaming::fileName(3, 12, 'AC/DC', 'Back In Black'))->toBe('03 - AC_DC - Back In Black.mp3');
});

it('pads to three digits for playlists over 99 tracks', function () {
    expect(AlbumNaming::fileName(7, 100, 'A', 'B'))->toBe('007 - A - B.mp3');
});

it('falls back for empty artist and title', function () {
    expect(AlbumNaming::fileName(1, 2, '', '?'))->toBe('01 - Unknown Artist - _.mp3')
        ->and(AlbumNaming::fileName(1, 2, 'A', '...'))->toBe('01 - A - Untitled.mp3');
});

it('caps artist at 60 and title at 90 characters', function () {
    $name = AlbumNaming::fileName(1, 2, str_repeat('a', 200), str_repeat('t', 200));

    expect($name)->toBe('01 - '.str_repeat('a', 60).' - '.str_repeat('t', 90).'.mp3');
});
