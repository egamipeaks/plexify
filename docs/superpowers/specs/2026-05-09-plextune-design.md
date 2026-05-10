# Plextune Design Spec

**Date:** 2026-05-09
**Status:** Approved
**Source:** `design/` folder (Plextune handoff from Claude Design)

## Overview

Plextune is a Spotify-style web app for browsing and playing music from a personal Plex Media Server. Desktop-first, single-user, runs locally on Laravel Herd over HTTPS.

The full UI design is documented in `design/README.md` (artist/album/track miller columns, app-side playlist folders, persistent bottom player, settings, etc.). This spec covers the technical architecture for implementing it in Laravel.

## Stack

- **Laravel 13** (latest)
- **Livewire 4** with single-file components (SFC, ⚡ prefix, default in v4)
- **Alpine.js** for client-only interactivity (player transport, scrubber, DnD, keyboard shortcuts)
- **Tailwind CSS** (port classes directly from the design files)
- **Pest 4** for testing, including browser tests via the Playwright plugin
- **Native HTML5 `<audio>`** for playback
- **MySQL** (via Herd)
- **Blade Icons** (`blade-ui-kit/blade-icons` + `mallardduck/blade-lucide-icons`) for inline SVG output matching the prototype's lucide-react icons.

### Visual Fidelity

All component implementations port HTML structure and Tailwind class lists verbatim from `design/rendered/<view>.html` (the rendered DOM of the prototype), not from the React JSX in `design/*.jsx`. Icons use `blade-ui-kit/blade-icons` with `mallardduck/blade-lucide-icons` to produce the same inline SVG output as the prototype's lucide-react components. Glyph substitutions (e.g., `▶`, `‹`, `⚙`) are forbidden; render the matching `<x-lucide-*>` SVG instead.

### Design Tokens

Defined in `resources/css/app.css` via Tailwind 4 `@theme`. Names match the prototype's `tailwind.config` (top of `design/rendered/homepage.html`).

| Token | Value | Notes |
|---|---|---|
| `--color-base` | `#000000` | Page background. Use `bg-base`, never `bg-bg`. |
| `--color-surface` | `#121212` | Default card surface. |
| `--color-surface-1` | `#181818` | Search input background. |
| `--color-surface-2` | `#242424` | Active nav item, cover-art placeholder. |
| `--color-surface-3` | `#2a2a2a` | Hover surfaces, context menus. |
| `--color-surface-4` | `#3e3e3e` | Scrollbar thumbs, slider tracks. |
| `--color-text-1` | `#FFFFFF` | Primary text. |
| `--color-text-2` | `#b3b3b3` | Secondary text, inactive nav labels. |
| `--color-text-3` | `#7a7a7a` | Tertiary text, key-cap borders. |
| `--color-accent` | `#1ED760` | Primary accent (Spotify green). |
| `--color-accent-hover` | `#3BE477` | Accent hover. |
| `--color-accent-press` | `#169c46` | Accent pressed state. |
| `--color-plex-orange-from` | `#e5a00d` | Plex chip gradient start. |
| `--color-plex-orange-to` | `#cc7700` | Plex chip gradient end. |
| `--font-sans` | `"DM Sans", system-ui, sans-serif` | Body type. |
| `--font-display` | `"Outfit", system-ui, sans-serif` | Display headings. |

DM Sans and Outfit load from Google Fonts in the layout `<head>`.

## Decisions Locked In

| Decision | Choice | Rationale |
|---|---|---|
| Frontend stack | Livewire 4 + Alpine | Matches user's existing skills. Design files are reference, not code; Tailwind ports framework-agnostically. |
| Component pattern | Single-file components (SFC) | v4 default. Self-contained, easier to navigate. |
| Audience | Single user, local | Lowest-friction starting point. Migration path to hosted is documented below. |
| Auth | None | Local-only on Herd. |
| Plex token storage | `.env` (`PLEX_TOKEN`, `PLEX_BASE_URL`) | Simplest. Future hosted migration moves it to a `users.plex_token` column. |
| Streaming | Browser hits Plex directly | Token in stream URL is acceptable for local. Future hosted migration switches to Laravel proxy or signed URLs. |
| HTTPS | Herd's built-in HTTPS | Avoids mixed-content issues with Plex's `*.plex.direct` certs. |
| Audio | Native HTML5 `<audio>` | No dependency. Gapless/crossfade deferred. |
| App state | Laravel DB (settings, folders, folder-playlist pivot) | Survives browser resets. Trivial future migration to multi-user (add `user_id`). |
| Scope | Full design, phased into milestones | All views planned, shippable per milestone. |
| Testing | Pest 4 + browser plugin (Playwright) | Browser tests for load-bearing flows; Livewire tests for persistence. |

