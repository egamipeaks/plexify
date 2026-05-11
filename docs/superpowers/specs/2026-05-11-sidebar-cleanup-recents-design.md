# Sidebar Cleanup and Recently Added / Recently Played Design

**Date:** 2026-05-11
**Status:** Approved
**Parent spec:** `docs/superpowers/specs/2026-05-09-plextune-design.md`
**Visual reference:** the existing miller-column library and the search-page album rows in `design/rendered/your-library.html` and `design/rendered/new-search.html`. There is no dedicated prototype for the Recently Added or Recently Played views; the album grid follows the search-page album-card style and the tracklist follows the playlist-detail tracklist.

## Goal

Tighten the sidebar's top nav, stop polluting the playlists list with Plex's auto-generated smart playlists, and give the existing Recently Added nav item (plus a new Recently Played nav item) actual destinations.

End state: three top-level nav items (Your Library, Recently Added, Recently Played), each routes to a real view. The playlists card lists only user playlists, and the smart playlists Plex auto-creates (All Music, Fresh, Recently Added, Recently Played) are hidden everywhere in the app.

## Decisions

| Decision | Choice | Notes |
|---|---|---|
| Smart playlist visibility | Filter out app-wide | Removed from `PlexClient::playlists()` and from `PlexClient::searchAll()`'s playlist hub. Search for "fresh" no longer surfaces the smart Fresh playlist. |
| Home vs Your Library nav item | Keep Your Library | Drop the Home item entirely. The view itself is unchanged; just the nav label and item count change. |
| Search nav item | Drop | The topbar's search input is the canonical entry point. The sidebar's Search nav item is redundant. |
| Recently Added layout | Album cover grid | Wrapping responsive grid (`grid grid-cols-[repeat(auto-fill,minmax(180px,1fr))] gap-4`) of cards with cover, title, artist, year. Clicking a card navigates to the library page with that album selected via the existing `?artist=X&album=Y` URL props. |
| Recently Played layout | Flat tracklist | Single-column tracklist (same column shape as the playlist-detail page). Clicking a row dispatches `play-track`. |
| Recently Played source | `/library/sections/{id}/all?type=10&sort=lastViewedAt:desc` then filter in PHP to rows with non-zero `lastViewedAt` | Library state, not `/status/sessions/history`. Simpler, no auth quirks, and avoids the awkward `viewCount>=1` URL parameter (Plex accepts it but Laravel's `Http::get($url, $params)` URL-encodes the `>=` and Plex's parser is finicky about it). Plex's `:desc` sort puts null `lastViewedAt` values at the bottom, so requesting `X-Plex-Container-Size: 200` and slicing to the first 50 with a non-zero `lastViewedAt` is reliable. Caveat: our player does not scrobble plays back to Plex yet, so `lastViewedAt` reflects plays made via other Plex clients (web/mobile) only. Adding a scrobble call is an M7 polish item; for now, the view is still useful for jumping back to whatever you were listening to in another client. |
| Container size | 50 fixed | Both recents views fetch up to 50 items. No pagination, no "Load more" in this scope. |
| Caching | 5 minutes (`TTL_PLAYLISTS`) | Same TTL as playlists. Cache keys: `plex:recently_added:{limit}`, `plex:recently_played:{limit}`. |

## Data Model

No schema changes. The Plex API does the heavy lifting; nothing is persisted.

`Playlist` DTO gains one new public readonly field:

```php
public bool $smart,
```

`Playlist::fromPlex` reads `(bool) ($row['smart'] ?? false)`. Plex's JSON returns `smart` as `false`/`true` for most playlists; some older PMS versions return `0`/`1`, and a missing field is treated as not-smart.

## `PlexClient` Changes

`playlists(): Collection<Playlist>` now ends with `->reject(fn (Playlist $p) => $p->smart)->values()` before returning. The cache key (`plex:playlists`) and the cached collection both store the already-filtered list.

`searchAll($query): SearchResults` applies the same `->reject($p->smart)` to the playlists hub before returning.

