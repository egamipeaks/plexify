# Plextune Milestone 4: Playlist Detail View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Read Plex audio playlists. Show them as a flat list in the sidebar "Your Playlists" card, and replace the `/playlist/{playlist}` stub with a real detail view: a gradient header (Plex composite cover, title, description, "N songs, duration", Play / Shuffle / inert Edit & Download buttons) over a tracklist with an Album column; clicking a row plays that track, Play plays the first track, Shuffle plays a random one.

**Architecture:** `PlexClient` gains `playlists()` (`GET /playlists?playlistType=audio`, cached 5 min) and `playlistTracks($id)` (`GET /playlists/{id}/items`, cached 5 min). The `Playlist` DTO gains a nullable `summary` (description). The `pages::playlist-detail` Livewire SFC reads the `{playlist}` route segment in `mount`, resolves the playlist's metadata from `playlists()->firstWhere('id', ...)` and its tracks from `playlistTracks($id)` via two `#[Computed]`s that catch `PlexException` into a public `$errorMessage` (the search-page priming pattern), and renders states (error panel / "playlist not found" / header + tracklist) inside one stable root `<div>`. The `sidebar` SFC becomes a real component with a `#[Computed] playlists()` that swallows `PlexException` to an empty collection, rendering each playlist as a `wire:navigate` link to `/playlist/{id}`. No mutations (rename/edit/delete), no folders/DnD — those are M5+. No player-queue work — Play/Shuffle/row-click each just `$dispatch('play-track', ...)` for one track, like the library and search views already do.

**Tech Stack:** Laravel HTTP client, Plex `/playlists` + `/playlists/{id}/items` API, `PlexCache` (Laravel `array`/file cache, `serializable_classes` allowlist), Livewire 4 SFC (`#[Computed]`, `boot()` service injection, `mount` route-segment param, `wire:navigate`), `blade-lucide-icons`, Pest 4 with `Http::fake()` for unit tests, Livewire component tests for the page/sidebar, the Playwright browser plugin for end-to-end.

**Spec reference:** `docs/superpowers/specs/2026-05-10-plextune-milestone-4-playlist-detail-design.md` (the whole document; it captures the brainstorming decisions).
**Visual reference:** `design/rendered/view-playlist.html` — rendered DOM of the prototype's playlist view. The detail-page tracklist is the **same component family as the library tracklist** (`resources/views/pages/⚡library.blade.php`), so the markup below is adapted from that file; cross-check the gradient color, the eyebrow/title/meta spacing, and the action-row button classes against `view-playlist.html` and prefer its exact class lists where they differ.
**Prior milestone:** `docs/superpowers/plans/2026-05-09-plextune-milestone-3-search.md`

---

## Key facts (confirmed against the codebase / Plex API)

- `GET /playlists?playlistType=audio` returns `MediaContainer.Metadata[]`, each row: `ratingKey`, `title`, `summary` (description, may be absent or `""`), `leafCount` (track count), `duration` (ms), `composite` (a Plex-rendered 4-up mosaic image path, e.g. `/playlists/4242/composite/1700000000`), `playlistType` (`audio`/`video`/`photo`), `smart` (`0`/`1`). `Playlist::fromPlex` already reads `ratingKey`/`title`/`leafCount`/`duration`/`composite`/`playlistType`; this milestone adds `summary`.
- `GET /playlists/{ratingKey}/items` returns `MediaContainer.Metadata[]` of **track** rows in the same shape `Track::fromPlex` already handles for `/library/metadata/{albumId}/children` (`ratingKey`, `title`, `grandparentTitle`/`originalTitle` = artist, `parentTitle` = album, `index`, `duration`, `Media[0].Part[0]` = `id` + `container`, `thumb`/`parentThumb`). A track's `index` here is its position **on its source album**, not its position in the playlist — so the detail page numbers rows by the 1-based loop index, not `$track->trackNumber`.
- A non-existent playlist id → Plex returns `404` from `/playlists/{id}/items`. The detail page also treats "id not present in `playlists()`" as not-found, before it ever calls `playlistTracks`.
- Plex playlist metadata exposes no friendly **owner** name; the design's "owner" line is omitted gracefully.
- The HTTP base URL in `PlexClient` is discovered via plex.tv unless `config('services.plex.base_url')` is set. `PlexClientTest` fakes plex.tv (`tests/Fixtures/Plex/resources.json`) so the discovered base URL is `https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400` — reuse that host in the new fakes (matching the existing `artists`/`albums`/`tracks` tests).
- `config/cache.php` has a `serializable_classes` allowlist (`Collection`, `Artist`, `Album`, `Track`). `playlists()` and `playlistTracks()` cache `Collection<Playlist>` / `Collection<Track>`; `Track` is already allowed, `Playlist` is not yet — add it.
- In the test environment `PLEX_TOKEN` is unset (`PlexClientTest` sets it per-test). So in feature tests that render a full page (which includes `<livewire:sidebar />` from the layout), the sidebar's `playlists()` calls `PlexClient` → `token()` throws `PlexAuthException` (a `PlexException`) → the sidebar swallows it → empty list, no real HTTP. That keeps `RoutesTest`/`LibraryViewTest`/etc. hermetic. Feature tests for the detail page itself mock `PlexClient` outright.
- `PlexException` is the abstract base of `PlexUnreachableException`, `PlexAuthException`, `PlexNotFoundException` — catching `PlexException` covers all three.

