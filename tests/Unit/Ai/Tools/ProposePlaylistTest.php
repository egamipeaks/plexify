<?php

use App\Ai\Support\ProposalStore;
use App\Ai\Tools\ProposePlaylist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\PlexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

function makeProposeTrack(string $key, string $title, string $artist = 'The Cure', string $album = 'Kiss Me'): Track
{
    return Track::fromPlex([
        'ratingKey' => $key,
        'title' => $title,
        'grandparentTitle' => $artist,
        'parentTitle' => $album,
        'duration' => 240000,
        'Media' => [['Part' => [['id' => 1, 'container' => 'mp3']]]],
    ]);
}

it('stores a proposal and returns an ack string', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('findTracks')
        ->once()
        ->with(['ratingKeys' => ['12345']])
        ->andReturn(collect([makeProposeTrack('12345', 'Just Like Heaven')]));

    $tool = new ProposePlaylist($plex, new ProposalStore, 'conv-uuid-42');

    $result = $tool->handle(new Request([
        'name' => 'New Wave Essentials',
        'description' => 'Twenty stone-cold classics',
        'trackRatingKeys' => ['12345'],
        'rationalePerTrack' => ['12345' => 'genre-defining 1987 track'],
    ]));

    expect((string) $result)->toContain('Proposal rendered');

    $stored = (new ProposalStore)->latest('conv-uuid-42');
    expect($stored->name)->toBe('New Wave Essentials')
        ->and($stored->description)->toBe('Twenty stone-cold classics')
        ->and($stored->payload['tracks'])->toHaveCount(1);

    $track = $stored->payload['tracks'][0];
    expect($track)->toMatchArray([
        'ratingKey' => '12345',
        'title' => 'Just Like Heaven',
        'artist' => 'The Cure',
        'album' => 'Kiss Me',
        'reason' => 'genre-defining 1987 track',
    ]);
});

it('preserves the LLM track order even when Plex returns them in a different order', function () {
    $plex = Mockery::mock(PlexClient::class);
    // Plex returns rows in a different order than the LLM proposed.
    $plex->shouldReceive('findTracks')
        ->once()
        ->andReturn(collect([
            makeProposeTrack('B', 'Track B'),
            makeProposeTrack('A', 'Track A'),
        ]));

    $tool = new ProposePlaylist($plex, new ProposalStore, 'conv-1');
    $tool->handle(new Request([
        'name' => 'Order test',
        'trackRatingKeys' => ['A', 'B'],
    ]));

    $tracks = (new ProposalStore)->latest('conv-1')->payload['tracks'];
    expect(array_column($tracks, 'ratingKey'))->toBe(['A', 'B']);
});

it('drops ratingKeys Plex no longer knows', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('findTracks')->once()->andReturn(collect([
        makeProposeTrack('1', 'Real Track'),
        // ratingKey 99 not returned (e.g. track was removed)
    ]));

    $tool = new ProposePlaylist($plex, new ProposalStore, 'conv-2');
    $tool->handle(new Request([
        'name' => 'X',
        'trackRatingKeys' => ['1', '99'],
    ]));

    $tracks = (new ProposalStore)->latest('conv-2')->payload['tracks'];
    expect($tracks)->toHaveCount(1)->and($tracks[0]['ratingKey'])->toBe('1');
});

it('description is optional and stores as null', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('findTracks')->once()->andReturn(collect([makeProposeTrack('1', 'X')]));

    $tool = new ProposePlaylist($plex, new ProposalStore, 'conv-3');
    $tool->handle(new Request(['name' => 'X', 'trackRatingKeys' => ['1']]));

    expect((new ProposalStore)->latest('conv-3')->description)->toBeNull();
});

it('schema requires name and trackRatingKeys', function () {
    $tool = new ProposePlaylist(Mockery::mock(PlexClient::class), new ProposalStore, 'conv-x');
    $schema = $tool->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['name', 'description', 'trackRatingKeys', 'rationalePerTrack']);
});
