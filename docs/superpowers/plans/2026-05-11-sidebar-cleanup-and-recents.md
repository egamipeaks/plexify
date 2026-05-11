# Sidebar Cleanup and Recents Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Filter Plex's auto-generated smart playlists out of the app, slim the sidebar's top nav to three real items (Your Library, Recently Added, Recently Played), and wire the two recents items to real routes (album-cover grid for Recently Added, flat tracklist for Recently Played).

**Architecture:** `Playlist` DTO gains a `bool $smart` field; `PlexClient::playlists()` and `searchAll()` reject smart entries. `PlexClient` gets two new read-only methods (`recentlyAddedAlbums`, `recentlyPlayedTracks`) hitting Plex's section endpoints with a `X-Plex-Container-Size` header and a 5-minute cache. Two new Livewire SFC routed pages (`pages::recently-added`, `pages::recently-played`) follow the existing page patterns (`boot(PlexClient $plex)`, public error-message + retry, `#[Computed]` accessor, single stable root). Recently Added uses a reusable `partials/album-card.blade.php` linking to the library page with the album pre-selected via the library page's existing `#[Url]` props.

**Tech Stack:** Laravel 13 + Livewire 4 SFCs, Tailwind 4, Pest 4 with `Http::fake()`, `blade-lucide-icons`.

**Spec reference:** `docs/superpowers/specs/2026-05-11-sidebar-cleanup-recents-design.md` (the whole document; it captures the brainstorming decisions).
**Prior milestone:** `docs/superpowers/plans/2026-05-10-plextune-milestone-5-folders-and-dnd.md`

---

## Key facts (confirmed against the codebase / Plex API)

