# Plextune Milestone 3: Search View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the `/search` stub with a real search view: the topbar input drives a `wire:navigate` to `/search?q=...`, the search page calls Plex's `/hubs/search` endpoint once, and renders filter-tab pills (All / Playlists / Albums / Artists / Tracks) over grouped result lists; tracks are click-to-play, artist/album rows deep-link into the library view.

**Architecture:** A new `PlexClient::searchAll($query)` hits `GET /hubs/search?query=...&limit=30`, keeps only the music-relevant hubs (`artist`, `album`, `track`, `playlist`), and returns a `SearchResults` DTO holding four `Collection`s. The `pages::search` Livewire single-file component reads `?q=` (a `mount` parameter), memoizes the search in a `#[Computed]`, and renders states (empty prompt / Plex-unreachable panel / no-results / grouped sections) — all inside one stable root `<div>` per the Livewire-4 morph gotcha. The topbar gains an `updatedQuery()` hook that redirects to `/search?q=...`. The library page's `mount` learns to preselect an artist/album from `?artist=`/`?album=` query params so search rows can link into it. Search is not cached (per spec).

**Tech Stack:** Laravel HTTP client, Plex `/hubs/search` API, Livewire 4 SFC (`#[Computed]`, `boot()` service injection, `redirectRoute(navigate: true)`), `blade-lucide-icons`, Pest 4 with `Http::fake()` for unit tests and the Playwright browser plugin for end-to-end.

**Spec reference:** `docs/superpowers/specs/2026-05-09-plextune-design.md` (the "Search" sub-sections under Architecture / Data Flow, and Milestone 3)
**Visual reference:** `design/rendered/new-search.html` — this file is the prototype's compiled React (`plextune-views.jsx`), so port the *structure and Tailwind class lists* from the `SearchResults` component in it, not literal DOM. The relevant components inside it are `SearchResults`, `Pill`, `RowBase`, `SectionHeader`. (Ignore `search.html` — it is the older layout. Ignore the `SearchEmpty` "Browse all" genre grid — out of scope; we use the simple centered prompt that `SearchResults` itself renders when `!q`.)
**Prior milestone:** `docs/superpowers/plans/2026-05-09-plextune-milestone-2-library-and-plex-client.md`

---

## Key facts confirmed against the live Plex server

