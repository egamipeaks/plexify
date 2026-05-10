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

    private const PREFIX = 'plex:';

    private array $keys = [];

    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $namespaced = self::PREFIX . $key;
        $this->keys[$namespaced] = true;

        return Cache::remember($namespaced, $ttl, $callback);
    }

    public function forget(string $key): void
    {
        $namespaced = self::PREFIX . $key;
        unset($this->keys[$namespaced]);
        Cache::forget($namespaced);
    }

    public function flushAll(): void
    {
        foreach (array_keys($this->keys) as $namespaced) {
            Cache::forget($namespaced);
        }
        $this->keys = [];
    }
}
