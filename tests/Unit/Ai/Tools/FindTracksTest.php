<?php

use App\Ai\Tools\FindTracks;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\PlexClient;
use Laravel\Ai\Tools\Request;

function makeFindTrack(string $key, string $title): Track
{
    return Track::fromPlex([
        'ratingKey' => $key,
        'title' => $title,
        'grandparentTitle' => 'X Artist',
        'parentTitle' => 'X Album',
        'parentYear' => 1987,
        'duration' => 240000,
        'parentRatingKey' => '10',
        'grandparentRatingKey' => '20',
        'Media' => [['Part' => [['id' => 99, 'container' => 'mp3']]]],
    ]);
}

it('forwards filters and serializes track rows', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('findTracks')
        ->once()
        ->with(['styleIds' => ['201'], 'limit' => 20])
        ->andReturn(collect([makeFindTrack('1', 'Bizarre Love Triangle')]));

    $result = (new FindTracks($plex))
        ->handle(new Request(['styleIds' => ['201'], 'limit' => 20]));

    $rows = json_decode((string) $result, true);
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'ratingKey' => '1',
            'title' => 'Bizarre Love Triangle',
            'artist' => 'X Artist',
            'album' => 'X Album',
            'durationMs' => 240000,
        ]);
});

it('caps limit at 200', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('findTracks')
        ->once()
        ->with(Mockery::on(fn ($filters) => $filters['limit'] === 200))
        ->andReturn(collect());

    (new FindTracks($plex))->handle(new Request(['styleIds' => ['1'], 'limit' => 9999]));
});

it('defaults limit to 50 when omitted', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('findTracks')
        ->once()
        ->with(Mockery::on(fn ($filters) => $filters['limit'] === 50))
        ->andReturn(collect());

    (new FindTracks($plex))->handle(new Request(['styleIds' => ['1']]));
});

it('drops null/empty filter keys before forwarding', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('findTracks')
        ->once()
        ->with(Mockery::on(function ($filters) {
            // Must NOT include moodIds (empty), yearFrom (null via missing)
            return $filters === ['styleIds' => ['1'], 'limit' => 50];
        }))
        ->andReturn(collect());

    (new FindTracks($plex))->handle(new Request([
        'styleIds' => ['1'],
        'moodIds' => [],
        'limit' => null,
    ]));
});
