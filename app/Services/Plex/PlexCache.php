<?php

namespace App\Services\Plex;

use Closure;
use Illuminate\Support\Facades\Cache;

class PlexCache
{
    public const TTL_RESOURCES = 3600;          // 1 hour

    public const TTL_SECTIONS = 86400;          // 24 hours

    public const TTL_ARTISTS = 86400;           // 24 hours

    public const TTL_ALBUMS = 86400;            // 24 hours

    public const TTL_TRACKS = 604800;           // 7 days

    public const TTL_PLAYLISTS = 300;           // 5 minutes

    public const TTL_TAXONOMY = 86400;          // 24h — taxonomy rarely changes

    private const PREFIX = 'plex:';

    private const INDEX_KEY = 'plex:_index';

    // Long enough to outlive every tracked entry's TTL (longest is TTL_TRACKS = 7 days).
    private const INDEX_TTL = self::TTL_TRACKS;

    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $namespaced = self::PREFIX.$key;

        $value = Cache::remember($namespaced, $ttl, $callback);

        $this->trackKey($namespaced);

        return $value;
    }

    public function forget(string $key): void
    {
        $namespaced = self::PREFIX.$key;
        Cache::forget($namespaced);
        $this->untrackKey($namespaced);
    }

    public function flushAll(): void
    {
        $index = Cache::get(self::INDEX_KEY, []);

        foreach ($index as $namespaced) {
            Cache::forget($namespaced);
        }

        Cache::forget(self::INDEX_KEY);
    }

    private function trackKey(string $namespaced): void
    {
        $index = Cache::get(self::INDEX_KEY, []);

        if (in_array($namespaced, $index, true)) {
            return;
        }

        $index[] = $namespaced;
        Cache::put(self::INDEX_KEY, $index, self::INDEX_TTL);
    }

    private function untrackKey(string $namespaced): void
    {
        $index = Cache::get(self::INDEX_KEY, []);

        $filtered = array_values(array_filter($index, fn ($k) => $k !== $namespaced));

        if ($filtered === $index) {
            return;
        }

        if (empty($filtered)) {
            Cache::forget(self::INDEX_KEY);

            return;
        }

        Cache::put(self::INDEX_KEY, $filtered, self::INDEX_TTL);
    }
}