---

## File Structure

**Modified DTO:**
- `app/Services/Plex/Dto/Playlist.php` — add trailing `?string $summary = null` to the constructor; populate from `$row['summary'] ?? null` in `fromPlex`.

**Modified service:**
- `app/Services/Plex/PlexCache.php` — add `public const TTL_PLAYLISTS = 300;` (5 minutes), placed after `TTL_TRACKS`.
- `app/Services/Plex/PlexClient.php` — add `playlists(): Collection` and `playlistTracks(string $playlistId): Collection`, mirroring the existing `artists()` / `tracksForAlbum()` shape (cached via `$this->cache->remember`, status checks, typed exceptions).

**Modified config:**
- `config/cache.php` — add `App\Services\Plex\Dto\Playlist::class` to `serializable_classes` (the `use` for `Playlist` goes next to the existing `Artist`/`Album`/`Track` imports at the top of the file).

**Modified Livewire components:**
- `resources/views/pages/⚡playlist-detail.blade.php` — replace the stub with the full detail-view component + markup.
- `resources/views/components/⚡sidebar.blade.php` — convert the anonymous-class stub (`new class extends Component {};`) into a real component with `boot(PlexClient $plex)` + `protected PlexClient $plex` + `#[Computed] playlists()` + a `thumbFor()` helper, and replace the "No playlists yet" hard-coded block with the rendered list (keeping the placeholder for the empty/error case). Everything else in the file (nav card, the New folder / Create playlist / filter / Recents controls, `<livewire:server-chip />`) stays byte-for-byte.

**Tests:**
- `tests/Unit/PlexDtoTest.php` — append a case: `Playlist::fromPlex` populates `summary`, and tolerates its absence (→ `null`).
- `tests/Unit/PlexClientTest.php` — append cases: `playlists()` (request URL + query param + mapping + caching), `playlistTracks()` (request URL + mapping), 404 → `PlexNotFoundException`, 500 → `PlexUnreachableException` for both.
- `tests/Feature/PlaylistDetailViewTest.php` — **new**: header (title/description/"N songs, duration"), track rows + album column, `playTrack`/`playAll`/`shuffle` dispatch, Plex-unreachable panel + `retry()`, unknown-id "playlist not found".
- `tests/Feature/SidebarTest.php` — **new**: renders a row per playlist linking to `route('playlist', $id)`; on `PlexException` shows "No playlists yet" and does not throw.
- `tests/Feature/RoutesTest.php` — update the playlist smoke test (the stub text "Playlist abc123" is gone): mock `PlexClient::playlists` to return an empty collection and assert the page returns 200 and shows the "playlist not found" copy.
- `tests/Browser/PlaylistDetailTest.php` — **new**: from `/`, click the first sidebar playlist row → URL is `/playlist/...` → header title text present + ≥1 track row → drive a row click via `script()` → `<audio>` is playing. (Hits live Plex; cleanly skips/fails when Plex is down, like `LibraryDrillDownTest`/`SearchTest`.)

**New test fixtures:**
- `tests/Fixtures/Plex/playlists.json` — sample `/playlists?playlistType=audio` response: ≥2 audio playlists, one with a `summary` and one without.
- `tests/Fixtures/Plex/playlist_items.json` — sample `/playlists/{id}/items` response: ≥2 track rows with `Media[0].Part[0]`, `grandparentTitle`/`parentTitle`/`index`/`duration`/`thumb`.

**No changes to:** `routes/web.php` (the `/playlist/{playlist}` route already maps to `pages::playlist-detail`).

---

## Task 1: `Playlist` DTO gains `summary`; `PlexCache::TTL_PLAYLISTS`; cache allowlist

**Files:**
- Modify: `app/Services/Plex/Dto/Playlist.php`
- Modify: `app/Services/Plex/PlexCache.php`
- Modify: `config/cache.php`
- Test: `tests/Unit/PlexDtoTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/PlexDtoTest.php` (the `use App\Services\Plex\Dto\Playlist;` import already exists from M3 — do not duplicate it):

```php
it('maps a Plex playlist row with a summary to a Playlist DTO', function () {
    $playlist = Playlist::fromPlex([
        'ratingKey' => '4242',
        'title' => 'Late Night',
        'summary' => 'Wind-down listening.',
        'leafCount' => 37,
        'duration' => 8_460_000,
        'composite' => '/playlists/4242/composite/1700000000',
        'playlistType' => 'audio',
    ]);

    expect($playlist->summary)->toBe('Wind-down listening.')
        ->and($playlist->title)->toBe('Late Night')
        ->and($playlist->trackCount)->toBe(37);
});

it('leaves Playlist summary null when the Plex row has none', function () {
    $playlist = Playlist::fromPlex([
        'ratingKey' => '7',
        'title' => 'No Description',
        'leafCount' => 1,
        'duration' => 1000,
        'playlistType' => 'audio',
    ]);

    expect($playlist->summary)->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='Playlist summary'`
