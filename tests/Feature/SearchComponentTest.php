<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

function fakeSearchResults(): SearchResults
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

beforeEach(function () {
    $this->mock(PlexClient::class, function ($m) {
        $m->shouldReceive('searchAll')->andReturn(fakeSearchResults())->byDefault();
        $m->shouldReceive('thumbUrl')->andReturn(null)->byDefault();
        $m->shouldReceive('queueItem')->andReturnUsing(fn ($t) => ['id' => $t->id, 'url' => 'u', 'title' => $t->title, 'artist' => $t->artist, 'artwork' => null, 'albumId' => $t->albumId, 'artistId' => $t->artistId])->byDefault();
    });
});

it('renders nothing when there is no query', function () {
    Livewire::test('search')
        ->assertDontSee('No results found')
        ->assertDontSee('Playlists')
        ->assertDontSee('Holocene');
});

it('renders results for a query from the URL', function () {
    Livewire::withQueryParams(['q' => 'bon'])->test('search')
        ->assertSee('Holocene')
        ->assertSee('Bon Iver')
        ->assertSee('For Emma, Forever Ago')
        ->assertSee('Bon Voyage');
});

it('updates the query and re-runs the search when it receives the plextune-search event', function () {
    Livewire::test('search')
        ->dispatch('plextune-search', term: 'bon')
        ->assertSet('q', 'bon')
        ->assertSee('Holocene');
});

it('does not re-run the search when the same term is dispatched again', function () {
    Livewire::withQueryParams(['q' => 'bon'])->test('search')
        ->assertSet('q', 'bon')
        ->dispatch('plextune-search', term: 'bon')
        ->assertSet('q', 'bon');
});

it('switches result filter', function () {
    Livewire::withQueryParams(['q' => 'bon'])->test('search')
        ->call('setFilter', 'albums')
        ->assertSet('filter', 'albums');
});

it('dispatches play-track with the search context when a track is played', function () {
    Livewire::withQueryParams(['q' => 'bon'])->test('search')
        ->call('playTrack', '9001')
        ->assertDispatched('play-track', contextType: 'search', contextId: null);
});

it('resets filter to all when a new term is dispatched', function () {
    Livewire::withQueryParams(['q' => 'bon'])->test('search')
        ->call('setFilter', 'artists')
        ->assertSet('filter', 'artists')
        ->dispatch('plextune-search', term: 'iver')
        ->assertSet('filter', 'all');
});

it('toasts and shows the failure note when Plex search throws', function () {
    $this->mock(PlexClient::class, function ($m) {
        $m->shouldReceive('searchAll')->andThrow(new PlexUnreachableException('down'));
        $m->shouldReceive('thumbUrl')->andReturn(null);
    });

    Livewire::withQueryParams(['q' => 'bon'])->test('search')
        ->assertDispatched('notify', type: 'error')
        ->assertSeeHtml("Couldn't search right now");
});
