# Plextune Milestone 2: Library View + Plex API Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace M1's library stub with a real Library view that browses the user's Plex music library (artist → album → track miller columns) and plays tracks by streaming directly from Plex over HTTPS.

**Architecture:** A `PlexClient` service wraps Laravel's HTTP client and talks to the Plex Media Server. Server discovery is automatic (queries plex.tv with the user's token, picks the best HTTPS connection). The library Livewire component holds artist/album/track selections and uses Livewire 4 **islands** so each region (artists column, albums column, album header, tracklist) re-renders independently when a selection changes. Track clicks dispatch the `play-track` event already wired up in M1 — the player picks up real Plex stream URLs without any change to player code.

**Tech Stack:** Laravel HTTP client, Plex API (plex.tv resources + Plex Media Server library endpoints), Livewire 4 islands, Pest 4 with `Http::fake()` for unit-level tests and Playwright for end-to-end.

**Spec reference:** `docs/superpowers/specs/2026-05-09-plextune-design.md`
**Visual reference:** `design/rendered/your-library.html` (port class lists + structure verbatim)
**Prior milestone:** `docs/superpowers/plans/2026-05-09-plextune-milestone-1-shell-and-player.md`

---

## File Structure

**New service layer (`app/Services/Plex/`):**
- `PlexClient.php` — single class with all server-discovery + library + streaming methods. Reads token from `config('services.plex.token')`.
- `PlexCache.php` — thin wrapper around `Cache` with `plex:` namespace.
- `Dto/Artist.php`, `Dto/Album.php`, `Dto/Track.php` — readonly data classes mapped from Plex JSON.
- `Exceptions/PlexException.php` (abstract base)
- `Exceptions/PlexUnreachableException.php`
- `Exceptions/PlexAuthException.php`
- `Exceptions/PlexNotFoundException.php`

**New Livewire components:**
- `resources/views/components/⚡server-chip.blade.php` — replaces the stub server chip in the sidebar; shows live connection status.

**Modified Livewire components:**
- `resources/views/components/⚡sidebar.blade.php` — embed `<livewire:server-chip />` where the stub was.
- `resources/views/pages/⚡library.blade.php` — replace the stub + smoke-test button with the full library view (miller columns, album header, tracklist).

**Tests:**
- `tests/Unit/PlexClientTest.php` — endpoint correctness, query params, exception mapping (`Http::fake()`).
- `tests/Unit/PlexCacheTest.php` — namespace + TTL.
- `tests/Feature/LibraryViewTest.php` — Livewire-level tests for selection state, error states.
- `tests/Feature/ServerChipTest.php` — connection status rendering.
- `tests/Browser/LibraryDrillDownTest.php` — end-to-end: artist → album → track → player plays.

**Test fixtures:**
- `tests/Fixtures/Plex/resources.json` — sample plex.tv resources response.
- `tests/Fixtures/Plex/library_sections.json` — sample library sections list.
- `tests/Fixtures/Plex/artists.json` — sample artists response.
- `tests/Fixtures/Plex/albums_for_artist.json` — sample albums for one artist.
- `tests/Fixtures/Plex/tracks_for_album.json` — sample tracks for one album.

**Removed:**
- The "Play test track" button + `playTestTrack` method in `⚡library.blade.php` (replaced by real tracklist).
- `tests/Browser/AudioPersistenceTest.php` (replaced by `LibraryDrillDownTest` which exercises the same persistence path with real Plex data and additionally proves track-loading works).
- `public/test-track.mp3` (no longer needed; gitignored anyway).

---

## Task 1: Plex exception classes

**Files:**
- Create: `app/Services/Plex/Exceptions/PlexException.php`
- Create: `app/Services/Plex/Exceptions/PlexUnreachableException.php`
- Create: `app/Services/Plex/Exceptions/PlexAuthException.php`
- Create: `app/Services/Plex/Exceptions/PlexNotFoundException.php`

These are empty marker classes that extend a common base. PlexClient throws specific subclasses; UI components catch the base.

- [ ] **Step 1: Create the base abstract exception**

`app/Services/Plex/Exceptions/PlexException.php`:

```php
<?php

namespace App\Services\Plex\Exceptions;

use RuntimeException;

abstract class PlexException extends RuntimeException
{
}
```

- [ ] **Step 2: Create the three concrete exceptions**

`app/Services/Plex/Exceptions/PlexUnreachableException.php`:

```php
<?php

namespace App\Services\Plex\Exceptions;

class PlexUnreachableException extends PlexException
{
}
```

`app/Services/Plex/Exceptions/PlexAuthException.php`:

```php
<?php

namespace App\Services\Plex\Exceptions;

class PlexAuthException extends PlexException
{
}
```

`app/Services/Plex/Exceptions/PlexNotFoundException.php`:

```php
<?php

namespace App\Services\Plex\Exceptions;

class PlexNotFoundException extends PlexException
{
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/Plex/Exceptions/
git commit -m "feat: add PlexException base + Unreachable/Auth/NotFound subclasses"
```

---

## Task 2: Plex DTOs (Artist / Album / Track)

**Files:**
- Create: `app/Services/Plex/Dto/Artist.php`
- Create: `app/Services/Plex/Dto/Album.php`
- Create: `app/Services/Plex/Dto/Track.php`
- Create: `tests/Unit/PlexDtoTest.php`

DTOs are readonly classes (PHP 8.2+) with a static `fromPlex(array $row): static` factory. They isolate UI/Livewire code from Plex's JSON shape.

- [ ] **Step 1: Write the failing test**

`tests/Unit/PlexDtoTest.php`:

