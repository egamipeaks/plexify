<?php

use App\Services\Plex\Exceptions\PlexAuthException;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

it('renders the server name and reachable state', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('ping')
        ->andReturn([
            'name' => 'HOMESERVER',
            'reachable' => true,
            'connection' => 'direct',
            'machineIdentifier' => 'abc',
        ])
        ->shouldReceive('musicSectionTitle')
        ->andReturn('Music');

    Livewire::test('server-chip')
        ->assertSee('HOMESERVER')
        ->assertSee('Direct')
        ->assertSeeHtml('bg-accent'); // status dot color
});

it('shows red dot and Unreachable when ping returns unreachable', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('ping')
        ->andReturn([
            'name' => 'HOMESERVER',
            'reachable' => false,
            'connection' => 'down',
            'machineIdentifier' => null,
        ])
        ->shouldReceive('musicSectionTitle')
        ->andReturn('Music');

    Livewire::test('server-chip')
        ->assertSee('Unreachable')
        ->assertSeeHtml('bg-red-500');
});

it('handles PlexException gracefully and shows error state', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('ping')
        ->andThrow(new PlexAuthException('bad token'));

    Livewire::test('server-chip')
        ->assertSee('Auth error');
});

it('shows the effective library name beside the connection', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn([
        'name' => 'HomeServer',
        'reachable' => true,
        'connection' => 'direct',
    ]);
    $plex->shouldReceive('musicSectionTitle')->andReturn('Music');
    app()->instance(PlexClient::class, $plex);

    Livewire::test('server-chip')
        ->assertSee('Music · Direct connection');
});

it('renders without a library name when Plex is unreachable', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andThrow(new PlexUnreachableException('down'));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('server-chip')
        ->assertSee('Unreachable')
        ->assertDontSee('Music');
});

it('re-resolves the library name when a library-changed event is dispatched', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn([
        'name' => 'HomeServer',
        'reachable' => true,
        'connection' => 'direct',
    ]);
    $plex->shouldReceive('musicSectionTitle')->andReturn('Music', 'Classical');
    app()->instance(PlexClient::class, $plex);

    Livewire::test('server-chip')
        ->assertSee('Music')
        ->dispatch('library-changed')
        ->assertSee('Classical');
});
