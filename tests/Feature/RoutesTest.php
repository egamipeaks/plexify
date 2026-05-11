<?php

use App\Services\Plex\PlexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the library route', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Library');
});

it('renders the search route', function () {
    $this->get('/search')
        ->assertOk()
        ->assertSee('Search');
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
    });

    $this->get('/recently-played')
        ->assertOk()
        ->assertSee('Recently Played');
})->skip('page component added in Task 6');