Expected: FAIL — `Unknown named parameter $summary` (or property does not exist).

- [ ] **Step 3: Implement**

In `app/Services/Plex/Dto/Playlist.php`, add `summary` as the trailing constructor parameter and populate it in `fromPlex`:

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
            summary: $row['summary'] ?? null,
        );
    }
}
```

In `app/Services/Plex/PlexCache.php`, add the TTL constant after `TTL_TRACKS`:

```php
    public const TTL_TRACKS = 604800;           // 7 days
    public const TTL_PLAYLISTS = 300;           // 5 minutes
```

In `config/cache.php`, add the `Playlist` import next to the existing DTO imports at the top of the file and add it to the allowlist:

```php
use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\Track;
```

```php
    'serializable_classes' => [
        Collection::class,
        Artist::class,
        Album::class,
        Track::class,
        Playlist::class,
    ],
```

(If `config/cache.php` currently imports the DTO classes some other way, follow whatever pattern is already there — the point is `Playlist::class` joins the array.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='Playlist summary'`
Expected: PASS (2 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/Dto/Playlist.php app/Services/Plex/PlexCache.php config/cache.php tests/Unit/PlexDtoTest.php
git commit -m "feat: Playlist DTO summary, TTL_PLAYLISTS, cache allowlist"
```

---

## Task 2: `PlexClient::playlists()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Create: `tests/Fixtures/Plex/playlists.json`
- Test: `tests/Unit/PlexClientTest.php` (append)

- [ ] **Step 1: Create the fixture**

Create `tests/Fixtures/Plex/playlists.json`:

```json
{
  "MediaContainer": {
    "size": 2,
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
      }
    ]
  }
}
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Unit/PlexClientTest.php`:

```php
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

it('maps a 500 from /playlists to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists*' => Http::response('boom', 500),
    ]);

    $client = app(PlexClient::class);

    expect(fn () => $client->playlists())->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 3: Run to verify they fail**

Run: `php artisan test --compact --filter='playlists'`
Expected: FAIL — `Method ...playlists() does not exist` / `BadMethodCallException`.

- [ ] **Step 4: Implement `playlists()` in `PlexClient.php`**

Add this method after `tracksForAlbum()` (keep the existing `use App\Services\Plex\Dto\Playlist;` import — it is already present):

```php
    public function playlists(): Collection
    {
        return $this->cache->remember('playlists', PlexCache::TTL_PLAYLISTS, function () {
            $response = $this->server()->get('/playlists', ['playlistType' => 'audio']);

            if ($response->status() === 404) {
                throw new PlexNotFoundException('playlists endpoint not found.');
            }

            if (! $response->successful()) {
                throw new PlexUnreachableException('playlists endpoint returned ' . $response->status());
            }

            return collect(data_get($response->json(), 'MediaContainer.Metadata', []))
                ->map(fn (array $row) => Playlist::fromPlex($row))
                ->values();
        });
    }
```

- [ ] **Step 5: Run to verify they pass**

Run: `php artisan test --compact --filter='playlists'`
Expected: PASS (3 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php tests/Fixtures/Plex/playlists.json
git commit -m "feat: PlexClient::playlists()"
```

---

## Task 3: `PlexClient::playlistTracks()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Create: `tests/Fixtures/Plex/playlist_items.json`
- Test: `tests/Unit/PlexClientTest.php` (append)

- [ ] **Step 1: Create the fixture**

Create `tests/Fixtures/Plex/playlist_items.json`:

```json
{
  "MediaContainer": {
    "size": 2,
    "Metadata": [
      {
        "ratingKey": "8001",
        "title": "Holocene",
        "grandparentTitle": "Bon Iver",
        "parentTitle": "Bon Iver, Bon Iver",
        "index": 6,
        "duration": 337000,
        "thumb": "/library/metadata/8001/thumb/1700000010",
        "Media": [
          { "Part": [ { "id": 770001, "container": "flac" } ] }
        ]
      },
      {
        "ratingKey": "8002",
        "title": "Skinny Love",
        "grandparentTitle": "Bon Iver",
        "parentTitle": "For Emma, Forever Ago",
        "index": 3,
        "duration": 238000,
        "thumb": "/library/metadata/8002/thumb/1700000011",
        "Media": [
          { "Part": [ { "id": 770002, "container": "flac" } ] }
        ]
      }
    ]
  }
}
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Unit/PlexClientTest.php`:

```php
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

    expect(fn () => $client->playlistTracks('999'))->toThrow(\App\Services\Plex\Exceptions\PlexNotFoundException::class);
});

it('maps a 500 from /playlists/{id}/items to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242/items*' => Http::response('boom', 500),
    ]);

    $client = app(PlexClient::class);

    expect(fn () => $client->playlistTracks('4242'))->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 3: Run to verify they fail**

Run: `php artisan test --compact --filter='tracks of a playlist|playlists/{id}/items'`
Expected: FAIL — `Method ...playlistTracks() does not exist`.

- [ ] **Step 4: Implement `playlistTracks()` in `PlexClient.php`**

Add after `playlists()`:

```php
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
```

- [ ] **Step 5: Run to verify they pass**

Run: `php artisan test --compact --filter='tracks of a playlist|playlists/{id}/items'`
Expected: PASS (3 tests). Also run the whole `PlexClientTest`: `php artisan test --compact tests/Unit/PlexClientTest.php` → all green.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php tests/Fixtures/Plex/playlist_items.json
git commit -m "feat: PlexClient::playlistTracks()"
```

---

## Task 4: Playlist detail page — component logic + error / not-found states + header

**Files:**
- Modify (replace): `resources/views/pages/⚡playlist-detail.blade.php`
- Modify: `tests/Feature/RoutesTest.php` (the stub assertion breaks the moment the page is rebuilt — update it in this task)
- Test: `tests/Feature/PlaylistDetailViewTest.php` (new)

- [ ] **Step 1: Update the `RoutesTest` playlist smoke test**

In `tests/Feature/RoutesTest.php`, replace the `it('renders the playlist detail route', ...)` test (its stub text "Playlist abc123" disappears in Step 3) with:

```php
it('renders the playlist detail route', function () {
    // No Plex token in the test env, so the playlist-meta computed catches the
    // PlexAuthException and renders the "can't reach Plex" panel. Hermetic — no HTTP.
    $this->get('/playlist/abc123')
        ->assertOk()
        ->assertSee("Can't reach your Plex server");
});
```

Don't mock `PlexClient` here: a full HTTP render also pulls in `<livewire:sidebar />`, `<livewire:server-chip />`, and `<livewire:player />`, so a partial mock would need every method they touch. The existing route tests rely on the unset-token path; keep doing that. Leave the `/`, `/search`, `/settings` route tests as-is.

- [ ] **Step 2: Write the failing feature tests**

Create `tests/Feature/PlaylistDetailViewTest.php`:

```php
<?php

use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

function samplePlaylist(): Playlist
{
    return new Playlist(
        id: '4242',
        title: 'Late Night',
        trackCount: 2,
        durationMs: 575000,
        thumb: '/playlists/4242/composite/1700000000',
        playlistType: 'audio',
        summary: 'Wind-down listening.',
    );
}

function samplePlaylistTracks(): \Illuminate\Support\Collection
{
    return collect([
        new Track(id: '8001', title: 'Holocene', artist: 'Bon Iver', album: 'Bon Iver, Bon Iver', trackNumber: 6, durationMs: 337000, partId: 770001, container: 'flac', thumb: '/t/8001'),
        new Track(id: '8002', title: 'Skinny Love', artist: 'Bon Iver', album: 'For Emma, Forever Ago', trackNumber: 3, durationMs: 238000, partId: 770002, container: 'flac', thumb: '/t/8002'),
    ]);
}

function mockPlexForPlaylist(): void
{
    test()->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/library/parts/{$t->partId}/file.{$t->container}?X-Plex-Token=t");
    });
}

it('renders the playlist header with title, description and song count + duration', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSee('PLAYLIST')
        ->assertSee('Late Night')
        ->assertSee('Wind-down listening.')
        ->assertSee('2 songs, 9:35'); // 337000 + 238000 = 575000 ms = 575s = 9:35
});

it('renders one row per track with the album column', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSee('Holocene')
        ->assertSee('Bon Iver, Bon Iver')
        ->assertSee('Skinny Love')
        ->assertSee('For Emma, Forever Ago');
});

it('dispatches play-track for a clicked row', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('playTrack', '8002')
        ->assertDispatched('play-track',
            url: 'https://server/library/parts/770002/file.flac?X-Plex-Token=t',
            title: 'Skinny Love',
            artist: 'Bon Iver',
            artwork: 'https://thumb/t/8002',
        );
});

it('plays the first track when Play is pressed', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('playAll')
        ->assertDispatched('play-track', title: 'Holocene');
});

it('plays a track from the playlist when Shuffle is pressed', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('shuffle')
        ->assertDispatched('play-track'); // any track in the playlist
});

it('shows the Plex-unreachable panel and recovers on retry', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->once()->andThrow(new PlexUnreachableException('connection refused'));
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSee("Can't reach your Plex server")
        ->assertSee('Retry')
        ->call('retry')
        ->assertSee('Late Night');
});

it('shows a not-found panel when the id is not a known playlist', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        // playlistTracks must never be called for an unknown id
        $mock->shouldReceive('playlistTracks')->never();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => 'nope'])
        ->assertSee("Playlist not found");
});
```

> Note on the duration assertion: copy the `formatMs` helper from `pages::search` / `pages::library` exactly (`$seconds = (int) round($ms / 1000); sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60)`). 575000 ms → 575 s → `9:35`. If you change the rounding, update the test.

- [ ] **Step 3: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/PlaylistDetailViewTest.php tests/Feature/RoutesTest.php`
Expected: FAIL — the stub renders "Playlist 4242"/"Playlist abc123", none of the asserted strings/dispatches exist; `playTrack`/`playAll`/`shuffle`/`retry` methods don't exist; the updated `RoutesTest` playlist case fails too.

- [ ] **Step 4: Implement the component PHP + the error / not-found / header markup**

Replace the entire contents of `resources/views/pages/⚡playlist-detail.blade.php` with:

```blade
<?php

use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $playlist = '';

    public ?string $errorMessage = null;

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    public function mount(string $playlist): void
    {
        $this->playlist = $playlist;
    }

    public function playTrack(string $trackId): void
    {
        $track = $this->tracks->firstWhere('id', $trackId);

        if (! $track) {
            return;
        }

        $this->dispatchTrack($track);
    }

    public function playAll(): void
    {
        $track = $this->tracks->first();

        if (! $track) {
            return;
        }

        $this->dispatchTrack($track);
    }

    public function shuffle(): void
    {
        if ($this->tracks->isEmpty()) {
            return;
        }

        $this->dispatchTrack($this->tracks->random());
    }

    public function retry(): void
    {
        $this->errorMessage = null;
        unset($this->playlistMeta, $this->tracks);
    }

    #[Computed]
    public function playlistMeta(): ?Playlist
    {
        try {
            return $this->plex->playlists()->firstWhere('id', $this->playlist);
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. " . $e->getMessage();

            return null;
        }
    }

    /** @return Collection<int, Track> */
    #[Computed]
    public function tracks(): Collection
    {
        if (! $this->playlistMeta) {
            return collect();
        }

        try {
            return $this->plex->playlistTracks($this->playlist);
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. " . $e->getMessage();

            return collect();
        }
    }

    protected function dispatchTrack(Track $track): void
    {
        $this->dispatch('play-track',
            url: $this->plex->streamUrl($track),
            title: $track->title,
            artist: $track->artist,
            artwork: $this->thumbFor($track->thumb),
        );
    }

    protected function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }

    protected function formatMs(int $ms): string
    {
        $seconds = (int) round($ms / 1000);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
};
?>

