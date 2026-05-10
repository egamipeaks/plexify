<?php

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
        ]);

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
        ]);

    Livewire::test('server-chip')
        ->assertSee('Unreachable')
        ->assertSeeHtml('bg-red-500');
});

it('handles PlexException gracefully and shows error state', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('ping')
        ->andThrow(new \App\Services\Plex\Exceptions\PlexAuthException('bad token'));

    Livewire::test('server-chip')
        ->assertSee('Auth error');
});
