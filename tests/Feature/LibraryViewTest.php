<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

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
    $track = new Track(id: '9001', title: 'Test', artist: 'A', album: 'B', trackNumber: 1, durationMs: 1000, partId: 999, container: 'flac');

    $this->mock(PlexClient::class, function ($mock) use ($track) {
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
            url: 'https://plex/file.flac?X-Plex-Token=t',
            title: 'Test',
            artist: 'A',
            artwork: null,
        );
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
