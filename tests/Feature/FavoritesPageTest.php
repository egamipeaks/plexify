<?php

use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $mock = Mockery::mock(PlexClient::class);
    $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'M1']);
    $mock->shouldReceive('playlists')->andReturn(collect());
    $mock->shouldReceive('searchAll')->andReturn(SearchResults::empty());
    $mock->shouldReceive('thumbUrl')->andReturn(null);
    $mock->shouldReceive('streamUrl')->andReturn('http://x/stream');
    $mock->shouldReceive('queueItem')->andReturnUsing(fn ($t) => [
        'id' => $t->id, 'url' => 'http://x/stream', 'title' => $t->title,
        'artist' => $t->artist, 'artwork' => null,
        'albumId' => $t->albumId, 'artistId' => $t->artistId,
    ]);
    $mock->shouldReceive('scrobbleUrl')->andReturn('http://x/scrobble?key=__KEY__');
    app()->instance(PlexClient::class, $mock);
});

it('renders the favorites page with the empty state when there are no favorites', function () {
    app(PlexClient::class)->shouldReceive('favoriteTracks')->andReturn(collect());

    Livewire::test('pages::favorites')
        ->assertSee('Favorites')
        ->assertSee('No favorites yet');
});

it('renders each favorite track row', function () {
    app(PlexClient::class)->shouldReceive('favoriteTracks')->andReturn(collect([
        new Track(id: '11', title: 'Heart of Glass', artist: 'Blondie', album: 'Parallel Lines',
            trackNumber: 5, durationMs: 200000, partId: 1, container: 'mp3', userRating: 10),
        new Track(id: '12', title: 'Smalltown Boy', artist: 'Bronski Beat', album: 'Age of Consent',
            trackNumber: 1, durationMs: 300000, partId: 2, container: 'mp3', userRating: 10),
    ]));

    Livewire::test('pages::favorites')
        ->assertSee('Heart of Glass')
        ->assertSee('Smalltown Boy')
        ->assertSee('Blondie')
        ->assertDontSee('No favorites yet');
});

it('dispatches play-track with contextType=favorites when a row is played', function () {
    $track = new Track(id: '11', title: 'X', artist: 'Y', album: 'Z',
        trackNumber: 1, durationMs: 1000, partId: 1, container: 'mp3', userRating: 10);
    app(PlexClient::class)->shouldReceive('favoriteTracks')->andReturn(collect([$track]));

    Livewire::test('pages::favorites')
        ->call('playTrack', '11')
        ->assertDispatched('play-track', fn ($name, $args) => ($args['contextType'] ?? null) === 'favorites');
});

it('toggleHeart on the favorites page calls PlexClient::rateTrack', function () {
    app(PlexClient::class)->shouldReceive('favoriteTracks')->andReturn(collect());
    app(PlexClient::class)->shouldReceive('rateTrack')->with('99', 0)->once();

    Livewire::test('pages::favorites')
        ->call('toggleHeart', '99', 0)
        ->assertHasNoErrors();
});

it('shows a notify toast when favoriteTracks throws', function () {
    app(PlexClient::class)->shouldReceive('favoriteTracks')
        ->andThrow(new PlexUnreachableException('down'));

    Livewire::test('pages::favorites')
        ->assertDispatched('notify', fn ($name, $args) => ($args['type'] ?? null) === 'error');
});