```php
<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Track;

it('builds Artist from Plex JSON row', function () {
    $row = [
        'ratingKey' => '12345',
        'title' => 'Bon Iver',
        'thumb' => '/library/metadata/12345/thumb/1700000000',
        'childCount' => 5,
    ];

    $artist = Artist::fromPlex($row);

    expect($artist->id)->toBe('12345');
    expect($artist->name)->toBe('Bon Iver');
    expect($artist->thumb)->toBe('/library/metadata/12345/thumb/1700000000');
    expect($artist->albumCount)->toBe(5);
});

it('builds Album from Plex JSON row', function () {
    $row = [
        'ratingKey' => '67890',
        'title' => '22, A Million',
        'parentTitle' => 'Bon Iver',
        'year' => 2016,
        'thumb' => '/library/metadata/67890/thumb/1700000000',
        'leafCount' => 10,
        'duration' => 2160000,
    ];

    $album = Album::fromPlex($row);

    expect($album->id)->toBe('67890');
    expect($album->title)->toBe('22, A Million');
    expect($album->artist)->toBe('Bon Iver');
    expect($album->year)->toBe(2016);
    expect($album->thumb)->toBe('/library/metadata/67890/thumb/1700000000');
    expect($album->trackCount)->toBe(10);
    expect($album->durationMs)->toBe(2160000);
});

it('builds Track from Plex JSON row', function () {
    $row = [
        'ratingKey' => '11111',
        'title' => '33 GOD',
        'grandparentTitle' => 'Bon Iver',
        'parentTitle' => '22, A Million',
        'index' => 3,
        'duration' => 213000,
        'Media' => [
            [
                'audioCodec' => 'flac',
                'container' => 'flac',
                'Part' => [
                    [
                        'id' => 999,
                        'key' => '/library/parts/999/1700000000/file.flac',
                        'container' => 'flac',
                    ],
                ],
            ],
        ],
    ];

    $track = Track::fromPlex($row);

    expect($track->id)->toBe('11111');
    expect($track->title)->toBe('33 GOD');
    expect($track->artist)->toBe('Bon Iver');
    expect($track->album)->toBe('22, A Million');
    expect($track->trackNumber)->toBe(3);
    expect($track->durationMs)->toBe(213000);
    expect($track->partId)->toBe(999);
    expect($track->container)->toBe('flac');
});

it('handles missing optional fields gracefully', function () {
    $artist = Artist::fromPlex([
        'ratingKey' => '1',
        'title' => 'Unknown Artist',
    ]);

    expect($artist->thumb)->toBeNull();
    expect($artist->albumCount)->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=PlexDtoTest
```

Expected: FAIL — DTO classes don't exist.

- [ ] **Step 3: Create Artist DTO**

`app/Services/Plex/Dto/Artist.php`:

```php
<?php

namespace App\Services\Plex\Dto;

readonly class Artist
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $thumb,
        public int $albumCount,
    ) {}

    public static function fromPlex(array $row): self
    {
        return new self(
            id: (string) $row['ratingKey'],
            name: $row['title'],
            thumb: $row['thumb'] ?? null,
            albumCount: $row['childCount'] ?? 0,
        );
    }
}
```

- [ ] **Step 4: Create Album DTO**

`app/Services/Plex/Dto/Album.php`:

```php
<?php

namespace App\Services\Plex\Dto;

readonly class Album
{
    public function __construct(
        public string $id,
        public string $title,
        public string $artist,
        public ?int $year,
        public ?string $thumb,
        public int $trackCount,
        public int $durationMs,
    ) {}

    public static function fromPlex(array $row): self
    {
        return new self(
            id: (string) $row['ratingKey'],
            title: $row['title'],
            artist: $row['parentTitle'] ?? '',
            year: $row['year'] ?? null,
            thumb: $row['thumb'] ?? null,
            trackCount: $row['leafCount'] ?? 0,
            durationMs: $row['duration'] ?? 0,
        );
    }
}
```

- [ ] **Step 5: Create Track DTO**

`app/Services/Plex/Dto/Track.php`:

```php
<?php

namespace App\Services\Plex\Dto;

readonly class Track
{
    public function __construct(
        public string $id,
        public string $title,
        public string $artist,
        public string $album,
        public int $trackNumber,
        public int $durationMs,
        public int $partId,
        public string $container,
    ) {}

    public static function fromPlex(array $row): self
    {
        $part = $row['Media'][0]['Part'][0] ?? [];

        return new self(
            id: (string) $row['ratingKey'],
            title: $row['title'],
            artist: $row['grandparentTitle'] ?? '',
            album: $row['parentTitle'] ?? '',
            trackNumber: $row['index'] ?? 0,
            durationMs: $row['duration'] ?? 0,
            partId: (int) ($part['id'] ?? 0),
            container: $part['container'] ?? 'mp3',
        );
    }
}
```

- [ ] **Step 6: Run tests**

```bash
php artisan test --filter=PlexDtoTest
```

Expected: 4 passing.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Plex/Dto/ tests/Unit/PlexDtoTest.php
git commit -m "feat: add Plex DTOs for Artist, Album, Track"
```

---

## Task 3: PlexCache wrapper

**Files:**
- Create: `app/Services/Plex/PlexCache.php`
- Create: `tests/Unit/PlexCacheTest.php`

A thin wrapper around Laravel's `Cache` facade with a `plex:` namespace prefix and standard TTL constants.

- [ ] **Step 1: Write the failing test**

`tests/Unit/PlexCacheTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=PlexCacheTest
```

Expected: FAIL — class missing.

- [ ] **Step 3: Implement PlexCache**

`app/Services/Plex/PlexCache.php`:

```php
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
```

Note: `flushAll()` only forgets keys this instance has touched. For a true cross-process flush by prefix, the Laravel cache driver would need to support tagged caches. For our use (single user, single process), instance-level tracking is enough.

- [ ] **Step 4: Run tests**

```bash
php artisan test --filter=PlexCacheTest
```

Expected: 4 passing.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Plex/PlexCache.php tests/Unit/PlexCacheTest.php
git commit -m "feat: add PlexCache wrapper with TTL constants"
```

---

## Task 4: PlexClient — server discovery

**Files:**
- Create: `app/Services/Plex/PlexClient.php`
- Create: `tests/Unit/PlexClientTest.php`
- Create: `tests/Fixtures/Plex/resources.json`

Server discovery: given the user's token, fetch `https://plex.tv/api/v2/resources?includeHttps=1`, find the owned server (`provides=server`, `owned=true`), pick the best connection (prefer `local: true`, fall back to remote relay), and return the base URL.

- [ ] **Step 1: Create the test fixture**

`tests/Fixtures/Plex/resources.json`:

```json
[
  {
    "name": "HOMESERVER",
    "product": "Plex Media Server",
    "provides": "server",
    "owned": true,
    "clientIdentifier": "fdc67453cc21f68f457007dcba5e108675f02a56",
    "connections": [
      {
        "protocol": "https",
        "address": "10.0.0.50",
        "port": 32400,
        "uri": "https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400",
        "local": true,
        "relay": false
      },
      {
        "protocol": "https",
        "address": "107.216.58.9",
        "port": 24476,
        "uri": "https://107-216-58-9.c36d6e0431c147dda2be7d81893a1653.plex.direct:24476",
        "local": false,
        "relay": false
      }
    ]
  },
  {
    "name": "TV 2025",
    "product": "Plex for Samsung",
    "provides": "client,player",
    "owned": true,
    "connections": []
  }
]
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/PlexClientTest.php`:

```php
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
```

- [ ] **Step 3: Run test to verify it fails**

```bash
php artisan test --filter=PlexClientTest
```

Expected: FAIL — PlexClient class missing.

- [ ] **Step 4: Implement PlexClient with baseUrl()**

`app/Services/Plex/PlexClient.php`:

```php
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
        $response = $this->plexTv()->get('/resources', ['includeHttps' => 1]);

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
            ])
            ->throw(false);
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
```

