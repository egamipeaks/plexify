<?php

use App\Ai\Tools\SearchAlbums;
use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\PlexClient;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

it('returns up to limit album matches as JSON rows', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('searchAll')->once()->with('kiss me')->andReturn(new SearchResults(
        tracks: collect(),
        artists: collect(),
        albums: collect([
            new Album(id: '10', title: 'Kiss Me, Kiss Me, Kiss Me', artist: 'The Cure', year: 1987, thumb: null, trackCount: 18, durationMs: 4000000),
        ]),
        playlists: collect(),
    ));

    $result = (new SearchAlbums($plex))->handle(new Request(['query' => 'kiss me']));

    expect(json_decode((string) $result, true))->toBe([
        ['ratingKey' => '10', 'title' => 'Kiss Me, Kiss Me, Kiss Me', 'artist' => 'The Cure', 'year' => 1987, 'trackCount' => 18],
    ]);
});

it('respects the limit parameter', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('searchAll')->once()->andReturn(new SearchResults(
        tracks: collect(),
        artists: collect(),
        albums: collect([
            new Album(id: '1', title: 'A', artist: 'Artist', year: 2020, thumb: null, trackCount: 10, durationMs: 3000000),
            new Album(id: '2', title: 'B', artist: 'Artist', year: 2020, thumb: null, trackCount: 10, durationMs: 3000000),
            new Album(id: '3', title: 'C', artist: 'Artist', year: 2020, thumb: null, trackCount: 10, durationMs: 3000000),
        ]),
        playlists: collect(),
    ));

    $result = (new SearchAlbums($plex))->handle(new Request(['query' => 'x', 'limit' => 2]));

    expect(json_decode((string) $result, true))->toHaveCount(2);
});

it('schema requires query', function () {
    $schema = (new SearchAlbums(Mockery::mock(PlexClient::class)))
        ->schema(new JsonSchemaTypeFactory);
    expect($schema)->toHaveKeys(['query', 'limit']);
});
