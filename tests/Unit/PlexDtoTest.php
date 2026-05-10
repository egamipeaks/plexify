<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\SearchResults;
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

it('prefers originalTitle over grandparentTitle for track artist on compilation albums', function () {
    $row = [
        'ratingKey' => '22222',
        'title' => 'Holocene',
        'originalTitle' => 'Bon Iver',
        'grandparentTitle' => 'Various Artists',
        'parentTitle' => 'Now That\'s What I Call Music',
        'index' => 5,
        'duration' => 249000,
        'Media' => [
            [
                'Part' => [
                    [
                        'id' => 111,
                        'container' => 'mp3',
                    ],
                ],
            ],
        ],
    ];

    $track = Track::fromPlex($row);

    expect($track->artist)->toBe('Bon Iver');
});

it('handles missing optional fields gracefully', function () {
    $artist = Artist::fromPlex([
        'ratingKey' => '1',
        'title' => 'Unknown Artist',
    ]);

    expect($artist->thumb)->toBeNull();
    expect($artist->albumCount)->toBe(0);
});

it('maps a Plex playlist hub item to a Playlist DTO', function () {
    $playlist = Playlist::fromPlex([
        'ratingKey' => '4242',
        'title' => 'Late Night',
        'leafCount' => 37,
        'duration' => 8_460_000,
        'composite' => '/playlists/4242/composite/1700000000',
        'playlistType' => 'audio',
    ]);

    expect($playlist->id)->toBe('4242')
        ->and($playlist->title)->toBe('Late Night')
        ->and($playlist->trackCount)->toBe(37)
        ->and($playlist->durationMs)->toBe(8_460_000)
        ->and($playlist->thumb)->toBe('/playlists/4242/composite/1700000000')
        ->and($playlist->playlistType)->toBe('audio');
});

it('defaults missing playlist fields sensibly', function () {
    $playlist = Playlist::fromPlex(['ratingKey' => 5, 'title' => 'Untitled']);

    expect($playlist->id)->toBe('5')
        ->and($playlist->trackCount)->toBe(0)
        ->and($playlist->durationMs)->toBe(0)
        ->and($playlist->thumb)->toBeNull()
        ->and($playlist->playlistType)->toBe('audio');
});

it('maps a track search-hub item (grandparent/parent fields + thumb) to a Track DTO', function () {
    $track = Track::fromPlex([
        'ratingKey' => '53268',
        'title' => 'Holocene',
        'grandparentTitle' => 'Bon Iver',
        'parentTitle' => 'Bon Iver, Bon Iver',
        'duration' => 337_000,
        'thumb' => '/library/metadata/53268/thumb/1',
        'parentThumb' => '/library/metadata/53267/thumb/1',
        'Media' => [['Part' => [['id' => 991, 'container' => 'flac']]]],
    ]);

    expect($track->id)->toBe('53268')
        ->and($track->title)->toBe('Holocene')
        ->and($track->artist)->toBe('Bon Iver')
        ->and($track->album)->toBe('Bon Iver, Bon Iver')
        ->and($track->durationMs)->toBe(337_000)
        ->and($track->partId)->toBe(991)
        ->and($track->container)->toBe('flac')
        ->and($track->thumb)->toBe('/library/metadata/53268/thumb/1');
});

it('falls back to parentThumb then null for a track without its own thumb', function () {
    expect(Track::fromPlex([
        'ratingKey' => 1, 'title' => 'x', 'grandparentTitle' => 'a', 'parentTitle' => 'b',
        'parentThumb' => '/p/thumb',
        'Media' => [['Part' => [['id' => 1, 'container' => 'mp3']]]],
    ])->thumb)->toBe('/p/thumb');

    expect(Track::fromPlex([
        'ratingKey' => 1, 'title' => 'x', 'grandparentTitle' => 'a', 'parentTitle' => 'b',
        'Media' => [['Part' => [['id' => 1, 'container' => 'mp3']]]],
    ])->thumb)->toBeNull();
});

it('maps an album search-hub item (parentRatingKey) to artistId', function () {
    $album = Album::fromPlex([
        'ratingKey' => '58120',
        'title' => 'For Emma, Forever Ago',
        'parentTitle' => 'Bon Iver',
        'parentRatingKey' => '58119',
        'year' => 2007,
        'thumb' => '/library/metadata/58120/thumb/1',
    ]);

    expect($album->id)->toBe('58120')
        ->and($album->artist)->toBe('Bon Iver')
        ->and($album->artistId)->toBe('58119')
        ->and($album->year)->toBe(2007);
});

it('leaves album artistId null when parentRatingKey is absent', function () {
    expect(Album::fromPlex(['ratingKey' => 1, 'title' => 'x'])->artistId)->toBeNull();
});

it('builds an empty SearchResults that reports zero total', function () {
    $results = SearchResults::empty();

    expect($results->tracks)->toHaveCount(0)
        ->and($results->artists)->toHaveCount(0)
        ->and($results->albums)->toHaveCount(0)
        ->and($results->playlists)->toHaveCount(0)
        ->and($results->total())->toBe(0)
        ->and($results->isEmpty())->toBeTrue();
});

it('SearchResults total sums all four collections', function () {
    $results = new SearchResults(
        tracks: collect([1, 2, 3]),
        artists: collect([1]),
        albums: collect([1, 2]),
        playlists: collect([1]),
    );

    expect($results->total())->toBe(7)->and($results->isEmpty())->toBeFalse();
});

it('maps a Plex playlist row with a summary to a Playlist DTO', function () {
    $playlist = Playlist::fromPlex([
        'ratingKey' => '4242',
        'title' => 'Late Night',
        'summary' => 'Wind-down listening.',
        'leafCount' => 37,
        'duration' => 8_460_000,
        'composite' => '/playlists/4242/composite/1700000000',
        'playlistType' => 'audio',
    ]);

    expect($playlist->summary)->toBe('Wind-down listening.')
        ->and($playlist->title)->toBe('Late Night')
        ->and($playlist->trackCount)->toBe(37);
});

it('leaves Playlist summary null when the Plex row has none', function () {
    $playlist = Playlist::fromPlex([
        'ratingKey' => '7',
        'title' => 'No Description',
        'leafCount' => 1,
        'duration' => 1000,
        'playlistType' => 'audio',
    ]);

    expect($playlist->summary)->toBeNull();
});
