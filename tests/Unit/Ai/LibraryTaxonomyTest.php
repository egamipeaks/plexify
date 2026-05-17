<?php

use App\Ai\Support\LibraryTaxonomy;
use App\Services\Plex\PlexClient;

it('returns a snapshot of genre/style/mood names from PlexClient', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('genres')->once()->andReturn([
        ['id' => '1', 'name' => 'Pop/Rock'],
        ['id' => '2', 'name' => 'Electronic'],
    ]);
    $plex->shouldReceive('styles')->once()->andReturn([
        ['id' => '3', 'name' => 'New Wave'],
    ]);
    $plex->shouldReceive('moods')->once()->andReturn([
        ['id' => '4', 'name' => 'Melancholy'],
    ]);

    $snap = (new LibraryTaxonomy($plex))->snapshot();

    expect($snap)->toBe([
        'genres' => ['Pop/Rock', 'Electronic'],
        'styles' => ['New Wave'],
        'moods' => ['Melancholy'],
    ]);
});
