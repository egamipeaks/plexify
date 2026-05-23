# Plextune Favorites (heart = 5 stars)

Date: 2026-05-23
Branch (proposed): `feat/favorites`

## Goal

Let the user "heart" tracks. A hearted track is stored as a Plex 5-star
rating (Plex's 0-10 scale, so 10). Un-hearting clears the rating
(back to 0). A new "Favorites" entry in the sidebar opens a page that
lists every 5-star track in the library, sorted by most recently
rated first.

## Behaviour

### Heart toggle

- A heart icon appears in three places.
  - Each track row in the **library album tracklist** (both density variants).
  - Each track row in the **playlist detail tracklist** (both density variants).
  - The **now-playing strip** in the persistent player, next to the
    track title (replacing the prototype's static heart slot).
- The heart is filled (`x-lucide-heart` with `fill-current text-accent`)
  when the track is currently 5-star, outline otherwise.
- Clicking the heart toggles state.
  - Off to on: PUT rating 10.
  - On to off: PUT rating 0 (clear).
- The toggle is **optimistic**. The icon flips immediately on the
  client. If the Plex call fails we revert the icon and dispatch a
  `notify` error toast using the existing toast component.
- Hover behaviour matches the existing track row pattern. The heart
  is dimmed when not hovered and not filled; it stays visible (filled
  accent) on hovered or already-favorited rows. The now-playing
  heart is always visible.

### Heart state syncing

State lives in two places client-side.

1. The server-rendered row reflects the rating that was on the `Track`
   DTO at render time.
2. A new Alpine store `$store.favorites` holds the set of ratingKeys
   the user has toggled in this session (plus their current intended
   state), so the icon stays consistent across pages without a full
   reload. Same registration pattern as `$store.player` (in the
   layout `<head>` under `alpine:init`).

The row's `:class` reads the store first, falling back to the server
value. The now-playing heart binds the same way, keyed off
`$store.player.currentId`.

### Favorites page

- Route `/favorites`, named `favorites`, page component
  `pages::favorites` at `resources/views/pages/⚡favorites.blade.php`.
- Plain tracklist, no gradient header. Page renders just a small
  page title ("Favorites"), a track count, and the library-style
  tracklist (`#`, title, album, duration), reusing the
  `playlist-detail` row markup so the existing playing-row
  highlight, artist/album links, density toggle, and DnD source
  behaviour all carry over. Reorder DnD is **not** wired here (no
  underlying position to persist; see Out of scope).
- Sort: most recently rated first. Plex exposes `lastRatedAt` on
  rated rows; we sort server-side via Plex's query string.
- Empty state: a centered "No favorites yet" panel matching the
  existing empty-state styles.
- The page uses the same context tagging as playlist detail.
  Playing a row dispatches `play-track` with `contextType:
  'favorites'`, `contextId: null`. The animated `.eq` indicator and
  the sidebar source dot light up only when the player's context is
  favorites.

### Sidebar entry

- New "Favorites" link sits directly above "Your Library" in the
  sidebar, alongside the existing "Generate" link (same row style,
  `x-lucide-heart` icon). Active state when on `/favorites`. The
  link is **draggable: false** and not a drop target.

### Optimistic counter

When the favorites page is mounted, we fetch the full list once.
Hearting or unhearting a track elsewhere does **not** live-update
the page's row list (the next visit refetches). This keeps the
contract simple. The store's session set is enough to keep the
heart icons themselves consistent.

## Plex integration

### Rating endpoint

```
PUT /:/rating?key={ratingKey}&identifier=com.plexapp.plugins.library&rating={0|10}
```

The X-Plex token is appended per existing convention.

### Listing favorites

```
GET /library/sections/{musicSectionId}/all
    ?type=10
    &userRating=10
    &sort=lastRatedAt:desc
    &X-Plex-Container-Size={limit}
```

`type=10` is Plex's track type. We cap at 1000 rows for the first
cut, mirroring the recently-played pattern.

### Cache

- `PlexCache::TTL_FAVORITES = 300` (5 min).
- Cache key `plex:favorites:{limit}`. Busted by `rateTrack()`.
- `flushAll()` already wipes everything indexed under `plex:*`, no
  change there.

### `PlexClient` additions

- `rateTrack(string $ratingKey, int $rating): void` — validates
  `$rating` is 0 or 10 (we only use the two endpoints), PUTs, throws
  the usual `PlexException` subclasses on failure, busts
  `plex:favorites:*`.
- `favoriteTracks(int $limit = 1000): Collection<int, Track>` —
  hits the listing endpoint above, cached, returns Track DTOs.

### `Track` DTO additions

- `public readonly int $userRating` (0-10, defaults 0 when Plex
  omits it). Lets each row decide if the heart is filled at render.
- `public readonly ?string $lastRatedAt` (raw Plex timestamp,
  nullable). Not displayed; kept for completeness so the DTO
  matches the row shape Plex returns.

Adding these requires `php artisan cache:clear` per the documented
DTO-stale-cache gotcha. We will mention this in the implementation
plan and the PR description.

`config/cache.php` already allowlists `Track::class`; no change needed.

## Component changes

### New

- `resources/views/pages/⚡favorites.blade.php` — page component
  with `public Collection $tracks`, `mount(PlexClient $plex)`
  fetching favorites, `playTrack($id)` dispatcher, `toggleHeart($id)`
  action (shared with the other heart-bearing components via a small
  trait, see Trait below).
- `resources/views/components/heart-button.blade.php` (plain Blade
  anonymous component, **no `⚡` prefix**) — renders the heart icon
  with the Alpine `:class` binding and `@click.stop` that calls
  `$wire.toggleHeart(id)`. Used by every track row and the
  now-playing strip.

### Modified

- `app/Services/Plex/PlexClient.php` — new methods above.
- `app/Services/Plex/Dto/Track.php` — new fields + `fromPlex`
  parsing.
- `app/Services/Plex/Cache/PlexCache.php` — new TTL constant.
- `resources/views/components/⚡sidebar.blade.php` — Favorites link
  above "Your Library", reusing the Generate link's row markup.
- `resources/views/components/⚡player.blade.php` — heart slot wired
  to `<x-heart-button>` with the now-playing track id.
- `resources/views/pages/⚡library.blade.php` — heart cell in the
  album tracklist (both density variants); new `toggleHeart` action.
- `resources/views/pages/⚡playlist-detail.blade.php` — same. Will
  share the action via a small trait so the three components don't
  diverge.

### Trait

`App\Livewire\Concerns\TogglesFavorite` — one public method
`toggleHeart(string $ratingKey, int $currentRating): void` that
inverts the rating and calls `PlexClient::rateTrack`. On failure
dispatches `notify`. The trait is used by the player component,
library page, playlist-detail page, and favorites page. Other
surfaces (search, recently-played) can adopt it later. The trait owns the `protected PlexClient $plex`
resolution via `boot`.

### Routes

`routes/web.php` gets `Route::livewire('/favorites',
'pages::favorites')->name('favorites');`.

## Configuration

None. No new env vars.

## Tests

### Unit

- `tests/Unit/PlexClientTest.php` (extended) — `rateTrack` builds
  the correct URL with both rating=10 and rating=0; throws
  `PlexUnreachableException` on connection failure; busts
  `plex:favorites:*` cache. `favoriteTracks` builds the listing
  URL with the right query string, returns Track DTOs sorted as
  Plex returned them, respects the cache, returns an empty
  collection on a Plex 404.
- `tests/Unit/PlexDtoTest.php` (extended) — `Track::fromPlex`
  reads `userRating` and `lastRatedAt`; defaults to 0 / null when
  absent.

### Feature

- `tests/Feature/FavoritesPageTest.php` (new) — mounts the page
  with a mocked `PlexClient::favoriteTracks` and asserts row count,
  empty state, and that `toggleHeart` calls `rateTrack` with the
  inverted rating. Asserts a Plex failure dispatches a `notify`
  toast and does not throw.
- `tests/Feature/LibraryViewTest.php` (extended) — heart cell
  renders filled when `userRating === 10`, outline otherwise.
  `toggleHeart` action on the page works the same as the trait
  expects.
- `tests/Feature/PlaylistDetailViewTest.php` (extended) — same as
  above.
- `tests/Feature/PlayerComponentTest.php` (extended) — heart in
  the player wires to the trait.
- `tests/Feature/SidebarTest.php` (extended) — Favorites link is
  present, marked active on `/favorites`.

### Browser

- `tests/Browser/FavoritesTest.php` (new, hits live Plex) — visits
  `/favorites`, asserts the page loads and either the empty state
  or at least one row is shown; if a row is shown, click the
  heart, assert it un-fills, click again, assert it re-fills (and
  the underlying rating returns to 10 via a fresh ping).

All feature tests that render the layout already mock `ping()` and
`searchAll()`; the layout's sidebar now also renders the Favorites
link, but it doesn't need new mocking (no extra Plex call).

## Out of scope (deferred)

- Heart icon in the **search Songs group** and the
  **recently-played list**. The trait makes this trivial to add
  later but we'll ship the four primary surfaces first.
- Star ratings beyond the binary heart (1-4 stars). Plex supports
  the full scale; we choose not to surface it.
- DnD reordering inside the Favorites page. There's no app-side
  position to persist and `lastRatedAt` is the sort.
- Live cross-page sync of the favorites list itself (the row list
  on the page is fetched once at mount).

## Gotchas to honor

- New `Track` fields => `php artisan cache:clear` once after merge
  (per the cached-DTO gotcha).
- `$store.favorites` must be registered in the layout `<head>`'s
  `alpine:init` listener, not via `@script`, same as `$store.player`.
- `play-track` payload gains no new fields; the heart state is
  read from the store on hover/click, not from the queue item.
- Each row's heart click must be `@click.stop` so the row's
  `wire:click="playTrack"` doesn't also fire.
- The favorites page renders the layout (which renders the
  sidebar, which calls `ping()` and reads `playlists()`), so its
  feature test must mock `ping()` and `playlists()` like the other
  page tests do.