- `Playlist::fromPlex` reads `ratingKey/title/leafCount/duration/composite/playlistType/summary`. There is no `smart` field yet. Plex returns `"smart": false` or `"smart": true` in JSON for `/playlists?playlistType=audio`; some older PMS versions return `0`/`1`. The existing `tests/Fixtures/Plex/playlists.json` has both rows with `"smart": false`.
- `Album::fromPlex` already captures `artistId` from `parentRatingKey` (nullable), so album-card links of the form `route('library', ['artist' => $album->artistId, 'album' => $album->id])` work without DTO changes. When `artistId` is null the link still loads the library page; the album header will not render because the library's `albums()` computed only queries when `selectedArtistId` is set, but the page will not error. Documented in the spec as acceptable degradation.
- `Track::fromPlex` already handles `Media[0].Part[0]` (`id`/`container`) and `grandparentTitle`/`parentTitle`/`index`/`duration`/`thumb` the same way `tracksForAlbum` and `playlistTracks` rely on. The same DTO works for `/library/sections/{id}/all?type=10`.
- `PlexClient::server()` returns a `PendingRequest`. `->withHeader('X-Plex-Container-Size', '200')->get(...)` is the documented Laravel HTTP fluent pattern and adds the header for that single request.
- The existing `ensureOk(Response $response, string $context)` and `library/sections/{$sectionId}/...` patterns are reused verbatim. No new exception types.
- `PlexCache` already exposes `TTL_PLAYLISTS = 300` (5 minutes). Both recents methods reuse it.
- The library page (`resources/views/pages/⚡library.blade.php`) reads `?artist=` and `?album=` from the URL via `#[Url(as: 'artist', except: null)]` / `#[Url(as: 'album', except: null)]`. A `wire:navigate` link to `/?artist=X&album=Y` lands on the library with both pre-selected (the page's tests in `LibraryViewTest` already cover this via `Livewire::withQueryParams(['artist' => '100', 'album' => '1001'])`).
- `tests/Feature/RoutesTest.php` already uses `RefreshDatabase`; new routes just need a smoke `it(...)` block there. Full-page render goes through the layout which renders `<livewire:sidebar />`; with `PLEX_TOKEN` unset in the test env the sidebar's `playlists()` swallows the auth exception and the recents pages' own `boot()` fetches happen against a mocked `PlexClient`.
- The search page's album row (`pages/⚡search.blade.php`) uses a `<button wire:click="goToAlbum(...)">`, not a link. Refactoring it to the new `<a wire:navigate>` partial would change behaviour. The spec calls the search refactor opportunistic; **this plan does not refactor it.** The album-card partial is used only by Recently Added.
- `mallardduck/blade-lucide-icons` ships a `history` icon; `<x-lucide-history />` is the chosen distinct icon for Recently Played (vs `<x-lucide-clock />` for Recently Added).

---

## File Structure

**New:**
- `tests/Fixtures/Plex/recently_added.json` — sample `/library/sections/{id}/recentlyAdded?type=9` response with ≥2 album rows.
- `tests/Fixtures/Plex/recently_played.json` — sample `/library/sections/{id}/all?type=10&sort=lastViewedAt:desc` response with ≥2 played track rows + 1 unplayed (zero/missing `lastViewedAt`) row to exercise the in-PHP filter.
- `resources/views/partials/album-card.blade.php` — one album card with cover + title + meta, used by Recently Added.
- `resources/views/pages/⚡recently-added.blade.php` — routed Livewire SFC.
- `resources/views/pages/⚡recently-played.blade.php` — routed Livewire SFC.
- `tests/Feature/RecentlyAddedTest.php` — page tests.
- `tests/Feature/RecentlyPlayedTest.php` — page tests.

**Modified:**
- `app/Services/Plex/Dto/Playlist.php` — add `public readonly bool $smart`.
- `app/Services/Plex/PlexClient.php` — reject smart in `playlists()` and `searchAll()` playlist hub; add `recentlyAddedAlbums()` + `recentlyPlayedTracks()`.
- `resources/views/components/⚡sidebar.blade.php` — top nav now Your Library / Recently Added / Recently Played.
- `routes/web.php` — two new routes.
- `tests/Fixtures/Plex/playlists.json` — append a third row with `"smart": true`.
- `tests/Unit/PlexDtoTest.php` — `Playlist::fromPlex` reads `smart`.
- `tests/Unit/PlexClientTest.php` — `playlists()`/`searchAll()` filter smart; new cases for the two recents methods.
- `tests/Feature/SidebarTest.php` — assert the three-item nav (Home/Search gone).
- `tests/Feature/RoutesTest.php` — smoke tests for the two new routes.
- `CLAUDE.md` — smart-filter note, two new routes, the `lastViewedAt`-without-scrobble caveat, updated test count.

**No changes to:** the layout, the player, the playlist-detail page, the library page, `Album`/`Track` DTOs, `Folder`/`FolderPlaylist`, migrations, `config/cache.php`.

---

## Task 1: `Playlist::smart` + filter in `PlexClient::playlists()` and `searchAll()`

**Files:**
- Modify: `app/Services/Plex/Dto/Playlist.php`
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `tests/Fixtures/Plex/playlists.json`
- Modify: `tests/Unit/PlexDtoTest.php`
- Modify: `tests/Unit/PlexClientTest.php`

- [ ] **Step 1: Append a smart playlist to the playlists fixture**

Replace the contents of `tests/Fixtures/Plex/playlists.json` with:

```json
{
  "MediaContainer": {
    "size": 3,
    "Metadata": [
      {
        "ratingKey": "4242",
        "title": "Late Night",
        "summary": "Wind-down listening.",
        "leafCount": 3,
        "duration": 720000,
        "composite": "/playlists/4242/composite/1700000000",
        "playlistType": "audio",
        "smart": false
      },
      {
        "ratingKey": "4243",
        "title": "Bangers",
        "leafCount": 2,
        "duration": 480000,
        "composite": "/playlists/4243/composite/1700000001",
        "playlistType": "audio",
        "smart": false
      },
      {
        "ratingKey": "4244",
        "title": "All Music",
        "leafCount": 9999,
        "duration": 0,
        "composite": "/playlists/4244/composite/1700000002",
        "playlistType": "audio",
        "smart": true
      }
    ]
  }
}
```

- [ ] **Step 2: Write the failing DTO test**

In `tests/Unit/PlexDtoTest.php`, append (use the file's existing imports + helpers):

```php
it('reads the smart flag on Playlist::fromPlex', function () {
    expect(App\Services\Plex\Dto\Playlist::fromPlex([
        'ratingKey' => '1',
        'title' => 'Smart',
        'smart' => true,
    ])->smart)->toBeTrue();

    expect(App\Services\Plex\Dto\Playlist::fromPlex([
        'ratingKey' => '2',
        'title' => 'Smart numeric',
        'smart' => 1,
    ])->smart)->toBeTrue();

    expect(App\Services\Plex\Dto\Playlist::fromPlex([
        'ratingKey' => '3',
        'title' => 'Plain',
        'smart' => false,
    ])->smart)->toBeFalse();

    expect(App\Services\Plex\Dto\Playlist::fromPlex([
        'ratingKey' => '4',
        'title' => 'Missing field',
    ])->smart)->toBeFalse();
});
```

- [ ] **Step 3: Write the failing PlexClient filter test**

In `tests/Unit/PlexClientTest.php`, append:

```php
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
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `php artisan test --compact --filter="smart flag on Playlist|filters smart playlists"`
Expected: FAIL — `smart` property does not exist on `Playlist`; `playlists()` still returns three entries.

- [ ] **Step 5: Add `smart` to the DTO**

In `app/Services/Plex/Dto/Playlist.php`, add a trailing constructor argument and a corresponding `fromPlex` line:

```php
<?php

namespace App\Services\Plex\Dto;

readonly class Playlist
{
    public function __construct(
        public string $id,
        public string $title,
        public int $trackCount,
        public int $durationMs,
        public ?string $thumb,
        public string $playlistType,
        public ?string $summary = null,
        public bool $smart = false,
    ) {}

    public static function fromPlex(array $row): self
    {
        return new self(
            id: (string) $row['ratingKey'],
            title: $row['title'],
            trackCount: $row['leafCount'] ?? 0,
            durationMs: $row['duration'] ?? 0,
            thumb: $row['composite'] ?? $row['thumb'] ?? null,
            playlistType: $row['playlistType'] ?? 'audio',
            summary: ($row['summary'] ?? '') !== '' ? $row['summary'] : null,
            smart: (bool) ($row['smart'] ?? false),
        );
    }
}
```

- [ ] **Step 6: Filter smart in `playlists()`**

In `app/Services/Plex/PlexClient.php`, find the `playlists()` method and replace its returned collection map with a map-then-reject. The whole method body becomes:

```php
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
```

- [ ] **Step 7: Filter smart in `searchAll()` playlist hub**

In the same file, find the `searchAll(string $query)` method's `playlists:` argument inside the `new SearchResults(...)` call. Change:

```php
playlists: $metadata('playlist')
    ->filter(fn (array $row) => ($row['playlistType'] ?? 'audio') === 'audio')
    ->map(fn (array $row) => Playlist::fromPlex($row))
    ->values(),
```

to:

```php
playlists: $metadata('playlist')
    ->filter(fn (array $row) => ($row['playlistType'] ?? 'audio') === 'audio')
    ->map(fn (array $row) => Playlist::fromPlex($row))
    ->reject(fn (Playlist $p) => $p->smart)
    ->values(),
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --compact --filter=PlexClientTest`
Expected: PASS (existing + new cases).

Run: `php artisan test --compact --filter=PlexDtoTest`
Expected: PASS.

Run the full suite to make sure nothing else broke (SidebarTest mocks `playlists()` directly and is unaffected; the existing `playlists()` test now sees only two titles, which matches its existing assertions):

Run: `php artisan test --compact`
Expected: PASS (all).

- [ ] **Step 9: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/Dto/Playlist.php app/Services/Plex/PlexClient.php tests/Fixtures/Plex/playlists.json tests/Unit/PlexDtoTest.php tests/Unit/PlexClientTest.php
git commit -m "feat: filter Plex smart playlists out of the sidebar and search"
```

---

## Task 2: `PlexClient::recentlyAddedAlbums()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `tests/Unit/PlexClientTest.php`
- Create: `tests/Fixtures/Plex/recently_added.json`

- [ ] **Step 1: Create the fixture**

Create `tests/Fixtures/Plex/recently_added.json`:

```json
{
  "MediaContainer": {
    "size": 2,
    "Metadata": [
      {
        "ratingKey": "9001",
        "parentRatingKey": "100",
        "title": "Recently Added One",
        "parentTitle": "Artist Alpha",
        "year": 2026,
        "leafCount": 10,
        "duration": 2400000,
        "thumb": "/library/metadata/9001/thumb/1700000001",
        "addedAt": 1714000000
      },
      {
        "ratingKey": "9002",
        "parentRatingKey": "101",
        "title": "Recently Added Two",
        "parentTitle": "Artist Beta",
        "year": 2025,
        "leafCount": 8,
        "duration": 1900000,
        "thumb": "/library/metadata/9002/thumb/1700000002",
        "addedAt": 1713000000
      }
    ]
  }
}
```

- [ ] **Step 2: Write the failing test**

Append to `tests/Unit/PlexClientTest.php`:

```php
it('fetches recently added albums with the container-size header', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/recentlyAdded*' => Http::response(file_get_contents(fixturePath('recently_added.json')), 200),
    ]);

    $albums = app(PlexClient::class)->recentlyAddedAlbums(50);

    expect($albums)->toHaveCount(2)
        ->and($albums->first()->id)->toBe('9001')
        ->and($albums->first()->artistId)->toBe('100')
        ->and($albums->first()->title)->toBe('Recently Added One');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/library/sections/3/recentlyAdded')
        && str_contains($request->url(), 'type=9')
        && $request->header('X-Plex-Container-Size') === ['50']);
});

it('caches recently added albums by limit and reuses the cached collection', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/recentlyAdded*' => Http::response(file_get_contents(fixturePath('recently_added.json')), 200),
    ]);

    app(PlexClient::class)->recentlyAddedAlbums(50);
    app(PlexClient::class)->recentlyAddedAlbums(50);

    expect(Cache::has('plex:recently_added:50'))->toBeTrue();
    Http::assertSentCount(3); // resources + library/sections + recentlyAdded — each once
});

it('maps a 404 on recentlyAddedAlbums to PlexNotFoundException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/recentlyAdded*' => Http::response('nope', 404),
    ]);

    expect(fn () => app(PlexClient::class)->recentlyAddedAlbums(50))->toThrow(PlexNotFoundException::class);
});

it('maps a 500 on recentlyAddedAlbums to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/recentlyAdded*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->recentlyAddedAlbums(50))->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter="recently added"`
Expected: FAIL — `recentlyAddedAlbums` is not defined.

- [ ] **Step 4: Implement the method**

In `app/Services/Plex/PlexClient.php`, add after `playlistTracks()`:

```php
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
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="recently added"`
Expected: PASS.

Run: `php artisan test --compact --filter=PlexClientTest`
Expected: PASS (all existing PlexClientTest cases still green).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php tests/Fixtures/Plex/recently_added.json
git commit -m "feat: PlexClient::recentlyAddedAlbums()"
```

---

## Task 3: `PlexClient::recentlyPlayedTracks()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `tests/Unit/PlexClientTest.php`
- Create: `tests/Fixtures/Plex/recently_played.json`

- [ ] **Step 1: Create the fixture**

Create `tests/Fixtures/Plex/recently_played.json`. Includes two played rows and one unplayed (missing `lastViewedAt`) row to exercise the in-PHP filter:

```json
{
  "MediaContainer": {
    "size": 3,
    "Metadata": [
      {
        "ratingKey": "70001",
        "title": "Played Track One",
        "grandparentTitle": "Artist One",
        "parentTitle": "Album One",
        "index": 3,
        "duration": 215000,
        "thumb": "/library/metadata/70001/thumb/1714000001",
        "lastViewedAt": 1714000050,
        "viewCount": 12,
        "Media": [{ "Part": [{ "id": 7001, "container": "flac" }] }]
      },
      {
        "ratingKey": "70002",
        "title": "Played Track Two",
        "grandparentTitle": "Artist Two",
        "parentTitle": "Album Two",
        "index": 1,
        "duration": 188000,
        "thumb": "/library/metadata/70002/thumb/1714000002",
        "lastViewedAt": 1714000020,
        "viewCount": 4,
        "Media": [{ "Part": [{ "id": 7002, "container": "flac" }] }]
      },
      {
        "ratingKey": "70003",
        "title": "Never Played",
        "grandparentTitle": "Artist Three",
        "parentTitle": "Album Three",
        "index": 1,
        "duration": 200000,
        "thumb": "/library/metadata/70003/thumb/1714000003",
        "Media": [{ "Part": [{ "id": 7003, "container": "flac" }] }]
      }
    ]
  }
}
```

- [ ] **Step 2: Write the failing test**

Append to `tests/Unit/PlexClientTest.php`:

```php
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
        && $request->header('X-Plex-Container-Size') === ['200']);
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

it('maps a 500 on recentlyPlayedTracks to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->recentlyPlayedTracks(50))->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter="recently played"`
Expected: FAIL — `recentlyPlayedTracks` is not defined.

- [ ] **Step 4: Implement the method**

In `app/Services/Plex/PlexClient.php`, add after `recentlyAddedAlbums()`:

```php
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
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="recently played"`
Expected: PASS.

Run: `php artisan test --compact --filter=PlexClientTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php tests/Fixtures/Plex/recently_played.json
git commit -m "feat: PlexClient::recentlyPlayedTracks()"
```

---

## Task 4: Routes + sidebar nav cleanup

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/components/⚡sidebar.blade.php`
- Modify: `tests/Feature/SidebarTest.php`
- Modify: `tests/Feature/RoutesTest.php`

- [ ] **Step 1: Add the two new routes**

In `routes/web.php`, add the two new lines (the file's import + the existing four routes stay):

```php
<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::library')->name('library');
Route::livewire('/search', 'pages::search')->name('search');
Route::livewire('/recently-added', 'pages::recently-added')->name('recentlyAdded');
Route::livewire('/recently-played', 'pages::recently-played')->name('recentlyPlayed');
Route::livewire('/playlist/{playlist}', 'pages::playlist-detail')->name('playlist');
Route::livewire('/settings', 'pages::settings')->name('settings');
```

(The two new routes do not yet have page components; Tasks 5 and 6 add them. Until then `/recently-added` and `/recently-played` would 404 with "View not found" — Tasks 5 and 6 finish that. Within this task we only need `route('recentlyAdded')` / `route('recentlyPlayed')` to resolve for the sidebar links, which they do once the routes are registered.)

- [ ] **Step 2: Update the failing sidebar nav tests**

In `tests/Feature/SidebarTest.php`, append:

```php
it('shows three top-level nav items: Library / Recently Added / Recently Played', function () {
    mockSidebarPlex();

    $html = Livewire::test('sidebar')->html();
    $topNav = explode('Your Playlists', $html, 2)[0];

    preg_match_all('/<a [^>]*wire:navigate/i', $topNav, $matches);
    expect($matches[0])->toHaveCount(3);

    expect($topNav)->toContain('Your Library')
        ->and($topNav)->toContain('Recently Added')
        ->and($topNav)->toContain('Recently Played')
        ->and($topNav)->not->toContain('Home')
        ->and($topNav)->not->toContain('Search');
});

it('links the Recently Added and Recently Played nav items to their routes', function () {
    mockSidebarPlex();

    Livewire::test('sidebar')
        ->assertSeeHtml('href="'.route('recentlyAdded').'"')
        ->assertSeeHtml('href="'.route('recentlyPlayed').'"');
});
```

Run: `php artisan test --compact --filter=SidebarTest`
Expected: FAIL — both new tests fail (the old nav still has Home and Search; the new routes have no rendered link yet).

- [ ] **Step 3: Rewrite the sidebar top nav card**

In `resources/views/components/⚡sidebar.blade.php`, replace the entire `{{-- Top nav card --}}` block (the `<div class="bg-surface rounded-lg p-2 flex flex-col gap-1">...</div>` that contains the four `<a>` elements) with this three-item version:

```blade
    {{-- Top nav card --}}
    <div class="bg-surface rounded-lg p-2 flex flex-col gap-1">
        <a href="{{ route('library') }}" wire:navigate
           @class([
               'w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors',
               'text-white bg-surface-2' => request()->routeIs('library'),
               'text-text-2 hover:text-white' => ! request()->routeIs('library'),
           ])>
            <x-lucide-library class="w-5 h-5" />
            <span class="flex-1 text-left">Your Library</span>
        </a>

        <a href="{{ route('recentlyAdded') }}" wire:navigate
           @class([
               'w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors',
               'text-white bg-surface-2' => request()->routeIs('recentlyAdded'),
               'text-text-2 hover:text-white' => ! request()->routeIs('recentlyAdded'),
           ])>
            <x-lucide-clock class="w-5 h-5" />
            <span class="flex-1 text-left">Recently Added</span>
        </a>

        <a href="{{ route('recentlyPlayed') }}" wire:navigate
           @class([
               'w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors',
               'text-white bg-surface-2' => request()->routeIs('recentlyPlayed'),
               'text-text-2 hover:text-white' => ! request()->routeIs('recentlyPlayed'),
           ])>
            <x-lucide-history class="w-5 h-5" />
            <span class="flex-1 text-left">Recently Played</span>
        </a>
    </div>
```

- [ ] **Step 4: Run sidebar tests + full suite**

Run: `php artisan test --compact --filter=SidebarTest`
Expected: PASS (the two new tests + every prior SidebarTest case still green).

Note: the `RoutesTest` for `/recently-added` and `/recently-played` is added in the next step, but a quick smoke check that the route resolution works is useful — `php artisan route:list --path=recent` should list both.

- [ ] **Step 5: Add provisional route smoke tests (skipped)**

In `tests/Feature/RoutesTest.php`, append:

```php
it('renders the recently added route', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('recentlyAddedAlbums')->andReturn(collect());
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => null]);
    });

    $this->get('/recently-added')
        ->assertOk()
        ->assertSee('Recently Added');
})->skip('page component added in Task 5');