<div class="h-full flex flex-col">
{{-- Force the computeds so a Plex failure populates $errorMessage before the branches below. --}}
@php($this->playlistMeta)
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
@elseif (! $this->playlistMeta)
    <div class="flex-1 grid place-items-center p-12 text-center">
        <div class="max-w-md">
            <x-lucide-list-music class="w-12 h-12 mx-auto text-text-3 mb-4" />
            <h2 class="text-xl font-bold mb-2">Playlist not found</h2>
            <p class="text-text-2 text-sm mb-6">This playlist doesn't exist on your Plex server.</p>
            <a href="{{ route('library') }}" wire:navigate
               class="px-5 py-2 bg-white text-black rounded-full font-bold hover:scale-105 transition-transform inline-block">
                Back to library
            </a>
        </div>
    </div>
@else
    @php($meta = $this->playlistMeta)
    {{-- Gradient header --}}
    <div class="px-2 pt-2 pb-2 flex-none">
        <div class="relative overflow-hidden rounded-lg" style="background: linear-gradient(180deg, #4a3b6b 0%, #2a2438 50%, var(--color-surface) 100%);">
            <div class="px-6 py-5 flex items-end gap-5">
                @if ($meta->thumb)
                    <img src="{{ $this->thumbFor($meta->thumb) }}" alt="{{ $meta->title }}"
                         class="rounded flex-none shadow-2xl bg-surface-2 object-cover" style="width: 180px; height: 180px;">
                @else
                    <div class="rounded relative overflow-hidden flex-none shadow-2xl bg-surface-2 grid place-items-center" style="width: 180px; height: 180px;">
                        <x-lucide-list-music class="w-12 h-12 text-text-3" />
                    </div>
                @endif
                <div class="flex-1 min-w-0">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-white/80">PLAYLIST</div>
                    <h1 class="text-[clamp(28px,4.2vw,56px)] font-black tracking-tight leading-[1.05] truncate">{{ $meta->title }}</h1>
                    @if ($meta->summary)
                        <div class="mt-2 text-[14px] text-text-2 truncate">{{ $meta->summary }}</div>
                    @endif
                    <div class="mt-2 flex items-center gap-2 text-[13px] text-text-2 flex-wrap">
                        <span class="whitespace-nowrap tabular-nums">{{ $this->tracks->count() }} songs, {{ $this->formatMs($this->tracks->sum('durationMs')) }}</span>
                    </div>
                </div>
            </div>
            <div class="px-6 pb-4 flex items-center gap-4">
                <button type="button" wire:click="playAll"
                        class="w-14 h-14 rounded-full bg-accent hover:bg-accent-hover grid place-items-center text-black shadow-xl hover:scale-105 active:scale-100 transition-transform">
                    <x-lucide-play class="w-[22px] h-[22px]" style="fill: currentColor;" />
                </button>
                <button type="button" wire:click="shuffle"
                        class="w-10 h-10 rounded-full grid place-items-center text-text-2 hover:text-white hover:scale-105 transition-all">
                    <x-lucide-shuffle class="w-[22px] h-[22px]" />
                </button>
                <button type="button"
                        class="px-4 h-9 rounded-full border border-text-3/40 hover:border-white text-[13px] font-bold text-white transition-colors">
                    Edit playlist
                </button>
                <button type="button" class="w-10 h-10 rounded-full grid place-items-center text-text-2 hover:text-white hover:scale-105 transition-all">
                    <x-lucide-download class="w-5 h-5" />
                </button>
                <div class="flex-1"></div>
            </div>
        </div>
    </div>

    {{-- Tracklist — added in Task 5 --}}
    <div class="flex-1 p-2 overflow-auto" data-region="tracklist"></div>
