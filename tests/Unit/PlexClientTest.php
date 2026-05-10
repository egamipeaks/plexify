<?php

use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexAuthException;
use App\Services\Plex\Exceptions\PlexNotFoundException;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Illuminate\Http\Client\ConnectionException;
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
        'https://plex.tv/api/v2/resources*' => fn () => throw new ConnectionException('connect timed out'),
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

    expect(fn () => $client->musicSectionId())->toThrow(PlexNotFoundException::class);
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

it('builds a stream URL for a track', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
    ]);

    $client = app(PlexClient::class);
    $track = new Track(
        id: '1',
        title: 'Test',
        artist: 'A',
        album: 'B',
        trackNumber: 1,
        durationMs: 1000,
        partId: 999,
        container: 'flac',
    );

    expect($client->streamUrl($track))
        ->toBe('https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/parts/999/file.flac?X-Plex-Token=test-token');
});

it('builds a thumb URL with token', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
    ]);

    $client = app(PlexClient::class);

    expect($client->thumbUrl('/library/metadata/100/thumb/1700000000'))
        ->toBe('https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/metadata/100/thumb/1700000000?X-Plex-Token=test-token');
});

it('returns null thumbUrl for empty input', function () {
    Http::fake([]);
    config()->set('services.plex.base_url', 'https://anything');
    $client = app(PlexClient::class);
    expect($client->thumbUrl(null))->toBeNull();
    expect($client->thumbUrl(''))->toBeNull();
});

it('reports ping status with server name when reachable', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response([
            'MediaContainer' => [
                'machineIdentifier' => 'fdc67453cc21f68f457007dcba5e108675f02a56',
                'version' => '1.42.2.10156',
            ],
        ], 200),
    ]);

    $client = app(PlexClient::class);
    $status = $client->ping();

    expect($status['name'])->toBe('HOMESERVER');
    expect($status['connection'])->toBe('direct');
    expect($status['reachable'])->toBeTrue();
});

it('reports unreachable when /identity fails', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => fn () => throw new ConnectionException('refused'),
    ]);

    $client = app(PlexClient::class);
    $status = $client->ping();

    expect($status['reachable'])->toBeFalse();
    expect($status['connection'])->toBe('down');
});

it('searches /hubs/search and parses only the music hubs', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake([
        'https://server.plex.direct:32400/hubs/search*' => Http::response(
            file_get_contents(fixturePath('hubs_search.json')),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $results = app(PlexClient::class)->searchAll('bon');

    expect($results)->toBeInstanceOf(SearchResults::class)
        ->and($results->artists)->toHaveCount(2)
        ->and($results->albums)->toHaveCount(2)
        ->and($results->tracks)->toHaveCount(2)
        ->and($results->playlists)->toHaveCount(1); // playlistType: "video" filtered out

    expect($results->artists->first()->name)->toBe('Bon Iver')
        ->and($results->artists->first()->albumCount)->toBe(5)
        ->and($results->albums->first()->artistId)->toBe('53843')
        ->and($results->tracks->first()->title)->toBe('Holocene')
        ->and($results->tracks->first()->artist)->toBe('Bon Iver')
        ->and($results->tracks->first()->partId)->toBe(660001)
        ->and($results->tracks->first()->thumb)->toBe('/library/metadata/53268/thumb/1')
        ->and($results->playlists->first()->title)->toBe('Bon Voyage')
        ->and($results->playlists->first()->trackCount)->toBe(18);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/hubs/search')
        && str_contains($request->url(), 'query=bon')
        && str_contains($request->url(), 'limit=30'));
});

it('returns an empty SearchResults for a blank query without calling Plex', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake();

    $results = app(PlexClient::class)->searchAll('   ');

    expect($results->isEmpty())->toBeTrue();
    Http::assertNothingSent();
});

it('tolerates a hubs/search response with no matching hubs', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake([
        'https://server.plex.direct:32400/hubs/search*' => Http::response([
            'MediaContainer' => ['size' => 0, 'Hub' => [
                ['type' => 'artist', 'size' => 0],
                ['type' => 'show', 'size' => 0],
            ]],
        ], 200),
    ]);

    expect(app(PlexClient::class)->searchAll('zzzz')->isEmpty())->toBeTrue();
});

