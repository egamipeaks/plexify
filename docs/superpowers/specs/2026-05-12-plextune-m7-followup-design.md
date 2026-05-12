# Plextune — M7 follow-up design (search rework, per-section compact, media keys, now-playing icon)

Status: approved 2026-05-12. This is a continuation of Milestone 7 (Polish) — it lands on the same `feat/m7-polish` branch before M7 is merged. Implementation plan: `docs/superpowers/plans/2026-05-12-plextune-m7-followup.md` (written next).

These four items came out of the M7 manual-acceptance pass. Two are reworks of things M7 shipped imperfectly (compact density; the sidebar now-playing indicator) and one fixes a pre-existing search-input bug that M7's keyboard work brought into focus; one is new (media keys). They are independent of each other.

## Scope

1. **Search rework** — typing in the topbar search box must not lose the cursor or clobber typed characters, and must show results live in place. Search becomes a "mode" overlaying the main pane; the topbar input is Alpine-owned; no navigation happens during typing.
2. **Per-section compact density** — replace M7's global `data-density` CSS-padding approach with what the design docs actually specify: per-section compact toggles (artists column, albums column, library tracklist, playlist tracklist), wired to the existing placeholder header buttons, each persisted, with the Settings "Density default" as the fallback; and compact being the real reduced layout from `design/rendered/compact.html` / `design/plextune-parts.jsx` / `design/plextune-views.jsx`, not just tighter padding.
3. **Media keys** — wire the browser Media Session API so the OS media keys (on this keyboard: F7 = previous, F8 = play/pause, F9 = next) and the macOS Now Playing widget control playback.
4. **Now-playing source indicator icon** — the sidebar playlist row (and the album/playlist headers) should show a small static green speaker glyph, not the animated equalizer, when that list is the playback source. The animated equalizer stays in the tracklist `#` column (matching Spotify).

### Out of scope

Theme/accent picker, the other never-built Settings sections (Account, Downloads, Library, full Playback panel, Shortcuts cheat-sheet). Anything not in the four items above.

---

## 1. Search rework

### Problem

`/search` is a real route. The topbar search input is `wire:model.live.debounce.300ms="query"` on the `topbar` Livewire component, and `updatedQuery()` does `redirectRoute('search', ['q' => $this->query], navigate: true)`. So every debounced keystroke triggers a full `wire:navigate` to `/search?q=...`, which re-renders the topbar. Two consequences: (a) if a navigation response lands while the user has typed another character, the morph overwrites the typed text with the server-rendered value; (b) the cursor is bumped out of the input on every navigation.

### Approach (based on a proven pattern from another project)