@endif
</div>
```

> Cross-check the gradient stops, the `180px` mosaic size, the eyebrow casing (`PLAYLIST` vs `Playlist`), and the action-row button classes against `design/rendered/view-playlist.html`; prefer that file's exact class lists where they differ from the library-derived markup above. The component PHP above is final regardless.

- [ ] **Step 5: Run the feature tests**

Run: `php artisan test --compact tests/Feature/PlaylistDetailViewTest.php tests/Feature/RoutesTest.php`
Expected: the header / error / not-found / `playTrack` / `playAll` / `shuffle` / `retry` tests PASS, and all of `RoutesTest` passes; the "renders one row per track with the album column" test still FAILS (tracklist body lands in Task 5).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡playlist-detail.blade.php tests/Feature/PlaylistDetailViewTest.php tests/Feature/RoutesTest.php
git commit -m "feat: playlist detail page — header, error and not-found states"
```

---

## Task 5: Playlist detail page — tracklist with Album column

**Files:**
- Modify: `resources/views/pages/⚡playlist-detail.blade.php` (replace the `{{-- Tracklist — added in Task 5 --}}` placeholder block)
- Test: `tests/Feature/PlaylistDetailViewTest.php` (already written in Task 4 — the "renders one row per track" case)

- [ ] **Step 1: Confirm the failing test**

