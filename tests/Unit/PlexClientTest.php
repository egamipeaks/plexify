<?php

use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexAuthException;
use App\Services\Plex\Exceptions\PlexNotFoundException;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
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
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response(file_get_contents(fixturePath('albums_for_artist.json')), 200),
    ]);

    $client = app(PlexClient::class);
    $albums = $client->albumsForArtist('100');

    expect($albums)->toHaveCount(2);
    expect($albums->first()->title)->toBe('22, A Million');
    expect($albums->first()->year)->toBe(2016);
    expect($albums->first()->trackCount)->toBe(10);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/library/sections/3/all')
        && str_contains($request->url(), 'type=9')
        && str_contains($request->url(), 'artist.id=100'));
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

it('builds a queue item from a Track, falling back to the track thumb', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
    ]);

    $client = app(PlexClient::class);

    $track = new Track(
        id: '9001', title: 'Holocene', artist: 'Bon Iver', album: '22, A Million',
        trackNumber: 3, durationMs: 213000, partId: 660001, container: 'flac',
        thumb: '/t/9001', albumId: '67890', artistId: '100',
    );

    $item = $client->queueItem($track);

    expect($item['id'])->toBe('9001');
    expect($item['title'])->toBe('Holocene');
    expect($item['artist'])->toBe('Bon Iver');
    expect($item['albumId'])->toBe('67890');
    expect($item['artistId'])->toBe('100');
    expect($item['url'])->toBe($client->streamUrl($track));
    expect($item['artwork'])->toBe($client->thumbUrl('/t/9001'));
});

it('uses the artwork override for a queue item when given', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
    ]);

    $client = app(PlexClient::class);

    $track = new Track(
        id: '9001', title: 'x', artist: 'y', album: 'z',
        trackNumber: 1, durationMs: 1000, partId: 1, container: 'flac',
        thumb: '/t/track', albumId: '1001', artistId: '100',
    );

    $item = $client->queueItem($track, 'https://cdn/album-cover.jpg');

    expect($item['artwork'])->toBe('https://cdn/album-cover.jpg');
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

function fakePlexWriteEndpoints(): void
{
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response(
            ['MediaContainer' => ['machineIdentifier' => 'M1']], 200,
        ),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists*' => Http::response(
            ['MediaContainer' => ['Metadata' => [['ratingKey' => '7777']]]], 200,
        ),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/:/rating*' => Http::response('', 200),
    ]);
}

it('adds a track to a playlist via PUT /playlists/{id}/items with a server uri', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:playlists', 'stale', 300);

    app(PlexClient::class)->addTrackToPlaylist('4242', '99');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/playlists/4242/items?')
        && str_contains($request->url(), 'uri='.urlencode('server://M1/com.plexapp.plugins.library/library/metadata/99')));

    expect(Cache::has('plex:playlists'))->toBeFalse();
});

it('adds an album to a playlist via PUT /playlists/{id}/items with the album rating key', function () {
    fakePlexWriteEndpoints();

    app(PlexClient::class)->addAlbumToPlaylist('4242', '1001');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/playlists/4242/items?')
        && str_contains($request->url(), 'uri='.urlencode('server://M1/com.plexapp.plugins.library/library/metadata/1001')));
});

it('maps a 404 from add-to-playlist to PlexNotFoundException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'M1']], 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242/items*' => Http::response('nope', 404),
    ]);

    expect(fn () => app(PlexClient::class)->addTrackToPlaylist('4242', '99'))->toThrow(PlexNotFoundException::class);
});

it('maps a 500 from add-to-playlist to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'M1']], 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242/items*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->addTrackToPlaylist('4242', '99'))->toThrow(PlexUnreachableException::class);
});

it('creates an audio playlist seeded from a track and returns the new rating key', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:playlists', 'stale', 300);

    $id = app(PlexClient::class)->createPlaylist('My Mix', '99');

    expect($id)->toBe('7777');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/playlists?')
        && str_contains($request->url(), 'type=audio')
        && str_contains($request->url(), 'smart=0')
        && str_contains($request->url(), 'title=My+Mix')
        && str_contains($request->url(), 'uri='.urlencode('server://M1/com.plexapp.plugins.library/library/metadata/99')));

    expect(Cache::has('plex:playlists'))->toBeFalse();
});