- `GET /hubs/search?query=X&limit=N` returns `MediaContainer.Hub[]`. Each hub has a `type` and (for media types) a `Metadata[]` array. Hub types present include `show`, `artist`, `album`, `movie`, `track`, `actor`, `director`, `episode`, `playlist`, `genre`, `collection`, etc. We keep only `artist`, `album`, `track`, `playlist`.
- **artist** hits have: `ratingKey`, `title`, `thumb`, `childCount` (= album count). No track count. → `Artist::fromPlex` already handles this shape unchanged.
- **album** hits have: `ratingKey`, `title`, `parentTitle` (artist name), `parentRatingKey` (artist's ratingKey), `year`, `thumb`. No `leafCount`/`duration`. → `Album::fromPlex` handles the title/artist/year/thumb; we add an optional `artistId` from `parentRatingKey`.
- **track** hits have: `ratingKey`, `title`, `grandparentTitle` (artist), `parentTitle` (album), `duration` (ms), `thumb`/`parentThumb`, and `Media[0].Part[0]` (so `streamUrl()` works). They may *not* have `index`. → `Track::fromPlex` already reads `grandparentTitle`/`parentTitle`/`Media`; we add an optional `thumb`.
- **playlist** hits have: `ratingKey`, `title`, `leafCount` (track count), `duration` (ms), `composite` (thumbnail path), `playlistType` (`audio`/`video`/`photo`). We keep only `playlistType === 'audio'`. → needs a new `Playlist` DTO.
- A query that matches nothing returns the same hub list with `size: 0` and no `Metadata` — `searchAll` must tolerate missing/empty hubs.
- `bg-surface-0` appears in the prototype JSX for the sticky pill bar's background, but the project's Tailwind theme only defines `surface-1`..`surface-4` (plus `base` = `#000`). Use `bg-base/95` in our markup for that sticky bar.

---

## File Structure

**New DTOs (`app/Services/Plex/Dto/`):**
- `Playlist.php` — readonly: `id`, `title`, `trackCount`, `durationMs`, `thumb`, `playlistType`. (Also used by M4.)
- `SearchResults.php` — readonly: four `Collection`s (`tracks`, `artists`, `albums`, `playlists`) + `empty()`, `total()`, `isEmpty()`.

**Modified DTOs:**
- `app/Services/Plex/Dto/Track.php` — add trailing optional `?string $thumb = null`; populate it in `fromPlex` from `thumb` / `parentThumb`.
- `app/Services/Plex/Dto/Album.php` — add trailing optional `?string $artistId = null`; populate it in `fromPlex` from `parentRatingKey`.

**Modified service:**
- `app/Services/Plex/PlexClient.php` — add `searchAll(string $query): SearchResults`.

**Modified Livewire components:**
- `resources/views/components/⚡topbar.blade.php` — add `updatedQuery()` that `redirectRoute('search', ['q' => $this->query], navigate: true)`.
- `resources/views/pages/⚡search.blade.php` — replace the stub with the full search view component + markup.
- `resources/views/pages/⚡library.blade.php` — widen `mount()` to accept `?string $artist = null, ?string $album = null` and preselect them.

**Tests:**
- `tests/Unit/PlexDtoTest.php` — add cases for `Playlist::fromPlex`, `Track::fromPlex` (thumb + search-hub shape), `Album::fromPlex` (artistId), `SearchResults` helpers.
- `tests/Unit/PlexClientTest.php` — add cases for `searchAll` (parsing, ignoring non-music hubs, audio-only playlist filter, exception mapping).
- `tests/Feature/TopbarTest.php` — **new**: query update redirects to `/search?q=...`.
- `tests/Feature/SearchViewTest.php` — **new**: empty prompt, error panel, grouped results, no-results, pill filtering, click-to-play, artist/album navigation, filter reset on query change.
- `tests/Feature/LibraryViewTest.php` — add cases for `?artist=` / `?album=` preselection.
- `tests/Browser/SearchTest.php` — **new**: type in topbar → `/search?q=...` → results render → click a pill → click a track → audio playing. (Hits live Plex.)
- `tests/Browser/LibraryDrillDownTest.php` — update the one stale `assertSee('Search view stub')` to `assertSee('Search your library')`.

**New test fixture:**
- `tests/Fixtures/Plex/hubs_search.json` — sample `/hubs/search` response (artist/album/track/playlist hubs + `show`/`movie`/`genre` hubs that must be ignored, + one `playlistType: video` playlist that must be filtered out).

**Removed:** nothing.

**No changes to:** `routes/web.php` (the `/search` route already maps to `pages::search`; the library deep-link uses query params, not route params), `config/cache.php` (`searchAll` does not cache, so `serializable_classes` is untouched — M4 will add `Playlist::class` there when it caches playlists).

---

## Task 1: DTOs — `Playlist`, `SearchResults`, and `Track.thumb` / `Album.artistId`

**Files:**
- Create: `app/Services/Plex/Dto/Playlist.php`
- Create: `app/Services/Plex/Dto/SearchResults.php`
- Modify: `app/Services/Plex/Dto/Track.php`
- Modify: `app/Services/Plex/Dto/Album.php`
- Test: `tests/Unit/PlexDtoTest.php` (append cases)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/PlexDtoTest.php`:

```php
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\SearchResults;

it('maps a Plex playlist hub item to a Playlist DTO', function () {
    $playlist = Playlist::fromPlex([
        'ratingKey' => '4242',
        'title' => 'Late Night',
        'leafCount' => 37,
        'duration' => 8_460_000,
        'composite' => '/playlists/4242/composite/1700000000',
        'playlistType' => 'audio',
    ]);

    expect($playlist->id)->toBe('4242')
        ->and($playlist->title)->toBe('Late Night')
        ->and($playlist->trackCount)->toBe(37)
        ->and($playlist->durationMs)->toBe(8_460_000)
        ->and($playlist->thumb)->toBe('/playlists/4242/composite/1700000000')
        ->and($playlist->playlistType)->toBe('audio');
});

it('defaults missing playlist fields sensibly', function () {
    $playlist = Playlist::fromPlex(['ratingKey' => 5, 'title' => 'Untitled']);

    expect($playlist->id)->toBe('5')
        ->and($playlist->trackCount)->toBe(0)
        ->and($playlist->durationMs)->toBe(0)
        ->and($playlist->thumb)->toBeNull()
        ->and($playlist->playlistType)->toBe('audio');
});

it('maps a track search-hub item (grandparent/parent fields + thumb) to a Track DTO', function () {
    $track = Track::fromPlex([
        'ratingKey' => '53268',
        'title' => 'Holocene',
        'grandparentTitle' => 'Bon Iver',
        'parentTitle' => 'Bon Iver, Bon Iver',
        'duration' => 337_000,
        'thumb' => '/library/metadata/53268/thumb/1',
        'parentThumb' => '/library/metadata/53267/thumb/1',
        'Media' => [['Part' => [['id' => 991, 'container' => 'flac']]]],
    ]);

    expect($track->id)->toBe('53268')
        ->and($track->title)->toBe('Holocene')
        ->and($track->artist)->toBe('Bon Iver')
        ->and($track->album)->toBe('Bon Iver, Bon Iver')
        ->and($track->durationMs)->toBe(337_000)
        ->and($track->partId)->toBe(991)
        ->and($track->container)->toBe('flac')
        ->and($track->thumb)->toBe('/library/metadata/53268/thumb/1');
});

it('falls back to parentThumb then null for a track without its own thumb', function () {
    expect(Track::fromPlex([
        'ratingKey' => 1, 'title' => 'x', 'grandparentTitle' => 'a', 'parentTitle' => 'b',
        'parentThumb' => '/p/thumb',
        'Media' => [['Part' => [['id' => 1, 'container' => 'mp3']]]],
    ])->thumb)->toBe('/p/thumb');

    expect(Track::fromPlex([
        'ratingKey' => 1, 'title' => 'x', 'grandparentTitle' => 'a', 'parentTitle' => 'b',
        'Media' => [['Part' => [['id' => 1, 'container' => 'mp3']]]],
    ])->thumb)->toBeNull();
});

it('maps an album search-hub item (parentRatingKey) to artistId', function () {
    $album = Album::fromPlex([
        'ratingKey' => '58120',
        'title' => 'For Emma, Forever Ago',
        'parentTitle' => 'Bon Iver',
        'parentRatingKey' => '58119',
        'year' => 2007,
        'thumb' => '/library/metadata/58120/thumb/1',
    ]);

    expect($album->id)->toBe('58120')
        ->and($album->artist)->toBe('Bon Iver')
        ->and($album->artistId)->toBe('58119')
        ->and($album->year)->toBe(2007);
});

it('leaves album artistId null when parentRatingKey is absent', function () {
    expect(Album::fromPlex(['ratingKey' => 1, 'title' => 'x'])->artistId)->toBeNull();
});

it('builds an empty SearchResults that reports zero total', function () {
    $results = SearchResults::empty();

    expect($results->tracks)->toHaveCount(0)
        ->and($results->artists)->toHaveCount(0)
        ->and($results->albums)->toHaveCount(0)
        ->and($results->playlists)->toHaveCount(0)
        ->and($results->total())->toBe(0)
        ->and($results->isEmpty())->toBeTrue();
});

it('SearchResults total sums all four collections', function () {
    $results = new SearchResults(
        tracks: collect([1, 2, 3]),
        artists: collect([1]),
        albums: collect([1, 2]),
        playlists: collect([1]),
    );

    expect($results->total())->toBe(7)->and($results->isEmpty())->toBeFalse();
});
```

(Existing `PlexDtoTest` cases that build `new Track(...)` / `new Album(...)` with named arguments are unaffected — we only add trailing optional parameters.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=PlexDtoTest`
Expected: FAIL — `Class "App\Services\Plex\Dto\Playlist" not found`, plus failures on `Track::thumb` / `Album::artistId` not existing.

- [ ] **Step 3: Create the `Playlist` DTO**

`app/Services/Plex/Dto/Playlist.php`:

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
        );
    }
}
```

- [ ] **Step 4: Create the `SearchResults` DTO**

`app/Services/Plex/Dto/SearchResults.php`:

```php
<?php

namespace App\Services\Plex\Dto;