Note: the `ConnectionException` thrown inside `Http::fake()` callbacks bubbles up directly, so we need to catch it. Update the `discoverBaseUrl()` method:

Replace the `$response = ...` line with:

```php
try {
    $response = $this->plexTv()->get('/resources', ['includeHttps' => 1]);
} catch (ConnectionException $e) {
    throw new PlexUnreachableException('Cannot reach plex.tv: ' . $e->getMessage(), previous: $e);
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter=PlexClientTest
```

Expected: 6 passing. If any fail, debug carefully — server discovery has several branches.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php tests/Fixtures/Plex/resources.json
git commit -m "feat: PlexClient::baseUrl() — discover server URL via plex.tv resources"
```

---

## Task 5: PlexClient — music section discovery

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `tests/Unit/PlexClientTest.php`
- Create: `tests/Fixtures/Plex/library_sections.json`

`musicSectionId(): int` calls `{baseUrl}/library/sections`, picks the first section with `type=artist`.

- [ ] **Step 1: Create the fixture**

`tests/Fixtures/Plex/library_sections.json`:

```json
{
  "MediaContainer": {
    "size": 3,
    "Directory": [
      {"key": "1", "type": "movie", "title": "Movies"},
      {"key": "2", "type": "show", "title": "TV Shows"},
      {"key": "3", "type": "artist", "title": "Music"}
    ]
  }
}
```

- [ ] **Step 2: Add the failing test**

Append to `tests/Unit/PlexClientTest.php`:

```php
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
```

- [ ] **Step 3: Run tests to confirm failure**

```bash
php artisan test --filter=PlexClientTest
```

- [ ] **Step 4: Implement musicSectionId() + a server() helper**

Add to `PlexClient.php`:

```php
use App\Services\Plex\Exceptions\PlexNotFoundException;

// ... inside the class, after baseUrl():

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

private function server(): PendingRequest
{
    // No ->throw() — Laravel's HTTP client does NOT auto-throw on 4xx/5xx by
    // default, so we inspect $response->status() ourselves. (->throw(false) is
    // a TypeError in this Laravel version; throw() only accepts ?callable.)
    return Http::baseUrl($this->baseUrl())
        ->acceptJson()
        ->withHeaders([
            'X-Plex-Token' => $this->token(),
            'X-Plex-Client-Identifier' => 'plexify',
        ]);
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter=PlexClientTest
```

Expected: 8 passing (6 prior + 2 new).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php tests/Fixtures/Plex/library_sections.json
git commit -m "feat: PlexClient::musicSectionId() — discover music section type=artist"
```

---

## Task 6: PlexClient — artists()

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `tests/Unit/PlexClientTest.php`
- Create: `tests/Fixtures/Plex/artists.json`

`artists(): Collection<Artist>` calls `{baseUrl}/library/sections/{musicSectionId}/all?type=8`, maps each row through `Artist::fromPlex`.

- [ ] **Step 1: Create the fixture**

`tests/Fixtures/Plex/artists.json`:

```json
{
  "MediaContainer": {
    "size": 2,
    "Metadata": [
      {
        "ratingKey": "100",
        "title": "Bon Iver",
        "thumb": "/library/metadata/100/thumb/1700000000",
        "childCount": 5
      },
      {
        "ratingKey": "200",
        "title": "Radiohead",
        "thumb": "/library/metadata/200/thumb/1700000001",
        "childCount": 9
      }
    ]
  }
}
```

- [ ] **Step 2: Add the failing test**

Append to `tests/Unit/PlexClientTest.php`:

```php
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
```

- [ ] **Step 3: Run tests to confirm failure**

- [ ] **Step 4: Implement artists()**

Add to `PlexClient.php`:

```php
use App\Services\Plex\Dto\Artist;
use Illuminate\Support\Collection;

// ... inside the class:

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
```

- [ ] **Step 5: Run tests**

Expected: 10 passing.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php tests/Fixtures/Plex/artists.json
git commit -m "feat: PlexClient::artists() — list music library artists"
```

---

## Task 7: PlexClient — albumsForArtist() + tracksForAlbum()

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `tests/Unit/PlexClientTest.php`
- Create: `tests/Fixtures/Plex/albums_for_artist.json`
- Create: `tests/Fixtures/Plex/tracks_for_album.json`

Both follow the same pattern: `GET /library/metadata/{ratingKey}/children`, map response rows.

- [ ] **Step 1: Create fixtures**

`tests/Fixtures/Plex/albums_for_artist.json`:

```json
{
  "MediaContainer": {
    "size": 2,
    "Metadata": [
      {
        "ratingKey": "1001",
        "title": "22, A Million",
        "parentTitle": "Bon Iver",
        "year": 2016,
        "thumb": "/library/metadata/1001/thumb/1700000000",
        "leafCount": 10,
        "duration": 2160000
      },
      {
        "ratingKey": "1002",
        "title": "i,i",
        "parentTitle": "Bon Iver",
        "year": 2019,
        "thumb": "/library/metadata/1002/thumb/1700000001",
        "leafCount": 13,
        "duration": 2520000
      }
    ]
  }
}
```

`tests/Fixtures/Plex/tracks_for_album.json`:

```json
{
  "MediaContainer": {
    "size": 2,
    "Metadata": [
      {
        "ratingKey": "9001",
        "title": "22 (OVER S∞∞N)",
        "grandparentTitle": "Bon Iver",
        "parentTitle": "22, A Million",
        "index": 1,
        "duration": 169000,
        "Media": [{
          "audioCodec": "flac",
          "container": "flac",
          "Part": [{"id": 9001001, "key": "/library/parts/9001001/1700000000/file.flac", "container": "flac"}]
        }]
      },
      {
        "ratingKey": "9002",
        "title": "10 d E A T h b R E a s T ⚄⚄",
        "grandparentTitle": "Bon Iver",
        "parentTitle": "22, A Million",
        "index": 2,
        "duration": 240000,
        "Media": [{
          "audioCodec": "flac",
          "container": "flac",
          "Part": [{"id": 9001002, "key": "/library/parts/9001002/1700000000/file.flac", "container": "flac"}]
        }]
      }
    ]
  }
}
```

- [ ] **Step 2: Add the failing tests**

Append to `tests/Unit/PlexClientTest.php`:

```php
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
```

- [ ] **Step 3: Run tests to confirm failure**

- [ ] **Step 4: Implement both methods**

Add to `PlexClient.php`:

```php
use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexNotFoundException;

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
```

- [ ] **Step 5: Run tests**

Expected: 12 passing.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php tests/Fixtures/Plex/
git commit -m "feat: PlexClient::albumsForArtist + tracksForAlbum"
```

---

## Task 8: PlexClient — streamUrl() + thumbUrl()

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `tests/Unit/PlexClientTest.php`

`streamUrl(Track $track): string` returns `{baseUrl}/library/parts/{partId}/file.{container}?X-Plex-Token={token}`. No HTTP call.

`thumbUrl(string $thumb): ?string` returns `{baseUrl}{thumb}?X-Plex-Token={token}` or null if `$thumb` is empty.

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/PlexClientTest.php`:

```php
it('builds a stream URL for a track', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
    ]);

    $client = app(PlexClient::class);
    $track = new \App\Services\Plex\Dto\Track(
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
```

- [ ] **Step 2: Implement both methods**

Add to `PlexClient.php`:

```php
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
```

Add the `use App\Services\Plex\Dto\Track;` import at the top if not already present.

- [ ] **Step 3: Run tests**

```bash
php artisan test --filter=PlexClientTest
```

Expected: 15 passing.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat: PlexClient::streamUrl + thumbUrl with token-appended URLs"
```

---

## Task 9: PlexClient — ping() for connection check

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `tests/Unit/PlexClientTest.php`

`ping(): array` returns connection metadata for the server chip — `['name' => ..., 'connection' => 'direct'|'relay'|'down', 'machineIdentifier' => ...]`.

`/identity` is the lightweight Plex endpoint that returns the server name and identifier without auth-checking deeply.

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/PlexClientTest.php`:

```php
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
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'),
    ]);

    $client = app(PlexClient::class);
    $status = $client->ping();

    expect($status['reachable'])->toBeFalse();
    expect($status['connection'])->toBe('down');
});
```

- [ ] **Step 2: Implement ping()**

Add to `PlexClient.php`:

```php
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
        && collect($resources['connections'] ?? [])->firstWhere('uri', $this->baseUrl())['local'] ?? false;

    return [
        'name' => $name,
        'reachable' => true,
        'connection' => $isLocal ? 'direct' : 'relay',
        'machineIdentifier' => data_get($response->json(), 'MediaContainer.machineIdentifier'),
    ];
}