it('renames a playlist via PUT /playlists/{id}?title=', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:playlists', 'stale', 300);

    app(PlexClient::class)->renamePlaylist('4242', 'Renamed');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/playlists/4242?')
        && str_contains($request->url(), 'title=Renamed'));

    expect(Cache::has('plex:playlists'))->toBeFalse();
});

it('deletes a playlist via DELETE /playlists/{id}', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242' => Http::response('', 200),
    ]);
    Cache::put('plex:playlists', 'stale', 300);
    Cache::put('plex:playlist:4242:items', 'stale', 300);

    app(PlexClient::class)->deletePlaylist('4242');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/playlists/4242'));

    expect(Cache::has('plex:playlists'))->toBeFalse()
        ->and(Cache::has('plex:playlist:4242:items'))->toBeFalse();
});

it('maps a 404 from createPlaylist to PlexNotFoundException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'M1']], 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists*' => Http::response('nope', 404),
    ]);

    expect(fn () => app(PlexClient::class)->createPlaylist('X', '99'))->toThrow(PlexNotFoundException::class);
});

it('maps a 500 from renamePlaylist to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->renamePlaylist('4242', 'X'))->toThrow(PlexUnreachableException::class);
});

it('filters smart playlists out of playlists()', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists*' => Http::response(file_get_contents(fixturePath('playlists.json')), 200),
    ]);

    $titles = app(PlexClient::class)->playlists()->pluck('title')->all();

    expect($titles)->toBe(['Late Night', 'Bangers'])
        ->and($titles)->not->toContain('All Music');
});

it('filters smart playlists out of searchAll() playlist hub', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/hubs/search*' => Http::response([
            'MediaContainer' => [
                'Hub' => [
                    [
                        'type' => 'playlist',
                        'Metadata' => [
                            ['ratingKey' => '1', 'title' => 'User Mix', 'playlistType' => 'audio', 'smart' => false],
                            ['ratingKey' => '2', 'title' => 'Fresh',    'playlistType' => 'audio', 'smart' => true],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $results = app(PlexClient::class)->searchAll('foo');

    expect($results->playlists->pluck('title')->all())->toBe(['User Mix']);
});

it('fetches recently added albums with container params as query string', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response(file_get_contents(fixturePath('recently_added.json')), 200),
    ]);

    $albums = app(PlexClient::class)->recentlyAddedAlbums(50);

    expect($albums)->toHaveCount(2)
        ->and($albums->first()->id)->toBe('9001')
        ->and($albums->first()->artistId)->toBe('100')
        ->and($albums->first()->title)->toBe('Recently Added One');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/library/sections/3/all')
        && str_contains($request->url(), 'type=9')
        && str_contains($request->url(), 'sort='.urlencode('addedAt:desc'))
        && str_contains($request->url(), 'X-Plex-Container-Start=0')
        && str_contains($request->url(), 'X-Plex-Container-Size=50'));
});

it('caches recently added albums by limit and reuses the cached collection', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response(file_get_contents(fixturePath('recently_added.json')), 200),
    ]);

    app(PlexClient::class)->recentlyAddedAlbums(50);
    app(PlexClient::class)->recentlyAddedAlbums(50);

    expect(Cache::has('plex:recently_added:50'))->toBeTrue();
    Http::assertSentCount(3); // resources + library/sections + library/all — each once
});

it('maps a 404 on recentlyAddedAlbums to PlexNotFoundException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response('nope', 404),
    ]);

    expect(fn () => app(PlexClient::class)->recentlyAddedAlbums(50))->toThrow(PlexNotFoundException::class);
});

it('maps a 500 on recentlyAddedAlbums to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->recentlyAddedAlbums(50))->toThrow(PlexUnreachableException::class);
});

it('fetches recently played tracks and drops unplayed rows in PHP', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response(file_get_contents(fixturePath('recently_played.json')), 200),
    ]);

    $tracks = app(PlexClient::class)->recentlyPlayedTracks(50);

    expect($tracks)->toHaveCount(2)
        ->and($tracks->pluck('id')->all())->toBe(['70001', '70002']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/library/sections/3/all')
        && str_contains($request->url(), 'type=10')
        && str_contains($request->url(), 'sort='.urlencode('lastViewedAt:desc'))
        && str_contains($request->url(), 'X-Plex-Container-Start=0')
        && str_contains($request->url(), 'X-Plex-Container-Size=200'));
});

