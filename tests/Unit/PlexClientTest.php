<?php

use App\Services\Plex\Exceptions\PlexAuthException;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config()->set('services.plex.token', 'test-token');
});

function fixturePath(string $name): string
{
    return base_path("tests/Fixtures/Plex/{$name}");
}

it('discovers the owned server and prefers a local HTTPS connection', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(
            file_get_contents(fixturePath('resources.json')),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $client = app(PlexClient::class);

    expect($client->baseUrl())->toBe('https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400');
});

it('falls back to a remote connection when no local one is reachable', function () {
    $resources = json_decode(file_get_contents(fixturePath('resources.json')), true);
    // Remove the local connection from the first server
    $resources[0]['connections'] = array_values(array_filter(
        $resources[0]['connections'],
        fn ($c) => $c['local'] === false,
    ));

    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response($resources, 200),
    ]);

    $client = app(PlexClient::class);

    expect($client->baseUrl())->toBe('https://107-216-58-9.c36d6e0431c147dda2be7d81893a1653.plex.direct:24476');
});

it('throws PlexAuthException when plex.tv returns 401', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response('Unauthorized', 401),
    ]);

    $client = app(PlexClient::class);

    expect(fn () => $client->baseUrl())->toThrow(PlexAuthException::class);
});

it('throws PlexUnreachableException when plex.tv connection fails', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('connect timed out'),
    ]);

    $client = app(PlexClient::class);

    expect(fn () => $client->baseUrl())->toThrow(PlexUnreachableException::class);
});

it('caches the discovered URL across calls', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(
            file_get_contents(fixturePath('resources.json')),
            200,
        ),
    ]);

    $client = app(PlexClient::class);
    $client->baseUrl();
    $client->baseUrl();

    Http::assertSentCount(1);
});

it('honors PLEX_BASE_URL config override and skips discovery', function () {
    config()->set('services.plex.base_url', 'https://my-override.plex.direct:32400');
    Http::fake();

    $client = app(PlexClient::class);

    expect($client->baseUrl())->toBe('https://my-override.plex.direct:32400');
    Http::assertNothingSent();
});

it('discovers the music library section by type=artist', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(
            file_get_contents(fixturePath('library_sections.json')),
            200,
        ),
    ]);

    $client = app(PlexClient::class);

    expect($client->musicSectionId())->toBe(3);
});

it('throws PlexNotFoundException when no music section exists', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response([
            'MediaContainer' => [
                'Directory' => [
                    ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
                ],
            ],
        ], 200),
    ]);

    $client = app(PlexClient::class);

    expect(fn () => $client->musicSectionId())->toThrow(\App\Services\Plex\Exceptions\PlexNotFoundException::class);
});

it('lists artists in the music section', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response(file_get_contents(fixturePath('artists.json')), 200),
    ]);

    $client = app(PlexClient::class);
    $artists = $client->artists();

    expect($artists)->toHaveCount(2);
    expect($artists->first()->name)->toBe('Bon Iver');
    expect($artists->first()->albumCount)->toBe(5);
    expect($artists->last()->name)->toBe('Radiohead');
});

it('caches the artists list', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response(file_get_contents(fixturePath('artists.json')), 200),
    ]);

    $client = app(PlexClient::class);
    $client->artists();
    $client->artists();

    Http::assertSentCount(3); // resources + sections + artists, no second artists call
});

it('lists albums for an artist by ratingKey', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/metadata/100/children*' => Http::response(file_get_contents(fixturePath('albums_for_artist.json')), 200),
    ]);

    $client = app(PlexClient::class);
    $albums = $client->albumsForArtist('100');

    expect($albums)->toHaveCount(2);
    expect($albums->first()->title)->toBe('22, A Million');
    expect($albums->first()->year)->toBe(2016);
    expect($albums->first()->trackCount)->toBe(10);
});

it('lists tracks for an album by ratingKey', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/metadata/1001/children*' => Http::response(file_get_contents(fixturePath('tracks_for_album.json')), 200),
    ]);

    $client = app(PlexClient::class);
    $tracks = $client->tracksForAlbum('1001');

    expect($tracks)->toHaveCount(2);
    expect($tracks->first()->title)->toBe('22 (OVER S∞∞N)');
    expect($tracks->first()->trackNumber)->toBe(1);
    expect($tracks->first()->partId)->toBe(9001001);
    expect($tracks->first()->container)->toBe('flac');
});