use Illuminate\Support\Collection;

readonly class SearchResults
{
    /**
     * @param Collection<int, Track> $tracks
     * @param Collection<int, Artist> $artists
     * @param Collection<int, Album> $albums
     * @param Collection<int, Playlist> $playlists
     */
    public function __construct(
        public Collection $tracks,
        public Collection $artists,
        public Collection $albums,
        public Collection $playlists,
    ) {}

    public static function empty(): self
    {
        return new self(collect(), collect(), collect(), collect());
    }

    public function total(): int
    {
        return $this->tracks->count()
            + $this->artists->count()
            + $this->albums->count()
            + $this->playlists->count();
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }
}
```

- [ ] **Step 5: Add `thumb` to the `Track` DTO**

Edit `app/Services/Plex/Dto/Track.php` — add a trailing constructor parameter and populate it in `fromPlex`:

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
        public ?string $thumb = null,
    ) {}

    public static function fromPlex(array $row): self
    {
        $part = $row['Media'][0]['Part'][0] ?? [];

        return new self(
            id: (string) $row['ratingKey'],
            title: $row['title'],
            artist: $row['originalTitle'] ?? $row['grandparentTitle'] ?? '',
            album: $row['parentTitle'] ?? '',
            trackNumber: $row['index'] ?? 0,
            durationMs: $row['duration'] ?? 0,
            partId: (int) ($part['id'] ?? 0),
            container: $part['container'] ?? 'mp3',
            thumb: $row['thumb'] ?? $row['parentThumb'] ?? null,
        );
    }
}
```

- [ ] **Step 6: Add `artistId` to the `Album` DTO**

Edit `app/Services/Plex/Dto/Album.php` — add a trailing constructor parameter and populate it in `fromPlex`:

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
        public ?string $artistId = null,
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
            artistId: isset($row['parentRatingKey']) ? (string) $row['parentRatingKey'] : null,
        );
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=PlexDtoTest`
Expected: PASS (all cases, old and new).

- [ ] **Step 8: Commit**

```bash
git add app/Services/Plex/Dto/Playlist.php app/Services/Plex/Dto/SearchResults.php app/Services/Plex/Dto/Track.php app/Services/Plex/Dto/Album.php tests/Unit/PlexDtoTest.php
git commit -m "feat: add Playlist + SearchResults DTOs, Track.thumb, Album.artistId"
```

---

## Task 2: `PlexClient::searchAll()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Create: `tests/Fixtures/Plex/hubs_search.json`
- Test: `tests/Unit/PlexClientTest.php` (append cases)

- [ ] **Step 1: Create the fixture**

`tests/Fixtures/Plex/hubs_search.json`:

```json
{
  "MediaContainer": {
    "size": 6,
    "Hub": [
      {
        "title": "Shows",
        "type": "show",
        "hubIdentifier": "show",
        "size": 1,
        "Metadata": [
          { "ratingKey": "7895", "type": "show", "title": "The Office (US)" }
        ]
      },
      {
        "title": "Artists",
        "type": "artist",
        "hubIdentifier": "artist",
        "size": 2,
        "Metadata": [
          { "ratingKey": "53843", "type": "artist", "title": "Bon Iver", "thumb": "/library/metadata/53843/thumb/1", "childCount": 5 },
          { "ratingKey": "53999", "type": "artist", "title": "Bon Jovi", "childCount": 12 }
        ]
      },
      {
        "title": "Albums",
        "type": "album",
        "hubIdentifier": "album",
        "size": 2,
        "Metadata": [
          { "ratingKey": "58120", "type": "album", "title": "For Emma, Forever Ago", "parentTitle": "Bon Iver", "parentRatingKey": "53843", "year": 2007, "thumb": "/library/metadata/58120/thumb/1" },
          { "ratingKey": "58200", "type": "album", "title": "i,i", "parentTitle": "Bon Iver", "parentRatingKey": "53843", "year": 2019, "thumb": "/library/metadata/58200/thumb/1" }
        ]
      },
      {
        "title": "Movies",
        "type": "movie",
        "hubIdentifier": "movie",
        "size": 1,
        "Metadata": [
          { "ratingKey": "1001", "type": "movie", "title": "Bon Cop, Bad Cop" }
        ]
      },
      {
        "title": "Tracks",
        "type": "track",
        "hubIdentifier": "track",
        "size": 2,
        "Metadata": [
          { "ratingKey": "53268", "type": "track", "title": "Holocene", "grandparentTitle": "Bon Iver", "parentTitle": "Bon Iver, Bon Iver", "duration": 337000, "thumb": "/library/metadata/53268/thumb/1", "Media": [{ "Part": [{ "id": 660001, "container": "flac" }] }] },
          { "ratingKey": "53269", "type": "track", "title": "Skinny Love", "grandparentTitle": "Bon Iver", "parentTitle": "For Emma, Forever Ago", "duration": 238000, "thumb": "/library/metadata/53269/thumb/1", "Media": [{ "Part": [{ "id": 660002, "container": "mp3" }] }] }
        ]
      },
      {
        "title": "Playlists",
        "type": "playlist",
        "hubIdentifier": "playlist",
        "size": 2,
        "Metadata": [
          { "ratingKey": "4242", "type": "playlist", "title": "Bon Voyage", "leafCount": 18, "duration": 4200000, "composite": "/playlists/4242/composite/1", "playlistType": "audio" },
          { "ratingKey": "4243", "type": "playlist", "title": "Bon Appetit (Video)", "leafCount": 7, "duration": 1600000, "playlistType": "video" }
        ]
      },
      {
        "title": "Genres",
        "type": "genre",
        "hubIdentifier": "genre",
        "size": 1,
        "Directory": [
          { "key": "/library/sections/6/genre/12", "title": "Indie Folk" }
        ]
      }
    ]
  }
}
```

- [ ] **Step 2: Add the failing tests**

Append to `tests/Unit/PlexClientTest.php`:

```php
use App\Services\Plex\Dto\SearchResults;
use Illuminate\Http\Client\ConnectionException;

it('searches /hubs/search and parses only the music hubs', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake([
        'https://server.plex.direct:32400/hubs/search*' => Http::response(
            file_get_contents(fixturePath('hubs_search.json')),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $results = app(PlexClient::class)->searchAll('bon');

    expect($results)->toBeInstanceOf(SearchResults::class)
        ->and($results->artists)->toHaveCount(2)
        ->and($results->albums)->toHaveCount(2)
        ->and($results->tracks)->toHaveCount(2)
        // the playlistType: "video" entry is filtered out
        ->and($results->playlists)->toHaveCount(1);

    expect($results->artists->first()->name)->toBe('Bon Iver')
        ->and($results->artists->first()->albumCount)->toBe(5)
        ->and($results->albums->first()->artistId)->toBe('53843')
        ->and($results->tracks->first()->title)->toBe('Holocene')
        ->and($results->tracks->first()->artist)->toBe('Bon Iver')
        ->and($results->tracks->first()->partId)->toBe(660001)
        ->and($results->tracks->first()->thumb)->toBe('/library/metadata/53268/thumb/1')
        ->and($results->playlists->first()->title)->toBe('Bon Voyage')
        ->and($results->playlists->first()->trackCount)->toBe(18);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/hubs/search')
        && str_contains($request->url(), 'query=bon')
        && str_contains($request->url(), 'limit=30'));
});

it('returns an empty SearchResults for a blank query without calling Plex', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake();

    $results = app(PlexClient::class)->searchAll('   ');

    expect($results->isEmpty())->toBeTrue();
    Http::assertNothingSent();
});

it('tolerates a hubs/search response with no matching hubs', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake([
        'https://server.plex.direct:32400/hubs/search*' => Http::response([
            'MediaContainer' => ['size' => 0, 'Hub' => [
                ['type' => 'artist', 'size' => 0],
                ['type' => 'show', 'size' => 0],
            ]],
        ], 200),
    ]);

    expect(app(PlexClient::class)->searchAll('zzzz')->isEmpty())->toBeTrue();
});

it('maps a 401 from hubs/search to PlexAuthException', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake([
        'https://server.plex.direct:32400/hubs/search*' => Http::response('nope', 401),
    ]);

    expect(fn () => app(PlexClient::class)->searchAll('bon'))->toThrow(PlexAuthException::class);
});

it('maps a connection failure from hubs/search to PlexUnreachableException', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake([
        'https://server.plex.direct:32400/hubs/search*' => fn () => throw new ConnectionException('connect timed out'),
    ]);

    expect(fn () => app(PlexClient::class)->searchAll('bon'))->toThrow(PlexUnreachableException::class);
});

it('maps a 500 from hubs/search to PlexUnreachableException', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake([
        'https://server.plex.direct:32400/hubs/search*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->searchAll('bon'))->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --filter=PlexClientTest`
Expected: FAIL — `Method App\Services\Plex\PlexClient::searchAll does not exist`.

- [ ] **Step 4: Implement `searchAll()`**

Edit `app/Services/Plex/PlexClient.php`. Add the import near the top with the other DTO imports:

```php
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\SearchResults;
```

(`Illuminate\Http\Client\ConnectionException` is already imported in this file.)

Add this method after `tracksForAlbum()` (and before `streamUrl()`):

```php
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
        throw new PlexUnreachableException('Plex search failed: ' . $e->getMessage(), previous: $e);
    }

    if ($response->status() === 401 || $response->status() === 403) {
        throw new PlexAuthException('Plex rejected the search request (status ' . $response->status() . ').');
    }

    if (! $response->successful()) {
        throw new PlexUnreachableException('hubs/search returned ' . $response->status());
    }

    $hubs = collect(data_get($response->json(), 'MediaContainer.Hub', []));
    $metadata = fn (string $type) => collect($hubs->firstWhere('type', $type)['Metadata'] ?? []);

    return new SearchResults(
        tracks: $metadata('track')->map(fn (array $row) => Track::fromPlex($row))->values(),
        artists: $metadata('artist')->map(fn (array $row) => Artist::fromPlex($row))->values(),
        albums: $metadata('album')->map(fn (array $row) => Album::fromPlex($row))->values(),
        playlists: $metadata('playlist')
            ->filter(fn (array $row) => ($row['playlistType'] ?? 'audio') === 'audio')
            ->map(fn (array $row) => Playlist::fromPlex($row))
            ->values(),
    );
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=PlexClientTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php tests/Fixtures/Plex/hubs_search.json
git commit -m "feat: add PlexClient::searchAll() backed by /hubs/search"
```

---

## Task 3: Topbar redirects to `/search` when the query changes

**Files:**
- Modify: `resources/views/components/⚡topbar.blade.php`
- Test: `tests/Feature/TopbarTest.php` (new)

- [ ] **Step 1: Write the failing test**

`tests/Feature/TopbarTest.php`:

```php
<?php

use Livewire\Livewire;

it('redirects to the search page when the query updates', function () {
    Livewire::test('topbar')
        ->set('query', 'bon iver')
        ->assertRedirect(route('search', ['q' => 'bon iver']));
});

it('redirects to a bare search page when the query is cleared', function () {
    Livewire::test('topbar')
        ->set('query', '')
        ->assertRedirect(route('search', ['q' => '']));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TopbarTest`
Expected: FAIL — no redirect happens (component has no `updatedQuery`).

- [ ] **Step 3: Add the `updatedQuery()` hook**

Edit the `<?php ... ?>` block at the top of `resources/views/components/⚡topbar.blade.php` to:

```php
<?php

use Livewire\Component;

new class extends Component {
    public string $query = '';

    public function updatedQuery(): void
    {
        $this->redirectRoute('search', ['q' => $this->query], navigate: true);
    }
};
?>
```

Leave the rest of the file (the markup) unchanged — the input already has `wire:model.live.debounce.300ms="query"`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=TopbarTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/⚡topbar.blade.php tests/Feature/TopbarTest.php
git commit -m "feat: topbar search input navigates to /search?q="
```

---

## Task 4: Library page preselects an artist/album from `?artist=` / `?album=`

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php`
- Test: `tests/Feature/LibraryViewTest.php` (append cases)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/LibraryViewTest.php`:

```php
it('preselects an artist passed as a query parameter', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: '22, A Million', artist: 'Bon Iver', year: 2016, thumb: null, trackCount: 1, durationMs: 1000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->andReturn(collect());
    });

    Livewire::test('pages::library', ['artist' => '100'])
        ->assertSet('selectedArtistId', '100')
        ->assertSee('22, A Million');
});