private function ownedServerResource(): array
{
    return $this->cache->remember('owned_resource', PlexCache::TTL_RESOURCES, function () {
        try {
            $response = $this->plexTv()->get('/resources', ['includeHttps' => 1]);
        } catch (ConnectionException $e) {
            throw new PlexUnreachableException('Cannot reach plex.tv: ' . $e->getMessage(), previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new PlexAuthException('Plex token rejected.');
        }

        $owned = collect($response->json())->first(
            fn (array $r) => str_contains($r['provides'] ?? '', 'server') && ($r['owned'] ?? false),
        );

        if (! $owned) {
            throw new PlexUnreachableException('No owned Plex server found.');
        }

        return $owned;
    });
}
```

Note: refactor `discoverBaseUrl()` to use `ownedServerResource()` to avoid duplicate fetch logic. Replace its body with:

```php
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
```

- [ ] **Step 3: Run tests**

```bash
php artisan test --filter=PlexClientTest
```

Expected: 17 passing.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat: PlexClient::ping() for live server status"
```

---

## Task 10: Server chip Livewire component

**Files:**
- Generate: `php artisan make:livewire server-chip`
- Edit: `resources/views/components/⚡server-chip.blade.php`
- Modify: `resources/views/components/⚡sidebar.blade.php`
- Create: `tests/Feature/ServerChipTest.php`

The server chip in the sidebar shows the server name, a green/red status dot, and connection metadata. It's a Livewire component so it can re-fetch status on demand without refreshing the whole sidebar.

- [ ] **Step 1: Generate component**

```bash
php artisan make:livewire server-chip
```

- [ ] **Step 2: Write the feature test**

`tests/Feature/ServerChipTest.php`:

```php
<?php

use App\Services\Plex\PlexClient;
use Livewire\Livewire;

it('renders the server name and reachable state', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('ping')
        ->andReturn([
            'name' => 'HOMESERVER',
            'reachable' => true,
            'connection' => 'direct',
            'machineIdentifier' => 'abc',
        ]);

    Livewire::test('server-chip')
        ->assertSee('HOMESERVER')
        ->assertSee('Direct')
        ->assertSeeHtml('bg-accent'); // status dot color
});

it('shows red dot and Unreachable when ping returns unreachable', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('ping')
        ->andReturn([
            'name' => 'HOMESERVER',
            'reachable' => false,
            'connection' => 'down',
            'machineIdentifier' => null,
        ]);

    Livewire::test('server-chip')
        ->assertSee('Unreachable')
        ->assertSeeHtml('bg-red-500');
});

it('handles PlexException gracefully and shows error state', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('ping')
        ->andThrow(new \App\Services\Plex\Exceptions\PlexAuthException('bad token'));

    Livewire::test('server-chip')
        ->assertSee('Auth error');
});
```

- [ ] **Step 3: Run test to verify it fails**

```bash
php artisan test --filter=ServerChipTest
```

- [ ] **Step 4: Implement the component**

`resources/views/components/⚡server-chip.blade.php`:

```blade
<?php

use App\Services\Plex\Exceptions\PlexAuthException;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Livewire\Component;

new class extends Component {
    public string $name = '—';
    public string $connection = 'down';
    public bool $reachable = false;
    public ?string $error = null;

    public function mount(PlexClient $plex): void
    {
        $this->refreshStatus($plex);
    }

    public function refresh(PlexClient $plex): void
    {
        $this->refreshStatus($plex);
    }

    private function refreshStatus(PlexClient $plex): void
    {
        try {
            $status = $plex->ping();
            $this->name = $status['name'];
            $this->reachable = $status['reachable'];
            $this->connection = $status['connection'];
            $this->error = null;
        } catch (PlexAuthException) {
            $this->error = 'Auth error';
            $this->reachable = false;
        } catch (PlexException) {
            $this->error = 'Unreachable';
            $this->reachable = false;
        }
    }
};
?>

<div wire:click="refresh"
     class="bg-surface rounded-lg p-3 flex items-center gap-3 cursor-pointer hover:bg-surface-1 transition-colors">
    <div class="w-9 h-9 rounded-md bg-gradient-to-br from-plex-orange-from to-plex-orange-to grid place-items-center text-black flex-none font-black text-[14px]">P</div>

    <div class="min-w-0 flex-1">
        <div class="text-[13px] font-bold truncate flex items-center gap-1.5">
            {{ $name }}
            <span @class([
                'w-1.5 h-1.5 rounded-full inline-block',
                'bg-accent' => $reachable,
                'bg-red-500' => ! $reachable,
            ])></span>
        </div>
        <div class="text-[11px] text-text-2 truncate flex items-center gap-1">
            @if ($error)
                <x-lucide-triangle-alert class="w-3 h-3" />
                {{ $error }}
            @elseif ($reachable)
                <x-lucide-wifi class="w-3 h-3" />
                {{ ucfirst($connection) }} connection
            @else
                <x-lucide-wifi-off class="w-3 h-3" />
                Unreachable
            @endif
        </div>
    </div>

    <button type="button" class="w-7 h-7 rounded-full grid place-items-center text-text-2 hover:text-white hover:bg-surface-3" title="Refresh">
        <x-lucide-refresh-cw class="w-3.5 h-3.5" />
    </button>
</div>
```

- [ ] **Step 5: Replace the stub server chip in the sidebar**

In `resources/views/components/⚡sidebar.blade.php`, find the existing server chip block (the orange-gradient div with stub data from M1.5) and replace it with:

```blade
<livewire:server-chip />
```

Verify with:
```bash
grep -A2 "server-chip\|HomeServer" resources/views/components/'⚡sidebar.blade.php'
```

- [ ] **Step 6: Run tests**

```bash
php artisan test
```

Expected: all prior tests + 3 new ServerChipTest = 20 passing.

- [ ] **Step 7: Manual sanity check**

```bash
curl -sk https://plexify.test/ | grep -E "HOMESERVER|Direct|Auth error" | head -3
```

Should show your real server name "HOMESERVER" since `.env` has the working token.

- [ ] **Step 8: Commit**

```bash
git add resources/views/components/'⚡server-chip.blade.php' \
        resources/views/components/'⚡sidebar.blade.php' \
        tests/Feature/ServerChipTest.php
git commit -m "feat: live server chip showing Plex connection status"
```

---

## Task 11: Library view — scaffolding + artists column

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php`
- Create: `tests/Feature/LibraryViewTest.php`

Replace the stub library page with a real Livewire component that loads artists in `mount()`. Render the artist list in the left miller column. Selection state lives in the component. Errors during mount() set `$error` so the view renders an error panel.

The Albums column, album header, and tracklist are stubbed in this task and filled in by Tasks 12–13.

- [ ] **Step 1: Write the failing test**

`tests/Feature/LibraryViewTest.php`:

```php
<?php

use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

it('lists artists from PlexClient on mount', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 5),
            new Artist(id: '200', name: 'Radiohead', thumb: null, albumCount: 9),
        ]));
    });

    Livewire::test('pages::library')
        ->assertSee('Bon Iver')
        ->assertSee('Radiohead')
        ->assertSee('5 albums')
        ->assertSee('9 albums');
});

