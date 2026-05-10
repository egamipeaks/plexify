<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Track;

it('builds Artist from Plex JSON row', function () {
    $row = [
        'ratingKey' => '12345',
        'title' => 'Bon Iver',
        'thumb' => '/library/metadata/12345/thumb/1700000000',
        'childCount' => 5,
    ];

    $artist = Artist::fromPlex($row);

    expect($artist->id)->toBe('12345');
    expect($artist->name)->toBe('Bon Iver');
    expect($artist->thumb)->toBe('/library/metadata/12345/thumb/1700000000');
    expect($artist->albumCount)->toBe(5);
});

it('builds Album from Plex JSON row', function () {
    $row = [
        'ratingKey' => '67890',
        'title' => '22, A Million',
        'parentTitle' => 'Bon Iver',
        'year' => 2016,
        'thumb' => '/library/metadata/67890/thumb/1700000000',
        'leafCount' => 10,
        'duration' => 2160000,
    ];

    $album = Album::fromPlex($row);

    expect($album->id)->toBe('67890');
    expect($album->title)->toBe('22, A Million');
    expect($album->artist)->toBe('Bon Iver');
    expect($album->year)->toBe(2016);
    expect($album->thumb)->toBe('/library/metadata/67890/thumb/1700000000');
    expect($album->trackCount)->toBe(10);
    expect($album->durationMs)->toBe(2160000);
});

it('builds Track from Plex JSON row', function () {
    $row = [
        'ratingKey' => '11111',
        'title' => '33 GOD',
        'grandparentTitle' => 'Bon Iver',
        'parentTitle' => '22, A Million',
        'index' => 3,
        'duration' => 213000,
        'Media' => [
            [
                'audioCodec' => 'flac',
                'container' => 'flac',
                'Part' => [
                    [
                        'id' => 999,
                        'key' => '/library/parts/999/1700000000/file.flac',
                        'container' => 'flac',
                    ],
                ],
            ],
        ],
    ];

    $track = Track::fromPlex($row);

    expect($track->id)->toBe('11111');
    expect($track->title)->toBe('33 GOD');
    expect($track->artist)->toBe('Bon Iver');
    expect($track->album)->toBe('22, A Million');
    expect($track->trackNumber)->toBe(3);
    expect($track->durationMs)->toBe(213000);
    expect($track->partId)->toBe(999);
    expect($track->container)->toBe('flac');
});

it('handles missing optional fields gracefully', function () {
    $artist = Artist::fromPlex([
        'ratingKey' => '1',
        'title' => 'Unknown Artist',
    ]);

    expect($artist->thumb)->toBeNull();
    expect($artist->albumCount)->toBe(0);
});