it('preselects both artist and album passed as query parameters', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
        $mock->shouldReceive('albumsForArtist')->with('100')->andReturn(collect([
            new Album(id: '1001', title: '22, A Million', artist: 'Bon Iver', year: 2016, thumb: null, trackCount: 1, durationMs: 169000),
        ]));
        $mock->shouldReceive('tracksForAlbum')->with('1001')->andReturn(collect([
            new Track(id: '9001', title: '715 - CRΣΣKS', artist: 'Bon Iver', album: '22, A Million', trackNumber: 4, durationMs: 178000, partId: 1, container: 'flac'),
        ]));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::library', ['artist' => '100', 'album' => '1001'])
        ->assertSet('selectedArtistId', '100')
        ->assertSet('selectedAlbumId', '1001')
        ->assertSee('715 - CRΣΣKS');
});

it('ignores empty artist/album query parameters', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('artists')->andReturn(collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: null, albumCount: 1),
        ]));
    });

    Livewire::test('pages::library', ['artist' => '', 'album' => ''])
        ->assertSet('selectedArtistId', null)
        ->assertSet('selectedAlbumId', null);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=LibraryViewTest`
Expected: FAIL — `selectedArtistId` stays `null` (mount ignores the parameter).

- [ ] **Step 3: Widen `mount()` in the library component**

Edit `resources/views/pages/⚡library.blade.php` — replace the `mount` method (currently lines ~18-21) with:

```php
    public function mount(PlexClient $plex, ?string $artist = null, ?string $album = null): void
    {
        $this->loadArtists($plex);

        if (! empty($artist)) {
            $this->selectedArtistId = $artist;
        }

        if (! empty($album)) {
            $this->selectedAlbumId = $album;
        }
    }
```

(Livewire resolves `$artist` / `$album` from the `?artist=` / `?album=` query string. Everything else in the component is unchanged: `albums`, `tracks`, and `selectedAlbum` computeds already key off `$selectedArtistId` / `$selectedAlbumId`.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=LibraryViewTest`
Expected: PASS (old and new cases).

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/⚡library.blade.php tests/Feature/LibraryViewTest.php
git commit -m "feat: library view deep-links via ?artist= and ?album="
```

---

## Task 5: Search view component + markup

**Files:**
- Modify (full replace): `resources/views/pages/⚡search.blade.php`
- Test: `tests/Feature/SearchViewTest.php` (new)

- [ ] **Step 1: Write the failing tests**

`tests/Feature/SearchViewTest.php`:

```php
<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

function sampleResults(): SearchResults
{
    return new SearchResults(
        tracks: collect([
            new Track(id: '9001', title: 'Holocene', artist: 'Bon Iver', album: 'Bon Iver, Bon Iver', trackNumber: 0, durationMs: 337000, partId: 660001, container: 'flac', thumb: '/t/9001'),
        ]),
        artists: collect([
            new Artist(id: '100', name: 'Bon Iver', thumb: '/t/100', albumCount: 5),
        ]),
        albums: collect([
            new Album(id: '1001', title: 'For Emma, Forever Ago', artist: 'Bon Iver', year: 2007, thumb: '/t/1001', trackCount: 0, durationMs: 0, artistId: '100'),
        ]),
        playlists: collect([
            new Playlist(id: '4242', title: 'Bon Voyage', trackCount: 18, durationMs: 4200000, thumb: '/t/4242', playlistType: 'audio'),
        ]),
    );
}

it('shows the search prompt when the query is empty', function () {
    Livewire::test('pages::search')
        ->assertSee('Search your library')
        ->assertSee('Find any song, artist, or album from your Plex server.');
});

it('renders the results grouped by type with filter pills', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->assertSee('Playlists')
        ->assertSee('Albums')
        ->assertSee('Artists')
        ->assertSee('Tracks')
        ->assertSee('Bon Voyage')
        ->assertSee('For Emma, Forever Ago')
        ->assertSee('Bon Iver')
        ->assertSee('Holocene');
});

it('shows a no-results message when the search returns nothing', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('searchAll')->andReturn(SearchResults::empty());

    Livewire::test('pages::search', ['q' => 'zzzzz'])
        ->assertSee('No results found for "zzzzz"');
});

it('shows the Plex-unreachable panel when the search fails', function () {
    $this->mock(PlexClient::class)
        ->shouldReceive('searchAll')->andThrow(new PlexUnreachableException("connection refused"));

    Livewire::test('pages::search', ['q' => 'bon'])
        ->assertSee("Can't reach your Plex server")
        ->assertSee('Retry');
});

it('narrows to a single section when a filter pill is clicked', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->call('setFilter', 'artists')
        ->assertSet('filter', 'artists')
        ->assertSee('Bon Iver')
        ->assertDontSee('Holocene')
        ->assertDontSee('Bon Voyage');
});

it('dispatches play-track when a track row is clicked', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->with(\Mockery::on(fn ($t) => $t->id === '9001'))
            ->andReturn('https://server/library/parts/660001/file.flac?X-Plex-Token=t');
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->call('playTrack', '9001')
        ->assertDispatched('play-track',
            url: 'https://server/library/parts/660001/file.flac?X-Plex-Token=t',
            title: 'Holocene',
            artist: 'Bon Iver',
            artwork: 'https://thumb/t/9001',
        );
});

it('navigates to the library when an artist row is clicked', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->call('goToArtist', '100')
        ->assertRedirect(route('library', ['artist' => '100']));
});

it('navigates to the library album when an album row is clicked', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->call('goToAlbum', '100', '1001')
        ->assertRedirect(route('library', ['artist' => '100', 'album' => '1001']));
});