The fix is to make the search **input Alpine-owned** (so Livewire's morph never touches its value), have it **dispatch a browser event** with the term instead of binding to a Livewire prop, and render **results in a separate component** that re-renders itself (not the topbar) — with **no navigation** in the typing loop. Search becomes a mode that overlays the main pane.

#### `topbar` component changes

- Remove `#[Url(as: 'q', except: '')] public string $query` and `updatedQuery()`. The topbar component keeps its non-search bits (back/forward buttons, settings link) and continues to be a Livewire component (it has nothing else to be), but its search input is no longer Livewire-bound.
- The search `<input>` becomes Alpine-owned via a small `topbarSearch()` Alpine component:
  - State: `term` (string).
  - `x-data="topbarSearch(@js(request()->query('q', '')))"` — seeded once on page load from the current `?q=` so a direct visit to `/search?q=foo` shows `foo` in the box. (Use `request()->query('q', '')` since the topbar no longer has a `#[Url]` prop.)
  - `<input id="topbar-search" x-model="term" x-on:input.debounce.300ms="$dispatch('plextune-search', { term })" ...>` — keeps the existing Tailwind classes and the `⌘K` hint badge; keeps `id="topbar-search"` so the keyboard shortcut still focuses it.
  - A clear `×` button shown when `term !== ''`: `@click="term = ''; $refs.searchInput.focus(); $dispatch('plextune-search', { term: '' })"`. Add `x-ref="searchInput"` on the `<input>`. Place the `×` between the input and the `⌘K` hint, or replace the `⌘K` hint with the `×` when `term !== ''` (designer's call in the plan — keep it minimal).
  - Register `topbarSearch` via a `<script>` (use `@once` or an `alpine:init` listener — match how other Alpine data components are registered in this app; `@script` on a Livewire component runs after `alpine:init`, so for a layout-level component an `alpine:init` listener or an `@once` script in the topbar partial is fine).

#### New `search` Livewire component (`resources/views/components/⚡search.blade.php`)

A single-file Livewire component rendered in the layout's main pane, **always present**.

- `#[Url(as: 'q', except: '')] public string $q = '';` — syncs to/from `?q=`. Setting it server-side updates the URL with no navigation.
- `public string $filter = 'all';` — the active result-type pill.
- `#[On('plextune-search')] public function applyTerm(string $term): void { $this->q = trim($term); }` — receives the browser event from the topbar; setting `$q` re-runs `results()` and syncs the URL.
- `public function setFilter(string $filter): void { $this->filter = $filter; }`
- `#[Computed] public function results(): SearchResults` — `app(PlexClient::class)->searchAll($this->q)`, catching `PlexException` → dispatch the M7 `notify` toast and return an empty `SearchResults`. (No more inline `errorMessage` red panel for search; an error toast is enough now that search is an overlay.)
- `playTrack(string $trackId)`, `goToAlbum(?string $artistId, string $albumId)`, `goToArtist(string $artistId)` — moved over from the current `pages::search`. `goToAlbum`/`goToArtist` use `Livewire.navigate('/?artist=…&album=…')` (which drops `?q=` → the overlay closes). `playTrack` dispatches `play-track` with `contextType: 'search', contextId: null` (preserving the M7 context-aware highlight).
- Helpers: `thumbFor(?string $thumb)`, `showSection(string $key)` — moved over.
- **Render:** if `$this->q === ''`, the component's single root element renders empty (e.g. `<div></div>` with no content) so the page underneath shows through. If `$this->q !== ''`, it renders the full search-results UI ported from `design/rendered/new-search.html` (filter tab pills + grouped result lists, with the playing-row highlight on the Songs group per M7), wrapped in an element that covers the main content area — `absolute inset-0 z-30 bg-base overflow-y-auto` inside the `<main>`'s content region (so it sits over `{{ $slot }}` but under the topbar and the player). The Livewire-4 one-stable-root rule applies: the component's root `<div>` is always the same element; the `@if ($q === '')` conditional is *inside* it.

#### Layout changes (`resources/views/components/layouts/app.blade.php`)

The `<main>` currently is:
```html
<main class="flex-1 min-w-0 bg-surface rounded-lg flex flex-col overflow-hidden">
    <livewire:topbar />
    <div class="flex-1 min-h-0 overflow-hidden">
        {{ $slot }}
    </div>
</main>
```
Becomes:
```html
<main class="flex-1 min-w-0 bg-surface rounded-lg flex flex-col overflow-hidden">
    <livewire:topbar />
    <div class="flex-1 min-h-0 overflow-hidden relative">
        {{ $slot }}
        <livewire:search />
    </div>
</main>
```
(`relative` on the content wrapper so the search overlay's `absolute inset-0` is scoped to it.)

#### `/search` route

Keep the route. Its page component (`resources/views/pages/⚡search.blade.php`) becomes a thin stub — a single empty/placeholder root element (e.g. a centered "Search your library" hint, or just an empty `<div class="h-full">`), because the `<livewire:search>` overlay covers it whenever `?q=` is set. (When you visit `/search` with no `?q=`, you see the stub; start typing and the overlay opens over it.) All of `pages::search`'s current logic (the `q`/`errorMessage` props, `results()`, `setFilter`, `playTrack`, `goToAlbum`, `goToArtist`, `formatMs`-already-removed, `showSection`, the template) moves into the new `<livewire:search>` component. Net: the route stays for shareable URLs and as the topbar's conceptual "home"; the component does the work.

#### Tests

- Feature: a `SearchComponentTest` (or rename/repurpose `SearchViewTest`) — `Livewire::test('search')` with `Livewire::withQueryParams(['q' => 'beatles'])` and a mocked `PlexClient::searchAll(...)`: renders the results; `dispatch('plextune-search', term: 'x')` sets `$q` and re-runs `results()`; `setFilter('albums')` works; `playTrack(...)` dispatches `play-track` with `contextType: 'search'`; an empty `$q` renders the empty root (assert it does NOT render the filter pills). The `topbar` component test: it no longer has a `query` prop / no longer dispatches a navigate. `RoutesTest`: `/search` still 200s (the layout now also renders `<livewire:search>` — that component touches `PlexClient::searchAll` only when `?q=` is set, so a bare `/search` is fine; for `/search?q=foo` the test's `PlexClient` mock needs `searchAll` stubbed).
- Browser (`tests/Browser/SearchTest.php`): type into the topbar from the library page → results appear (overlay) without navigating away and the topbar input keeps focus and its full value (`document.activeElement.id === 'topbar-search'` and its `.value` matches what was typed); type a second char after a pause → both chars present (the M7 regression test, kept and adapted); the clear `×` empties the box and the overlay; clicking an album result navigates and closes the overlay; direct visit to `/search?q=foo` shows the overlay with the box pre-filled.

---

## 2. Per-section compact density

### What the design specifies (from `design/README.md` + `design/rendered/compact.html` + `design/plextune-parts.jsx` + `design/plextune-views.jsx`)

- **Per-section toggles**, not one global flag. The README lists state `tracksCompact`, `artistsCompact`, `albumsCompact` ("Persist per-section in user prefs"). The Settings "Density default (Comfortable/Compact)" sets the default a section uses until the user overrides it. We add a fourth: the playlist-detail tracklist.
- The toggle UI already exists as placeholder buttons: in each MillerCol header and each tracklist header there's a `flex items-center bg-surface-2 rounded p-0.5` with two `w-6 h-6 grid place-items-center rounded` buttons — a 4-square (grid-2x2) icon = comfortable and a 3-line (menu) icon = compact. The active one gets `bg-surface-4 text-white`. Currently they do nothing. Wire them.
- **Compact MillerCol:** rows become `px-3 py-[3px] text-[13px]`, `renderItem(it, true)` renders a **single line** (no thumbnail, no metadata sub-line — just the name, truncated). Selected row in compact = `bg-accent/15 text-accent` (vs `bg-surface-3 text-white` in comfortable). The miller-columns block grows to `260px` tall in compact (vs `220px`) per README §3a.
- **Compact tracklist (library album view):** `grid-template-columns: 20px 1.4fr 1fr 50px` (`#` / title / artist / time), `px-3 py-[3px] text-[13px]`, **no cover, no like (♥) column**. The `#` cell still shows the play-state: track number normally, the animated `.eq` equalizer when this row is playing in this context (carry the M7 context-aware highlight). Playing-row title tinted `text-accent`. Sticky header row matches: `#  Title  Artist  ⏱`.
- **Compact tracklist (playlist-detail view):** `grid-template-columns: 20px 1.4fr 1fr 1fr 50px` (`#` / title / artist / album / time) per `PL_COLS_COMPACT` in `plextune-views.jsx` — it keeps the Album column. Same `py-[3px]`, no cover, no like.
- Comfortable layouts stay exactly as they are now (the M7 work for highlight/source-indicator/collapsible-header on the comfortable headers is unchanged).

### Implementation

- **`AppSetting`:** keep `density()`/`setDensity()` as the **default**. Add four typed accessors backed by the `Setting` model, each defaulting to `density() === 'compact'` (so the Settings default seeds them until explicitly set):
  - `artistsCompact(): bool` / `setArtistsCompact(bool)` — key `artists_compact`
  - `albumsCompact(): bool` / `setAlbumsCompact(bool)` — key `albums_compact`
  - `libraryTracksCompact(): bool` / `setLibraryTracksCompact(bool)` — key `library_tracks_compact`
  - `playlistTracksCompact(): bool` / `setPlaylistTracksCompact(bool)` — key `playlist_tracks_compact`
  - Each getter: `Setting::get($key, null)` — if `null` (never set), fall back to `self::density() === self::DENSITY_COMPACT`; otherwise `(bool)` the stored value. (So flipping the Settings default flips all not-yet-overridden sections; once a section is toggled, it sticks.)
- **`pages::library`:** add `public bool $artistsCompact`, `public bool $albumsCompact`, `public bool $tracksCompact` (the album-tracklist one — use `libraryTracksCompact`), initialised in `mount()` from the accessors; `updatedArtistsCompact`/`updatedAlbumsCompact`/`updatedTracksCompact` lifecycle hooks persisting via `setArtistsCompact` etc. The placeholder header buttons get `wire:click="$set('artistsCompact', false)"` / `wire:click="$set('artistsCompact', true)"` (and analogous for albums and the tracklist), with `@class` making the active one `bg-surface-4 text-white`. The artist/album row markup and the tracklist markup branch on the relevant flag: comfortable = current markup; compact = the reduced layout described above. (Use Blade `@if ($artistsCompact) ... @else ... @endif` inside the row loop — server-rendered, re-renders on `$set`, so no Alpine-morph gotcha.) The miller-columns block height becomes `260px` when *both* columns are compact, or — simpler — keep it `220px` always and let compact rows just fit more in; the README's `260px` is a nicety, decide in the plan (lean toward keeping `220px` to avoid layout churn; note the deviation).
- **`pages::playlist-detail`:** add `public bool $tracksCompact` (the `playlistTracksCompact` one), `mount()` init, `updatedTracksCompact` hook, the placeholder buttons wired, the tracklist markup branching comfortable/compact (`PL_COLS_COMPACT` = `20px 1.4fr 1fr 1fr 50px`). It already has the placeholder buttons? Check — the current playlist-detail tracklist header may not have the grid-2x2/menu buttons; the design (`PlaylistTracklist` in `plextune-views.jsx`) has them. If absent, add them, matching the library tracklist's button block.
- **`pages::settings`:** the existing Density toggle stays but its label/help text updates to "Density default" — "Sets the default for lists you haven't individually adjusted." `updatedDensity` still calls `AppSetting::setDensity`. (No need to also reset the per-section overrides — leave them; the getters fall back to the new default only for sections that were never explicitly toggled. If the user expects "changing the default re-applies everywhere", that's a possible enhancement but out of scope; note it.)
- **Remove** the M7 `data-density` attribute on `<body>` and the `[data-density="compact"] ...` block in `resources/css/app.css` — superseded. (If any compact styling is cleaner as a CSS class than as inline Blade conditionals — e.g. a `.tracklist-compact` modifier — that's fine, but key it off a class the section toggles, not a body attribute.)
- **Visual fidelity:** port the compact markup/classes from `design/rendered/compact.html` (the rendered DOM) — that's the source of truth, per the project convention. `design/plextune-parts.jsx` (`MillerCol`, `AlbumHeader`) and `design/plextune-views.jsx` (`PlaylistTrackRow`/`PlaylistTracklist`, `PL_COLS_COMPACT`, and the library `TrackRow`/`Tracklist` compact branch) show the React structure. Where the prototype shows data we don't have (the FLAC/format pill — we have no codec info from Plex), omit it gracefully, same as everywhere else.

### Tests

- Unit: `AppSettingTest` — the four new accessors default to `density()`'s value when unset, round-trip when set, and flipping `setDensity` flips an unset section but not an explicitly-set one.
- Feature: `LibraryViewTest` — with `artistsCompact` true the page renders the compact artist row markup (give the compact row a hook, e.g. a `data-compact-row` attr or just assert the absence of the thumbnail `<img>` / presence of the `py-[3px]` class) and toggling the prop persists; same for `albumsCompact` and the library tracklist (`grid-template-columns: 20px 1.4fr 1fr 50px` appears, no `<x-lucide-heart>` like cell). `PlaylistDetailViewTest` — `tracksCompact` true renders `PL_COLS_COMPACT`, persists. `SettingsViewTest` — the Density-default toggle still persists via `AppSetting::setDensity`.
- Browser: `LibraryDrillDownTest` (or a new `CompactTest`) — click the compact (3-line) button in the artists column header, assert the compact layout appears; reload, assert it stuck; same for a tracklist. Mirror existing `script()`-driven patterns.

---

## 3. Media keys (Media Session API)

When a track is playing, register `navigator.mediaSession` action handlers so the OS media keys and the system Now Playing widget control the player. Implemented in the `audioPlayer()` Alpine scope (`resources/views/components/⚡player.blade.php`).

- In `loadAndPlay(i)` (after setting the audio src / store `currentId`), if `'mediaSession' in navigator`, set `navigator.mediaSession.metadata = new MediaMetadata({ title: this.current.title, artist: this.current.artist, artwork: this.current.artwork ? [{ src: this.current.artwork }] : [] })`.
- In `init()` (once), if `'mediaSession' in navigator`, register handlers:
  - `navigator.mediaSession.setActionHandler('play', () => this.togglePlay())` — actually `() => { if (this.$refs.audio.paused) this.$refs.audio.play(); }`.
  - `'pause'` → `() => this.$refs.audio.pause()`.
  - `'previoustrack'` → `() => this.previous()`.
  - `'nexttrack'` → `() => this.next()`.
  - (Optional, low value: `'seekbackward'`/`'seekforward'`/`'stop'` — skip; YAGNI.)
- Keep `navigator.mediaSession.playbackState` in sync: in the `<audio>` `@play`/`@pause` handlers (or in `loadAndPlay`), set `navigator.mediaSession.playbackState = 'playing' | 'paused'` when the API exists.
- This is purely additive; the existing `keydown` shortcuts (Space/arrows/⌘K) stay. (F8 already worked via the browser's default; with `setActionHandler('play'/'pause')` registered it routes through our handler, which is fine — same effect.)
- No new tests required for the Media Session wiring specifically (it's browser-API glue with no Playwright support for OS media keys); a manual smoke check covers it. The plan may add a tiny browser assertion that `navigator.mediaSession.metadata?.title` is set after playing a track, if it's not flaky.

---

## 4. Now-playing source indicator icon

M7 put the animated `.eq` equalizer on the sidebar playlist row and the album/playlist headers as the "this list is the playback source" indicator. The animated equalizer is right for the **tracklist `#` column** (Spotify uses it there) but wrong for the **source indicator** — that should be a small static green speaker glyph (like Spotify's "now playing here" speaker icon).

- **Sidebar playlist row** (`resources/views/partials/playlist-row.blade.php`): replace the `<span class="eq ...">...</span>` inside the `<template x-if="$store.player?.contextType === 'playlist' && ...">` with a static accent-green speaker icon — `<x-lucide-volume-1 class="w-3.5 h-3.5 text-accent flex-none" />` (or `audio-lines` — pick whichever lucide glyph reads closest to Spotify's; `volume-1` is a speaker with one curved wave, which matches). No animation, no `is-paused` class.
- **Library album header and playlist-detail header** (in `pages/⚡library.blade.php` and `pages/⚡playlist-detail.blade.php`, both the expanded and collapsed variants): same swap — the `.eq` source indicator becomes the static `<x-lucide-volume-1 class="... text-accent">`.
- **Do NOT touch** the `.eq` equalizer in the tracklist `#` columns (library, playlist-detail, search Songs, recently-played) — that stays animated.
- The `.eq` CSS in `resources/css/app.css` stays (still used by tracklists).

### Tests

- Browser (`PlayerQueueTest.php`): the existing source-indicator assertions that look for `.eq` inside the sidebar row / album header → update them to look for the new icon (the `<svg>` lucide outputs has a class; assert e.g. `[wire\:key^="sidebar-pl-"] svg.text-accent` is present, or give the icon a `data-source-indicator` attribute and assert that). Tracklist `.eq` assertions stay.

---

## Definition of done

`php artisan test` green (the existing M7 suite plus the new/changed tests). `npm run build` run. Manual smoke pass with the user covering: typing in the topbar search (no focus loss, no clobbered chars, live results, clear button, direct `/search?q=` entry), the per-section compact toggles (artists, albums, both tracklists — they actually change the layout and persist; Settings "Density default" still works), F7/F8/F9 media keys + macOS Now Playing widget, the static speaker source indicator. Then `CLAUDE.md` updated (M7 + this follow-up), and `feat/m7-polish` merged.
