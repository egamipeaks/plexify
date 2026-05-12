<?php

use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Livewire\Livewire;

function samplePlaylist(): Playlist
{
    return new Playlist(
        id: '4242',
        title: 'Late Night',
        trackCount: 2,
        durationMs: 575000,
        thumb: '/playlists/4242/composite/1700000000',
        playlistType: 'audio',
        summary: 'Wind-down listening.',
    );
}

function samplePlaylistTracks(): Collection
{
    return collect([
        new Track(id: '8001', title: 'Holocene', artist: 'Bon Iver', album: 'Bon Iver, Bon Iver', trackNumber: 6, durationMs: 337000, partId: 770001, container: 'flac', thumb: '/t/8001', albumId: '5001', artistId: '100'),
        new Track(id: '8002', title: 'Skinny Love', artist: 'Bon Iver', album: 'For Emma, Forever Ago', trackNumber: 3, durationMs: 238000, partId: 770002, container: 'flac', thumb: '/t/8002', albumId: '5002', artistId: '100'),
    ]);
}

function mockPlexForPlaylist(): void
{
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/library/parts/{$t->partId}/file.{$t->container}?X-Plex-Token=t");
    });
}

it('renders the playlist header with title, description and song count + duration', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSee('PLAYLIST')
        ->assertSee('Late Night')
        ->assertSee('Wind-down listening.')
        ->assertSee('2 songs, 9:35'); // 337000 + 238000 = 575000 ms = 575s = 9:35
});

it('renders one row per track with the album column', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSee('Holocene')
        ->assertSee('Bon Iver, Bon Iver')
        ->assertSee('Skinny Love')
        ->assertSee('For Emma, Forever Ago')
        ->assertSeeHtml('plextune/track');
});

it('dispatches play-track for a clicked row', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('playTrack', '8002')
        ->assertDispatched('play-track',
            queue: [
                ['id' => '8001', 'url' => 'https://server/library/parts/770001/file.flac?X-Plex-Token=t', 'title' => 'Holocene', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8001', 'albumId' => '5001', 'artistId' => '100'],
                ['id' => '8002', 'url' => 'https://server/library/parts/770002/file.flac?X-Plex-Token=t', 'title' => 'Skinny Love', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8002', 'albumId' => '5002', 'artistId' => '100'],
            ],
            index: 1,
            contextType: 'playlist',
            contextId: '4242',
        );
});

it('plays the first track when Play is pressed', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('playAll')
        ->assertDispatched('play-track',
            queue: [
                ['id' => '8001', 'url' => 'https://server/library/parts/770001/file.flac?X-Plex-Token=t', 'title' => 'Holocene', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8001', 'albumId' => '5001', 'artistId' => '100'],
                ['id' => '8002', 'url' => 'https://server/library/parts/770002/file.flac?X-Plex-Token=t', 'title' => 'Skinny Love', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8002', 'albumId' => '5002', 'artistId' => '100'],
            ],
            index: 0,
            contextType: 'playlist',
            contextId: '4242',
        );
});

it('shuffles the playlist when Shuffle is pressed', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('shuffle')
        ->assertDispatched('play-track', function ($event, $params) {
            return ($params['shuffle'] ?? false) === true
                && in_array($params['index'], [0, 1], true)
                && count($params['queue']) === 2
                && ($params['contextType'] ?? null) === 'playlist'
                && ($params['contextId'] ?? null) === '4242';
        });
});

it('shows the Plex-unreachable panel and recovers on retry', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->once()->andThrow(new PlexUnreachableException('connection refused'));
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSee("Can't reach your Plex server")
        ->assertSee('Retry')
        ->call('retry')
        ->assertSee('Late Night');
});

it('shows a not-found panel when the id is not a known playlist', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        // playlistTracks must never be called for an unknown id
        $mock->shouldReceive('playlistTracks')->never();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => 'nope'])
        ->assertSee('Playlist not found');
});