Two new public methods:

```php
public function recentlyAddedAlbums(int $limit = 50): Collection
{
    return $this->cache->remember("recently_added:{$limit}", PlexCache::TTL_PLAYLISTS, function () use ($limit) {
        $sectionId = $this->musicSectionId();
        $response = $this->server()->withHeader('X-Plex-Container-Size', (string) $limit)
            ->get("/library/sections/{$sectionId}/recentlyAdded", ['type' => 9]);

        $this->ensureOk($response, "library/sections/{$sectionId}/recentlyAdded");

        return collect(data_get($response->json(), 'MediaContainer.Metadata', []))
            ->map(fn (array $row) => Album::fromPlex($row))
            ->values();
    });
}

public function recentlyPlayedTracks(int $limit = 50): Collection
{
    return $this->cache->remember("recently_played:{$limit}", PlexCache::TTL_PLAYLISTS, function () use ($limit) {
        $sectionId = $this->musicSectionId();
        // Ask for 4x the desired count so we can slice past any unplayed rows
        // that Plex's :desc sort may have allowed through.
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

The `Http::withHeader('X-Plex-Container-Size', ...)` call adds the paging header on top of the base `server()` PendingRequest. Exception mapping reuses the existing `ensureOk()` helper. Both methods bust nothing on success because they are read-only.

Note: a successful playlist mutation (`addTrack...`, `createPlaylist`, etc.) does not bust the recents caches. A new album shown in Recently Added flows in on the next 5-minute window. This is acceptable for an M7-deferred staleness concern; the existing "Resync metadata" Settings button (M6) will forget all `plex:*` keys.

## Sidebar Top Nav

The top nav card in `resources/views/components/⚡sidebar.blade.php` currently has four rows: Home, Search, Your Library, Recently Added. The new version has three: Your Library, Recently Added, Recently Played.

Each row uses the existing `<a wire:navigate ... @class([... 'bg-surface-2' => request()->routeIs('routeName')])>` pattern. Routes used:

- Your Library: `route('library')`, active on `library`.
- Recently Added: `route('recentlyAdded')`, active on `recentlyAdded`.
- Recently Played: `route('recentlyPlayed')`, active on `recentlyPlayed`.

Icons (from `mallardduck/blade-lucide-icons`):

- Your Library: `<x-lucide-library />` (unchanged).
- Recently Added: `<x-lucide-clock />` (unchanged).
- Recently Played: `<x-lucide-history />` (new; distinct from Recently Added so the icons read differently at a glance).

The Home and Search items are deleted outright. No tombstones, no fallback.

## New Routes and Pages

`routes/web.php` gains:

```php
Route::livewire('/recently-added',  'pages::recently-added')->name('recentlyAdded');
Route::livewire('/recently-played', 'pages::recently-played')->name('recentlyPlayed');
```

### `pages::recently-added` (`resources/views/pages/⚡recently-added.blade.php`)

```
boot(PlexClient $plex): void                # protected PlexClient $plex
mount(): void                               # calls loadAlbums(); sets $errorMessage on PlexException
retry(): void                               # clear $errorMessage; loadAlbums() again
private loadAlbums(): void                  # try $this->plex->recentlyAddedAlbums(); catch -> $errorMessage
#[Computed] albums(): Collection<Album>     # returns the loaded list or empty
protected thumbFor(?string $thumb): ?string # delegates to PlexClient
```

Single stable root `<div class="h-full flex flex-col">`. Body:

- Error panel branch (`x-lucide-server-off`, "Can't reach your Plex server", retry button) when `$errorMessage`.
- Empty branch ("No recent additions yet") when the loaded list is empty.
- Otherwise a header section (`<h1>Recently Added</h1>`) and a `<div class="grid grid-cols-[repeat(auto-fill,minmax(180px,1fr))] gap-4">` of album cards (one `@include('partials.album-card', ...)` per album).

Album card click: a `<a href="{{ route('library', ['artist' => $album->artistId, 'album' => $album->id]) }}" wire:navigate>` that lands on the library page with the album selected. The library page's `#[Url(as: 'artist')]` and `#[Url(as: 'album')]` props pick it up on mount and trigger the existing `tracksForAlbum` load.

