<?php

namespace App\Services\Plex;

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexAuthException;
use App\Services\Plex\Exceptions\PlexNotFoundException;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
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
                throw new PlexUnreachableException('library/sections returned ' . $response->status());
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
                throw new PlexUnreachableException('artists endpoint returned ' . $response->status());
            }

            return collect(data_get($response->json(), 'MediaContainer.Metadata', []))
                ->map(fn (array $row) => Artist::fromPlex($row));
        });
    }

    public function albumsForArtist(string $artistId): Collection
    {
        return $this->cache->remember("albums:{$artistId}", PlexCache::TTL_ALBUMS, function () use ($artistId) {
            $response = $this->server()->get("/library/metadata/{$artistId}/children");

            if ($response->status() === 404) {
                throw new PlexNotFoundException("Artist {$artistId} not found.");
            }

            if (! $response->successful()) {
                throw new PlexUnreachableException("albumsForArtist returned {$response->status()}");
            }

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
        try {
            $response = $this->plexTv()->get('/resources', ['includeHttps' => 1]);
        } catch (ConnectionException $e) {
            throw new PlexUnreachableException('Cannot reach plex.tv: ' . $e->getMessage(), previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new PlexAuthException('Plex token rejected by plex.tv (status ' . $response->status() . ').');
        }

        if (! $response->successful()) {
            throw new PlexUnreachableException('plex.tv resources endpoint returned status ' . $response->status());
        }

        $resources = $response->json();
        $owned = collect($resources)->first(
            fn (array $r) => str_contains($r['provides'] ?? '', 'server') && ($r['owned'] ?? false),
        );

        if (! $owned) {
            throw new PlexUnreachableException('No owned Plex server found for this token.');
        }

        $connections = collect($owned['connections'] ?? []);
        $best = $connections->firstWhere('local', true)
            ?? $connections->firstWhere('protocol', 'https')
            ?? $connections->first();

        if (! $best) {
            throw new PlexUnreachableException('Owned server has no usable connection.');
        }

        return $best['uri'];
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