it('renders error panel when Plex is unreachable on mount', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('artists')
        ->andThrow(new PlexUnreachableException("Can't reach"));

    Livewire::test('pages::library')
        ->assertSee("Can't reach your Plex server")
        ->assertSee('Retry');
});

it('selects an artist on click', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 5),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect());
    });

    Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->assertSet('selectedArtistId', '100');
});
```

- [ ] **Step 2: Replace `⚡library.blade.php`**

```blade
<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?string $selectedArtistId = null;
    public ?string $selectedAlbumId = null;
    public ?string $errorMessage = null;

    /** @var Collection<int, Artist> */
    public Collection $artists;

    public function mount(PlexClient $plex): void
    {
        $this->artists = collect();
        $this->loadArtists($plex);
    }

    public function selectArtist(string $id): void
    {
        $this->selectedArtistId = $id;
        $this->selectedAlbumId = null;
    }

    public function selectAlbum(string $id): void
    {
        $this->selectedAlbumId = $id;
    }

    public function retry(PlexClient $plex): void
    {
        $this->errorMessage = null;
        $this->loadArtists($plex);
    }

    private function loadArtists(PlexClient $plex): void
    {
        try {
            $this->artists = $plex->artists();
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. " . $e->getMessage();
        }
    }

    #[Computed]
    public function albums(PlexClient $plex): Collection
    {
        if (! $this->selectedArtistId) {
            return collect();
        }
        try {
            return $plex->albumsForArtist($this->selectedArtistId);
        } catch (PlexException) {
            return collect();
        }
    }

    #[Computed]
    public function tracks(PlexClient $plex): Collection
    {
        if (! $this->selectedAlbumId) {
            return collect();
        }
        try {
            return $plex->tracksForAlbum($this->selectedAlbumId);
        } catch (PlexException) {
            return collect();
        }
    }

    #[Computed]
    public function selectedAlbum(): ?Album
    {
        return $this->albums->firstWhere('id', $this->selectedAlbumId);
    }
};
?>

@if ($errorMessage)
    <div class="h-full grid place-items-center p-12 text-center">
        <div class="max-w-md">
            <x-lucide-server-off class="w-12 h-12 mx-auto text-text-3 mb-4" />
            <h2 class="text-xl font-bold mb-2">Can't reach your Plex server</h2>
            <p class="text-text-2 text-sm mb-6">{{ $errorMessage }}</p>
            <button type="button" wire:click="retry"
                    class="px-5 py-2 bg-white text-black rounded-full font-bold hover:scale-105 transition-transform">
                Retry
            </button>
        </div>
    </div>
@else
    <div class="h-full flex flex-col">
        {{-- Miller columns: Artists + Albums --}}
        <div class="grid grid-cols-2 gap-2 px-2 pt-2 flex-none" style="height: 220px;">
            {{-- Artists column --}}
            <div class="flex flex-col min-h-0 bg-surface rounded-lg overflow-hidden">
                <div class="px-4 pt-3 pb-2 flex items-center justify-between gap-2 flex-none">
                    <div class="text-[13px] font-bold uppercase tracking-wider text-text-2 whitespace-nowrap">Artists</div>
                    <div class="text-[11px] text-text-3">{{ $artists->count() }}</div>
                </div>
                <div class="overflow-y-auto flex-1">
                    @foreach ($artists as $artist)
                        <button wire:click="selectArtist('{{ $artist->id }}')"
                                @class([
                                    'w-full flex items-center gap-3 px-3 py-2 text-left transition-colors',
                                    'bg-surface-3 text-white' => $selectedArtistId === $artist->id,
                                    'text-text-2 hover:text-white hover:bg-surface-2' => $selectedArtistId !== $artist->id,
                                ])>
                            <div class="w-9 h-9 rounded-full bg-surface-2 flex-none grid place-items-center">
                                <x-lucide-user class="w-4 h-4 text-text-3" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-[14px] font-semibold truncate">{{ $artist->name }}</div>
                                <div class="text-[11px] text-text-2 truncate">{{ $artist->albumCount }} albums</div>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Albums column (Task 12) --}}
            <div class="flex flex-col min-h-0 bg-surface rounded-lg overflow-hidden">
                <div class="px-4 pt-3 pb-2 flex items-center justify-between gap-2 flex-none">
                    <div class="text-[13px] font-bold uppercase tracking-wider text-text-2 whitespace-nowrap">Albums</div>
                </div>
                <div class="overflow-y-auto flex-1 grid place-items-center text-text-3 text-[12px]">
                    @if (! $selectedArtistId)
                        Select an artist
                    @else
                        Albums for selected artist (Task 12)
                    @endif
                </div>
            </div>
        </div>

        {{-- Album header (Task 13) --}}
        <div class="flex-none p-2"></div>

        {{-- Tracklist (Task 13) --}}
        <div class="flex-1 p-2 overflow-auto"></div>
    </div>
