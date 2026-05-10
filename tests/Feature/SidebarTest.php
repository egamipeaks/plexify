<?php

use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

it('lists the Plex playlists as links into the detail view', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andReturn(collect([
            new Playlist(id: '4242', title: 'Late Night', trackCount: 12, durationMs: 0, thumb: '/t/4242', playlistType: 'audio'),
            new Playlist(id: '4243', title: 'Bangers', trackCount: 30, durationMs: 0, thumb: null, playlistType: 'audio'),
        ]));
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('ping')->andReturn(['name' => 'HOME', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'abc']);
    });

    Livewire::test('sidebar')
        ->assertSee('Late Night')
        ->assertSee('Bangers')
        ->assertSeeHtml('href="'.route('playlist', '4242').'"')
        ->assertSeeHtml('href="'.route('playlist', '4243').'"')
        ->assertDontSee('No playlists yet');
});

it('falls back to the empty placeholder when Plex is unreachable', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andThrow(new PlexUnreachableException('refused'));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn(['name' => 'HOME', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => 'abc']);
    });

    Livewire::test('sidebar')
        ->assertSee('No playlists yet')
        ->assertSee('Your Playlists'); // the card chrome still renders
});