Run: `php artisan test --compact tests/Feature/PlaylistDetailViewTest.php --filter='one row per track'`
Expected: FAIL (the tracklist body is still the empty `data-region="tracklist"` div).

- [ ] **Step 2: Implement the tracklist**

In `resources/views/pages/⚡playlist-detail.blade.php`, replace this block:

```blade
    {{-- Tracklist — added in Task 5 --}}
    <div class="flex-1 p-2 overflow-auto" data-region="tracklist"></div>
```

with (markup adapted from the library page's comfortable-mode tracklist; columns are grip / `#` / title+art / **Album** / `♥` / time; rows are numbered by the 1-based loop index):

```blade
    {{-- Tracklist --}}
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
                            class="row group w-full grid items-center px-4 py-2 rounded text-[14px] text-left hover:bg-white/[0.07] transition-colors"
                            style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">
                        <span class="text-text-3 group-hover:text-white grid place-items-center">
                            <x-lucide-grip-vertical class="w-[14px] h-[14px]" />
                        </span>
                        <span class="tabular-nums text-text-2 text-right">{{ $i + 1 }}</span>
                        <div class="min-w-0 flex items-center gap-3">
                            @if ($track->thumb)
                                <img src="{{ $this->thumbFor($track->thumb) }}" alt="{{ $track->title }}"
                                     class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 36px; height: 36px;" loading="lazy">
                            @else
                                <div class="rounded-sm relative overflow-hidden flex-none bg-surface-2 grid place-items-center" style="width: 36px; height: 36px;">
                                    <x-lucide-disc class="w-3.5 h-3.5 text-text-3" />
                                </div>
                            @endif
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
```

(The library page also has a comfortable/compact toggle widget and a `data-region` scroll-restore `@script` block. The playlist page has no compact mode in M4 — omit the toggle. A scroll-restore script is unnecessary here since the whole page is one component that only re-renders on `retry`/`playTrack`; skip it.)

- [ ] **Step 3: Run the feature test file**

Run: `php artisan test --compact tests/Feature/PlaylistDetailViewTest.php`
Expected: PASS (all cases).

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡playlist-detail.blade.php
git commit -m "feat: playlist detail page — tracklist with album column"
```

---

## Task 6: Sidebar flat playlist list

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php`
- Test: `tests/Feature/SidebarTest.php` (new)

(`tests/Feature/RoutesTest.php` was already updated in Task 4 — leave it alone here.)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SidebarTest.php`:

```php
<?php

use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

it('lists the Plex playlists as links into the detail view', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andReturn(collect([
            new Playlist(id: '4242', title: 'Late Night', trackCount: 12, durationMs: 0, thumb: '/t/4242', playlistType: 'audio'),
            new Playlist(id: '4243', title: 'Bangers', trackCount: 30, durationMs: 0, thumb: null, playlistType: 'audio'),
        ]));
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
    });

    Livewire::test('sidebar')
        ->assertSee('Late Night')
        ->assertSee('Bangers')
        ->assertSeeHtml('href="' . route('playlist', '4242') . '"')
        ->assertSeeHtml('href="' . route('playlist', '4243') . '"')
        ->assertDontSee('No playlists yet');
});

