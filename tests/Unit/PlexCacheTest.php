<?php

use App\Services\Plex\PlexCache;
use Illuminate\Support\Facades\Cache;

it('namespaces keys with plex: prefix', function () {
    $cache = new PlexCache();

    $cache->remember('artists', PlexCache::TTL_ARTISTS, fn () => ['a', 'b']);

    expect(Cache::get('plex:artists'))->toBe(['a', 'b']);
});

it('returns the cached value on second call without invoking closure', function () {
    $cache = new PlexCache();
    $callCount = 0;

    $first = $cache->remember('test', 60, function () use (&$callCount) {
        $callCount++;
        return 'computed';
    });

    $second = $cache->remember('test', 60, function () use (&$callCount) {
        $callCount++;
        return 'should-not-run';
    });

    expect($first)->toBe('computed');
    expect($second)->toBe('computed');
    expect($callCount)->toBe(1);
});

it('forgets a single key', function () {
    $cache = new PlexCache();
    $cache->remember('test', 60, fn () => 'value');

    $cache->forget('test');

    expect(Cache::get('plex:test'))->toBeNull();
});

it('flushes all plex: keys via flushAll()', function () {
    $cache = new PlexCache();
    $cache->remember('a', 60, fn () => 1);
    $cache->remember('b', 60, fn () => 2);

    $cache->flushAll();

    expect(Cache::get('plex:a'))->toBeNull();
    expect(Cache::get('plex:b'))->toBeNull();
});