### `pages::recently-played` (`resources/views/pages/⚡recently-played.blade.php`)

```
boot(PlexClient $plex): void
mount(): void                               # calls loadTracks(); sets $errorMessage on PlexException
retry(): void                               # clear $errorMessage; loadTracks() again
playTrack(string $trackId): void            # firstWhere id; dispatch('play-track', url/title/artist/artwork)
private loadTracks(): void                  # try $this->plex->recentlyPlayedTracks(); catch -> $errorMessage
#[Computed] tracks(): Collection<Track>
protected thumbFor(?string $thumb): ?string
protected formatMs(int $ms): string         # standard m:ss formatter
```

Body branches mirror the Recently Added page (error / empty / list). The tracklist markup is copied from `pages::playlist-detail` (sticky header, `grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px`, grip / # / title-with-art / album / heart / time). Track rows are draggable (M5 pattern: `draggable="true" ondragstart="event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"`).

Empty state copy: "Nothing played yet." with an explanatory line that play history from other Plex clients shows up here too.

## Reusable Album Card Partial

`resources/views/partials/album-card.blade.php` expects `$album` (Album DTO) and `$thumbUrl` (pre-resolved). It renders:

```blade
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

The same broken-image fallback pattern from the M5 sidebar (placeholder behind, `<img onerror>` removes itself).

Opportunistic cleanup: `pages::search`'s "Albums" row currently inlines an album card. Refactor it to `@include('partials.album-card', ...)`. The search album row uses a horizontal flex strip (`flex gap-2 overflow-x-auto`), not a grid; the partial works inside either container because it owns its own card structure (`flex flex-col gap-2 ...`). Width comes from the parent grid/strip's column track / `flex-none w-44` wrapper. To keep the search row's existing fixed-width cards, wrap the include in `<div class="flex-none w-44">` inside the search loop.

If the search card's existing visual style differs in a way that breaks fidelity when swapped, leave the search markup alone and use the partial only in Recently Added. The refactor is a nice-to-have, not a requirement.

## Sidebar Test Updates

`tests/Feature/SidebarTest.php` adjusts the existing render assertions:

- `assertDontSee('Home')` (the nav item label is gone; "HomeServer" in the server chip stays, so `assertDontSeeHtml` against the nav link's `wire:navigate` route is the safer check: `assertDontSeeHtml('href="'.route('library').'" wire:navigate>\n')` is brittle, so instead assert the nav region only contains three nav anchors).
- `assertDontSee('Search')` (likewise; topbar search input is not in this component).
- `assertSeeHtml('href="'.route('recentlyAdded').'"')`.
- `assertSeeHtml('href="'.route('recentlyPlayed').'"')`.

Pragmatic version: drop a structural assertion that the top nav card has exactly three `<a>` elements:

```php
$rendered = Livewire::test('sidebar')->html();
preg_match_all('/<a [^>]*wire:navigate/', explode('Your Playlists', $rendered, 2)[0], $m);
expect($m[0])->toHaveCount(3);
```

Cruder but robust against icon / class churn.

## Test Plan

### Unit

`tests/Unit/PlexDtoTest.php`:
- `Playlist::fromPlex` sets `smart=true` for `"smart": true`, `smart=true` for `"smart": 1`, `smart=false` for `"smart": false` / missing.

`tests/Unit/PlexClientTest.php`:
- `playlists()` returns only the non-smart entries from a fixture containing both.
- `searchAll()` returns only non-smart playlists in the playlists hub.
- `recentlyAddedAlbums()` hits `/library/sections/{id}/recentlyAdded?type=9`, sends the `X-Plex-Container-Size` header, maps to `Album` DTOs, caches under the right key, maps 404 -> `PlexNotFoundException`, 500 -> `PlexUnreachableException`.
- `recentlyPlayedTracks()` hits `/library/sections/{id}/all` with `type=10`, `sort=lastViewedAt:desc`, `X-Plex-Container-Size` set to `4 * limit`, filters out rows with `lastViewedAt` missing or zero, slices to `$limit`, maps to `Track` DTOs, caches, error mapping.

### Feature

`tests/Feature/RecentlyAddedTest.php` (new):
- Mocked `PlexClient::recentlyAddedAlbums` returns two albums; the page renders both titles and `wire:key="album-card-{id}"`; the cards link to `route('library', ['artist' => ..., 'album' => ...])`.
- Mocked `recentlyAddedAlbums` throws `PlexUnreachableException`; the page shows the error panel + Retry button; calling `retry` clears the error and re-fetches.
- Mocked `recentlyAddedAlbums` returns an empty collection; the page shows "No recent additions yet".

`tests/Feature/RecentlyPlayedTest.php` (new):
- Mocked `recentlyPlayedTracks` returns two tracks; the page renders both `wire:key="track-{id}"` rows.
- `playTrack` dispatches `play-track` with the right `url`/`title`/`artist`/`artwork`.
- Unreachable -> error panel + Retry.
- Empty -> "Nothing played yet."

`tests/Feature/SidebarTest.php`:
- Adjust render assertions for the new three-item nav (asserts above).

`tests/Feature/RoutesTest.php`:
- Smoke tests for `/recently-added` and `/recently-played` (mocked `PlexClient` returning empty collections; assert 200).

### Browser

None. The existing browser suite already covers the patterns these views reuse (drill-down, player, sidebar). Avoid adding another test that hits live Plex unless something genuinely cannot be tested at the Feature level.

### Fixtures

`tests/Fixtures/Plex/playlists.json`: add a third row with `"smart": true` (e.g. an "All Music" entry) so the smart-filter is exercised.

`tests/Fixtures/Plex/recently_added.json` (new): a `MediaContainer.Metadata[]` of two album rows shaped the way `Album::fromPlex` expects.

`tests/Fixtures/Plex/recently_played.json` (new): a `MediaContainer.Metadata[]` of two track rows shaped the way `Track::fromPlex` expects (full `Media[0].Part[0]`, `grandparentTitle`, etc.).

## Files

**New:**
- `resources/views/pages/⚡recently-added.blade.php`
- `resources/views/pages/⚡recently-played.blade.php`
- `resources/views/partials/album-card.blade.php`
- `tests/Feature/RecentlyAddedTest.php`
- `tests/Feature/RecentlyPlayedTest.php`
- `tests/Fixtures/Plex/recently_added.json`
- `tests/Fixtures/Plex/recently_played.json`

**Modified:**
- `app/Services/Plex/Dto/Playlist.php` (add `smart`)
- `app/Services/Plex/PlexClient.php` (filter, two new methods)
- `resources/views/components/⚡sidebar.blade.php` (top nav, three items)
- `resources/views/pages/⚡search.blade.php` (optional: use `partials.album-card`)
- `routes/web.php` (two routes)
- `tests/Unit/PlexDtoTest.php`, `tests/Unit/PlexClientTest.php`, `tests/Feature/SidebarTest.php`, `tests/Feature/RoutesTest.php`
- `tests/Fixtures/Plex/playlists.json` (add a smart row)
- `CLAUDE.md` (note the smart-playlist filter, the two new routes, the `lastViewedAt`-without-scrobble caveat, updated test count)

**No changes to:** the layout, the player, the playlist-detail page, the library page, the album models, migrations.

## Out of Scope

- Scrobbling our own plays back to Plex (so `lastViewedAt` reflects this app's plays too). Defer to M7.
- Pagination / "Load more" on either recents view. 50 items fixed.
- A separate dashboard view that combines both recents lists. The two views stay independent.
- Smart-playlist read-only browsing (the user wants them gone, not just non-editable).
- Bypassing the `plex:recently_*` caches on playlist mutations. The M6 Settings "Resync metadata" button clears `plex:*` wholesale.