it('renders the recently played route', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('recentlyPlayedTracks')->andReturn(collect());
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => null]);
    });

    $this->get('/recently-played')
        ->assertOk()
        ->assertSee('Recently Played');
})->skip('page component added in Task 6');
```

(The `->skip(...)` is intentional. Tasks 5 and 6 remove the skip on their respective tests when the page component lands.)

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/components/⚡sidebar.blade.php tests/Feature/SidebarTest.php tests/Feature/RoutesTest.php
git commit -m "feat: sidebar nav is now three items (Library / Recently Added / Recently Played) + routes"
```

---

## Task 5: Recently Added page + reusable album-card partial

**Files:**
- Create: `resources/views/partials/album-card.blade.php`
- Create: `resources/views/pages/⚡recently-added.blade.php`
- Create: `tests/Feature/RecentlyAddedTest.php`
- Modify: `tests/Feature/RoutesTest.php` (un-skip the route smoke test from Task 4 step 5)

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/RecentlyAddedTest.php`:

```php
<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

function album(string $id, string $title, string $artistId = '100', string $artist = 'Some Artist', ?int $year = 2026): Album
{
    return new Album(
        id: $id,
        title: $title,
        artist: $artist,
        year: $year,
        thumb: "/library/metadata/{$id}/thumb",
        trackCount: 10,
        durationMs: 0,
        artistId: $artistId,
    );
}