it('resets the filter to "all" when the query changes', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('searchAll')->andReturn(sampleResults());
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::search', ['q' => 'bon'])
        ->call('setFilter', 'artists')
        ->assertSet('filter', 'artists')
        ->set('q', 'rad')
        ->assertSet('filter', 'all');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SearchViewTest`
Expected: FAIL — the stub doesn't render the prompt text, has no `searchAll` wiring, no `setFilter` / `playTrack` / `goToArtist` / `goToAlbum` methods.

- [ ] **Step 3: Replace `⚡search.blade.php` with the full component + view**

`resources/views/pages/⚡search.blade.php`:

```blade
<?php

use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $q = '';

    /** all | playlists | albums | artists | tracks */
    public string $filter = 'all';

    public ?string $errorMessage = null;

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    public function mount(?string $q = null): void
    {
        $this->q = (string) $q;
    }

    public function updatedQ(): void
    {
        $this->filter = 'all';
        $this->errorMessage = null;
        unset($this->results);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function showSection(string $key): bool
    {
        return $this->filter === 'all' || $this->filter === $key;
    }

    public function playTrack(string $trackId): void
    {
        $track = $this->results?->tracks->firstWhere('id', $trackId);

        if (! $track) {
            return;
        }

        $this->dispatch('play-track',
            url: $this->plex->streamUrl($track),
            title: $track->title,
            artist: $track->artist,
            artwork: $this->thumbFor($track->thumb),
        );
    }

    public function goToArtist(string $artistId): void
    {
        $this->redirectRoute('library', ['artist' => $artistId], navigate: true);
    }

    public function goToAlbum(?string $artistId, string $albumId): void
    {
        $this->redirectRoute('library', array_filter([
            'artist' => $artistId,
            'album' => $albumId,
        ]), navigate: true);
    }

    public function retry(): void
    {
        $this->errorMessage = null;
        unset($this->results);
    }

    #[Computed]
    public function results(): ?SearchResults
    {
        if (trim($this->q) === '') {
            return null;
        }

        try {
            return $this->plex->searchAll($this->q);
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. " . $e->getMessage();

            return null;
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
};
?>

<div class="h-full flex flex-col">
@if ($errorMessage)
    <div class="flex-1 grid place-items-center p-12 text-center">
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
@elseif (trim($q) === '')
    <div class="flex-1 grid place-items-center text-center px-6">
        <div>
            <div class="text-[20px] font-bold text-white mb-1">Search your library</div>
            <div class="text-text-2 text-[14px]">Find any song, artist, or album from your Plex server.</div>
        </div>
    </div>
@else
    <div class="flex-1 overflow-y-auto scroll px-6 pb-6">
        {{-- Filter tab pills (sticky) --}}
        <div class="sticky top-0 z-10 bg-base/95 backdrop-blur-sm pt-4 pb-3 -mx-6 px-6 flex items-center gap-2 flex-wrap">
            @php($pills = ['all' => 'All', 'playlists' => 'Playlists', 'albums' => 'Albums', 'artists' => 'Artists', 'tracks' => 'Tracks'])
            @foreach ($pills as $key => $label)
                @php($count = match ($key) {
                    'all' => $this->results->total(),
                    'playlists' => $this->results->playlists->count(),
                    'albums' => $this->results->albums->count(),
                    'artists' => $this->results->artists->count(),
                    'tracks' => $this->results->tracks->count(),
                })
                <button type="button" wire:click="setFilter('{{ $key }}')"
                        @class([
                            'px-3.5 py-1.5 rounded-full text-[13px] font-semibold transition-colors whitespace-nowrap',
                            'bg-white text-black' => $filter === $key,
                            'bg-surface-2 hover:bg-surface-3 text-white' => $filter !== $key,
                        ])>
                    {{ $label }}<span @class(['ml-1.5', 'text-black/50' => $filter === $key, 'text-text-2' => $filter !== $key])>{{ $count }}</span>
                </button>
            @endforeach
        </div>

        @if ($this->results->isEmpty())
            <div class="pt-16 text-center">
                <div class="text-[20px] font-bold text-white mb-2">No results found for "{{ $q }}"</div>
                <div class="text-text-2 text-[14px]">Please make sure your words are spelled correctly, or use fewer or different keywords.</div>
            </div>
        @else
            <div class="flex flex-col">
                {{-- Playlists (display-only in M3) --}}
                @if ($this->showSection('playlists') && $this->results->playlists->isNotEmpty())
                    <section>
                        @if ($filter === 'all')
                            <div class="flex items-center gap-2 px-3 pt-5 pb-2">
                                <h2 class="text-[15px] font-bold uppercase tracking-wider text-text-2">Playlists</h2>
                                <span class="text-[12px] text-text-3">{{ $this->results->playlists->count() }}</span>
                            </div>
                        @endif
                        <div class="flex flex-col">
                            @foreach ($this->results->playlists as $playlist)
                                <div wire:key="search-pl-{{ $playlist->id }}"
                                     class="group w-full grid items-center gap-3 px-3 py-2 rounded text-left"
                                     style="grid-template-columns: 44px 1fr auto;">
                                    @if ($playlist->thumb)
                                        <img src="{{ $this->thumbFor($playlist->thumb) }}" alt="{{ $playlist->title }}"
                                             class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 44px; height: 44px;" loading="lazy">
                                    @else
                                        <div class="rounded-sm flex-none bg-surface-2 grid place-items-center" style="width: 44px; height: 44px;">
                                            <x-lucide-list-music class="w-4 h-4 text-text-3" />
                                        </div>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="truncate text-[14px] font-medium text-white">{{ $playlist->title }}</div>
                                        <div class="truncate text-[12px] text-text-2">Playlist · {{ $playlist->trackCount }} songs</div>
                                    </div>
                                    <span></span>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Albums --}}
                @if ($this->showSection('albums') && $this->results->albums->isNotEmpty())
                    <section>
                        @if ($filter === 'all')
                            <div class="flex items-center gap-2 px-3 pt-5 pb-2">
                                <h2 class="text-[15px] font-bold uppercase tracking-wider text-text-2">Albums</h2>
                                <span class="text-[12px] text-text-3">{{ $this->results->albums->count() }}</span>
                            </div>
                        @endif
                        <div class="flex flex-col">
                            @foreach ($this->results->albums as $album)
                                <button type="button" wire:key="search-al-{{ $album->id }}"
                                        wire:click="goToAlbum('{{ $album->artistId }}', '{{ $album->id }}')"
                                        class="group w-full grid items-center gap-3 px-3 py-2 rounded hover:bg-white/[0.06] transition-colors text-left"
                                        style="grid-template-columns: 44px 1fr auto;">
                                    @if ($album->thumb)
                                        <img src="{{ $this->thumbFor($album->thumb) }}" alt="{{ $album->title }}"
                                             class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 44px; height: 44px;" loading="lazy">
                                    @else
                                        <div class="rounded-sm flex-none bg-surface-2 grid place-items-center" style="width: 44px; height: 44px;">
                                            <x-lucide-disc class="w-4 h-4 text-text-3" />
                                        </div>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="truncate text-[14px] font-medium text-white">{{ $album->title }}</div>
                                        <div class="truncate text-[12px] text-text-2">
                                            Album@if ($album->year) · {{ $album->year }}@endif@if ($album->artist) · {{ $album->artist }}@endif
                                        </div>
                                    </div>
                                    <span></span>
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Artists --}}
                @if ($this->showSection('artists') && $this->results->artists->isNotEmpty())
                    <section>
                        @if ($filter === 'all')
                            <div class="flex items-center gap-2 px-3 pt-5 pb-2">
                                <h2 class="text-[15px] font-bold uppercase tracking-wider text-text-2">Artists</h2>
                                <span class="text-[12px] text-text-3">{{ $this->results->artists->count() }}</span>
                            </div>
                        @endif
                        <div class="flex flex-col">
                            @foreach ($this->results->artists as $artist)
                                <button type="button" wire:key="search-ar-{{ $artist->id }}"
                                        wire:click="goToArtist('{{ $artist->id }}')"
                                        class="group w-full grid items-center gap-3 px-3 py-2 rounded hover:bg-white/[0.06] transition-colors text-left"
                                        style="grid-template-columns: 44px 1fr auto;">
                                    @if ($artist->thumb)
                                        <img src="{{ $this->thumbFor($artist->thumb) }}" alt="{{ $artist->name }}"
                                             class="rounded-full flex-none bg-surface-2 object-cover" style="width: 44px; height: 44px;" loading="lazy">
                                    @else
                                        <div class="rounded-full flex-none bg-surface-2 grid place-items-center" style="width: 44px; height: 44px;">
                                            <x-lucide-user class="w-4 h-4 text-text-3" />
                                        </div>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="truncate text-[14px] font-medium text-white">{{ $artist->name }}</div>
                                        <div class="truncate text-[12px] text-text-2">
                                            Artist@if ($artist->albumCount) · {{ $artist->albumCount }} albums@endif
                                        </div>
                                    </div>
                                    <span class="text-[12px] text-text-3 opacity-0 group-hover:opacity-100">&#8617;</span>
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Tracks --}}
                @if ($this->showSection('tracks') && $this->results->tracks->isNotEmpty())
                    <section>
                        @if ($filter === 'all')
                            <div class="flex items-center gap-2 px-3 pt-5 pb-2">
                                <h2 class="text-[15px] font-bold uppercase tracking-wider text-text-2">Tracks</h2>
                                <span class="text-[12px] text-text-3">{{ $this->results->tracks->count() }}</span>
                            </div>
                        @endif
                        <div class="flex flex-col">
                            @foreach ($this->results->tracks as $track)
                                <button type="button" wire:key="search-tr-{{ $track->id }}"
                                        wire:click="playTrack('{{ $track->id }}')"
                                        class="group w-full grid items-center gap-3 px-3 py-2 rounded hover:bg-white/[0.06] transition-colors text-left"
                                        style="grid-template-columns: 44px 1fr auto;">
                                    @if ($track->thumb)
                                        <img src="{{ $this->thumbFor($track->thumb) }}" alt="{{ $track->title }}"
                                             class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 44px; height: 44px;" loading="lazy">
                                    @else
                                        <div class="rounded-sm flex-none bg-surface-2 grid place-items-center" style="width: 44px; height: 44px;">
                                            <x-lucide-disc class="w-4 h-4 text-text-3" />
                                        </div>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="truncate text-[14px] font-medium text-white">{{ $track->title }}</div>
                                        <div class="truncate text-[12px] text-text-2">{{ $track->artist }}@if ($track->album) · {{ $track->album }}@endif</div>
                                    </div>
                                    <span class="text-[12px] tabular-nums text-text-3">{{ $this->formatMs($track->durationMs) }}</span>
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>
        @endif
    </div>