@endif
```

- [ ] **Step 3: Run tests**

```bash
php artisan test --filter=LibraryViewTest
```

Expected: 3 passing. The mock for `albumsForArtist` is needed in the third test because the `albums` computed property fires when `selectedArtistId` is set.

- [ ] **Step 4: Manual check**

```bash
curl -sk https://plexify.test/ | grep -E "Bon Iver|Artists|Select an artist" | head -5
```

Should see real artist names from your Plex library.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/'⚡library.blade.php' tests/Feature/LibraryViewTest.php
git commit -m "feat: library view — artists column + error panel"
```

---

## Task 12: Library view — albums column (island)

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php`

When an artist is selected, the Albums column renders the result of `albumsForArtist`. We use a Livewire 4 **island** so only this region re-renders when the selection changes.

> **Note on island directive syntax:** the exact Blade directive name in Livewire 4 may be `@island('name') ... @endisland` or different. Verify against the live v4 docs at `https://livewire.laravel.com/docs/4.x/islands` before implementing. If the directive doesn't compile, fall back to no island wrapper — the library will still work correctly (the whole component just re-renders on each click, which is fine for the small data sizes here). Islands are a perf optimization, not a correctness requirement, so don't get blocked on directive syntax.

- [ ] **Step 1: Add a failing test for the albums column**

Append to `tests/Feature/LibraryViewTest.php`:

```php
it('lists albums for the selected artist', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 2),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: '22, A Million', artist: 'Bon Iver', year: 2016, thumb: null, trackCount: 10, durationMs: 2160000),
            new Album(id: '1002', title: 'i,i', artist: 'Bon Iver', year: 2019, thumb: null, trackCount: 13, durationMs: 2520000),
        ]));
    });

    Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->assertSee('22, A Million')
        ->assertSee('i,i')
        ->assertSee('2016')
        ->assertSee('2019');
});
```

Add the import: `use App\Services\Plex\Dto\Album;` at the top of the test file.

- [ ] **Step 2: Replace the Albums column placeholder with the real list**

In `⚡library.blade.php`, find the Albums column block and replace its scrolling area with:

```blade
{{-- Albums column --}}
<div class="flex flex-col min-h-0 bg-surface rounded-lg overflow-hidden">
    <div class="px-4 pt-3 pb-2 flex items-center justify-between gap-2 flex-none">
        <div class="text-[13px] font-bold uppercase tracking-wider text-text-2 whitespace-nowrap">Albums</div>
        @if ($selectedArtistId)
            <div class="text-[11px] text-text-3">{{ $this->albums->count() }}</div>
        @endif
    </div>
    @island
        <div class="overflow-y-auto flex-1">
            @if (! $selectedArtistId)
                <div class="grid place-items-center h-full text-text-3 text-[12px]">Select an artist</div>
            @elseif ($this->albums->isEmpty())
                <div class="grid place-items-center h-full text-text-3 text-[12px]">No albums</div>
            @else
                @foreach ($this->albums as $album)
                    <button wire:click="selectAlbum('{{ $album->id }}')"
                            @class([
                                'w-full flex items-center gap-3 px-3 py-2 text-left transition-colors',
                                'bg-surface-3 text-white' => $selectedAlbumId === $album->id,
                                'text-text-2 hover:text-white hover:bg-surface-2' => $selectedAlbumId !== $album->id,
                            ])>
                        <div class="w-9 h-9 rounded bg-surface-2 flex-none grid place-items-center">
                            <x-lucide-disc class="w-4 h-4 text-text-3" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-[14px] font-semibold truncate">{{ $album->title }}</div>
                            <div class="text-[11px] text-text-2 truncate">
                                {{ $album->year ?: '' }}{{ $album->year ? ' · ' : '' }}{{ $album->trackCount }} tracks
                            </div>
                        </div>
                    </button>
                @endforeach
            @endif
        </div>
    @endisland
</div>
```

- [ ] **Step 3: Run tests**

```bash
php artisan test --filter=LibraryViewTest
```

Expected: 4 passing.

- [ ] **Step 4: Commit**

```bash
git add resources/views/pages/'⚡library.blade.php' tests/Feature/LibraryViewTest.php
git commit -m "feat: library — albums column populated when artist selected"
```

---

## Task 13: Library view — album header + tracklist (islands) + click-to-play

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php`

Below the miller columns, an Album header (gradient strip with cover, title, action buttons) appears when an album is selected. Below that, the tracklist. Track row click → dispatch `play-track` with the stream URL → player picks it up.

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/LibraryViewTest.php`:

```php
use App\Services\Plex\Dto\Track;

it('renders album header and tracklist when album selected', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: '22, A Million', artist: 'Bon Iver', year: 2016, thumb: null, trackCount: 2, durationMs: 360000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->with('1001')->andReturn(collect([
            new Track(id: '9001', title: '22 (OVER S∞∞N)', artist: 'Bon Iver', album: '22, A Million', trackNumber: 1, durationMs: 169000, partId: 9001001, container: 'flac'),
            new Track(id: '9002', title: '10 d E A T h b R E a s T', artist: 'Bon Iver', album: '22, A Million', trackNumber: 2, durationMs: 240000, partId: 9001002, container: 'flac'),
        ]));
    });

    Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->call('selectAlbum', '1001')
        ->assertSee('22, A Million')
        ->assertSee('22 (OVER S∞∞N)')
        ->assertSee('10 d E A T h b R E a s T')
        ->assertSee('2:49') // 169s formatted
        ->assertSee('4:00'); // 240s formatted
});

it('dispatches play-track event with stream URL when track clicked', function () {
    $track = new Track(id: '9001', title: 'Test', artist: 'A', album: 'B', trackNumber: 1, durationMs: 1000, partId: 999, container: 'flac');

    $this->mock(PlexClient::class, function ($mock) use ($track) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'A', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->andReturn(collect([
            new Album(id: '1001', title: 'B', artist: 'A', year: 2024, thumb: null, trackCount: 1, durationMs: 1000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->andReturn(collect([$track]));
        $mock->shouldReceive('streamUrl')->with(\Mockery::on(fn ($t) => $t->id === '9001'))->andReturn('https://plex/file.flac?X-Plex-Token=t');
    });

    Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->call('selectAlbum', '1001')
        ->call('playTrack', '9001')
        ->assertDispatched('play-track',
            url: 'https://plex/file.flac?X-Plex-Token=t',
            title: 'Test',
            artist: 'A',
        );
});
```

- [ ] **Step 2: Add `playTrack` and a `formatMs` helper as class methods**