function mockRecentlyAddedPlex(?Closure $extra = null): void
{
    test()->mock(PlexClient::class, function ($mock) use ($extra) {
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => null]);

        if ($extra) {
            $extra($mock);
        }
    });
}

it('renders an album card per Recently Added album', function () {
    mockRecentlyAddedPlex(function ($mock) {
        $mock->shouldReceive('recentlyAddedAlbums')->once()->andReturn(collect([
            album('9001', 'One'),
            album('9002', 'Two'),
        ]));
    });

    Livewire::test('pages::recently-added')
        ->assertSee('Recently Added')
        ->assertSee('One')
        ->assertSee('Two')
        ->assertSeeHtml('href="'.route('library', ['artist' => '100', 'album' => '9001']).'"')
        ->assertSeeHtml('href="'.route('library', ['artist' => '100', 'album' => '9002']).'"');
});

it('shows an empty state when there are no recently added albums', function () {
    mockRecentlyAddedPlex(function ($mock) {
        $mock->shouldReceive('recentlyAddedAlbums')->once()->andReturn(collect());
    });

    Livewire::test('pages::recently-added')
        ->assertSee('Recently Added')
        ->assertSee('No recent additions yet');
});

it('shows an error panel and recovers via retry when Plex is unreachable', function () {
    $calls = 0;
    mockRecentlyAddedPlex(function ($mock) use (&$calls) {
        $mock->shouldReceive('recentlyAddedAlbums')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? throw new PlexUnreachableException('refused')
                : collect([album('9001', 'After Retry')]);
        });
    });

    Livewire::test('pages::recently-added')
        ->assertSee("Can't reach your Plex server")
        ->call('retry')
        ->assertDontSee("Can't reach your Plex server")
        ->assertSee('After Retry');
});
```

Run: `php artisan test --compact --filter=RecentlyAddedTest`
Expected: FAIL — `pages::recently-added` does not exist.

- [ ] **Step 2: Create the album-card partial**

Create `resources/views/partials/album-card.blade.php`:

```blade
{{--
    One album card. Used in the Recently Added grid.
    Expects: $album (App\Services\Plex\Dto\Album), $thumbUrl (?string).
--}}
<a href="{{ route('library', ['artist' => $album->artistId, 'album' => $album->id]) }}" wire:navigate
   wire:key="album-card-{{ $album->id }}"
   class="group flex flex-col gap-2 p-3 rounded-lg bg-surface hover:bg-surface-1 transition-colors">
    <div class="relative rounded-md overflow-hidden bg-surface-2 grid place-items-center aspect-square shadow-lg">
        <x-lucide-disc class="w-8 h-8 text-text-3" />
        @if ($album->thumb && $thumbUrl)
            <img src="{{ $thumbUrl }}" alt="{{ $album->title }}" loading="lazy"
                 onerror="this.remove()"
                 class="absolute inset-0 w-full h-full object-cover">
        @endif
    </div>
    <div class="min-w-0">
        <div class="text-[14px] font-semibold text-white truncate">{{ $album->title }}</div>
        <div class="text-[12px] text-text-2 truncate">{{ collect([$album->artist, $album->year])->filter()->implode(' · ') }}</div>
    </div>
