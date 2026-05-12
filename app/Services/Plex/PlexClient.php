<?php

namespace App\Services\Plex;

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexAuthException;
use App\Services\Plex\Exceptions\PlexNotFoundException;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class PlexClient
{
    private const PLEX_TV = 'https://plex.tv/api/v2';

    public function __construct(
        private readonly PlexCache $cache,
    ) {}

    public function baseUrl(): string
    {
        $override = config('services.plex.base_url');

        if (! empty($override)) {
            return $override;
        }

        return $this->cache->remember('base_url', PlexCache::TTL_RESOURCES, function () {
            return $this->discoverBaseUrl();
        });
    }

    public function musicSectionId(): int
    {
        return $this->cache->remember('music_section', PlexCache::TTL_SECTIONS, function () {
            $response = $this->server()->get('/library/sections');

            if (! $response->successful()) {
                throw new PlexUnreachableException('library/sections returned '.$response->status());
            }

            $directory = data_get($response->json(), 'MediaContainer.Directory', []);
            $music = collect($directory)->first(fn (array $d) => ($d['type'] ?? null) === 'artist');

            if (! $music) {
                throw new PlexNotFoundException('No music library section found on this Plex server.');
            }

            return (int) $music['key'];
        });
    }

    public function artists(): Collection
    {
        return $this->cache->remember('artists', PlexCache::TTL_ARTISTS, function () {
            $sectionId = $this->musicSectionId();
            $response = $this->server()->get("/library/sections/{$sectionId}/all", ['type' => 8]);

            if (! $response->successful()) {
                throw new PlexUnreachableException('artists endpoint returned '.$response->status());
            }

            return collect(data_get($response->json(), 'MediaContainer.Metadata', []))
                ->map(fn (array $row) => Artist::fromPlex($row));
        });
    }

    public function albumsForArtist(string $artistId): Collection
    {
        return $this->cache->remember("albums:{$artistId}", PlexCache::TTL_ALBUMS, function () use ($artistId) {
            // Filter section/all by artist.id instead of using /library/metadata/{id}/children.
            // The /children endpoint returns 0 for "track artists" (artists credited via
            // a track's originalTitle but not the album's parentTitle), which incorrectly
            // hides albums whose parentRatingKey points to those artists. The filter
            // approach returns the same set for normal album artists and the correct
            // non-empty set for track artists.
            $sectionId = $this->musicSectionId();
            $response = $this->server()->get("/library/sections/{$sectionId}/all", [
                'type' => 9,
                'artist.id' => $artistId,
            ]);

            $this->ensureOk($response, "library/sections/{$sectionId}/all?artist.id={$artistId}");

            return collect(data_get($response->json(), 'MediaContainer.Metadata', []))
                ->map(fn (array $row) => Album::fromPlex($row));
        });
    }

    public function tracksForAlbum(string $albumId): Collection
    {
        return $this->cache->remember("tracks:{$albumId}", PlexCache::TTL_TRACKS, function () use ($albumId) {
            $response = $this->server()->get("/library/metadata/{$albumId}/children");

            if ($response->status() === 404) {
                throw new PlexNotFoundException("Album {$albumId} not found.");
            }

            if (! $response->successful()) {
                throw new PlexUnreachableException("tracksForAlbum returned {$response->status()}");
            }

            return collect(data_get($response->json(), 'MediaContainer.Metadata', []))
                ->map(fn (array $row) => Track::fromPlex($row));
        });
    }

    public function playlists(): Collection
    {
        return $this->cache->remember('playlists', PlexCache::TTL_PLAYLISTS, function () {
            $response = $this->server()->get('/playlists', ['playlistType' => 'audio']);

            if ($response->status() === 404) {
                throw new PlexNotFoundException('playlists endpoint not found.');
            }

            if (! $response->successful()) {
                throw new PlexUnreachableException('playlists endpoint returned '.$response->status());
            }

            return collect(data_get($response->json(), 'MediaContainer.Metadata', []))
                ->map(fn (array $row) => Playlist::fromPlex($row))
                ->reject(fn (Playlist $p) => $p->smart)
                ->values();
        });
    }

    public function playlistTracks(string $playlistId): Collection
    {
        return $this->cache->remember("playlist:{$playlistId}:items", PlexCache::TTL_PLAYLISTS, function () use ($playlistId) {
            $response = $this->server()->get("/playlists/{$playlistId}/items");

            if ($response->status() === 404) {
                throw new PlexNotFoundException("Playlist {$playlistId} not found.");
            }

            if (! $response->successful()) {
                throw new PlexUnreachableException("playlistTracks returned {$response->status()}");
            }

            return collect(data_get($response->json(), 'MediaContainer.Metadata', []))
                ->map(fn (array $row) => Track::fromPlex($row))
                ->values();
        });
    }

    public function recentlyAddedAlbums(int $limit = 50): Collection
    {
        return $this->cache->remember("recently_added:{$limit}", PlexCache::TTL_PLAYLISTS, function () use ($limit) {
            $sectionId = $this->musicSectionId();

            $response = $this->server()
                ->withHeader('X-Plex-Container-Size', (string) $limit)
                ->get("/library/sections/{$sectionId}/recentlyAdded", ['type' => 9]);

            $this->ensureOk($response, "library/sections/{$sectionId}/recentlyAdded");

            return collect(data_get($response->json(), 'MediaContainer.Metadata', []))
                ->map(fn (array $row) => Album::fromPlex($row))
                ->values();
        });
    }

    public function recentlyPlayedTracks(int $limit = 50): Collection
    {
        return $this->cache->remember("recently_played:{$limit}", PlexCache::TTL_PLAYLISTS, function () use ($limit) {
            $sectionId = $this->musicSectionId();

            // Ask for 4x so we can slice past any unplayed rows Plex's :desc sort allowed through.
            $response = $this->server()
                ->withHeader('X-Plex-Container-Size', (string) ($limit * 4))
                ->get("/library/sections/{$sectionId}/all", [
                    'type' => 10,
                    'sort' => 'lastViewedAt:desc',
                ]);

            $this->ensureOk($response, "library/sections/{$sectionId}/all");

            return collect(data_get($response->json(), 'MediaContainer.Metadata', []))
                ->filter(fn (array $row) => ! empty($row['lastViewedAt']))
                ->map(fn (array $row) => Track::fromPlex($row))
                ->take($limit)
                ->values();
        });
    }

    public function machineIdentifier(): string
    {
        return $this->cache->remember('machine_identifier', PlexCache::TTL_RESOURCES, function () {
            $response = $this->server()->get('/identity');

            if (! $response->successful()) {
                throw new PlexUnreachableException('identity endpoint returned '.$response->status());
            }

            $id = data_get($response->json(), 'MediaContainer.machineIdentifier');

            if (empty($id)) {
                throw new PlexNotFoundException('Plex /identity did not return a machineIdentifier.');
            }

            return (string) $id;
        });
    }

    public function addTrackToPlaylist(string $playlistId, string $trackId): void
    {
        $this->putPlaylistItem($playlistId, $this->libraryItemUri($trackId));
    }

    public function addAlbumToPlaylist(string $playlistId, string $albumId): void
    {
        $this->putPlaylistItem($playlistId, $this->libraryItemUri($albumId));
    }

    public function searchAll(string $query): SearchResults
    {
        $query = trim($query);

        if ($query === '') {
            return SearchResults::empty();
        }

        try {
            $response = $this->server()->get('/hubs/search', [
                'query' => $query,
                'limit' => 30,
            ]);
        } catch (ConnectionException $e) {
            throw new PlexUnreachableException('Plex search failed: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new PlexAuthException('Plex rejected the search request (status '.$response->status().').');
        }

        if (! $response->successful()) {
            throw new PlexUnreachableException('hubs/search returned '.$response->status());
        }

        $hubs = collect(data_get($response->json(), 'MediaContainer.Hub', []));
        $metadata = fn (string $type) => collect(data_get($hubs->firstWhere('type', $type), 'Metadata', []));

        return new SearchResults(
            tracks: $metadata('track')->map(fn (array $row) => Track::fromPlex($row))->values(),
            artists: $metadata('artist')->map(fn (array $row) => Artist::fromPlex($row))->values(),
            albums: $metadata('album')->map(fn (array $row) => Album::fromPlex($row))->values(),
            playlists: $metadata('playlist')
                ->filter(fn (array $row) => ($row['playlistType'] ?? 'audio') === 'audio')
                ->map(fn (array $row) => Playlist::fromPlex($row))
                ->reject(fn (Playlist $p) => $p->smart)
                ->values(),
        );
    }

    public function streamUrl(Track $track): string
    {
        return sprintf(
            '%s/library/parts/%d/file.%s?X-Plex-Token=%s',
            $this->baseUrl(),
            $track->partId,
            $track->container,
            $this->token(),
        );
    }

    public function thumbUrl(?string $thumb): ?string
    {
        if (empty($thumb)) {
            return null;
        }

        return sprintf(
            '%s%s?X-Plex-Token=%s',
            $this->baseUrl(),
            $thumb,
            $this->token(),
        );
    }

    public function scrobbleUrl(string $ratingKey): string
    {
        return sprintf(
            '%s/:/scrobble?key=%s&identifier=com.plexapp.plugins.library&X-Plex-Token=%s',
            $this->baseUrl(),
            $ratingKey,
            $this->token(),
        );
    }

    /**
     * @return array{
     *     id: string,
     *     url: string,
     *     title: string,
     *     artist: string,
     *     artwork: ?string,
     *     albumId: ?string,
     *     artistId: ?string,
     * }
     */
    public function queueItem(Track $track, ?string $artworkOverride = null): array
    {
        return [
            'id' => $track->id,
            'url' => $this->streamUrl($track),
            'title' => $track->title,
            'artist' => $track->artist,
            'artwork' => $artworkOverride ?? $this->thumbUrl($track->thumb),
            'albumId' => $track->albumId,
            'artistId' => $track->artistId,
        ];
    }

    public function ping(): array
    {
        $resources = $this->ownedServerResource();
        $name = $resources['name'] ?? 'Unknown';

        try {
            $response = $this->server()->timeout(3)->get('/identity');
        } catch (ConnectionException) {
            return [
                'name' => $name,
                'reachable' => false,
                'connection' => 'down',
                'machineIdentifier' => null,
            ];
        }

        if (! $response->successful()) {
            return [
                'name' => $name,
                'reachable' => false,
                'connection' => 'down',
                'machineIdentifier' => null,
            ];
        }

        $isLocal = str_contains($this->baseUrl(), 'plex.direct')
            && (collect($resources['connections'] ?? [])->firstWhere('uri', $this->baseUrl())['local'] ?? false);

        return [
            'name' => $name,
            'reachable' => true,
            'connection' => $isLocal ? 'direct' : 'relay',
            'machineIdentifier' => data_get($response->json(), 'MediaContainer.machineIdentifier'),
        ];
    }

    public function createPlaylist(string $title, string $seedTrackId): string
    {
        try {
            $response = $this->server()->post('/playlists?'.http_build_query([
                'type' => 'audio',
                'title' => $title,
                'smart' => 0,
                'uri' => $this->libraryItemUri($seedTrackId),
            ]));
        } catch (ConnectionException $e) {
            throw new PlexUnreachableException('Creating playlist failed: '.$e->getMessage(), previous: $e);
        }

        $this->ensureOk($response, 'POST playlists');

        $id = data_get($response->json(), 'MediaContainer.Metadata.0.ratingKey');

        if (empty($id)) {
            throw new PlexNotFoundException('Plex did not return a ratingKey for the new playlist.');
        }

        $this->cache->forget('playlists');

        return (string) $id;
    }

    public function renamePlaylist(string $playlistId, string $title): void
    {
        try {
            $response = $this->server()->put("/playlists/{$playlistId}?".http_build_query(['title' => $title]));
        } catch (ConnectionException $e) {
            throw new PlexUnreachableException('Renaming playlist failed: '.$e->getMessage(), previous: $e);
        }

        $this->ensureOk($response, "PUT playlists/{$playlistId}");
        $this->cache->forget('playlists');
    }

    public function deletePlaylist(string $playlistId): void
    {
        try {
            $response = $this->server()->delete("/playlists/{$playlistId}");
        } catch (ConnectionException $e) {
            throw new PlexUnreachableException('Deleting playlist failed: '.$e->getMessage(), previous: $e);
        }

        $this->ensureOk($response, "DELETE playlists/{$playlistId}");
        $this->cache->forget('playlists');
        $this->cache->forget("playlist:{$playlistId}:items");
    }

    private function putPlaylistItem(string $playlistId, string $uri): void
    {
        try {
            $response = $this->server()->put("/playlists/{$playlistId}/items?".http_build_query(['uri' => $uri]));
        } catch (ConnectionException $e) {
            throw new PlexUnreachableException('Adding to playlist failed: '.$e->getMessage(), previous: $e);
        }

        $this->ensureOk($response, "playlists/{$playlistId}/items");

        $this->cache->forget('playlists');
        $this->cache->forget("playlist:{$playlistId}:items");
    }

    private function libraryItemUri(string $ratingKey): string
    {
        return sprintf(
            'server://%s/com.plexapp.plugins.library/library/metadata/%s',
            $this->machineIdentifier(),
            $ratingKey,
        );
    }

    private function ensureOk(Response $response, string $context): void
    {
        if ($response->status() === 404) {
            throw new PlexNotFoundException("{$context}: not found.");
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new PlexAuthException("{$context}: Plex rejected the request (status {$response->status()}).");
        }

        if (! $response->successful()) {
            throw new PlexUnreachableException("{$context} returned {$response->status()}.");
        }
    }

    private function server(): PendingRequest
    {
        // No ->throw() — Laravel's HTTP client does NOT auto-throw on 4xx/5xx by
        // default, so we inspect $response->status() ourselves.
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withHeaders([
                'X-Plex-Token' => $this->token(),
                'X-Plex-Client-Identifier' => 'plexify',
            ]);
    }

    private function discoverBaseUrl(): string
    {
        $owned = $this->ownedServerResource();
        $connections = collect($owned['connections'] ?? []);
        $best = $connections->firstWhere('local', true)
            ?? $connections->firstWhere('protocol', 'https')
            ?? $connections->first();

        if (! $best) {
            throw new PlexUnreachableException('Owned server has no usable connection.');
        }

        return $best['uri'];
    }

    private function ownedServerResource(): array
    {
        return $this->cache->remember('owned_resource', PlexCache::TTL_RESOURCES, function () {
            try {
                $response = $this->plexTv()->get('/resources', ['includeHttps' => 1]);
            } catch (ConnectionException $e) {
                throw new PlexUnreachableException('Cannot reach plex.tv: '.$e->getMessage(), previous: $e);
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new PlexAuthException('Plex token rejected by plex.tv (status '.$response->status().').');
            }

            if (! $response->successful()) {
                throw new PlexUnreachableException('plex.tv resources endpoint returned status '.$response->status());
            }

            $owned = collect($response->json())->first(
                fn (array $r) => str_contains($r['provides'] ?? '', 'server') && ($r['owned'] ?? false),
            );

            if (! $owned) {
                throw new PlexUnreachableException('No owned Plex server found for this token.');
            }

            return $owned;
        });
    }

    private function plexTv(): PendingRequest
    {
        return Http::baseUrl(self::PLEX_TV)
            ->acceptJson()
            ->withHeaders([
                'X-Plex-Token' => $this->token(),
                'X-Plex-Client-Identifier' => 'plexify',
            ]);
    }

    private function token(): string
    {
        $token = config('services.plex.token');

        if (empty($token)) {
            throw new PlexAuthException('PLEX_TOKEN is not set in .env.');
        }

        return $token;
    }
}
