<?php

namespace App\Services\Plex;

use App\Services\Plex\Exceptions\PlexAuthException;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
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
