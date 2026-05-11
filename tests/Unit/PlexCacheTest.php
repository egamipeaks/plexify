<?php

use App\Services\Plex\PlexCache;
use Illuminate\Support\Facades\Cache;

it('namespaces keys with plex: prefix', function () {
    $cache = new PlexCache;

    $cache->remember('artists', PlexCache::TTL_ARTISTS, fn () => ['a', 'b']);

    expect(Cache::get('plex:artists'))->toBe(['a', 'b']);
});

it('returns the cached value on second call without invoking closure', function () {
    $cache = new PlexCache;
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
    $cache = new PlexCache;
    $cache->remember('test', 60, fn () => 'value');

    $cache->forget('test');

    expect(Cache::get('plex:test'))->toBeNull();
});

it('flushes all plex: keys via flushAll()', function () {
    $cache = new PlexCache;
    $cache->remember('a', 60, fn () => 1);
    $cache->remember('b', 60, fn () => 2);

    $cache->flushAll();

    expect(Cache::get('plex:a'))->toBeNull();
    expect(Cache::get('plex:b'))->toBeNull();
});

test('remember appends the namespaced key to plex:_index', function () {
    $cache = new PlexCache;

    $cache->remember('foo', 60, fn () => 'value-foo');

    expect(Cache::get('plex:_index'))->toBe(['plex:foo']);
});

test('remember does not duplicate an existing index entry on a cache hit', function () {
    $cache = new PlexCache;

    $cache->remember('foo', 60, fn () => 'value-foo');
    $cache->remember('foo', 60, fn () => 'value-foo-again');

    expect(Cache::get('plex:_index'))->toBe(['plex:foo']);
});

test('remember appends each distinct key once across calls', function () {
    $cache = new PlexCache;

    $cache->remember('foo', 60, fn () => 'a');
    $cache->remember('bar', 60, fn () => 'b');
    $cache->remember('foo', 60, fn () => 'a-again');

    expect(Cache::get('plex:_index'))->toEqualCanonicalizing(['plex:foo', 'plex:bar']);
});

test('forget removes the entry from plex:_index', function () {
    $cache = new PlexCache;

    $cache->remember('foo', 60, fn () => 'a');
    $cache->remember('bar', 60, fn () => 'b');

    $cache->forget('foo');

    expect(Cache::get('plex:_index'))->toBe(['plex:bar']);
    expect(Cache::get('plex:foo'))->toBeNull();
});

test('flushAll forgets every tracked key and clears the index', function () {
    $cache = new PlexCache;

    $cache->remember('foo', 60, fn () => 'a');
    $cache->remember('bar', 60, fn () => 'b');

    $cache->flushAll();

    expect(Cache::get('plex:foo'))->toBeNull();
    expect(Cache::get('plex:bar'))->toBeNull();
    expect(Cache::get('plex:_index'))->toBeNull();
});

test('flushAll is a no-op when the index is empty', function () {
    $cache = new PlexCache;

    $cache->flushAll();

    expect(Cache::get('plex:_index'))->toBeNull();
});

test('flushAll works across instances (cross-request)', function () {
    $writer = new PlexCache;
    $writer->remember('foo', 60, fn () => 'a');
    $writer->remember('bar', 60, fn () => 'b');

    $clearer = new PlexCache;
    $clearer->flushAll();

    expect(Cache::get('plex:foo'))->toBeNull();
    expect(Cache::get('plex:bar'))->toBeNull();
});