it('falls back to the empty placeholder when Plex is unreachable', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andThrow(new PlexUnreachableException('refused'));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('sidebar')
        ->assertSee('No playlists yet')
        ->assertSee('Your Playlists'); // the card chrome still renders
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/SidebarTest.php`
Expected: FAIL — the current sidebar has no `playlists()` method and hard-codes "No playlists yet", so `assertSee('Late Night')` and `assertDontSee('No playlists yet')` both fail (and `Livewire::test('sidebar')` may error on the unknown `playlists` method called by the mock if the template referenced it — it doesn't yet, so the failure is the missing rendered rows).

- [ ] **Step 3: Implement the sidebar component**

In `resources/views/components/⚡sidebar.blade.php`, replace the PHP block at the top:

```php
<?php

use Livewire\Component;

new class extends Component {};
?>
```

with:

```php
<?php

use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    /** @return Collection<int, Playlist> */
    #[Computed]
    public function playlists(): Collection
    {
        try {
            return $this->plex->playlists();
        } catch (PlexException) {
            return collect();
        }
    }

    public function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }
};
?>
```

Then, in the same file, replace this block (the scroll area inside the "Playlists card"):

```blade
        <div class="flex-1 overflow-y-auto scroll px-2 pb-2 flex flex-col gap-0.5">
            <div class="px-3 py-6 text-[12px] text-text-3 text-center">No playlists yet</div>
        </div>
```

with:

```blade
        <div class="flex-1 overflow-y-auto scroll px-2 pb-2 flex flex-col gap-0.5">
            @forelse ($this->playlists as $playlist)
                <a href="{{ route('playlist', $playlist->id) }}" wire:navigate wire:key="sidebar-pl-{{ $playlist->id }}"
                   @class([
                       'w-full flex items-center gap-3 px-2 py-1.5 rounded-md transition-colors',
                       'text-white bg-surface-2' => request()->routeIs('playlist') && (string) request()->route('playlist') === $playlist->id,
                       'text-text-2 hover:text-white hover:bg-surface-2' => ! (request()->routeIs('playlist') && (string) request()->route('playlist') === $playlist->id),
                   ])>
                    @if ($playlist->thumb)
                        <img src="{{ $this->thumbFor($playlist->thumb) }}" alt="{{ $playlist->title }}"
                             class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 32px; height: 32px;" loading="lazy">
                    @else
                        <div class="rounded-sm flex-none bg-surface-2 grid place-items-center" style="width: 32px; height: 32px;">
                            <x-lucide-list-music class="w-3.5 h-3.5 text-text-3" />
                        </div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="text-[14px] truncate">{{ $playlist->title }}</div>
                        <div class="text-[12px] text-text-3 truncate">Playlist · {{ $playlist->trackCount }} songs</div>
                    </div>
                </a>
            @empty
                <div class="px-3 py-6 text-[12px] text-text-3 text-center">No playlists yet</div>
            @endforelse
        </div>
```

Leave the rest of the file (nav card, the "Your Playlists" header with its New folder / Create playlist buttons, the filter / Recents row, `<livewire:server-chip />`) exactly as it is.

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan test --compact tests/Feature/SidebarTest.php tests/Feature/RoutesTest.php`
Expected: PASS (all).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/⚡sidebar.blade.php tests/Feature/SidebarTest.php tests/Feature/RoutesTest.php
git commit -m "feat: sidebar flat playlist list"
```

---

## Task 7: Browser test — navigate into a playlist and play a track

**Files:**
- Create: `tests/Browser/PlaylistDetailTest.php`

This mirrors `tests/Browser/LibraryDrillDownTest.php` (read that file first for the established patterns: `visit()`, `script()` to drive list interactions, audio-playing assertion). It hits the live Plex server, so it fails when Plex is unreachable — that's expected and matches the other browser tests.

- [ ] **Step 1: Write the test**

Create `tests/Browser/PlaylistDetailTest.php`:

```php
<?php

