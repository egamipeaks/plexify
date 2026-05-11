<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

function sampleResults(): SearchResults
{
    return new SearchResults(
        tracks: collect([
            new Track(id: '9001', title: 'Holocene', artist: 'Bon Iver', album: 'Bon Iver, Bon Iver', trackNumber: 0, durationMs: 337000, partId: 660001, container: 'flac', thumb: '/t/9001', albumId: '1001', artistId: '100'),
        ]),
        artists: collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: '/t/100', albumCount: 5),
        ]),
        albums: collect([
            new Album(id: '1001', title: 'For Emma, Forever Ago', artist: 'Bon Iver', year: 2007, thumb: '/t/1001', trackCount: 0, durationMs: 0, artistId: '100'),
        ]),
        playlists: collect([
            new Playlist(id: '4242', title: 'Bon Voyage', trackCount: 18, durationMs: 4200000, thumb: '/t/4242', playlistType: 'audio'),
        ]),
    );
}

it('shows the search prompt when the query is empty', function () {
    Livewire::test('pages::search')
        ->assertSee('Search your library')
        ->assertSee('Find any song, artist, or album from your Plex server.');
});

it('renders the results grouped by type with filter pills', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->assertSee('Playlists')
        ->assertSee('Albums')
        ->assertSee('Artists')
        ->assertSee('Tracks')
        ->assertSee('Bon Voyage')
        ->assertSee('For Emma, Forever Ago')
        ->assertSee('Bon Iver')
        ->assertSee('Holocene')
        ->assertSeeHtml('plextune/track');
});

it('shows a no-results message when the search returns nothing', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('searchAll')->andReturn(SearchResults::empty());

    Livewire::test('pages::search', ['q' => 'zzzzz'])
        ->assertSee('No results found for "zzzzz"');
});

it('shows the Plex-unreachable panel when the search fails', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('searchAll')->andThrow(new PlexUnreachableException('connection refused'));

    Livewire::test('pages::search', ['q' => 'bon'])
        ->assertSee("Can't reach your Plex server")
        ->assertSee('Retry');
});

it('narrows to a single section when a filter pill is clicked', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->call('setFilter', 'artists')
        ->assertSet('filter', 'artists')
        ->assertSee('Bon Iver')
        ->assertDontSee('Holocene')
        ->assertDontSee('Bon Voyage');
});

it('dispatches play-track when a track row is clicked', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->with(Mockery::on(fn ($t) => $t->id === '9001'))
            ->andReturn('https://server/library/parts/660001/file.flac?X-Plex-Token=t');
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->call('playTrack', '9001')
        ->assertDispatched('play-track',
            queue: [[
                'id' => '9001',
                'url' => 'https://server/library/parts/660001/file.flac?X-Plex-Token=t',
                'title' => 'Holocene',
                'artist' => 'Bon Iver',
                'artwork' => 'https://thumb/t/9001',
                'albumId' => '1001',
                'artistId' => '100',
            ]],
            index: 0,
        );
});

it('navigates to the library when an artist row is clicked', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->call('goToArtist', '100')
        ->assertRedirect(route('library', ['artist' => '100']));
});

it('navigates to the library album when an album row is clicked', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->call('goToAlbum', '100', '1001')
        ->assertRedirect(route('library', ['artist' => '100', 'album' => '1001']));
});

it('resets the filter to "all" when the query changes', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->call('setFilter', 'artists')
        ->assertSet('filter', 'artists')
        ->set('q', 'rad')
        ->assertSet('filter', 'all');
});