it('caches recently played tracks under the limit-specific key', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response(file_get_contents(fixturePath('recently_played.json')), 200),
    ]);

    app(PlexClient::class)->recentlyPlayedTracks(50);
    app(PlexClient::class)->recentlyPlayedTracks(50);

    expect(Cache::has('plex:recently_played:50'))->toBeTrue();
    Http::assertSentCount(3);
});

it('maps a 404 on recentlyPlayedTracks to PlexNotFoundException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response('nope', 404),
    ]);

    expect(fn () => app(PlexClient::class)->recentlyPlayedTracks(50))->toThrow(PlexNotFoundException::class);
});

it('builds a scrobble url for a rating key', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
    ]);

    $client = app(PlexClient::class);

    expect($client->scrobbleUrl('12345'))
        ->toContain('/:/scrobble?')
        ->toContain('key=12345')
        ->toContain('identifier=com.plexapp.plugins.library')
        ->toContain('X-Plex-Token=test-token');
});

it('maps a 500 on recentlyPlayedTracks to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->recentlyPlayedTracks(50))->toThrow(PlexUnreachableException::class);
});

it('moves a playlist item after another item via PUT .../move?after=', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:playlist:4242:items', 'stale', 300);

    app(PlexClient::class)->moveTrack('4242', '9002', '9001');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/playlists/4242/items/9002/move?')
        && str_contains($request->url(), 'after=9001'));

    expect(Cache::has('plex:playlist:4242:items'))->toBeFalse();
});

it('moves a playlist item to the front via PUT .../move with no after param', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:playlist:4242:items', 'stale', 300);

    app(PlexClient::class)->moveTrack('4242', '9002', null);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/playlists/4242/items/9002/move')
        && ! str_contains($request->url(), 'after='));

    expect(Cache::has('plex:playlist:4242:items'))->toBeFalse();
});

it('maps a 500 from the move endpoint to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242/items/9002/move*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->moveTrack('4242', '9002', '9001'))->toThrow(PlexUnreachableException::class);
});

it('removes a playlist item via DELETE /playlists/{id}/items/{itemId}', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:playlist:4242:items', 'stale', 300);

    app(PlexClient::class)->removeTrackFromPlaylist('4242', '9002');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/playlists/4242/items/9002'));

    expect(Cache::has('plex:playlist:4242:items'))->toBeFalse();
});

it('maps a 500 from the remove endpoint to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242/items/9002*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->removeTrackFromPlaylist('4242', '9002'))->toThrow(PlexUnreachableException::class);
});

it('fetches genres for the music section', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/genre*' => Http::response([
            'MediaContainer' => ['Directory' => [
                ['key' => '101', 'title' => 'Pop/Rock'],
                ['key' => '102', 'title' => 'Electronic'],
            ]],
        ], 200),
    ]);

    $genres = app(PlexClient::class)->genres();

    expect($genres)->toBe([
        ['id' => '101', 'name' => 'Pop/Rock'],
        ['id' => '102', 'name' => 'Electronic'],
    ]);
});

it('fetches styles for the music section', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/style*' => Http::response([
            'MediaContainer' => ['Directory' => [['key' => '201', 'title' => 'New Wave']]],
        ], 200),
    ]);

    expect(app(PlexClient::class)->styles())->toBe([
        ['id' => '201', 'name' => 'New Wave'],
    ]);
});

it('fetches moods for the music section', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/mood*' => Http::response([
            'MediaContainer' => ['Directory' => [['key' => '301', 'title' => 'Melancholy']]],
        ], 200),
    ]);

    expect(app(PlexClient::class)->moods())->toBe([
        ['id' => '301', 'name' => 'Melancholy'],
    ]);
});

it('caches the taxonomy results', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/genre*' => Http::response([
            'MediaContainer' => ['Directory' => [['key' => '1', 'title' => 'X']]],
        ], 200),
    ]);

    $client = app(PlexClient::class);
    $client->genres();
    $client->genres();

    Http::assertSentCount(3); // resources + sections + genre, no second genre call
});

