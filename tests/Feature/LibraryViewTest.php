<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use App\Support\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists artists from PlexClient on mount', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 5),
            new Artist(id: '200', name: 'Radiohead', thumb: null, albumCount: 9),
        ]));
    });

    Livewire::test('pages::library')
        ->assertSee('Bon Iver')
        ->assertSee('Radiohead');
});

it('renders error panel when Plex is unreachable on mount', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('artists')
        ->andThrow(new PlexUnreachableException("Can't reach"));

    Livewire::test('pages::library')
        ->assertSee("Can't reach your Plex server")
        ->assertSee('Retry');
});

it('selects an artist on click', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 5),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect());
    });

    Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->assertSet('selectedArtistId', '100');
});

it('lists albums for the selected artist', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 2),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: '22, A Million', artist: 'Bon Iver', year: 2016, thumb: null, trackCount: 10, durationMs: 2160000),
            new Album(id: '1002', title: 'i,i', artist: 'Bon Iver', year: 2019, thumb: null, trackCount: 13, durationMs: 2520000),
        ]));
    });

    Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->assertSee('22, A Million')
        ->assertSee('i,i')
        ->assertSee('2016')
        ->assertSee('2019');
});

it('renders album header and tracklist when album selected', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: '22, A Million', artist: 'Bon Iver', year: 2016, thumb: null, trackCount: 2, durationMs: 360000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->with('1001')->andReturn(collect([
            new Track(id: '9001', title: '22 (OVER S∞∞N)', artist: 'Bon Iver', album: '22, A Million', trackNumber: 1, durationMs: 169000, partId: 9001001, container: 'flac'),
            new Track(id: '9002', title: '10 d E A T h b R E a s T', artist: 'Bon Iver', album: '22, A Million', trackNumber: 2, durationMs: 240000, partId: 9001002, container: 'flac'),
        ]));
    });

    Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->call('selectAlbum', '1001')
        ->assertSee('22, A Million')
        ->assertSee('22 (OVER S∞∞N)')
        ->assertSee('10 d E A T h b R E a s T')
        ->assertSee('2:49') // 169s formatted
        ->assertSee('4:00') // 240s formatted
        ->assertSeeHtml('ondragstart=')        // track rows are draggable
        ->assertSeeHtml('plextune/track')
        ->assertSeeHtml('plextune/album');     // the album-header cover is draggable
});

