<?php

use App\Services\Plex\PlexClient;
use App\Support\AppSetting;
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

it('puts the density attribute on the body element', function () {
    AppSetting::setDensity('compact');

    $this->get('/settings')
        ->assertOk()
        ->assertSee('data-density="compact"', false);
});

it('defaults the body density attribute to comfortable', function () {
    $this->get('/settings')
        ->assertOk()
        ->assertSee('data-density="comfortable"', false);
});
