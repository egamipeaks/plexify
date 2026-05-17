<?php

use App\Ai\Tools\SimilarArtists;
use App\Services\Plex\PlexClient;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

it('returns similar artists as JSON {id,name} rows', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('similarArtists')->once()->with('58563')->andReturn(collect([
        ['id' => '1', 'name' => 'Stephin Merritt'],
        ['id' => '2', 'name' => 'The Gothic Archies'],
    ]));

    $result = (new SimilarArtists($plex))->handle(new Request(['artistId' => '58563']));

    expect(json_decode((string) $result, true))->toBe([
        ['id' => '1', 'name' => 'Stephin Merritt'],
        ['id' => '2', 'name' => 'The Gothic Archies'],
    ]);
});

it('schema requires artistId', function () {
    $schema = (new SimilarArtists(Mockery::mock(PlexClient::class)))
        ->schema(new JsonSchemaTypeFactory);
    expect($schema)->toHaveKey('artistId');
});