it('dispatches play-track event with stream URL when track clicked', function () {
    $track = new Track(id: '9001', title: 'Test', artist: 'A', album: 'B', trackNumber: 1, durationMs: 1000, partId: 999, container: 'flac', albumId: '1001', artistId: '100');

    $this->mock(PlexClient::class, function ($mock) use ($track) {
        $mock->makePartial();
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'A', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->andReturn(collect([
            new Album(id: '1001', title: 'B', artist: 'A', year: 2024, thumb: null, trackCount: 1, durationMs: 1000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->andReturn(collect([$track]));
        $mock->shouldReceive('streamUrl')->with(Mockery::on(fn ($t) => $t->id === '9001'))->andReturn('https://plex/file.flac?X-Plex-Token=t');
        $mock->shouldReceive('thumbUrl')->with(null)->andReturn(null);
    });

    Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->call('selectAlbum', '1001')
        ->call('playTrack', '9001')
        ->assertDispatched('play-track',
            queue: [[
                'id' => '9001',
                'url' => 'https://plex/file.flac?X-Plex-Token=t',
                'title' => 'Test',
                'artist' => 'A',
                'artwork' => null,
                'albumId' => '1001',
                'artistId' => '100',
                'userRating' => 0,
            ]],
            index: 0,
            contextType: 'album',
            contextId: '1001',
        );
});

it('plays the whole album when the album-header Play button is pressed', function () {
    $t1 = new Track(id: '9001', title: 'One', artist: 'A', album: 'B', trackNumber: 1, durationMs: 1000, partId: 991, container: 'flac', albumId: '1001', artistId: '100');
    $t2 = new Track(id: '9002', title: 'Two', artist: 'A', album: 'B', trackNumber: 2, durationMs: 2000, partId: 992, container: 'flac', albumId: '1001', artistId: '100');

    $this->mock(PlexClient::class, function ($mock) use ($t1, $t2) {
        $mock->makePartial();
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'A', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->andReturn(collect([
            new Album(id: '1001', title: 'B', artist: 'A', year: 2024, thumb: null, trackCount: 2, durationMs: 3000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->andReturn(collect([$t1, $t2]));
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://plex/{$t->partId}.flac?X-Plex-Token=t");
        $mock->shouldReceive('thumbUrl')->with(null)->andReturn(null);
    });

    Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->call('selectAlbum', '1001')
        ->call('playAlbum')
        ->assertDispatched('play-track',
            queue: [
                ['id' => '9001', 'url' => 'https://plex/991.flac?X-Plex-Token=t', 'title' => 'One', 'artist' => 'A', 'artwork' => null, 'albumId' => '1001', 'artistId' => '100', 'userRating' => 0],
                ['id' => '9002', 'url' => 'https://plex/992.flac?X-Plex-Token=t', 'title' => 'Two', 'artist' => 'A', 'artwork' => null, 'albumId' => '1001', 'artistId' => '100', 'userRating' => 0],
            ],
            index: 0,
            contextType: 'album',
            contextId: '1001',
        );
});

it('shuffles the album when the album-header Shuffle button is pressed', function () {
    $t1 = new Track(id: '9001', title: 'One', artist: 'A', album: 'B', trackNumber: 1, durationMs: 1000, partId: 991, container: 'flac', albumId: '1001', artistId: '100');
    $t2 = new Track(id: '9002', title: 'Two', artist: 'A', album: 'B', trackNumber: 2, durationMs: 2000, partId: 992, container: 'flac', albumId: '1001', artistId: '100');

    $this->mock(PlexClient::class, function ($mock) use ($t1, $t2) {
        $mock->makePartial();
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'A', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->andReturn(collect([
            new Album(id: '1001', title: 'B', artist: 'A', year: 2024, thumb: null, trackCount: 2, durationMs: 3000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->andReturn(collect([$t1, $t2]));
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://plex/{$t->partId}.flac?X-Plex-Token=t");
        $mock->shouldReceive('thumbUrl')->with(null)->andReturn(null);
    });

    Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->call('selectAlbum', '1001')
        ->call('shuffleAlbum')
        ->assertDispatched('play-track', function ($event, $params) {
            return ($params['shuffle'] ?? false) === true
                && in_array($params['index'], [0, 1], true)
                && count($params['queue']) === 2
                && $params['queue'][0]['id'] === '9001'
                && $params['queue'][1]['id'] === '9002'
                && ($params['contextType'] ?? null) === 'album'
                && ($params['contextId'] ?? null) === '1001';
        });
});

it('preselects an artist passed as a query parameter', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: '22, A Million', artist: 'Bon Iver', year: 2016, thumb: null, trackCount: 1, durationMs: 1000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->andReturn(collect());
    });

    Livewire::withQueryParams(['artist' => '100'])
        ->test('pages::library')
        ->assertSet('selectedArtistId', '100')
        ->assertSee('22, A Million');
});

it('preselects both artist and album passed as query parameters', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: '22, A Million', artist: 'Bon Iver', year: 2016, thumb: null, trackCount: 1, durationMs: 169000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->with('1001')->andReturn(collect([
            new Track(id: '9001', title: '715 - CRΣΣKS', artist: 'Bon Iver', album: '22, A Million', trackNumber: 4, durationMs: 178000, partId: 1, container: 'flac'),
        ]));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::withQueryParams(['artist' => '100', 'album' => '1001'])
        ->test('pages::library')
        ->assertSet('selectedArtistId', '100')
        ->assertSet('selectedAlbumId', '1001')
        ->assertSee('715 - CRΣΣKS');
});

it('starts with no selection when no query parameters present', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
    });

    Livewire::test('pages::library')
        ->assertSet('selectedArtistId', null)
        ->assertSet('selectedAlbumId', null);
});

it('hides the album header when no album is selected and lets the miller columns fill the page', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::library')
        ->assertDontSee('ALBUM')
        ->assertDontSeeHtml('wire:click="closeAlbum"')
        ->assertSeeHtml('flex-1 min-h-0');
});

it('closes the album header when closeAlbum is called', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: 'For Emma', artist: 'Bon Iver', year: 2007, thumb: null, trackCount: 1, durationMs: 0, artistId: '100'),
        ]));
        $mock->shouldReceive('tracksForAlbum')->with('1001')->andReturn(collect());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::withQueryParams(['artist' => '100', 'album' => '1001'])
        ->test('pages::library')
        ->assertSet('selectedAlbumId', '1001')
        ->assertSeeHtml('wire:click="closeAlbum"')
        ->call('closeAlbum')
        ->assertSet('selectedAlbumId', null)
        ->assertDontSeeHtml('wire:click="closeAlbum"');
});

it('renders the collapsed album header when the setting is on', function () {
    AppSetting::setAlbumHeaderCollapsed(true);

    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: 'For Emma', artist: 'Bon Iver', year: 2007, thumb: null, trackCount: 1, durationMs: 0, artistId: '100'),
        ]));
        $mock->shouldReceive('tracksForAlbum')->with('1001')->andReturn(collect());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::withQueryParams(['artist' => '100', 'album' => '1001'])
        ->test('pages::library')
        ->assertSet('headerCollapsed', true)
        ->assertSeeHtml('data-album-header-collapsed');
});