</a>
```

- [ ] **Step 3: Create the page component**

Create `resources/views/pages/⚡recently-added.blade.php`:

```php
<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?string $errorMessage = null;

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    public function mount(): void
    {
        $this->loadAlbums();
    }

    public function retry(): void
    {
        $this->errorMessage = null;
        unset($this->albums);
        $this->loadAlbums();
    }

    /** @return Collection<int, Album> */
    #[Computed]
    public function albums(): Collection
    {
        if ($this->errorMessage) {
            return collect();
        }

        try {
            return $this->plex->recentlyAddedAlbums();
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. ".$e->getMessage();

            return collect();
        }
    }

    public function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }

    private function loadAlbums(): void
    {
        try {
            $this->plex->recentlyAddedAlbums();
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. ".$e->getMessage();
        }
    }
};
?>

<div class="h-full flex flex-col">
    @php($this->albums)
    @if ($this->errorMessage)
        <div class="flex-1 grid place-items-center p-12 text-center">
            <div class="max-w-md">
                <x-lucide-server-off class="w-12 h-12 mx-auto text-text-3 mb-4" />
                <h2 class="text-xl font-bold mb-2">Can't reach your Plex server</h2>
                <p class="text-text-2 text-sm mb-6">{{ $this->errorMessage }}</p>
                <button type="button" wire:click="retry"
                        class="px-5 py-2 bg-white text-black rounded-full font-bold hover:scale-105 transition-transform">
                    Retry
                </button>
            </div>
        </div>
    @elseif ($this->albums->isEmpty())
        <div class="flex-1 grid place-items-center p-12 text-center">
            <div class="max-w-md">
                <x-lucide-clock class="w-12 h-12 mx-auto text-text-3 mb-4" />
                <h2 class="text-xl font-bold mb-2">Recently Added</h2>
                <p class="text-text-2 text-sm">No recent additions yet.</p>
            </div>
        </div>
    @else
        <div class="px-6 pt-6 pb-2 flex-none">
            <h1 class="text-[clamp(22px,3.4vw,40px)] font-black tracking-tight text-white">Recently Added</h1>
            <p class="text-text-2 text-sm">The latest albums imported into your library.</p>
        </div>
        <div class="flex-1 overflow-y-auto scroll px-6 pb-6">
            <div class="grid grid-cols-[repeat(auto-fill,minmax(180px,1fr))] gap-4">
                @foreach ($this->albums as $album)
                    @include('partials.album-card', ['album' => $album, 'thumbUrl' => $this->thumbFor($album->thumb)])
                @endforeach
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 4: Un-skip the route smoke test**

