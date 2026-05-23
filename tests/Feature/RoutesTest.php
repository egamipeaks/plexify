<?php

use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\PlexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the library route', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Library');
});

it('renders the search route', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => null]);
        $mock->shouldReceive('scrobbleUrl')->andReturn('');
        $mock->shouldReceive('searchAll')->andReturn(SearchResults::empty());
    });

    $this->get('/search')
        ->assertOk()
        ->assertSee('Search your library');
});

it('renders the search route with a query', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => null]);
        $mock->shouldReceive('scrobbleUrl')->andReturn('');
        $mock->shouldReceive('searchAll')->with('foo')->andReturn(SearchResults::empty());
    });

    $this->get('/search?q=foo')
        ->assertOk()
        ->assertSee('data-region="search-overlay"', false);
});

it('renders the playlist detail route', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn([
            'name' => 'Test',
            'reachable' => false,
            'connection' => 'down',
            'machineIdentifier' => null,
        ]);
        $mock->shouldReceive('scrobbleUrl')->andReturn('');
    });

    $this->get('/playlist/abc123')
        ->assertOk()
        ->assertSee('Playlist not found');
});

it('renders the settings route', function () {
    $this->get('/settings')
        ->assertOk()
        ->assertSee('Settings');
});

it('renders the recently added route', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('recentlyAddedAlbums')->andReturn(collect());
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => null]);
        $mock->shouldReceive('scrobbleUrl')->andReturn('');
    });

    $this->get('/recently-added')
        ->assertOk()
        ->assertSee('Recently Added');
});

it('renders the recently played route', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('recentlyPlayedTracks')->andReturn(collect());
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => null]);
        $mock->shouldReceive('scrobbleUrl')->andReturn('');
    });

    $this->get('/recently-played')
        ->assertOk()
        ->assertSee('Recently Played');
});

it('resolves /favorites to the favorites page', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('ping')->andReturn(['name' => 'T', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'M']);
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('searchAll')->andReturn(SearchResults::empty());
        $mock->shouldReceive('thumbUrl')->andReturn(null);
        $mock->shouldReceive('favoriteTracks')->andReturn(collect());
        $mock->shouldReceive('scrobbleUrl')->andReturn('http://x/scrobble?key=__KEY__');
    });

    $this->get('/favorites')->assertOk();
});