it('navigates from the sidebar into a playlist and plays a track', function () {
    $page = visit('/');

    // The sidebar lists Plex playlists; click the first one.
    $firstHref = $page->script("document.querySelector('[wire\\\\:key^=\"sidebar-pl-\"]')?.getAttribute('href')");
    expect($firstHref)->not->toBeNull('Expected at least one playlist in the sidebar (is the Plex server reachable and does it have an audio playlist?)');

    $page->script("document.querySelector('[wire\\\\:key^=\"sidebar-pl-\"]').click()");
    $page->assertUrlContains('/playlist/');

    // Header + at least one track row.
    $page->assertSee('PLAYLIST');
    $page->assertPresent('[wire\\:key^="track-"]');

    // Click the first track row, then confirm the persisted player is playing.
    $page->script("document.querySelector('[wire\\\\:key^=\"track-\"]').click()");

    $playing = retry(fn () => $page->script("(() => { const a = document.querySelector('audio'); return a && !a.paused && a.currentTime > 0; })()"), times: 20, sleep: 250);
    expect($playing)->toBeTrue('Expected the <audio> element to be playing after clicking a track row');
});
```

> Adjust `assertUrlContains` / `assertPresent` / the `retry()` helper to whatever the Pest browser plugin and `LibraryDrillDownTest` actually use in this repo — match that file. The CLAUDE.md "Pest 4 browser plugin" gotcha lists the available methods (`script()`, `assertPresent()`, `assertVisible()`, `assertSee()`, `assertUrlIs()`, etc.) — if `assertUrlContains` isn't available, use `assertUrlIs` with the resolved URL or assert via `script("location.pathname.startsWith('/playlist/')")`.

- [ ] **Step 2: Run the browser test**

Run: `php artisan test --compact tests/Browser/PlaylistDetailTest.php`
Expected: PASS when the Plex server is reachable and has at least one audio playlist with tracks. If Plex is down, it fails for that reason — note it and move on (the other browser tests are in the same boat).

- [ ] **Step 3: Commit**

```bash
git add tests/Browser/PlaylistDetailTest.php
git commit -m "test: browser coverage for playlist detail navigation and play"
```

---

## Task 8: Full test run, docs, milestone bookkeeping

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test --compact`
Expected: green. (M3 baseline was 81 tests; M4 adds roughly: 2 DTO + ~6 PlexClient + 7 PlaylistDetailView + 2 Sidebar + 1 Browser, with RoutesTest unchanged in count — so ~99±.) If `tests/Browser/*` fails only because the Plex server is unreachable, that's acceptable; everything else must pass.

- [ ] **Step 2: Run Pint over the whole change**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no issues (or it fixes them — re-stage if so).

- [ ] **Step 3: Update `CLAUDE.md`**

- In the "Milestone status" table, change row 4 ("Playlist detail view") status from `Next` to `Done`, and change row 5 ("App side folders + drag and drop") status from `Not started` to `Next`.
- Update the line "Git log is the authoritative record... `php artisan test` should be green (81 tests as of end of M3...)" to reflect the new test count as of end of M4.
- In the "Plex integration" paragraph, add `playlists()` (cached 5min) and `playlistTracks($id)` (cached 5min) to the `PlexClient` method list, and note `PlexCache::TTL_PLAYLISTS`. Mention that `Playlist` DTO now has `summary`.
- In the "Gotchas" list, the existing `config/cache.php` bullet currently says "M4 caches playlists, so it must add `Playlist::class` here" — update it to past tense ("M4 added `Playlist::class`").
- In the "Component tags" / pages note, no change needed (`pages::playlist-detail` already existed as a stub; it's now real).
- If there's a "Gotchas" note worth adding from this milestone (e.g. the `request()->route('playlist')` active-state check in the sidebar, or "the sidebar now calls Plex on every page but swallows `PlexException` so tests stay hermetic with `PLEX_TOKEN` unset"), add it concisely. Don't invent gotchas that didn't bite.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: mark milestone 4 (playlist detail) done; update PlexClient/test notes"
```

---

## Self-review notes (for the executor)

- **Single root element:** `pages::playlist-detail` and `sidebar` each keep one stable root (`<div class="h-full flex flex-col">` / `<aside ...>`) with all conditionals inside — per the Livewire-4 morph gotcha.
- **Computed priming:** the `@php($this->playlistMeta)` + `@php($this->tracks)` lines at the top of the page body are load-bearing (they make a Plex failure populate `$errorMessage` before the `@if` branches); the template reads `$this->errorMessage`, not a local.
- **No Blade-directive-after-word traps:** the meta line uses a single `{{ ... }}` expression, not `word@if(...)...@endif`.
- **`#[Computed]` + DI:** computeds don't autowire — `playlistMeta`/`tracks`/`playlists` use `$this->plex` set in `boot()`. `boot`/`mount` do autowire, so `boot(PlexClient $plex)` is fine.
- **`assertSee` escaping:** none of the asserted strings contain `"` or `&`, so no `&quot;`/`&amp;` gymnastics needed (unlike the search no-results heading).
- **Caching:** `playlists()` and `playlistTracks()` go through `PlexCache::remember`, which means cached `Collection<Playlist>` / `Collection<Track>` — `Playlist::class` must be (and is, in Task 1) in `config/cache.php`'s `serializable_classes`.
