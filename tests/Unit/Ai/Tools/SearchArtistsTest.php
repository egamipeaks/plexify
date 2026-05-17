<?php

use App\Ai\Tools\SearchArtists;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\PlexClient;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

it('returns up to limit artist matches as JSON rows', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('searchAll')->once()->with('cure')->andReturn(new SearchResults(
        tracks: collect(),
        artists: collect([
            new Artist(id: '1', name: 'The Cure', thumb: null, albumCount: 13),
            new Artist(id: '2', name: 'Cure The', thumb: null, albumCount: 1),
        ]),
        albums: collect(),
        playlists: collect(),
    ));

    $result = (new SearchArtists($plex))->handle(new Request(['query' => 'cure']));

    expect(json_decode((string) $result, true))->toBe([
        ['ratingKey' => '1', 'name' => 'The Cure', 'albumCount' => 13],
        ['ratingKey' => '2', 'name' => 'Cure The', 'albumCount' => 1],
    ]);
});

it('respects the limit parameter', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('searchAll')->once()->andReturn(new SearchResults(
        tracks: collect(),
        artists: collect([
            new Artist(id: '1', name: 'A', thumb: null, albumCount: 1),
            new Artist(id: '2', name: 'B', thumb: null, albumCount: 1),
            new Artist(id: '3', name: 'C', thumb: null, albumCount: 1),
        ]),
        albums: collect(),
        playlists: collect(),
    ));

    $result = (new SearchArtists($plex))->handle(new Request(['query' => 'x', 'limit' => 2]));

    expect(json_decode((string) $result, true))->toHaveCount(2);
});

it('schema requires query', function () {
    $schema = (new SearchArtists(Mockery::mock(PlexClient::class)))
        ->schema(new JsonSchemaTypeFactory);
    expect($schema)->toHaveKeys(['query', 'limit']);
});