it('maps a 401 from hubs/search to PlexAuthException', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake([
        'https://server.plex.direct:32400/hubs/search*' => Http::response('nope', 401),
    ]);

    expect(fn () => app(PlexClient::class)->searchAll('bon'))->toThrow(PlexAuthException::class);
});

it('maps a connection failure from hubs/search to PlexUnreachableException', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake([
        'https://server.plex.direct:32400/hubs/search*' => fn () => throw new ConnectionException('connect timed out'),
    ]);

    expect(fn () => app(PlexClient::class)->searchAll('bon'))->toThrow(PlexUnreachableException::class);
});

it('maps a 500 from hubs/search to PlexUnreachableException', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake([
        'https://server.plex.direct:32400/hubs/search*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->searchAll('bon'))->toThrow(PlexUnreachableException::class);
});

it('lists audio playlists', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists*' => Http::response(file_get_contents(fixturePath('playlists.json')), 200),
    ]);

    $client = app(PlexClient::class);
    $playlists = $client->playlists();

    expect($playlists)->toHaveCount(2)
        ->and($playlists->first()->title)->toBe('Late Night')
        ->and($playlists->first()->trackCount)->toBe(3)
        ->and($playlists->first()->summary)->toBe('Wind-down listening.')
        ->and($playlists->last()->summary)->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/playlists')
        && str_contains($request->url(), 'playlistType=audio'));
});

it('caches the playlists list', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists*' => Http::response(file_get_contents(fixturePath('playlists.json')), 200),
    ]);

    $client = app(PlexClient::class);
    $client->playlists();
    $client->playlists();

    Http::assertSentCount(2); // resources + playlists, no second playlists call
});

it('maps a 404 from /playlists to PlexNotFoundException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists*' => Http::response('not found', 404),
    ]);

    $client = app(PlexClient::class);

    expect(fn () => $client->playlists())->toThrow(PlexNotFoundException::class);
});

it('maps a 500 from /playlists to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists*' => Http::response('boom', 500),
    ]);

    $client = app(PlexClient::class);

    expect(fn () => $client->playlists())->toThrow(PlexUnreachableException::class);
});

it('lists the tracks of a playlist by ratingKey', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242/items*' => Http::response(file_get_contents(fixturePath('playlist_items.json')), 200),
    ]);

    $client = app(PlexClient::class);
    $tracks = $client->playlistTracks('4242');

    expect($tracks)->toHaveCount(2)
        ->and($tracks->first()->title)->toBe('Holocene')
        ->and($tracks->first()->artist)->toBe('Bon Iver')
        ->and($tracks->first()->album)->toBe('Bon Iver, Bon Iver')
        ->and($tracks->first()->partId)->toBe(770001)
        ->and($tracks->first()->container)->toBe('flac');
});

it('maps a 404 from /playlists/{id}/items to PlexNotFoundException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/999/items*' => Http::response('not found', 404),
    ]);

    $client = app(PlexClient::class);

    expect(fn () => $client->playlistTracks('999'))->toThrow(PlexNotFoundException::class);
});

it('maps a 500 from /playlists/{id}/items to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242/items*' => Http::response('boom', 500),
    ]);

    $client = app(PlexClient::class);

    expect(fn () => $client->playlistTracks('4242'))->toThrow(PlexUnreachableException::class);
});

it('reads and caches the server machine identifier', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response(
            ['MediaContainer' => ['machineIdentifier' => 'MACHINE-123']], 200,
        ),
    ]);

    $client = app(PlexClient::class);

    expect($client->machineIdentifier())->toBe('MACHINE-123')
        ->and($client->machineIdentifier())->toBe('MACHINE-123'); // cached, no second /identity call

    Http::assertSentCount(2); // resources + identity, only once each
});

it('throws PlexUnreachableException when /identity fails for machineIdentifier', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->machineIdentifier())->toThrow(PlexUnreachableException::class);
});