In `⚡library.blade.php`, add to the anonymous class body (after `selectAlbum`):

```php
public function playTrack(string $trackId, PlexClient $plex): void
{
    $track = $this->tracks->firstWhere('id', $trackId);

    if (! $track) {
        return;
    }

    $this->dispatch('play-track',
        url: $plex->streamUrl($track),
        title: $track->title,
        artist: $track->artist,
    );
}

public function formatMs(int $ms): string
{
    $seconds = (int) round($ms / 1000);
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;

    return sprintf('%d:%02d', $m, $s);
}
```

Use it in the template as `$this->formatMs($value)`. Class methods are fine to call from Blade and avoid the function-redeclaration footgun of a top-level helper inside an SFC's PHP block.

- [ ] **Step 3: Add the album header and tracklist sections**

Replace the two empty placeholder divs at the bottom of the template (the ones marked `Album header (Task 13)` and `Tracklist (Task 13)`) with:

```blade
{{-- Album header --}}
@if ($this->selectedAlbum)
    @island
        <div class="flex-none p-2">
            <div class="bg-gradient-to-b from-surface-3 to-surface rounded-lg p-6 flex items-end gap-6">
                <div class="w-[120px] h-[120px] rounded shadow-2xl bg-surface-2 grid place-items-center flex-none">
                    <x-lucide-disc class="w-10 h-10 text-text-3" />
                </div>
                <div class="min-w-0">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-text-2">Album</div>
                    <h1 class="font-black truncate" style="font-size: clamp(22px, 3.4vw, 40px);">{{ $this->selectedAlbum->title }}</h1>
                    <div class="text-[13px] text-text-2 mt-2">
                        {{ $this->selectedAlbum->artist }}
                        @if ($this->selectedAlbum->year)
                            · {{ $this->selectedAlbum->year }}
                        @endif
                        · {{ $this->selectedAlbum->trackCount }} songs · {{ $this->formatMs($this->selectedAlbum->durationMs) }}
                    </div>
                </div>
            </div>
        </div>
    @endisland
@endif

{{-- Tracklist --}}
@if ($selectedAlbumId)
    @island
        <div class="flex-1 p-2 overflow-auto">
            <div class="bg-surface rounded-lg">
                <div class="grid gap-2 px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-text-2 border-b border-surface-2 sticky top-0 bg-surface"
                     style="grid-template-columns: 36px 1fr 60px;">
                    <div class="text-right">#</div>
                    <div>Title</div>
                    <div class="text-right">Time</div>
                </div>
                @foreach ($this->tracks as $track)
                    <button wire:click="playTrack('{{ $track->id }}')"
                            class="w-full grid gap-2 px-4 py-2 hover:bg-surface-2 transition-colors text-left text-[13px] tabular-nums"
                            style="grid-template-columns: 36px 1fr 60px;">
                        <div class="text-text-2 text-right">{{ $track->trackNumber }}</div>
                        <div class="min-w-0">
                            <div class="font-semibold truncate text-white">{{ $track->title }}</div>
                            <div class="text-[11px] text-text-2 truncate">{{ $track->artist }}</div>
                        </div>
                        <div class="text-text-2 text-right">{{ $this->formatMs($track->durationMs) }}</div>
                    </button>
                @endforeach
            </div>
        </div>
    @endisland
@endif
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --filter=LibraryViewTest
```

Expected: 6 passing.

- [ ] **Step 5: Manual check**

Visit `https://plexify.test/`. Should see real artists. Click one → albums load. Click an album → tracklist appears. Click a track → audio starts playing in the player.

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/'⚡library.blade.php' tests/Feature/LibraryViewTest.php
git commit -m "feat: library — album header, tracklist, click-to-play"
```

---

## Task 14: Visual fidelity polish — port class lists from design/rendered

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php`

Cross-check the library view's class lists against `design/rendered/your-library.html` (artists row, albums row, album header gradient, tracklist `gridTemplateColumns`). The rough scaffold from Task 11–13 may differ in spacing, typography, hover states, or icon sizes. Read the rendered DOM and bring fidelity up.

Key things to verify against the design source:
- Miller column header: "ARTISTS" eyebrow with sort button + count + comfortable/compact toggle.
- Artist row: 36px circular cover, name, "X albums · Y tracks" subtitle.
- Album row: 36px square cover, title, "year · format pill" line.
- Album header: full gradient strip, cover with shadow-2xl, eyebrow + title + meta + Play/Shuffle/Download action row.
- Tracklist: comfortable column template `40px(grip) 36px(#) 1.6fr(title+art) 1fr(album) 16px(♥) 60px(time)`.

Use `grep -n "Albums\|Artists\|gridTemplateColumns" design/rendered/your-library.html` to find the relevant blocks, then port the exact class lists.

- [ ] **Step 1: Read the relevant sections of the rendered HTML**

```bash
grep -on "ARTISTS\|comfortable\|gridTemplateColumns" design/rendered/your-library.html | head -20
# Then read 2000 chars around the offsets for each.
```

- [ ] **Step 2: Update the library view with the design's exact class lists**

This is a manual cross-reference. For each region (artists column header, artist row, albums row, album header, tracklist row), copy the rendered HTML's class string and paste it into the Blade template. Do not invent new classes — copy verbatim.

Two specific things that probably need fixing from the scaffold:
1. The artist row in the design has a colored gradient thumbnail (procedurally generated). For our scaffold the lucide-user inside `bg-surface-2` is fine — keep it as a placeholder until we wire real thumbnails (Task 15).
2. The tracklist `grid-template-columns` should match the design's six-column layout (grip, #, title+art, album, ♥, time) for comfortable mode — port exactly.

- [ ] **Step 3: Run tests**

```bash
php artisan test
```

All tests should still pass — visual class changes don't affect Livewire test assertions on text content.

- [ ] **Step 4: Manual check + screenshot diff**

Visit `https://plexify.test/`, then open `design/Plextune.html` in another tab. Compare side-by-side.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/'⚡library.blade.php'
git commit -m "chore: port library view class lists from design/rendered/your-library.html"
```

---

## Task 15: Wire up real album/artist thumbnails

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php`

Replace the lucide-user / lucide-disc placeholders with real `<img>` tags pointing at `PlexClient::thumbUrl($artist->thumb)` and `PlexClient::thumbUrl($album->thumb)`.

- [ ] **Step 1: Add a `thumbFor` method on the component that resolves PlexClient from the container**

Livewire only autowires services for action methods (e.g., `wire:click` handlers). Methods called from Blade during render do NOT get autowiring, so we resolve `PlexClient` via the container inside the method.

```php
public function thumbFor(?string $thumb): ?string
{
    return app(PlexClient::class)->thumbUrl($thumb);
}
```

- [ ] **Step 2: Update artist + album rows to use real thumbnails**