@endif
</div>
```

Notes for the implementer:
- One stable root `<div class="h-full flex flex-col">` with all states as `@if/@elseif/@else` branches inside it — required by the Livewire-4 single-root morph gotcha (see CLAUDE.md).
- The component reads `?q=` via the `mount(?string $q = null)` parameter (Livewire fills it from the query string). It is *not* a `#[Url]` property — search state is seeded once per navigation; the topbar owns the live input.
- `$this->results` is a `#[Computed]` so it's evaluated at most once per request; `unset($this->results)` busts it after `retry`/`updatedQ`.
- If `<x-lucide-list-music>` is not available in `mallardduck/blade-lucide-icons`, use `<x-lucide-music>` instead (run `php artisan blade-icons:cache` is not needed; just confirm the icon exists). The `&#8617;` is the prototype's literal "↵" hover hint on clickable rows, not an icon substitution.
- The sticky pill bar uses `bg-base/95` (the page background) because the prototype's `bg-surface-0` is not a token in this project.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SearchViewTest`
Expected: PASS (all 9 cases).

- [ ] **Step 5: Manual sanity check**

```bash
npm run build
```

Open `https://plexify.test/search?q=the` in a browser. Confirm: filter pills with counts render; sections appear; clicking a pill narrows to one section; clicking a track plays it (audio); clicking an artist navigates to `/?artist=...` and the library opens with that artist preselected; the topbar input still shows `the`. Then open `https://plexify.test/search` (no query) and confirm the "Search your library" prompt. Type a nonsense string in the topbar and confirm the "No results found" message.

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/⚡search.blade.php tests/Feature/SearchViewTest.php
git commit -m "feat: search view with filter pills and grouped Plex results"
```

---

## Task 6: Browser test + fix the stale library-test assertion

**Files:**
- Create: `tests/Browser/SearchTest.php`
- Modify: `tests/Browser/LibraryDrillDownTest.php` (one line)

- [ ] **Step 1: Fix the stale assertion in `LibraryDrillDownTest`**

In `tests/Browser/LibraryDrillDownTest.php`, the "keeps the player alive when navigating between views" test currently does:

```php
    $page->click('Search')
        ->assertUrlIs(url('/search'))
        ->assertSee('Search view stub');
```

Change `->assertSee('Search view stub')` to `->assertSee('Search your library')` (the search page now shows the prompt when no query is present).

- [ ] **Step 2: Write the browser test**

`tests/Browser/SearchTest.php`:

```php
<?php