In `tests/Feature/RoutesTest.php`, remove the `->skip('page component added in Task 5')` from the `'renders the recently added route'` test (leave the test body unchanged).

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=RecentlyAddedTest`
Expected: PASS (all three cases).

Run: `php artisan test --compact --filter=RoutesTest`
Expected: PASS (the Recently Added smoke now active and green; the Recently Played one is still `->skip`).

Run: `php artisan test --compact`
Expected: PASS (all).

- [ ] **Step 6: Build assets and commit**

```bash
npm run build
vendor/bin/pint --dirty --format agent
git add resources/views/partials/album-card.blade.php resources/views/pages/⚡recently-added.blade.php tests/Feature/RecentlyAddedTest.php tests/Feature/RoutesTest.php
git commit -m "feat: Recently Added page (album grid) + reusable album-card partial"
```

---

## Task 6: Recently Played page

**Files:**
- Create: `resources/views/pages/⚡recently-played.blade.php`
- Create: `tests/Feature/RecentlyPlayedTest.php`
- Modify: `tests/Feature/RoutesTest.php` (un-skip the route smoke test)

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/RecentlyPlayedTest.php`:

```php
<?php

use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

function recentTrack(string $id, string $title, string $album = 'Some Album', string $artist = 'Some Artist'): Track
{
    return new Track(
        id: $id,
        title: $title,
        artist: $artist,
        album: $album,
        trackNumber: 1,
        durationMs: 215000,
        partId: (int) $id,
        container: 'flac',
        thumb: "/library/metadata/{$id}/thumb",
    );
}

function mockRecentlyPlayedPlex(?Closure $extra = null): void
{
    test()->mock(PlexClient::class, function ($mock) use ($extra) {
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($track) => "https://stream/{$track->id}.flac");
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => null]);

        if ($extra) {
            $extra($mock);
        }
    });
}

it('renders a track row per Recently Played track', function () {
    mockRecentlyPlayedPlex(function ($mock) {
        $mock->shouldReceive('recentlyPlayedTracks')->once()->andReturn(collect([
            recentTrack('70001', 'One'),
            recentTrack('70002', 'Two'),
        ]));
    });

    Livewire::test('pages::recently-played')
        ->assertSee('Recently Played')
        ->assertSee('One')
        ->assertSee('Two')
        ->assertSeeHtml('wire:key="track-70001"')
        ->assertSeeHtml('wire:key="track-70002"');
});

it('shows an empty state when nothing has been played', function () {
    mockRecentlyPlayedPlex(function ($mock) {
        $mock->shouldReceive('recentlyPlayedTracks')->once()->andReturn(collect());
    });

    Livewire::test('pages::recently-played')
        ->assertSee('Recently Played')
        ->assertSee('Nothing played yet.');
});

it('shows an error panel when Plex is unreachable and recovers on retry', function () {
    $calls = 0;
    mockRecentlyPlayedPlex(function ($mock) use (&$calls) {
        $mock->shouldReceive('recentlyPlayedTracks')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? throw new PlexUnreachableException('refused')
                : collect([recentTrack('70001', 'After Retry')]);
        });
    });

    Livewire::test('pages::recently-played')
        ->assertSee("Can't reach your Plex server")
        ->call('retry')
        ->assertDontSee("Can't reach your Plex server")
        ->assertSee('After Retry');
});

it('playTrack dispatches play-track with the stream URL', function () {
    mockRecentlyPlayedPlex(function ($mock) {
        $mock->shouldReceive('recentlyPlayedTracks')->andReturn(collect([
            recentTrack('70001', 'Song'),
        ]));
    });

    Livewire::test('pages::recently-played')
        ->call('playTrack', '70001')
        ->assertDispatched('play-track', url: 'https://stream/70001.flac', title: 'Song');
});
```