## Architecture

### App Shell

The layout (`resources/views/components/layouts/app.blade.php`) is a plain Blade layout holding the three-region grid from the handoff:

```
┌─────────────────────────────────────┐
│  <livewire:topbar />                │
├──────────┬──────────────────────────┤
│ <livewire│   {{ $slot }}            │
│ :sidebar │   (routed view)          │
│ />       │                          │
├──────────┴──────────────────────────┤
│  <livewire:player />                │
└─────────────────────────────────────┘
```

`topbar`, `sidebar`, and `player` live in the layout. In Livewire 4 every component is an island by default, so they do not re-render when the main view changes. The entire `<livewire:player />` is wrapped in `@persist('player')` in the layout so its DOM, Livewire state, and Alpine state all survive `wire:navigate` morphs. Wrapping only the inner `<audio>` element is incorrect because it does not preserve Livewire component state and re-parents the audio out of the player's Alpine `x-data` scope (breaking `$refs`).

Sidebar nav links use `wire:navigate`. The URL changes properly (back/forward, deep-linking) but transitions feel SPA-fast.

### Routing

```php
Route::livewire('/', 'pages::library')->name('library');
Route::livewire('/search', 'pages::search')->name('search');
Route::livewire('/playlist/{playlist}', 'pages::playlist-detail')->name('playlist');
Route::livewire('/settings', 'pages::settings')->name('settings');
```

### Component Inventory

**Persistent layout components** (live in layout, render once):

- `topbar` (`resources/views/components/livewire/⚡topbar.blade.php`)
  Back/forward buttons (browser history via JS), search input with `wire:model.live.debounce.300ms` that triggers `wire:navigate` to `/search?q=...`, avatar button to settings.

- `sidebar` (`resources/views/components/livewire/⚡sidebar.blade.php`)
  Two stacked cards (top nav, playlists/folders) plus server chip. Owns folder DnD logic, persists folder/playlist mutations via Eloquent. Server chip shows live connection status.

- `player` (`resources/views/components/livewire/⚡player.blade.php`)
  Now-playing info, transport buttons, scrubber, volume, queue. The whole component is wrapped in `@persist('player')` at the layout level so its `<audio>` element, Livewire state, and Alpine `x-data` scope all survive `wire:navigate`. Most logic is Alpine (transport, scrubber position, volume changes); Livewire handles "load this track URL" via `#[On('play-track')]` and queue mutations.

**Routed view components** (`resources/views/pages/`):

- `library` (`pages/⚡library.blade.php`)
  One Livewire component containing miller columns (Artists + Albums) + album header + tracklist. The Albums column, album header, and tracklist are each wrapped as Livewire **islands** within this single component so that selecting an artist re-renders only the Albums island, and selecting an album re-renders only the album header + tracklist islands. (Reminder: islands are sub-regions of one component, not separate components.)

- `search` (`pages/⚡search.blade.php`)
  Reads `?q=` from URL. Empty query: centered "Search your library" prompt. Non-empty: top result card, songs column, artists row, albums row. Calls Plex's `/hubs/search` endpoint for one-shot results.

- `playlist-detail` (`pages/⚡playlist-detail.blade.php`)
  Gradient header + tracklist (with extra Album column). Inline rename via Alpine + Livewire commit on blur.

- `settings` (`pages/⚡settings.blade.php`)
  Left-rail nav with smooth-scroll anchors to right-pane sections (Account, Plex Server, Playback, Library, Display, Downloads, Shortcuts, About).

### Service Layer

`app/Services/Plex/`:

