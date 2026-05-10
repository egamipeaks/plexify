<?php

use App\Services\Plex\PlexClient;

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
