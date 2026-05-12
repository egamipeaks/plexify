<?php

use App\Services\Plex\PlexClient;
use App\Support\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->plex = Mockery::mock(PlexClient::class);
    $this->plex->shouldReceive('scrobbleUrl')
        ->andReturnUsing(fn ($key) => "http://plex.test/:/scrobble?key={$key}&identifier=com.plexapp.plugins.library&X-Plex-Token=t");
    $this->app->instance(PlexClient::class, $this->plex);
});

it('exposes scrobble config to the audio player', function () {
    AppSetting::setScrobbleEnabled(true);

    Livewire::test('player')
        ->assertSet('scrobbleEnabled', true)
        ->assertSeeHtml('__KEY__');
});

it('relays a play-track event as a queue-load browser event', function () {
    $queue = [
        ['id' => '1', 'url' => 'https://example.com/1.mp3', 'title' => 'One', 'artist' => 'A', 'artwork' => null],
        ['id' => '2', 'url' => 'https://example.com/2.mp3', 'title' => 'Two', 'artist' => 'B', 'artwork' => 'https://example.com/2.jpg'],
    ];

    Livewire::test('player')
        ->dispatch('play-track', queue: $queue, index: 1)
        ->assertDispatched('queue-load', queue: $queue, index: 1, shuffle: false, contextType: null, contextId: null);
});

it('forwards the shuffle flag on the queue-load event', function () {
    $queue = [['id' => '1', 'url' => 'u', 'title' => 't', 'artist' => 'a', 'artwork' => null]];

    Livewire::test('player')
        ->dispatch('play-track', queue: $queue, index: 0, shuffle: true)
        ->assertDispatched('queue-load', shuffle: true);
});

it('defaults index to 0 and shuffle to false when omitted', function () {
    $queue = [['id' => '1', 'url' => 'u', 'title' => 't', 'artist' => 'a', 'artwork' => null]];

    Livewire::test('player')
        ->dispatch('play-track', queue: $queue)
        ->assertDispatched('queue-load', queue: $queue, index: 0, shuffle: false, contextType: null, contextId: null);
});

it('relays play-track to queue-load including playback context', function () {
    $queue = [['id' => '1', 'url' => 'u', 'title' => 't', 'artist' => 'a', 'artwork' => null, 'albumId' => null, 'artistId' => null]];

    Livewire::test('player')
        ->dispatch('play-track', queue: $queue, index: 0, shuffle: false, contextType: 'album', contextId: '99')
        ->assertDispatched('queue-load', queue: $queue, index: 0, shuffle: false, contextType: 'album', contextId: '99');
});