- **`PlexClient`** wraps Laravel HTTP client. Reads token + base URL from `config('services.plex')`. Methods:
  - `artists(): Collection<Artist>`
  - `albumsForArtist(string $ratingKey): Collection<Album>`
  - `tracksForAlbum(string $ratingKey): Collection<Track>`
  - `playlists(): Collection<Playlist>`
  - `searchAll(string $query): SearchResults`
  - `streamUrl(string $trackId): string`
  - `addTrackToPlaylist(string $playlistId, string $trackId): void`

- **`PlexCache`** wraps Laravel cache with namespaced keys (`plex:artists`, `plex:albums:{artistId}`, etc.).

- **Exceptions** (in `app/Services/Plex/Exceptions/`):
  - `PlexUnreachableException` (connection refused, timeout)
  - `PlexAuthException` (401, 403)
  - `PlexNotFoundException` (404, malformed response)

### Eloquent Models

- **`Folder`** — `id`, `name`, `position` (sort order), `expanded` (bool).
- **`FolderPlaylist`** — pivot: `folder_id`, `plex_playlist_id` (string, since Plex IDs are strings), `position`.
- **`Setting`** — single-row key/value store. Keyed by `name`, value JSON-cast. Used for theme, accent, density defaults, compact toggles per section, playback quality, crossfade seconds, etc.
- **`PlaylistOverride`** — `plex_playlist_id`, `display_name`. Optional, for app-side renames.

### Cross-Component Communication

- **View → Player:** `$dispatch('play-track', trackId: $id, queue: $tracks)`. Player listens with `#[On('play-track')]`, fetches stream URL server-side, then dispatches a browser event to Alpine: `$this->js("window.dispatchEvent(new CustomEvent('audio:load', {detail: {url: '$url'}}))")`. Alpine listener on the `<audio>` element sets `src` and calls `.play()`.
- **Sidebar → View:** standard `wire:navigate` URL change.
- **Player → all views:** `$dispatch('track-changed', trackId: $id)` so views can highlight the playing row.

## Data Flow

### Browsing (server-side, cached)

1. `library` mounts → calls `PlexClient::artists()` → cached 24h.
2. User clicks artist → `wire:click="selectArtist($id)"` → property update → Albums island re-renders, calling `albumsForArtist($id)` (cached 24h).
3. User clicks album → `selectAlbum($id)` → tracklist island re-renders, calling `tracksForAlbum($id)` (cached 7d).

Cache TTLs:
- Artists: 24h
- Albums per artist: 24h
- Tracks per album: 7d
- Playlists: 5min
- Search results: not cached

"Resync metadata" button in Settings clears all `plex:*` cache keys.

### Streaming (browser hits Plex directly)

1. User clicks track row → `$dispatch('play-track', trackId: ..., queue: [...])`.
2. Player receives event server-side, calls `PlexClient::streamUrl($trackId)` which returns `{base}/library/parts/{partId}/file.{ext}?X-Plex-Token={token}` where `{ext}` matches the track's container (flac, mp3, m4a, etc., as reported by Plex).
3. Player dispatches browser event with the URL. Alpine sets `<audio src>` and plays.
4. Token is exposed in the audio element's `src`. Acceptable for single-user local. Future hosted migration switches to a Laravel-proxied stream or short-lived signed URLs.

### Search

