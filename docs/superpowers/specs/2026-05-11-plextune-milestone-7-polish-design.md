# Plextune — Milestone 7 (Polish) design

Status: approved 2026-05-11. Implementation plan: `docs/superpowers/plans/2026-05-11-plextune-milestone-7-polish.md` (to be written next).

M7 is the final milestone: a grab-bag of cross-cutting polish. None of the items below depend on each other much, so the implementation plan phases them into roughly ten independent TDD tasks, each with its own commit, built via the usual subagent-driven-development flow (implementer subagent + spec-compliance review + code-quality review per task), with a manual smoke-test checkpoint with the user before the final browser-test task.

## Scope summary

1. Keyboard shortcuts (play/pause, next/prev, focus search) + the topbar `⌘K` hint badge.
2. Error toasts (Plex/network errors, failed playlist CRUD, failed drag-drops).
3. Scrobble plays back to Plex + a Settings "Playback" section with the toggle.
4. Display-density wiring (the persisted Compact toggle finally does something).
5. Collapsible album / playlist header (the prototype's 56px collapsed row), persisted.
6. Context-aware playing-track highlight + a now-playing source indicator.
7. `formatMs` hour handling + extracting the duplicated helper to `App\Support\Duration`.
8. Empty states for playlist detail, recently played, library, and the sidebar.

### Explicitly out of scope

Transitions / animations work of any kind. A keyboard-shortcut help / cheat-sheet overlay. The prototype Settings sections we have no feature for (Account, Playback beyond the scrobble toggle, Downloads, Library, Shortcuts panel). Any theme / accent picker.

---

## 1. Keyboard shortcuts

A single `window` `keydown` listener registered inside `audioPlayer()`'s `init()` (it already owns `play()` / `togglePlay()` / `next()` / `prev()`):

- **Space** → toggle play/pause. `preventDefault()` so the page does not scroll. Skipped when a `<button>` or `[role="button"]` is the focused element (native Space-click handles it, avoids a double toggle) and skipped when focus is in an `<input>` / `<textarea>` / `<select>` / `contenteditable`.
- **ArrowRight** → next track. **ArrowLeft** → previous track. Skipped when focus is in an input-like element, and skipped when any of ⌘ / Ctrl / Alt is held.
- **⌘K** / **Ctrl+K**, and **/** → focus the topbar search input. `preventDefault()`. Skipped only when already typing in an input-like element. The topbar search `<input>` gets a stable `id` (e.g. `id="topbar-search"`) so the handler can `document.getElementById('topbar-search')?.focus()`.

Guard helper: a small `isTypingTarget(el)` predicate (`INPUT` / `TEXTAREA` / `SELECT` tag name, or `isContentEditable`).

The topbar search field also gains the **`⌘K` hint badge** on the right side of the input (it is in `design/README.md` — "magnifying glass icon left, ⌘K hint right" — but was never built). Port the badge markup/classes from `design/rendered/homepage.html` (or `your-library.html`) if present there; otherwise a small `kbd`-styled pill matching the prototype's `bg-white/10 text-[10px] font-bold` token usage.

### Tests

Browser test (Playwright, `tests/Browser/`): load a view, press Space, assert the player toggles (`$store.player.isPlaying` flips); press `/`, assert `document.activeElement` is the topbar search input. ArrowRight/Left next/prev can be covered by extending `PlayerQueueTest` (play a 2-track queue, press ArrowRight, assert `current` advanced).

---

## 2. Error toasts

A new Livewire component `toasts` (SFC at `resources/views/components/⚡toasts.blade.php`), rendered once in `resources/views/components/layouts/app.blade.php` (outside `@persist('player')`, fixed-position region, e.g. bottom-right or top-right per the prototype's general styling — no specific toast region exists in the prototype, so use a plain `fixed` stack styled with existing tokens: `bg-surface-2 ring-1 ring-white/10 rounded-lg`, red accent for errors).

Behaviour:

- The component holds a list of `{ id, type, message }` and renders them with an Alpine-managed auto-dismiss (~5 s) plus a manual dismiss `×`. No slide/fade transition (transitions are out of scope) — toasts simply appear and are removed.
- It listens for a Livewire/browser `notify` event with payload `{ type: 'error', message: string }` and appends a toast.
- A shared concern `App\Concerns\DispatchesPlexErrors` (a trait, or a small helper method) provides `dispatchPlexError(\App\Services\Plex\Exceptions\PlexException $e): void` → `$this->dispatch('notify', type: 'error', message: $e->userMessage())`. (`PlexException` already has a user-facing message accessor used by the inline displays — reuse it; if it is currently `getMessage()`-only, add a `userMessage()` that returns the friendly string.)

Where errors get routed:

- Page components that load from Plex (`pages::library` artist/album/track loads, `pages::playlist-detail` load, `pages::search`, `pages::recently-played`) — anywhere a `PlexException` is currently caught and either shown inline or swallowed, also call `dispatchPlexError()`. The **search no-results screen stays** (it is part of `new-search.html`'s design and is not strictly an error) and the **Settings "resync metadata" inline confirmation stays** (it has its own inline slot). The toast is the catch-all for failures that have no inline slot.
- Failed playlist CRUD (rename / delete / create) and failed drag-drops (`Playlist` write actions returning `false`, and the sidebar DnD handlers): emit an error toast in addition to the existing ~700 ms red ring-flash on the drop target. The ring-flash stays as the immediate, in-place cue; the toast is the persistent explanation. Successful CRUD / drops stay silent (no success toasts — the user asked for error notifications only).

### Tests

- Feature: assert `dispatch('notify', …)` fires from a page component when its mocked `PlexClient` throws a `PlexException`; assert the sidebar emits `notify` when a mocked playlist write returns `false`.
- The `toasts` component itself: a `Livewire::test('toasts')` that dispatches `notify` and asserts the toast renders / can be dismissed.

---

## 3. Scrobble plays to Plex

When a track finishes, tell Plex it was played (so the Plex-side "last played" / play counts — and our Recently Played view — reflect plays made in this app, not just other Plex clients).

- **`PlexClient::scrobbleUrl(string $ratingKey): string`** — builds `{baseUrl}/:/scrobble?key={ratingKey}&identifier=com.plexapp.plugins.library&X-Plex-Token={token}`. Pure string building, like `streamUrl()` / `thumbUrl()`. The base URL and token are the same ones `streamUrl()` already uses (the token is already client-visible in stream URLs, so exposing it here changes nothing).
- **`App\Support\AppSetting::scrobbleEnabled(): bool`** / **`setScrobbleEnabled(bool $value): void`** — wraps the existing `Setting` model with a typed accessor, default **`true`**. Single source of truth for the default, same pattern as `density()` / `setDensity()`.
- **Player wiring:** the `player` Livewire component (the thin relay) exposes two more values to the Blade view: `scrobbleEnabled` (bool, from `AppSetting::scrobbleEnabled()`) and `scrobbleUrlTemplate` — `PlexClient::scrobbleUrl('__KEY__')` with a literal placeholder the JS replaces (avoids re-resolving the client in JS). The Blade passes both into the `audioPlayer()` Alpine scope.
- **`audioPlayer()` behaviour:** in the `<audio>` `ended` handler, *before* `loadNext()` / auto-advance, if `scrobbleEnabled` and there is a `current` track, do a fire-and-forget `fetch(scrobbleUrlTemplate.replace('__KEY__', current.id), { mode: 'no-cors' })` (the queue item's `id` is the Plex `ratingKey`). Wrap in `try/catch` / `.catch(() => {})` — a failed scrobble must not interrupt playback or surface a toast. Scrobble fires only on natural track end, not on manual skip (skipping is not a "play").
- **Settings "Playback" section:** add a fourth `<section>` card to `pages/⚡settings.blade.php` (the prototype had a Playback section we did not build — we build only the one row we have a feature for). One row: "Scrobble plays to Plex" with a Comfortable/Compact-style toggle / segmented control matching the existing density control's styling. `wire:click="$set('scrobbleEnabled', …)"` → `updatedScrobbleEnabled($value)` → `AppSetting::setScrobbleEnabled()`. Place the section between "Display" and "About" (or after "Plex Server" — pick the order that reads best; Plex Server → Playback → Display → About is the natural grouping).

### Tests

- Unit: `PlexClientTest` — `scrobbleUrl()` returns the expected URL with the token. `AppSettingTest` — `scrobbleEnabled()` default `true`, `setScrobbleEnabled(false)` round-trips.
- Feature: `PlayerComponentTest` (or `PlayerComponentTest`'s render path) — the player view exposes `scrobbleEnabled` / `scrobbleUrlTemplate`. `SettingsViewTest` — the Playback toggle renders, `$set('scrobbleEnabled', false)` calls `AppSetting::setScrobbleEnabled(false)`.
- Browser: extend `SettingsTest` — flip the scrobble toggle, navigate away and back, assert it persisted.

---

## 4. Display-density wiring

The Compact / Comfortable toggle on Settings (M6) currently persists to the `Setting` table but nothing reads it. Wire it:

- The layout (`components/layouts/app.blade.php`) sets `data-density="{{ \App\Support\AppSetting::density() }}"` on the layout root element (the outermost `<div>` of the three-region grid, or `<body>` if there is a body-level wrapper — pick whichever is the stable root that survives `wire:navigate`).
- `resources/css/app.css` gets rules scoped to `[data-density="compact"]`:
  - Library and playlist-detail **tracklist rows**: reduced vertical padding / row height (the comfortable values are whatever the ported design uses; compact is a tighter step — match the prototype's compact look if it renders one, otherwise ~25–30 % tighter padding).
  - **Sidebar** playlist rows and folder header rows: tighter vertical padding.
  - The **album / playlist gradient header** (expanded state): reduced vertical padding on the gradient strip.
- No JS needed; it is a pure CSS attribute selector. Because the attribute is server-rendered from a persisted value and only changes on a full Settings round-trip, there is no Alpine-morph re-binding concern here.

### Tests

- Feature: a page test asserting the layout root carries `data-density="comfortable"` by default and `data-density="compact"` after `AppSetting::setDensity('compact')`.
- Browser: extend `SettingsTest` — set Compact, navigate to the library, assert the root has `data-density="compact"` (CSS effect itself is not asserted — no pixel regression testing).

---

## 5. Collapsible album / playlist header

The prototype's `AlbumHeader` (`design/plextune-parts.jsx`, and described in `design/README.md`) has a collapsed mode: a 56px-tall single row — 40px cover, title, inline meta, inline Play / Shuffle / Expand buttons — toggled by a collapse chevron in the top-right of the expanded gradient strip. Never built. Add it to:

- The **library album header** (`pages/⚡library.blade.php`) and the **playlist-detail header** (`pages/⚡playlist-detail.blade.php`).
- Port the collapsed-state markup/classes from `design/plextune-parts.jsx`'s `AlbumHeader` collapsed branch (and from `design/rendered/*` if a rendered collapsed variant exists). The collapsed row keeps the same play-queue dispatch behaviour on its Play / Shuffle buttons.
- The collapse/expand affordance: a chevron in the top-right of the expanded gradient strip. On the library album header it sits next to the existing close-`×` button (so "collapse" and "close the album view" remain distinct affordances); the collapsed row's "Expand" control returns to expanded.
- **State + persistence:** a public prop `public bool $headerCollapsed` on each page, initialised in `mount()` / `boot()` from new `App\Support\AppSetting::albumHeaderCollapsed(): bool` (default `false`). It is `@entangle('headerCollapsed')`'d into the header's Alpine scope so the toggle is instant client-side, and an `updatedHeaderCollapsed($value)` lifecycle hook persists it via `AppSetting::setAlbumHeaderCollapsed()`. (Using `@entangle` rather than a server-rendered JS literal — per the M5 gotcha that Alpine does not re-evaluate a morphed attribute string.) Both pages read the same setting, so the preference is global across album and playlist views.

### Tests

- Unit: `AppSettingTest` — `albumHeaderCollapsed()` default `false`, `setAlbumHeaderCollapsed(true)` round-trips.
- Feature: `LibraryViewTest` / `PlaylistDetailViewTest` — with `albumHeaderCollapsed` true the page renders the collapsed row markup; toggling the prop persists via `AppSetting`.
- Browser: extend `LibraryDrillDownTest` or `PlaylistDetailTest` — click the collapse chevron, assert the collapsed row is visible; reload, assert it is still collapsed.

---

## 6. Context-aware playing-track highlight + now-playing source indicator

Today the playing-row highlight (accent-coloured title + animated `.eq` equalizer in the `#` cell) only exists in the library album tracklist and the playlist-detail tracklist, and it matches on the bare track id from `$store.player.currentId` — so navigating to the *album* of a track that is actually playing from a *playlist* lights up the album row too. Fix the matching and extend the coverage, Spotify-style.

- **`play-track` event payload** gains `contextType` (`'album' | 'playlist' | 'search' | 'recently-played'`) and `contextId` (the album `ratingKey` for `'album'`, the playlist `ratingKey` for `'playlist'`, `null` for `'search'` / `'recently-played'`). The event payload is now `{ queue, index, shuffle, contextType, contextId }`. The `player` relay (`#[On('play-track')]`) forwards all five into the re-broadcast `queue-load` browser event.
- **`audioPlayer()`** writes `contextType` / `contextId` into `$store.player` (which already holds `currentId` / `isPlaying`) in the `queue-load` handler, alongside the existing fields. Shuffling re-orders the queue but does **not** change `contextId`.
- **Dispatchers** each set their context:
  - `pages::library` `playTrack` / `playAlbum` / `shuffleAlbum` → `contextType: 'album'`, `contextId: $this->selectedAlbumId`.
  - `pages::playlist-detail` `playTrack` / `playAll` / `shuffle` and `components::sidebar` `playPlaylist` → `contextType: 'playlist'`, `contextId:` the playlist `ratingKey`.
  - `pages::search` `playTrack` → `contextType: 'search'`, `contextId: null`.
  - `pages::recently-played` `playTrack` → `contextType: 'recently-played'`, `contextId: null`.
- **Tracklist row highlight** match condition in each view becomes, in effect, `$store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === '<this view>' && $store.player?.contextId === '<this id>'` (for `'search'` / `'recently-played'` the `contextId` clause is just the `contextType` check). Apply it to:
  - the library album tracklist (`'album'` + `selectedAlbumId`) — change from bare-id to context-aware,
  - the playlist-detail tracklist (`'playlist'` + playlist id) — change from bare-id to context-aware,
  - the **search "Songs" group** rows (`'search'`) — newly add the highlight (add `x-data="{}"` to the list container and the `:class` / `<template x-if>` `.eq` exactly like the existing two tracklists, with the context-aware condition),
  - the **recently-played list** rows (`'recently-played'`) — newly add the highlight the same way.
- **Now-playing source indicator:** show a small accent indicator — reuse the `.eq` equalizer, or a `<x-lucide-volume-2>` in `text-accent` — on:
  - the **sidebar playlist row** (`resources/views/partials/playlist-row.blade.php`) when `$store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $playlist id }}'`,
  - the **library album header** when `contextType === 'album' && contextId === selectedAlbumId`,
  - the **playlist-detail header** when `contextType === 'playlist' && contextId ===` this playlist.
  Every Alpine reactive expression here is null-guarded (`$store.player?.…`) per the M5 gotcha about uncaught throws killing Alpine.

### Tests

- Feature: `PlayerComponentTest` — the relay forwards `contextType` / `contextId` from `play-track` into `queue-load`. The five dispatch tests (library `playTrack`/`playAlbum`/`shuffleAlbum`, playlist-detail dispatch, sidebar `playPlaylist`, search `playTrack`, recently-played `playTrack`) assert the new fields are set correctly.
- Browser: extend `PlayerQueueTest` — play a track from a playlist, navigate to that track's album, assert the album row does **not** show `.eq`; navigate back to the playlist, assert the sidebar playlist row **does** show the source indicator. Also assert the search-Songs / recently-played highlight appears when playing from those views.

---

## 7. `formatMs` hour handling + dedup

`formatMs(int $ms): string` is copy-pasted in four page components (`pages/⚡search.blade.php`, `pages/⚡playlist-detail.blade.php`, `pages/⚡library.blade.php`, `pages/⚡recently-played.blade.php`).

- Extract to **`App\Support\Duration::format(int $ms): string`** (a small static helper class, sibling of `AppSetting` under `app/Support/`). All four pages call `\App\Support\Duration::format(...)` (drop their local `formatMs` methods).
- Handle durations `>= 3_600_000` ms (one hour) as `H:MM:SS` (e.g. `1:23:45`); under an hour stays `M:SS` (e.g. `3:07`). Negative / zero → `0:00`.
- New `tests/Unit/DurationTest.php` with a dataset: a few seconds, just under a minute, a few minutes, exactly an hour, over an hour, multi-hour, zero.

---

## 8. Empty states

On-brand empty states (centred or inline message, styled with existing tokens — `text-text-2` / `text-text-3`, an optional muted `<x-lucide-*>` glyph, no fabricated data), for:

- **Playlist detail** (`pages/⚡playlist-detail.blade.php`): when the loaded playlist has zero tracks, the tracklist area shows "This playlist is empty" (with a short hint, e.g. "Add songs from your library by dragging them onto the playlist") instead of a bare header over an empty table.
- **Recently played** (`pages/⚡recently-played.blade.php`): when Plex returns no recently-played tracks, show "Nothing played recently" instead of an empty list.
- **Library** (`pages/⚡library.blade.php`): when the Plex music section has no artists (or no music section was found), show an explanatory message in the artist column ("No music found in your Plex library" / "No music library found on this server"). Distinguish the "no music section" case from the "section exists but empty" case if the data allows; otherwise one message is fine.
- **Sidebar** (`components::sidebar` / the playlists card): when there are no Plex playlists, show a subtle "No playlists yet" line in the playlists card instead of an empty list.

Search keeps its existing no-results screen — not touched.

### Tests

- Feature: `PlaylistDetailViewTest` (empty playlist → empty-state copy), `RecentlyPlayed`-related test (empty → copy; create one if none exists), `LibraryViewTest` (no artists → copy), `SidebarTest` (no playlists → copy). Use the mocked `PlexClient` returning empty collections.

---

## File-level impact (non-exhaustive)

**New:**
- `app/Support/Duration.php`
- `app/Concerns/DispatchesPlexErrors.php` (trait) — or fold the helper into an existing base/concern if one fits
- `resources/views/components/⚡toasts.blade.php`
- `tests/Unit/DurationTest.php`
- possibly `tests/Browser/KeyboardShortcutsTest.php` (or fold into `PlayerQueueTest`)

**Modified:**
- `app/Support/AppSetting.php` — `scrobbleEnabled` / `setScrobbleEnabled`, `albumHeaderCollapsed` / `setAlbumHeaderCollapsed`
- `app/Services/Plex/PlexClient.php` — `scrobbleUrl()`
- `app/Services/Plex/Exceptions/PlexException.php` — `userMessage()` if not already present
- `resources/views/components/layouts/app.blade.php` — `data-density` attr, `<livewire:toasts />`, `id` on... (topbar input is in the topbar component), keyboard listener lives in `audioPlayer()` so no layout JS, scrobble values passed via the player component
- `resources/views/components/⚡topbar.blade.php` — `id="topbar-search"` on the input, `⌘K` hint badge
- `resources/views/components/⚡player.blade.php` (the relay + audio view, wherever `audioPlayer()` is defined) — forward `contextType`/`contextId`, scrobble fetch, keyboard listener, `$store.player` context fields
- `resources/views/pages/⚡library.blade.php`, `⚡playlist-detail.blade.php`, `⚡search.blade.php`, `⚡recently-played.blade.php` — `Duration::format`, context-aware dispatch + highlight, empty states; library + playlist-detail also get the collapsible header
- `resources/views/components/⚡sidebar.blade.php` + `resources/views/partials/playlist-row.blade.php` — source indicator, no-playlists empty state, context on `playPlaylist`
- `resources/views/pages/⚡settings.blade.php` — Playback section
- `resources/css/app.css` — `[data-density="compact"]` rules, collapsed-header styles
- Tests: `AppSettingTest`, `PlexClientTest`, `PlayerComponentTest`, `SettingsViewTest`, `LibraryViewTest`, `PlaylistDetailViewTest`, `SidebarTest`, recently-played feature test, `PlayerQueueTest`, `SettingsTest`, `LibraryDrillDownTest`/`PlaylistDetailTest`

## Definition of done

`php artisan test` green (existing 190 + the new ones). Manual smoke-test checklist run with the user before the final browser-test task. `CLAUDE.md` updated (M7 status → Done, milestone table, any new gotchas). The prototype's never-built Settings sections remain explicitly unbuilt; the M7 polish list above is fully shipped.