it('finds tracks filtered by style and mood and year range', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response(file_get_contents(fixturePath('tracks_for_album.json')), 200),
    ]);

    $tracks = app(PlexClient::class)->findTracks([
        'styleIds' => ['201', '202'],
        'moodIds' => ['301'],
        'yearFrom' => 1980,
        'yearTo' => 1989,
        'limit' => 50,
    ]);

    expect($tracks)->toBeInstanceOf(Collection::class)
        ->and($tracks->first())->toBeInstanceOf(Track::class);

    Http::assertSent(function ($request) {
        $url = $request->url();

        return str_contains($url, 'type=10')
            && str_contains($url, 'style=201%2C202') // comma-joined, urlencoded
            && str_contains($url, 'mood=301')
            && str_contains($url, 'X-Plex-Container-Size=50');
    });
});

it('looks up tracks by ratingKey list (bypasses section filter)', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/metadata/12345,67890' => Http::response(file_get_contents(fixturePath('tracks_for_album.json')), 200),
    ]);

    $tracks = app(PlexClient::class)->findTracks(['ratingKeys' => ['12345', '67890']]);

    expect($tracks)->toBeInstanceOf(Collection::class)
        ->and($tracks)->not->toBeEmpty();
});

it('throws PlexUnreachableException on findTracks failure', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response('', 500),
    ]);

    expect(fn () => app(PlexClient::class)->findTracks(['styleIds' => ['1']]))
        ->toThrow(PlexUnreachableException::class);
});

it('fetches popular tracks for an artist', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/metadata/58563/popular*' => Http::response(file_get_contents(fixturePath('tracks_for_album.json')), 200),
    ]);

    $tracks = app(PlexClient::class)->popularTracksForArtist('58563');

    expect($tracks)->toBeInstanceOf(Collection::class)
        ->and($tracks)->not->toBeEmpty()
        ->and($tracks->first())->toBeInstanceOf(Track::class);
});

it('returns similar artists from artist metadata', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/metadata/58563' => Http::response([
            'MediaContainer' => ['Metadata' => [[
                'ratingKey' => '58563',
                'title' => 'Magnetic Fields',
                'Similar' => [
                    ['id' => 1, 'tag' => 'Stephin Merritt'],
                    ['id' => 2, 'tag' => 'The Gothic Archies'],
                ],
            ]]],
        ], 200),
    ]);

    $similar = app(PlexClient::class)->similarArtists('58563');

    expect($similar)->toBeInstanceOf(Collection::class)
        ->and($similar->all())->toBe([
            ['id' => '1', 'name' => 'Stephin Merritt'],
            ['id' => '2', 'name' => 'The Gothic Archies'],
        ]);
});

it('returns an empty collection when artist has no Similar array', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/metadata/999' => Http::response([
            'MediaContainer' => ['Metadata' => [['ratingKey' => '999', 'title' => 'Obscure']]],
        ], 200),
    ]);

    expect(app(PlexClient::class)->similarArtists('999'))->toBeEmpty();
});

it('rates a track 5 stars via PUT /:/rating?rating=10', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:favorites:1000', 'stale', 300);
    Cache::put('plex:_index', ['plex:favorites:1000'], 300);

    app(PlexClient::class)->rateTrack('12345', 10);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/:/rating?')
        && str_contains($request->url(), 'key=12345')
        && str_contains($request->url(), 'rating=10')
        && str_contains($request->url(), 'identifier=com.plexapp.plugins.library'));

    expect(Cache::has('plex:favorites:1000'))->toBeFalse();
});

it('clears a rating via PUT /:/rating?rating=0', function () {
    fakePlexWriteEndpoints();

    app(PlexClient::class)->rateTrack('12345', 0);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), 'rating=0'));
});

it('rejects invalid rating values on rateTrack', function () {
    fakePlexWriteEndpoints();

    expect(fn () => app(PlexClient::class)->rateTrack('1', 5))
        ->toThrow(InvalidArgumentException::class);
});

it('maps a 500 from /:/rating to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/:/rating*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->rateTrack('12345', 10))
        ->toThrow(PlexUnreachableException::class);
});
