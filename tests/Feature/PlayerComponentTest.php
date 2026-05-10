<?php

use Livewire\Livewire;

it('dispatches a browser event when a track is loaded', function () {
    Livewire::test('player')
        ->call('loadTrack', 'https://example.com/track.mp3', 'Test Track', 'Test Artist')
        ->assertDispatched('audio-load', url: 'https://example.com/track.mp3');
});

it('updates now-playing state when a track is loaded', function () {
    Livewire::test('player')
        ->call('loadTrack', 'https://example.com/track.mp3', 'Test Track', 'Test Artist')
        ->assertSet('trackTitle', 'Test Track')
        ->assertSet('trackArtist', 'Test Artist')
        ->assertSet('trackUrl', 'https://example.com/track.mp3');
});

it('listens for play-track events and loads the track', function () {
    Livewire::test('player')
        ->dispatch('play-track', url: 'https://example.com/track.mp3', title: 'Evt Track', artist: 'Evt Artist')
        ->assertSet('trackTitle', 'Evt Track')
        ->assertDispatched('audio-load', url: 'https://example.com/track.mp3');
});

it('sets trackArtwork when loadTrack is called with artwork', function () {
    Livewire::test('player')
        ->call('loadTrack', 'https://example.com/track.mp3', 'T', 'A', 'https://example.com/art.jpg')
        ->assertSet('trackArtwork', 'https://example.com/art.jpg');
});

it('trackArtwork defaults to null when loadTrack called without artwork', function () {
    Livewire::test('player')
        ->call('loadTrack', 'https://example.com/track.mp3', 'T', 'A')
        ->assertSet('trackArtwork', null);
});

it('sets trackArtwork via play-track event', function () {
    Livewire::test('player')
        ->dispatch('play-track', url: 'https://example.com/track.mp3', title: 'T', artist: 'A', artwork: 'https://example.com/art.jpg')
        ->assertSet('trackArtwork', 'https://example.com/art.jpg');
});
