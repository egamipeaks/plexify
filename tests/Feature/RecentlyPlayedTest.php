<?php

use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

function recentTrack(string $id, string $title, string $album = 'Some Album', string $artist = 'Some Artist'): Track
{
    return new Track(
        id: $id,
        title: $title,
        artist: $artist,
        album: $album,
        trackNumber: 1,
        durationMs: 215000,
        partId: (int) $id,
        container: 'flac',
        thumb: "/library/metadata/{$id}/thumb",
    );
}

function mockRecentlyPlayedPlex(?Closure $extra = null): void
{
    test()->mock(PlexClient::class, function ($mock) use ($extra) {
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($track) => "https://stream/{$track->id}.flac");
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => null]);

        if ($extra) {
            $extra($mock);
        }
    });
}

it('renders a track row per Recently Played track', function () {
    mockRecentlyPlayedPlex(function ($mock) {
        $mock->shouldReceive('recentlyPlayedTracks')->once()->andReturn(collect([
            recentTrack('70001', 'One'),
            recentTrack('70002', 'Two'),
        ]));
    });

    Livewire::test('pages::recently-played')
        ->assertSee('Recently Played')
        ->assertSee('One')
        ->assertSee('Two')
        ->assertSeeHtml('wire:key="track-70001"')
        ->assertSeeHtml('wire:key="track-70002"');
});

it('shows an empty state when nothing has been played', function () {
    mockRecentlyPlayedPlex(function ($mock) {
        $mock->shouldReceive('recentlyPlayedTracks')->once()->andReturn(collect());
    });

    Livewire::test('pages::recently-played')
        ->assertSee('Recently Played')
        ->assertSee('Nothing played yet.');
});

it('shows an error panel when Plex is unreachable and recovers on retry', function () {
    $calls = 0;
    mockRecentlyPlayedPlex(function ($mock) use (&$calls) {
        $mock->shouldReceive('recentlyPlayedTracks')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? throw new PlexUnreachableException('refused')
                : collect([recentTrack('70001', 'After Retry')]);
        });
    });

    Livewire::test('pages::recently-played')
        ->assertSee("Can't reach your Plex server")
        ->call('retry')
        ->assertDontSee("Can't reach your Plex server")
        ->assertSee('After Retry');
});

it('playTrack dispatches play-track with the stream URL', function () {
    mockRecentlyPlayedPlex(function ($mock) {
        $mock->shouldReceive('recentlyPlayedTracks')->andReturn(collect([
            recentTrack('70001', 'Song'),
        ]));
    });

    Livewire::test('pages::recently-played')
        ->call('playTrack', '70001')
        ->assertDispatched('play-track', url: 'https://stream/70001.flac', title: 'Song');
});
