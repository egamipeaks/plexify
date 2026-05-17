<?php

use App\Ai\Tools\PopularTracksForArtist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\PlexClient;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

function makePopTrack(string $key, string $title): Track
{
    return Track::fromPlex([
        'ratingKey' => $key,
        'title' => $title,
        'grandparentTitle' => 'The Cure',
        'parentTitle' => 'Kiss Me',
        'duration' => 220000,
        'Media' => [['Part' => [['id' => 1, 'container' => 'mp3']]]],
    ]);
}

it('returns popular tracks for an artist as JSON rows', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('popularTracksForArtist')
        ->once()
        ->with('58563')
        ->andReturn(collect([makePopTrack('1', 'Just Like Heaven'), makePopTrack('2', 'Pictures of You')]));

    $result = (new PopularTracksForArtist($plex))->handle(new Request(['artistId' => '58563']));

    $rows = json_decode((string) $result, true);
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray([
            'ratingKey' => '1',
            'title' => 'Just Like Heaven',
            'artist' => 'The Cure',
            'album' => 'Kiss Me',
        ]);
});

it('exposes a schema requiring artistId', function () {
    $schema = (new PopularTracksForArtist(Mockery::mock(PlexClient::class)))
        ->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('artistId');
});