/*
 * End-to-end coverage for the topbar -> /search?q= flow and the search results view.
 * Hits the live Plex library configured via PLEX_TOKEN in .env; if Plex is unreachable
 * (or the library has nothing matching "the"), the result-dependent assertions fail —
 * that is an environmental dependency, not a code bug.
 *
 * As with LibraryDrillDownTest, list interactions are driven via script() (page.evaluate)
 * because the Pest browser plugin's click()/assertVisible() are strict and non-waiting.
 */

it('navigates to /search when typing in the topbar and renders grouped results', function () {
    $page = visit('/');

    // Type into the persistent topbar search input; wire:model.live.debounce.300ms +
    // updatedQuery() then redirect(navigate: true) to /search?q=the.
    $page->fill('input[placeholder="What do you want to play?"]', 'the');

    $arrived = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                if (location.pathname === '/search' && new URLSearchParams(location.search).get('q') === 'the') {
                    // wait for at least one result row to render
                    if (document.querySelectorAll('section button, section > div > div > div').length > 0) return true;
                }
                await sleep(150);
            }
            return false;
        })()
    JS);

    expect($arrived)->toBeTrue('Expected to land on /search?q=the with results rendered.');

    // The filter pills are present.
    $page->assertSee('All')->assertSee('Artists')->assertSee('Tracks');
});

it('plays a track from the search results', function () {
    $page = visit('/search?q=the');

    $nowPlaying = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            // Switch to the Tracks filter so only track rows are present.
            const deadline1 = Date.now() + 8000;
            let pill = null;
            while (Date.now() < deadline1) {
                pill = [...document.querySelectorAll('button[wire\\:click="setFilter(\'tracks\')"]')][0];
                if (pill) break;
                await sleep(150);
            }
            if (!pill) return '';
            pill.click();

            // Wait for a track row (button with wire:click^="playTrack") and click it.
            const deadline2 = Date.now() + 8000;
            let row = null;
            while (Date.now() < deadline2) {
                row = document.querySelector('button[wire\\:click^="playTrack"]');
                if (row) break;
                await sleep(150);
            }
            if (!row) return '';
            row.click();

            const deadline3 = Date.now() + 8000;
            while (Date.now() < deadline3) {
                const el = document.querySelector('[data-region=now-playing-title]');
                const text = el ? el.textContent.trim() : '';
                if (text !== '') return text;
                await sleep(100);
            }
            return '';
        })()
    JS);

    expect($nowPlaying)->not->toBe('', 'Expected the player to show a track title after clicking a search result.');
    $page->assertVisible('[data-region=now-playing-title]');
});
```

Notes for the implementer:
- The `[data-region=now-playing-title]` selector is the one `LibraryDrillDownTest` already relies on in the persistent player — reuse it as-is.
- If `visit('/search?q=the')` against the live library yields zero tracks, the `setFilter('tracks')` pill still renders (with count 0) but there will be no `playTrack` row; the test then fails the `not->toBe('')` expectation. That's the documented environmental dependency. If "the" turns out to be a poor query for the maintainer's library, swap it for a term that matches (e.g. an artist name known to be present), and keep the same shape.

- [ ] **Step 3: Run the browser tests**

Run: `php artisan test tests/Browser/SearchTest.php tests/Browser/LibraryDrillDownTest.php`
Expected: PASS (requires the live Plex server to be reachable).

- [ ] **Step 4: Commit**

```bash
git add tests/Browser/SearchTest.php tests/Browser/LibraryDrillDownTest.php
git commit -m "test: browser coverage for search navigation and click-to-play"
```

---

## Task 7: Full suite + manual smoke checklist

**Files:** none (verification only).

- [ ] **Step 1: Run the entire test suite**

Run: `php artisan test`
Expected: PASS — all suites green (Unit, Feature, Browser). The count should be the previous 47 plus the new cases (Task 1: ~8 DTO cases; Task 2: ~6 PlexClient cases; Task 3: 2 topbar cases; Task 4: 3 library cases; Task 5: 9 search cases; Task 6: 2 browser cases). If the browser tests fail, confirm the Plex server is reachable before treating it as a code defect.

- [ ] **Step 2: Manual smoke checklist (`https://plexify.test`)**

- [ ] On the library page, type a query in the topbar → URL becomes `/search?q=...`, results render, topbar still shows what you typed.
- [ ] Filter pills show counts; clicking "Tracks" shows only the Tracks list (no section header), clicking "All" restores all sections with headers.
- [ ] Click a track row → audio plays; the player shows the title/artist.
- [ ] Navigate to another view and back — audio keeps playing (persistent player still works).
- [ ] Click an artist row → lands on `/?artist=...`, the library opens with that artist preselected and its albums listed.
- [ ] Click an album row → lands on `/?artist=...&album=...`, the library opens with that album's header and tracklist.
- [ ] Playlist rows render with "Playlist · N songs" and are not clickable (no hover highlight) — expected for M3.
- [ ] Clear the topbar input → `/search` with the "Search your library" prompt.
- [ ] Type a nonsense string → "No results found for ..." with all pill counts at 0.
- [ ] Refresh on `/search?q=something` directly → results render (deep-link works).

- [ ] **Step 3: Update the milestone status in `CLAUDE.md`**

In `CLAUDE.md`, change the Milestone 3 row in the status table from `Next` to `Done`, and update Milestone 4's status note / the "as of end of M2" test count line if appropriate.

```bash
git add CLAUDE.md
git commit -m "docs: mark milestone 3 (search) done"
```

---

## Out of scope for M3 (deferred)

- The `SearchEmpty` "Browse all" genre grid (the prototype shows it as an alternative empty state; we use the simple centered prompt instead).
- Playlist click-through to a playlist detail view — that's M4 (`/playlist/{id}`); M3 renders playlists as display-only rows.
- Eliminating the per-debounced-keystroke browser-history entry the topbar redirect creates — M7 polish.
- Caching search results — the spec says search is uncached; revisit only if keystroke load becomes a problem.
- Showing "{N} tracks" on artist rows and a duration on album rows — `/hubs/search` doesn't return those for artist/album hits (same gap M2 hit), so they're omitted gracefully.
- Hour handling in `formatMs` — M7.