- Topbar input has `wire:model.live.debounce.300ms="query"`. Empty: nothing. Non-empty: `wire:navigate('/search?q=' + query)`.
- Search view reads `?q=` and calls `PlexClient::searchAll($query)` (Plex's `/hubs/search` returns top result + songs + artists + albums in one call).

### Playlists & Folders

- **Plex playlists** read via `PlexClient::playlists()`, mutated via Plex API.
- **App-side folders** are pure Eloquent. DnD a playlist into a folder updates the `FolderPlaylist` pivot.
- **DnD a track onto a playlist:** optimistic UI (Alpine adds row), then Livewire call to `PlexClient::addTrackToPlaylist()`. Roll back on failure.

## Error Handling

- `PlexClient` throws typed exceptions. Components catch them and render user-meaningful states.
- **Sidebar server chip:** shows live connection status. Green dot if reachable, red + "Server unreachable" if not.
- **View-level:** unreachable Plex during `mount()` renders a centered "Can't reach your Plex server" panel with a Retry button.
- **Tracklist row:** if a track 404s when played, dispatch a toast and skip to next in queue.
- **Audio errors:** Alpine listens for `<audio>` `error` events, dispatches `audio-error` to Livewire for logging, shows a toast, advances to next track.
- **Buffering:** Alpine watches `waiting` and `playing` events. If stalled > 10s, show a "Buffering…" indicator.
- **DnD failures:** optimistic UI, roll back on Livewire error, toast the failure.
- **Logging:** Plex API errors go to a dedicated `plex.log` channel via `Log::channel('plex')`.

**Out of scope for v1:**
- Retry-with-backoff (LAN is reliable enough; manual retry is fine).
- Offline mode (app is useless without Plex).
- Token rotation flow.

## Testing

### Pest 4 setup

```bash
composer require pestphp/pest-plugin-browser --dev
npm install playwright@latest
npx playwright install
```

Add `tests/Browser/Screenshots` to `.gitignore`.

### Unit / feature tests (run every commit)

- **`PlexClient`** with `Http::fake()` — endpoint correctness, query params, token attachment, exception mapping per HTTP status, stream URL builder.
- **Folder / playlist persistence** via `Livewire::test()` — drag-into-folder updates pivot, delete-folder returns playlists, inline rename writes to DB, settings round-trip.
- **One smoke test per routed view** — boots with mocked Plex data, renders without errors.

### Browser tests (run before milestone merges)

Cover only load-bearing flows:

1. **Audio persistence across navigation** — play track, switch views, confirm audio still playing. Validates the `@persist('player')` + island architecture.
2. **Miller column drill-down** — artist click loads albums, album click loads tracks. Validates island re-render isolation.
3. **Drag track onto playlist** — uses Playwright's native drag support. Validates optimistic UI + persistence path.
4. **Search redirect and debounce** — type into topbar, URL becomes `/search?q=...`, results render.
5. **Compact toggle persists** — flip compact, navigate away and back, still compact. Validates DB-backed settings round-trip via the UI.

### Not tested

- Every settings page row (covered by Livewire tests instead).
- Pixel-level visual regressions.
- Mobile viewports, dark mode variants (desktop-first; design tokens already dark).
- Multiple browsers (Chrome only for v1).

### Manual smoke-test checklist

Each milestone has its own checklist embedded in the implementation plan. Example for the player milestone:
- Play a track, hear audio.
- Scrub to a new position.
- Change volume.
- Navigate to another view, confirm audio doesn't stop.
- Hit space to pause/play.
- Hit → for next track in queue.

## Milestones

The implementation plan (next document) will phase the work into shippable milestones. Rough order:

1. **App shell + player** — layout, topbar, sidebar (nav only, no playlists yet), player with HTML5 `<audio>`. End state: can hardcode a test track URL and have the player play it.
2. **Library view** — Plex API integration, miller columns, album header, tracklist, "click track to play."
3. **Search view** — debounced search, results layout, click-to-play.
4. **Playlist detail view** — read Plex playlists, render playlist tracklist.
5. **Folders + DnD** — sidebar folder UX, drag tracks/albums/playlists, persistence.
6. **Settings** — all settings sections, theme/accent application.
7. **Polish** — keyboard shortcuts, transitions, error toasts, empty states.

Each milestone is end-to-end shippable: you can use the app at every stage.

## Future Migration Notes

If/when this becomes a hosted multi-user app:

1. Move Plex token from `.env` to `users.plex_token` column. Add Plex OAuth PIN flow.
2. Add Laravel auth (`php artisan install:breeze` or similar). Wrap routes in `auth` middleware.
3. Streaming: switch from browser-direct to Laravel-proxied (or short-lived signed URLs). Requires Laravel host to reach Plex server (Tailscale, VPN, or same network).
4. Add `user_id` to `Folder`, `FolderPlaylist`, `Setting`, `PlaylistOverride`.
5. Standard production concerns: HTTPS cert, queue worker for any long-running tasks, error monitoring.

The architecture is designed so these are additive changes, not rewrites.