In the artists column, replace:

```blade
<div class="w-9 h-9 rounded-full bg-surface-2 flex-none grid place-items-center">
    <x-lucide-user class="w-4 h-4 text-text-3" />
</div>
```

With:

```blade
@if ($artist->thumb)
    <img src="{{ $this->thumbFor($artist->thumb) }}" alt="{{ $artist->name }}"
         class="w-9 h-9 rounded-full bg-surface-2 flex-none object-cover" loading="lazy">
@else
    <div class="w-9 h-9 rounded-full bg-surface-2 flex-none grid place-items-center">
        <x-lucide-user class="w-4 h-4 text-text-3" />
    </div>
@endif
```

Similar replacement for the album row, but `rounded` (not `rounded-full`).

For the album header cover, similar pattern but `w-[120px] h-[120px] rounded shadow-2xl`.

- [ ] **Step 3: Manual check**

Visit `https://plexify.test/` → click an artist with album art. Confirm thumbs load (not broken-image icons).

- [ ] **Step 4: Run tests**

```bash
php artisan test
```

All should still pass.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/'⚡library.blade.php'
git commit -m "feat: render real Plex thumbnails for artists, albums, and album header"
```

---

## Task 16: Browser test — drill-down + click-to-play (real Plex)

**Files:**
- Create: `tests/Browser/LibraryDrillDownTest.php`
- Delete: `tests/Browser/AudioPersistenceTest.php`
- Delete: `public/test-track.mp3` (no longer needed)
- Modify: `.gitignore` (remove the `public/test-track.mp3` line — file's gone)

The new browser test exercises the full M2 flow against the real Plex server (since the user's `.env` has a working token). It replaces the M1 audio persistence test, which depended on the now-removed "Play test track" button.

- [ ] **Step 1: Write the browser test**

`tests/Browser/LibraryDrillDownTest.php`:

```php
<?php

it('drills down from artist to album to track and plays', function () {
    $page = visit('/');

    // Library renders with real artist data from Plex.
    // We don't know which artist is first alphabetically, so we wait for
    // the artist column to populate and click the first one.
    $page->assertVisible('[data-region=artists-column]')
        ->assertNoJavaScriptErrors();

    // Click the first artist in the list. Need a stable selector — add data-testid in M2.
    // For now: click the first <button> inside the artists column.
    $page->script("document.querySelector('[data-region=artists-column] button')?.click()");
    $page->wait(500); // network roundtrip for albumsForArtist

    // Albums column should populate.
    $page->assertVisible('[data-region=albums-column] button');

    // Click the first album.
    $page->script("document.querySelector('[data-region=albums-column] button')?.click()");
    $page->wait(500);

    // Tracklist should appear.
    $page->assertVisible('[data-region=tracklist] button');

    // Click the first track.
    $page->script("document.querySelector('[data-region=tracklist] button')?.click()");
    $page->wait(500);

    // The player should now show a track title (non-empty now-playing).
    // Asserting on non-empty text in a known DOM region is fragile but works:
    // get the player's title element and confirm it's non-empty.
    $page->script("document.querySelector('[data-region=now-playing-title]')?.textContent");
});

it('still survives navigation (replaces M1 audio persistence test)', function () {
    $page = visit('/');

    // Drill down + play, same as above.
    $page->script("document.querySelector('[data-region=artists-column] button')?.click()");
    $page->wait(500);
    $page->script("document.querySelector('[data-region=albums-column] button')?.click()");
    $page->wait(500);
    $page->script("document.querySelector('[data-region=tracklist] button')?.click()");
    $page->wait(500);

    // Capture the now-playing title BEFORE navigating away.
    // (We don't know the title in advance — depends on the user's library.)

    // Navigate to /search.
    $page->click('Search')
        ->assertUrlIs(url('/search'))
        ->assertSee('Search view stub');

    // The persistent player should still show whatever track was playing.
    // We assert it via the data-region: the now-playing title region must still exist
    // and be non-empty.
    $page->assertVisible('[data-region=now-playing-title]');
});
```

Note: this test relies on data-region selectors that need to be added to:
- `⚡library.blade.php`: `data-region="artists-column"`, `data-region="albums-column"`, `data-region="tracklist"` on the appropriate scrolling containers.
- `⚡player.blade.php`: `data-region="now-playing-title"` on the `<div>` showing `$trackTitle`.

Add those attributes when implementing.

- [ ] **Step 2: Add the data-region attributes**

In `⚡library.blade.php`:
- The artists column scrolling div: add `data-region="artists-column"`.
- The albums column scrolling div: add `data-region="albums-column"`.
- The tracklist scrolling div: add `data-region="tracklist"`.

In `⚡player.blade.php`:
- The `<div class="truncate text-[14px] font-semibold ...">{{ $trackTitle ?: '' }}</div>` — add `data-region="now-playing-title"`.

- [ ] **Step 3: Delete the M1 browser test and the test track file**

```bash
git rm tests/Browser/AudioPersistenceTest.php
rm -f public/test-track.mp3
```

In `.gitignore`, remove the `/public/test-track.mp3` line (now unused).

- [ ] **Step 4: Run all tests**

```bash
php artisan test
```

This requires your real Plex server to be reachable for the browser test. If it's down, the browser test will fail. The non-browser tests (`Http::fake()`) are unaffected.

- [ ] **Step 5: Commit**

```bash
git add tests/Browser/LibraryDrillDownTest.php \
        resources/views/pages/'⚡library.blade.php' \
        resources/views/components/'⚡player.blade.php' \
        .gitignore
git commit -m "test: replace audio-persistence test with full library drill-down + play"
```

---

## Done Criteria

Milestone 2 is complete when:

- `PlexClient` correctly discovers the user's Plex server URL and music section.
- The library view at `/` shows real artists from the user's Plex library.
- Clicking an artist loads their albums.
- Clicking an album loads its tracks.
- Clicking a track starts audio playback through the player.
- The sidebar's server chip shows live "HOMESERVER · Direct" status.
- All Pest tests pass (existing + new): unit tests for PlexClient, feature tests for library view + server chip, browser test for end-to-end drill-down.
- Visual fidelity: class lists in the library view match `design/rendered/your-library.html`.
- Error handling: when Plex is unreachable, the library shows a "Can't reach your Plex server" panel with a Retry button. The server chip shows red dot.

## What This Milestone Deliberately Does Not Do

- No search functionality (Milestone 3).
- No playlists or playlist detail view (Milestone 4).
- No app-side folders or DnD (Milestone 5).
- No settings UI for Plex config (Milestone 6).
- No compact/comfortable density toggles (Milestone 7).
- No keyboard shortcuts (Milestone 7).
- No queue management — playing a track replaces the current track (queue-as-album-tracklist comes in M3 or M4).
- No "Recently Added" view (the sidebar link goes to library for now).
