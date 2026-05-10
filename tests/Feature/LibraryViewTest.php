<?php

use App\Services\Plex\Dto\Artist;
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
        ->assertSee('Radiohead')
        ->assertSee('5 albums')
        ->assertSee('9 albums');
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
