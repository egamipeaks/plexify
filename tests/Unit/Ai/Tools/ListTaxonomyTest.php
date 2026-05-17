<?php

use App\Ai\Tools\ListTaxonomy;
use App\Services\Plex\PlexClient;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

it('returns styles when kind=style', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('styles')->once()->andReturn([
        ['id' => '201', 'name' => 'New Wave'],
        ['id' => '202', 'name' => 'Vaporwave'],
    ]);

    $tool = new ListTaxonomy($plex);
    $result = $tool->handle(new Request(['kind' => 'style']));

    expect(json_decode((string) $result, true))->toBe([
        ['id' => '201', 'name' => 'New Wave'],
        ['id' => '202', 'name' => 'Vaporwave'],
    ]);
});

it('returns genres when kind=genre', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('genres')->once()->andReturn([['id' => '1', 'name' => 'Pop/Rock']]);

    $result = (new ListTaxonomy($plex))->handle(new Request(['kind' => 'genre']));

    expect(json_decode((string) $result, true))->toBe([['id' => '1', 'name' => 'Pop/Rock']]);
});

it('returns moods when kind=mood', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('moods')->once()->andReturn([['id' => '301', 'name' => 'Melancholy']]);

    $result = (new ListTaxonomy($plex))->handle(new Request(['kind' => 'mood']));

    expect(json_decode((string) $result, true))->toBe([['id' => '301', 'name' => 'Melancholy']]);
});

it('throws InvalidArgumentException on unknown kind', function () {
    $plex = Mockery::mock(PlexClient::class);
    $tool = new ListTaxonomy($plex);

    expect(fn () => $tool->handle(new Request(['kind' => 'bogus'])))
        ->toThrow(InvalidArgumentException::class);
});

it('exposes a schema that requires kind enum', function () {
    $schema = (new ListTaxonomy(Mockery::mock(PlexClient::class)))
        ->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('kind');
});
