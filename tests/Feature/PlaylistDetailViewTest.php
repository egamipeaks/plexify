<?php

use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use App\Support\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
        new Track(id: '8001', title: 'Holocene', artist: 'Bon Iver', album: 'Bon Iver, Bon Iver', trackNumber: 6, durationMs: 337000, partId: 770001, container: 'flac', thumb: '/t/8001', albumId: '5001', artistId: '100', playlistItemId: 'i1'),
        new Track(id: '8002', title: 'Skinny Love', artist: 'Bon Iver', album: 'For Emma, Forever Ago', trackNumber: 3, durationMs: 238000, partId: 770002, container: 'flac', thumb: '/t/8002', albumId: '5002', artistId: '100', playlistItemId: 'i2'),
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

it('links the artist and album to the library from each comfortable playlist track row', function () {
    mockPlexForPlaylist();

    $artistUrl = route('library', ['artist' => '100']);
    $albumUrl1 = route('library', ['artist' => '100', 'album' => '5001']);
    $albumUrl2 = route('library', ['artist' => '100', 'album' => '5002']);

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSet('tracksCompact', false)
        ->assertSeeHtml("Livewire.navigate('".e($artistUrl)."')")
        ->assertSeeHtml("Livewire.navigate('".e($albumUrl1)."')")
        ->assertSeeHtml("Livewire.navigate('".e($albumUrl2)."')");
});

it('dispatches play-track for a clicked row', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('playTrack', '8002')
        ->assertDispatched('play-track',
            queue: [
                ['id' => '8001', 'url' => 'https://server/library/parts/770001/file.flac?X-Plex-Token=t', 'title' => 'Holocene', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8001', 'albumId' => '5001', 'artistId' => '100', 'userRating' => 0],
                ['id' => '8002', 'url' => 'https://server/library/parts/770002/file.flac?X-Plex-Token=t', 'title' => 'Skinny Love', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8002', 'albumId' => '5002', 'artistId' => '100', 'userRating' => 0],
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
                ['id' => '8001', 'url' => 'https://server/library/parts/770001/file.flac?X-Plex-Token=t', 'title' => 'Holocene', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8001', 'albumId' => '5001', 'artistId' => '100', 'userRating' => 0],
                ['id' => '8002', 'url' => 'https://server/library/parts/770002/file.flac?X-Plex-Token=t', 'title' => 'Skinny Love', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8002', 'albumId' => '5002', 'artistId' => '100', 'userRating' => 0],
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

it('renders the collapsed playlist header when the setting is on', function () {
    AppSetting::setAlbumHeaderCollapsed(true);
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSet('headerCollapsed', true)
        ->assertSeeHtml('data-playlist-header-collapsed');
});

it('persists the playlist header collapsed toggle', function () {
    AppSetting::setAlbumHeaderCollapsed(true);
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->set('headerCollapsed', false);

    expect(AppSetting::albumHeaderCollapsed())->toBeFalse();
});

it('renders the compact playlist tracklist when tracksCompact is on', function () {
    AppSetting::setPlaylistTracksCompact(true);
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSet('tracksCompact', true)
        ->assertSeeHtml('grid-template-columns: 20px 1.4fr 1fr 1fr 50px');
});

it('links the artist and album to the library in the compact playlist tracklist', function () {
    AppSetting::setPlaylistTracksCompact(true);
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSet('tracksCompact', true)
        ->assertSeeHtml("Livewire.navigate('".e(route('library', ['artist' => '100']))."')")
        ->assertSeeHtml("Livewire.navigate('".e(route('library', ['artist' => '100', 'album' => '5001']))."')");
});

it('persists the playlist tracklist compact toggle', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])->set('tracksCompact', true);
    expect(AppSetting::playlistTracksCompact())->toBeTrue();
});

it('shows an empty-playlist state for a playlist with no tracks', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andReturn(collect([
            new Playlist(
                id: '9999',
                title: 'Empty Playlist',
                trackCount: 0,
                durationMs: 0,
                thumb: null,
                playlistType: 'audio',
                summary: null,
            ),
        ]));
        $mock->shouldReceive('playlistTracks')->with('9999')->andReturn(collect());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '9999'])
        ->assertSee('This playlist is empty');
});

it('renders the artist and album as plain text when a playlist track has no album/artist ids', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(collect([
            new Track(id: '8003', title: 'Local Recording', artist: 'Field Tape', album: 'Untitled', trackNumber: 1, durationMs: 120000, partId: 770003, container: 'mp3', thumb: null, albumId: null, artistId: null),
        ]));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSee('Local Recording')
        ->assertSee('Field Tape')
        ->assertSee('Untitled')
        ->assertDontSee('?artist=');
});

it('moveTrack moving item 2 before item 1 calls Plex with after=null and returns true', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->once()->with('4242', 'i2', null);
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'i2', 'i1', 'before')
        ->assertReturned(true);
});

it('moveTrack moving item 1 after item 2 calls Plex with after=i2 and returns true', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->once()->with('4242', 'i1', 'i2');
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'i1', 'i2', 'after')
        ->assertReturned(true);
});

it('moveTrack on the same item is a no-op that returns true', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->never();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'i1', 'i1', 'before')
        ->assertReturned(true);
});

it('moveTrack to the position it already occupies is a no-op that returns true', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->never();
    });

    // item 1 dropped "before" item 2 — it's already immediately before item 2.
    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'i1', 'i2', 'before')
        ->assertReturned(true);
});

it('moveTrack with an unknown dragged id returns false without calling Plex', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->never();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'nope', 'i1', 'before')
        ->assertReturned(false);
});

it('moveTrack surfaces a Plex outage as a notify toast and returns false', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->once()->andThrow(new PlexUnreachableException('down'));
    });

    // Moving i2 before i1 requires a Plex call (i2 is currently after i1).
    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'i2', 'i1', 'before')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error');
});

it('removeTrack calls PlexClient::removeTrackFromPlaylist and returns true', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('removeTrackFromPlaylist')->once()->with('4242', 'i2');
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('removeTrack', 'i2')
        ->assertReturned(true);
});

it('removeTrack with an unknown item returns false without calling Plex', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('removeTrackFromPlaylist')->never();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('removeTrack', 'nope')
        ->assertReturned(false);
});

it('removeTrack surfaces a Plex outage as a notify toast and returns false', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('removeTrackFromPlaylist')->once()->andThrow(new PlexUnreachableException('down'));
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('removeTrack', 'i2')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error');
});

it('calls PlexClient::rateTrack via toggleHeart on the playlist-detail page', function () {
    $mock = Mockery::mock(PlexClient::class);
    $mock->shouldReceive('ping')->andReturn(['name' => 'T', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'M']);
    $mock->shouldReceive('playlists')->andReturn(collect());
    $mock->shouldReceive('searchAll')->andReturn(SearchResults::empty());
    $mock->shouldReceive('thumbUrl')->andReturn(null);
    $mock->shouldReceive('playlistTracks')->andReturn(collect());
    $mock->shouldReceive('rateTrack')->with('77', 0)->once();
    app()->instance(PlexClient::class, $mock);

    Livewire::test('pages::playlist-detail', ['playlist' => '1'])
        ->call('toggleHeart', '77', 0)
        ->assertHasNoErrors();
});