it('shows a no-music state when the library has no artists', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect());
    });

    Livewire::test('pages::library')
        ->assertSee('No music found');
});

it('persists the album header collapsed toggle', function () {
    AppSetting::setAlbumHeaderCollapsed(true);

    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: 'For Emma', artist: 'Bon Iver', year: 2007, thumb: null, trackCount: 1, durationMs: 0, artistId: '100'),
        ]));
        $mock->shouldReceive('tracksForAlbum')->with('1001')->andReturn(collect());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::withQueryParams(['artist' => '100', 'album' => '1001'])
        ->test('pages::library')
        ->set('headerCollapsed', false);

    expect(AppSetting::albumHeaderCollapsed())->toBeFalse();
});

it('renders the compact artist row layout when artistsCompact is on', function () {
    AppSetting::setArtistsCompact(true);

    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 5),
        ]));
    });

    Livewire::test('pages::library')
        ->assertSet('artistsCompact', true)
        ->assertSeeHtml('py-[3px]');
});

it('persists the artists compact toggle', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 5),
        ]));
    });

    Livewire::test('pages::library')->set('artistsCompact', true);

    expect(AppSetting::artistsCompact())->toBeTrue();
});

it('persists the albums compact toggle', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 5),
        ]));
    });

    Livewire::test('pages::library')->set('albumsCompact', true);

    expect(AppSetting::albumsCompact())->toBeTrue();
});

it('renders the compact tracklist layout when tracksCompact is on', function () {
    AppSetting::setLibraryTracksCompact(true);

    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: '22, A Million', artist: 'Bon Iver', year: 2016, thumb: null, trackCount: 1, durationMs: 169000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->with('1001')->andReturn(collect([
            new Track(id: '9001', title: '715 - CRΣΣKS', artist: 'Bon Iver', album: '22, A Million', trackNumber: 4, durationMs: 178000, partId: 1, container: 'flac'),
        ]));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::withQueryParams(['artist' => '100', 'album' => '1001'])
        ->test('pages::library')
        ->assertSet('tracksCompact', true)
        ->assertSeeHtml('grid-template-columns: 20px 1.4fr 1fr 50px');
});

it('persists the library tracklist compact toggle', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: '22, A Million', artist: 'Bon Iver', year: 2016, thumb: null, trackCount: 1, durationMs: 169000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->with('1001')->andReturn(collect([
            new Track(id: '9001', title: '715 - CRΣΣKS', artist: 'Bon Iver', album: '22, A Million', trackNumber: 4, durationMs: 178000, partId: 1, container: 'flac'),
        ]));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::withQueryParams(['artist' => '100', 'album' => '1001'])
        ->test('pages::library')
        ->set('tracksCompact', true);

    expect(AppSetting::libraryTracksCompact())->toBeTrue();
});

it('renders the heart-button component in the album tracklist', function () {
    $hearted = new Track(
        id: '99', title: 'A', artist: 'Artist', album: 'Album',
        trackNumber: 1, durationMs: 1000, partId: 1, container: 'mp3',
        userRating: 10,
    );
    $cold = new Track(
        id: '100', title: 'B', artist: 'Artist', album: 'Album',
        trackNumber: 2, durationMs: 1000, partId: 2, container: 'mp3',
        userRating: 0,
    );

    $this->mock(PlexClient::class, function ($mock) use ($hearted, $cold) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: 'A1', name: 'Artist', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->andReturn(collect([
            new Album(id: 'AL1', title: 'Album', artist: 'Artist', year: 2020, thumb: null, trackCount: 2, durationMs: 2000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->andReturn(collect([$hearted, $cold]));
        $mock->shouldReceive('thumbUrl')->andReturn(null);
    });

    Livewire::withQueryParams(['artist' => 'A1', 'album' => 'AL1'])
        ->test('pages::library')
        ->assertSeeHtml('data-rating-key="99"')
        ->assertSeeHtml('data-rating-key="100"');
});

it('calls PlexClient::rateTrack via toggleHeart on the library page', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect());
        $mock->shouldReceive('rateTrack')->with('77', 10)->once();
    });

    Livewire::test('pages::library')
        ->call('toggleHeart', '77', 10)
        ->assertHasNoErrors();
});

it('shows the album year in parentheses next to the title in both density variants', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 2),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: '22, A Million', artist: 'Bon Iver', year: 2016, thumb: null, trackCount: 10, durationMs: 2160000),
            new Album(id: '1002', title: 'Untitled', artist: 'Bon Iver', year: null, thumb: null, trackCount: 1, durationMs: 1000),
        ]));
    });

    $component = Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->assertSee('22, A Million (2016)')
        ->assertSee('Untitled')
        ->assertDontSee('Untitled (');

    $component->set('albumsCompact', true)
        ->assertSee('22, A Million (2016)')
        ->assertSee('Untitled')
        ->assertDontSee('Untitled (');
});