Run: `php artisan test --compact --filter=RecentlyPlayedTest`
Expected: FAIL — `pages::recently-played` does not exist.

- [ ] **Step 2: Create the page component**

Create `resources/views/pages/⚡recently-played.blade.php`:

```php
<?php

use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?string $errorMessage = null;

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    public function mount(): void
    {
        $this->loadTracks();
    }

    public function retry(): void
    {
        $this->errorMessage = null;
        unset($this->tracks);
        $this->loadTracks();
    }

    public function playTrack(string $trackId): void
    {
        $track = $this->tracks->firstWhere('id', $trackId);

        if (! $track instanceof Track) {
            return;
        }

        $this->dispatch('play-track',
            url: $this->plex->streamUrl($track),
            title: $track->title,
            artist: $track->artist,
            artwork: $this->thumbFor($track->thumb),
        );
    }

    /** @return Collection<int, Track> */
    #[Computed]
    public function tracks(): Collection
    {
        if ($this->errorMessage) {
            return collect();
        }

        try {
            return $this->plex->recentlyPlayedTracks();
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. ".$e->getMessage();

            return collect();
        }
    }

    public function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }

    protected function formatMs(int $ms): string
    {
        $seconds = (int) round($ms / 1000);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function loadTracks(): void
    {
        try {
            $this->plex->recentlyPlayedTracks();
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. ".$e->getMessage();
        }
    }
};
?>

<div class="h-full flex flex-col">
    @php($this->tracks)
    @if ($this->errorMessage)
        <div class="flex-1 grid place-items-center p-12 text-center">
            <div class="max-w-md">
                <x-lucide-server-off class="w-12 h-12 mx-auto text-text-3 mb-4" />
                <h2 class="text-xl font-bold mb-2">Can't reach your Plex server</h2>
                <p class="text-text-2 text-sm mb-6">{{ $this->errorMessage }}</p>
                <button type="button" wire:click="retry"
                        class="px-5 py-2 bg-white text-black rounded-full font-bold hover:scale-105 transition-transform">
                    Retry
                </button>
            </div>
        </div>
    @elseif ($this->tracks->isEmpty())
        <div class="flex-1 grid place-items-center p-12 text-center">
            <div class="max-w-md">
                <x-lucide-history class="w-12 h-12 mx-auto text-text-3 mb-4" />
                <h2 class="text-xl font-bold mb-2">Recently Played</h2>
                <p class="text-text-2 text-sm">Nothing played yet. Play history from other Plex clients shows up here too.</p>
            </div>
        </div>
    @else
        <div class="px-6 pt-6 pb-2 flex-none">
            <h1 class="text-[clamp(22px,3.4vw,40px)] font-black tracking-tight text-white">Recently Played</h1>
            <p class="text-text-2 text-sm">The tracks most recently played across your Plex clients.</p>
        </div>

        <div class="flex-1 min-h-0 flex flex-col px-2 pb-2 overflow-hidden" data-region="tracklist">
            <div class="flex-1 min-h-0 flex flex-col relative bg-surface rounded-lg overflow-hidden">
                <div class="grid items-center px-4 py-2 text-[11px] uppercase tracking-wider text-text-2 border-b border-white/10 sticky top-0 bg-surface z-10"
                     style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">
                    <span></span>
                    <span class="text-right">#</span>
                    <span>Title</span>
                    <span>Album</span>
                    <span></span>
                    <span class="text-right"><x-lucide-clock class="w-[14px] h-[14px] inline" /></span>
                </div>
                <div class="overflow-y-auto scroll flex-1 py-1">
                    @foreach ($this->tracks as $i => $track)
                        <button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')"
                                draggable="true"
                                ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"
                                class="row group w-full grid items-center px-4 py-2 rounded text-[14px] text-left hover:bg-white/[0.07] transition-colors"
                                style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">
                            <span class="text-text-3 group-hover:text-white grid place-items-center">
                                <x-lucide-grip-vertical class="w-[14px] h-[14px]" />
                            </span>
                            <span class="tabular-nums text-text-2 text-right">{{ $i + 1 }}</span>
                            <div class="min-w-0 flex items-center gap-3">
                                <div class="relative rounded-sm flex-none bg-surface-2 grid place-items-center overflow-hidden" style="width: 36px; height: 36px;">
                                    <x-lucide-disc class="w-3.5 h-3.5 text-text-3" />
                                    @if ($track->thumb && $this->thumbFor($track->thumb))
                                        <img src="{{ $this->thumbFor($track->thumb) }}" alt="{{ $track->title }}" loading="lazy"
                                             onerror="this.remove()"
                                             class="absolute inset-0 w-full h-full object-cover">
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <div class="truncate font-medium text-white">{{ $track->title }}</div>
                                    <div class="truncate text-[12px] text-text-2 group-hover:text-white">{{ $track->artist }}</div>
                                </div>
                            </div>
                            <div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>
                            <span class="grid place-items-center text-text-2 hover:text-white">
                                <x-lucide-heart class="w-3.5 h-3.5" />
                            </span>
                            <div class="text-text-2 tabular-nums text-right">{{ $this->formatMs($track->durationMs) }}</div>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 3: Un-skip the route smoke test**

In `tests/Feature/RoutesTest.php`, remove the `->skip('page component added in Task 6')` from the `'renders the recently played route'` test (leave the test body unchanged).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=RecentlyPlayedTest`
Expected: PASS (all four cases).

