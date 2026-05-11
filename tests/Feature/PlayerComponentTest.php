<?php

use Livewire\Livewire;

it('relays a play-track event as a queue-load browser event', function () {
    $queue = [
        ['id' => '1', 'url' => 'https://example.com/1.mp3', 'title' => 'One', 'artist' => 'A', 'artwork' => null],
        ['id' => '2', 'url' => 'https://example.com/2.mp3', 'title' => 'Two', 'artist' => 'B', 'artwork' => 'https://example.com/2.jpg'],
    ];

    Livewire::test('player')
        ->dispatch('play-track', queue: $queue, index: 1)
        ->assertDispatched('queue-load', queue: $queue, index: 1, shuffle: false);
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
        ->assertDispatched('queue-load', queue: $queue, index: 0, shuffle: false);
});