Run: `php artisan test --compact --filter=RoutesTest`
Expected: PASS.

Run: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 5: Build assets and commit**

```bash
npm run build
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡recently-played.blade.php tests/Feature/RecentlyPlayedTest.php tests/Feature/RoutesTest.php
git commit -m "feat: Recently Played page (tracklist with play + drag)"
```

---

## Task 7: Docs — CLAUDE.md updates

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Run the suite and note the new total test count**

Run: `php artisan test --compact`
Expected: all green; note the total count printed (e.g. `Tests: 1xx passed`).

- [ ] **Step 2: Update the test-count sentence**

In `CLAUDE.md`, find "`php artisan test` should be green (133 tests as of end of M5, including 8 Playwright browser tests)." and replace `133 tests as of end of M5` with `<N> tests after the sidebar cleanup pass (post-M5)` using the actual `N` from Step 1. Leave the browser-test count at 8 (this pass adds no browser tests).

- [ ] **Step 3: Update the Plex integration paragraph**

Find the "Plex integration" bullet under "Stack and conventions" and, after the `machineIdentifier()` mention, append:

> `recentlyAddedAlbums($limit = 50)` (`GET /library/sections/{id}/recentlyAdded?type=9` with `X-Plex-Container-Size`; cached 5min under `plex:recently_added:{limit}`), `recentlyPlayedTracks($limit = 50)` (`GET /library/sections/{id}/all?type=10&sort=lastViewedAt:desc` with `X-Plex-Container-Size: 4 * limit`, filtered in PHP to non-zero `lastViewedAt` rows then sliced to `$limit`; cached 5min under `plex:recently_played:{limit}`).

Avoid em dashes / en dashes in the new prose; use semicolons, parentheses, or commas instead.

- [ ] **Step 4: Add new "Gotchas" bullets**

Append two bullets to the Gotchas list:

```markdown
- **Plex's smart playlists are hidden app-wide.** `Playlist` DTO carries a `smart` bool (read from `$row['smart']`, accepts both bool and numeric); `PlexClient::playlists()` and `PlexClient::searchAll()`'s playlist hub both `->reject(fn ($p) => $p->smart)` so the sidebar and search never surface the auto-generated All Music, Fresh, Recently Added, and Recently Played playlists. These exposed-as-real-playlists were misleading because they cannot be modified the way user playlists can.
- **Recently Played accuracy is bounded by scrobbling.** Our player does not yet scrobble plays back to Plex, so `lastViewedAt` reflects plays from other Plex clients (web, mobile) only. The Recently Played view is still useful for jumping back to whatever was playing in another client; adding a `POST /:/scrobble?key=...&identifier=com.plexapp.plugins.library` call after a track ends is an M7 polish item.
```

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: smart-playlist filter, Recently Added/Played routes, scrobble caveat"
```

---

## Self-Review Notes (for the implementer)

- **Spec coverage:** `smart` DTO + filter (Task 1), `recentlyAddedAlbums` (Task 2), `recentlyPlayedTracks` (Task 3), three-item nav + new routes (Task 4), album-card partial + Recently Added page (Task 5), Recently Played page (Task 6), docs (Task 7). The search-page album-row refactor mentioned as optional in the spec is intentionally skipped here (its `<button wire:click="goToAlbum(...)">` shape isn't a drop-in replacement for the `<a wire:navigate>` partial).
- **Type / method name consistency:** `recentlyAddedAlbums(int $limit = 50): Collection`, `recentlyPlayedTracks(int $limit = 50): Collection`, `Playlist->smart` (bool), `route('recentlyAdded')`, `route('recentlyPlayed')`. The same names appear in every task that references them.
- **The `recently_played.json` fixture has one unplayed row** (no `lastViewedAt`) specifically so Task 3's filter-in-PHP step has something to drop; both played rows have `lastViewedAt > 0`. Task 3's test expects `->toHaveCount(2)` and `->pluck('id')->all() === ['70001', '70002']`.
- **Why two un-skip steps:** Task 4 registers the routes and the sidebar links, but the page components do not exist until Tasks 5 and 6. The route smoke tests are added in Task 4 with `->skip(...)` so the suite stays green between tasks, and the skip is removed in the task that adds the page.
- **Cache busting is intentionally absent on the new methods.** Adding tracks to a playlist or creating a playlist should not invalidate Recently Added or Recently Played (those reflect Plex library state and play history, not playlist contents). The M6 Settings "Resync metadata" button will clear all `plex:*` keys.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-11-sidebar-cleanup-and-recents.md`. Two execution options:

1. **Subagent-Driven (recommended)** — fresh subagent per task with a combined spec + code-quality review between tasks. Same approach M5 used.
2. **Inline Execution** — work through the tasks in this session with checkpoints.

Which approach?
