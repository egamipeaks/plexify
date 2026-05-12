# Plexify (Plextune) — project guide

A Spotify style web app for browsing and playing music from a personal Plex Media Server. Desktop first, single user, runs locally on Laravel Herd over HTTPS at `https://plexify.test`.

The product name in the design files is "Plextune". The repo and app title are "Plexify". Treat them as the same thing.

## Where the canonical docs live

- **Design spec:** `docs/superpowers/specs/2026-05-09-plextune-design.md`. Architecture, tech stack, locked in decisions, the 7 milestone breakdown, migration notes. Read this first when picking up the project.
- **Implementation plans:** `docs/superpowers/plans/2026-05-09-plextune-milestone-N-*.md`. One per milestone. M1, M2 and M3 plans exist and were executed (with some mid execution corrections, see Gotchas). M4 onward not yet written.
- **Design reference:** `design/`. The prototype is `design/Plextune.html` (React + Tailwind CDN, self contained). `design/README.md` is the original handoff describing intended structure. `design/rendered/*.html` are the rendered DOM of each prototype view, used as the source of truth for class lists and icon markup when building UI:
  - `homepage.html` / `your-library.html` — the library view (M2, built)
  - `new-search.html` — the **current** search results design (filter tab pills + grouped lists). Use this for M3, NOT `search.html` which is the older layout.
  - `view-playlist.html` — playlist detail (M4)
  - `settings.html` — settings page (M6)

## Milestone status

| # | Scope | Status |
|---|---|---|
| 1 | App shell (3 region layout), sidebar, topbar, persistent player | Done |
| 2 | PlexClient service + library view (artist/album/track miller columns, click to play, server chip, thumbnails) | Done |
| 3 | Search view (filter tab pills + grouped result lists, per `design/rendered/new-search.html`) | Done |
| 4 | Playlist detail view (read Plex playlists; gradient header + tracklist with Album column; flat playlist list in the sidebar) | Done |
| 5 | App side folders + drag and drop (Folder/FolderPlaylist models, collapsible sidebar folders, native HTML5 DnD, playlist context menus, playlist CRUD via Plex) | Done |
| 6 | Settings page (Plex Server status + Resync metadata button; Display density toggle, persist-only; About with app version) | Done |
| 7 | Polish (keyboard shortcuts, transitions, error toasts, compact/comfortable density wiring, scrobble-to-Plex, formatMs hour handling, etc.) | Done |

**Play queue and autoplay** shipped 2026-05-11 as a cross-cutting feature (branch `feat/play-queue`, ahead of M6). Spec: `docs/superpowers/specs/2026-05-11-plextune-play-queue-design.md`. Plan: `docs/superpowers/plans/2026-05-11-plextune-play-queue.md`. Covers: Alpine `audioPlayer()` queue with auto-advance, skip forward/back, shuffle, three-state repeat, consecutive-error guard; all views dispatch `play-track` with the full queue (track rows, the playlist-detail Play/Shuffle header buttons, the library album-header Play/Shuffle buttons, the sidebar playlist context-menu Play); new browser test `tests/Browser/PlayerQueueTest.php` (3 tests).

**Play-queue follow-ups (shipped 2026-05-11, branch `feat/play-queue-followups`).** Spec: `docs/superpowers/specs/2026-05-11-plextune-play-queue-followups-design.md`. Plan: `docs/superpowers/plans/2026-05-11-plextune-play-queue-followups.md`. Covers:
- **Playing-track highlight.** A global Alpine store `$store.player` (`{ currentId, isPlaying }`) is registered from the layout `<head>` via an `alpine:init` listener (so it exists before Alpine's first DOM walk, see Gotchas) and kept in sync by `audioPlayer()` (`currentId` in `loadAndPlay`, `isPlaying` from the `<audio>` `@play`/`@pause`). The **library album tracklist and the playlist-detail tracklist** read it: the playing row's title turns `text-accent` and its `#` cell shows an animated `.eq` equalizer (`.eq.is-paused` freezes it; styles in `resources/css/app.css`). Same highlight in the search "Songs" group and the recently-played list is still a follow-up.
- **Now-playing navigation.** The now-playing cover and track title `@click` → `audioPlayer().goToAlbum()` → `Livewire.navigate('/?artist=…&album=…')`; the artist subtitle → `goToArtist()`. Driven by `albumId`/`artistId` now carried on the `play-track` queue items (and on the `Track` DTO).
- **A plain Play / track-row click clears the shuffle toggle** (`queue-load` handler does `this.shuffle = !!e.detail.shuffle`); only the album/playlist Shuffle buttons set or keep it on.
- New browser tests in `tests/Browser/PlayerQueueTest.php` (now 6: the original 3 plus playing-row highlight, now-playing album nav, shuffle reset).

(Intentional, not a bug: pressing skip-forward while repeat is set to "one" restarts the current track rather than advancing — confirmed desired behavior.)

**Search input bug (fixed 2026-05-11):** typing a new query into the search box after results were already shown cleared the typed text out of the input. Cause: the topbar's `query` was a plain prop, so the `redirect(navigate: true)` that re-ran the search landed on a fresh topbar with `query = ''` and Livewire then cleared the bound input. Fix: bind it with `#[Url(as: 'q', except: '')]` so it re-hydrates from `?q=` on arrival. Regression test: `tests/Browser/SearchTest.php` "keeps the typed query in the topbar input after the search re-runs".

**M6 Settings (shipped 2026-05-11, branch `feat/settings`).** Spec: `docs/superpowers/specs/2026-05-11-plextune-milestone-6-settings-design.md`. Plan: `docs/superpowers/plans/2026-05-11-plextune-milestone-6-settings.md`. The `/settings` page (`pages/⚡settings.blade.php`) has three `<section>` cards in a `max-w-3xl` column (the prototype's seven-item left nav was intentionally dropped, we only have three sections): **Plex Server** (status dot from a fresh `PlexClient::ping()`, Connected / Token-set-but-unreachable / No-token; server name + connection rows; a "Resync metadata" button that calls `PlexCache::flushAll()` then re-pings, with inline "Cleared at HH:MM:SS" / "Cache cleared, but Plex is unreachable." confirmation), **Display** (a Comfortable/Compact density segmented control, `wire:click="$set('density', …)"` -> `updatedDensity` -> `AppSetting::setDensity()`; persist-only, no consumer yet, M7 wires the CSS), and **About** (`config('app.version')` from a new `APP_VERSION` env, plus a credit line). New `App\Support\AppSetting` static accessor wraps the existing `Setting` model with typed/validated `density()`/`setDensity()` (single source of truth for defaults + allowed values). `serverInfo()` is a plain `#[Computed]` (resolves `PlexClient` via `app()` since computeds can't autowire) read once via `@php($info = $this->serverInfo)`. Prototype sections we did NOT build (no feature backs them): Account, Playback (audio quality/gapless/crossfade/normalize/cellular), Downloads, Shortcuts. Scrobble-to-Plex toggle deferred to M7. Tests: `tests/Unit/AppSettingTest.php`, `tests/Feature/SettingsViewTest.php` (9), `tests/Browser/SettingsTest.php` (2), plus extended `tests/Unit/PlexCacheTest.php`.

**M7 Polish (shipped 2026-05-12, branch `feat/m7-polish`).** Specs: `docs/superpowers/specs/2026-05-11-plextune-milestone-7-polish-design.md` and `docs/superpowers/specs/2026-05-12-plextune-m7-followup-design.md`. Plans: matching `docs/superpowers/plans/` files. Core M7 work: `App\Support\Duration::format(int $ms): string` (`M:SS` / `H:MM:SS`; replaced four copies of `formatMs` across the page components). Error toasts: `resources/views/components/⚡toasts.blade.php` (Livewire SFC rendered once in the layout, listens for a `notify` browser event `{type:'error', message}`, auto-dismiss ~5s, dismissible; sidebar playlist-write catch blocks dispatch `notify`; errors only, no success toasts; full-screen inline panels kept for page-load failures). Scrobble-to-Plex: `PlexClient::scrobbleUrl(string $ratingKey)` (pure string builder); `AppSetting::scrobbleEnabled()`/`setScrobbleEnabled()` (default true); the player relay's `mount()` passes `scrobbleEnabled` and a `scrobbleUrlTemplate` (with `__KEY__` placeholder) into `audioPlayer()`; on `<audio>` `@ended` a fire-and-forget `fetch(..., {mode:'no-cors'})` fires when enabled; a new Settings "Playback" section has the toggle. Keyboard shortcuts: a `window` keydown listener in `audioPlayer().init()` (guarded by `window.__plexifyPlayerInited`): Space = play/pause (skipped when focus is on a button or in an input), ArrowLeft/ArrowRight = prev/next, Cmd-K/Ctrl-K and `/` = focus `#topbar-search`. Collapsible album/playlist header: `AppSetting::albumHeaderCollapsed()`/`setAlbumHeaderCollapsed()` (default false, shared by library and playlist-detail); a `public bool $headerCollapsed` prop `@entangle`d into the header's Alpine scope; `updatedHeaderCollapsed` persists; collapsed 56px row ported from the prototype; both variants `x-cloak`ed. Context-aware playing-row highlight: `play-track` (and the relayed `queue-load`, and `$store.player`) gained `contextType` (`'album'|'playlist'|'search'|'recently-played'`) and `contextId`; every dispatcher sets its context; a tracklist row highlights only when `currentId` AND `contextType`/`contextId` match; the highlight was extended to the search "Tracks" group and recently-played list. Now-playing source indicator: a small static green speaker (`<x-lucide-volume-1 class="text-accent" />`, tagged `data-source-indicator`) on the sidebar playlist row and the album/playlist headers (both expanded and collapsed) when that list is the playback source; the animated `.eq` equalizer stays only in tracklist `#` columns.

**M7 follow-up (shipped 2026-05-12, same branch).** Search reworked into an overlay model: the topbar's `wire:model` input is gone; a `topbarSearch()` Alpine data component (registered in the layout `<head>`'s `alpine:init` block alongside `$store.player`, seeded from `request()->query('q','')`) owns the `<input id="topbar-search">`, dispatches `plextune-search` on debounced input, and has a clear `×` button. A new `<livewire:search />` component lives in the layout's `<main>` content wrapper (which gained `relative`); it renders an empty `<div>` when `$q` is blank and a `<div class="absolute inset-0 z-30 bg-base overflow-y-auto" data-region="search-overlay">` with the full search UI when `$q` is set; it owns `#[Url(as:'q',except:'')] public string $q`, `#[On('plextune-search')] applyTerm(string $term)`, `setFilter`, `playTrack`, `goToAlbum`, `goToArtist`, `showSection`, and `results()` (errors go to a `notify` toast, no more inline error panel). The topbar Livewire class is now empty. The `/search` route page stub remains for direct entry. Net: typing never navigates, never loses focus, never clobbers a typed character; `?q=` stays in the URL for sharing. Per-section compact density: `AppSetting` gained `artistsCompact`/`albumsCompact`/`libraryTracksCompact`/`playlistTracksCompact` (each defaults to `density() === 'compact'` when unset, then sticks once explicitly toggled, via a private `sectionCompact($key)` helper); the placeholder grid-2x2/3-line header buttons in the Artists column, Albums column, library tracklist, and playlist tracklist are now wired (`wire:click="$set(...)"` + `updated*` hooks); compact uses the real reduced layout from `design/rendered/compact.html` (miller rows: `px-3 py-[3px] text-[13px]`, no thumbnail/metadata, selected = `bg-accent/15 text-accent`; library tracklist: `grid-template-columns: 20px 1.4fr 1fr 50px`, no cover/like, `py-[3px]`; playlist tracklist: `20px 1.4fr 1fr 1fr 50px`, keeps Album column); server-side `@if` branches per the Alpine-morph gotcha. The Settings "Density" toggle was relabeled "Density default". The M7 global `data-density` attribute on `<body>` and its CSS block were removed (superseded). Media Session API in `audioPlayer()`: `setActionHandler` for play/pause/previoustrack/nexttrack (OS media keys, macOS Now Playing widget), `MediaMetadata` set in `loadAndPlay`, `playbackState` synced on `@play`/`@pause`. Tests: `tests/Unit/DurationTest.php`, `tests/Feature/ToastsTest.php`, `tests/Browser/KeyboardShortcutsTest.php`, `tests/Feature/SearchComponentTest.php` (replaced the deleted `SearchViewTest.php`); extended `AppSettingTest`/`PlexClientTest`/`PlayerComponentTest`/`SettingsViewTest`/`LibraryViewTest`/`PlaylistDetailViewTest`/`SidebarTest`/`RoutesTest`/`TopbarTest`/`PlayerQueueTest`/`SearchTest`/`SettingsTest`.

**Player volume persistence + mute toggle (shipped 2026-05-12, branch `feat/player-volume-persistence`).** Player volume now persists across full page reloads via `localStorage['plextune.volume']`: `setVolume()` writes the value, `audioPlayer().init()` restores and validates it to the `[0,1]` range; both paths are `try/catch`-wrapped. The player's speaker button is now a mute toggle (`data-control="mute"`): `toggleMute()` flips the native `<audio>.muted` and the Alpine `muted` prop; `x-lucide-volume-2` (`data-icon="unmuted"`) and `x-lucide-volume-x` (`data-icon="muted"`) swap via `x-show`/`x-cloak`. Dragging the volume slider clears mute (via `setVolume()`). Mute state is intentionally not persisted across reloads. Tests: `tests/Browser/PlayerVolumeTest.php` (2).

**Playlist tracklist artist/album links (shipped 2026-05-12, branch `feat/playlist-tracklist-links`).** Spec: `docs/superpowers/specs/2026-05-12-plextune-playlist-tracklist-links-design.md`. Plan: `docs/superpowers/plans/2026-05-12-plextune-playlist-tracklist-links.md`. In the playlist-detail tracklist (both the compact and comfortable row variants) the artist text and the Album column are now `<span>`s with `@click.stop="Livewire.navigate('{{ route('library', ['artist' => artistId, 'album' => albumId]) }}')"`: clicking the artist opens `/?artist=...`, the album opens `/?artist=...&album=...` (the library album tracklist), mirroring the now-playing strip's `goToAlbum()`/`goToArtist()`. `.stop` keeps the click off the row's `wire:click="playTrack"`. Server-side `@if ($track->artistId)` / `@if ($track->artistId && $track->albumId)` guards fall back to plain text when the `Track` DTO's `artistId` (`grandparentRatingKey`) / `albumId` (`parentRatingKey`) is null. Only `pages/⚡playlist-detail.blade.php` changed (no PHP class / DTO changes); the library album tracklist and search results were intentionally left as plain text. Tests: extended `tests/Feature/PlaylistDetailViewTest.php` (+3) and `tests/Browser/PlaylistDetailTest.php` (+1).

Git log is the authoritative record of what shipped. `php artisan test` should be green (246 tests, including 20 Playwright browser tests).

## How work is done here

Milestones are built via the `superpowers:brainstorming` -> `writing-plans` -> `subagent-driven-development` flow: brainstorm decisions with the user, write a detailed plan doc, get approval, then execute task by task with a fresh implementer subagent per task plus a spec compliance review and a code quality review after each. Each task is a small TDD increment with its own commit. Don't skip the plan or the reviews.

## Stack and conventions

- **Laravel 13**, **Livewire 4** (single file components), **Alpine.js**, **Tailwind CSS 4**, **Pest 4** (with `pestphp/pest-plugin-browser` + Playwright), **MySQL** (via DBngin, not Herd), native HTML5 `<audio>`.
- **Livewire 4 SFCs** live at `resources/views/components/⚡<name>.blade.php` (the ⚡ is a literal U+26A1 emoji prefix, the v4 default). `php artisan make:livewire <name>` creates them there. Routed page components are at `resources/views/pages/⚡<name>.blade.php` and routed with `Route::livewire('/path', 'pages::name')`. Component tags self close: `<livewire:name />`.
- **Layout:** `resources/views/components/layouts/app.blade.php`. Three region grid: topbar / (sidebar + main slot) / player. The `<livewire:player />` is wrapped in `@persist('player')` so it (and its audio + Alpine state) survive `wire:navigate` page changes. The Livewire player component is a thin relay: `#[On('play-track')]` receives a payload of `queue` (array of track objects), `index` (int), and `shuffle` (bool), then re-broadcasts a `queue-load` browser event that the Alpine `audioPlayer()` scope consumes. All playback queue state (current track, auto-advance, skip forward/back with Spotify-style skip-back, shuffle, three-state repeat, consecutive error tracking) lives in `audioPlayer()`. Views build the full queue with stream URLs pre-resolved server-side (`PlexClient::streamUrl()`/`thumbUrl()` are pure string building) and dispatch `play-track` with the whole list. The now-playing strip binds to the Alpine `current` getter.
- **Icons:** `blade-ui-kit/blade-icons` + `mallardduck/blade-lucide-icons`. Use `<x-lucide-* />`. Never substitute Unicode glyphs for icons.
- **Design tokens** (in `resources/css/app.css`, Tailwind 4 `@theme` block): `bg-base` (#000), `bg-surface` (#121212), `bg-surface-1`..`bg-surface-4`, `text-text-1`/`text-text-2`/`text-text-3`, `bg-accent` (#1ED760), `bg-accent-hover` (#3BE477), `accent-press` (#169c46), `plex-orange-from`/`plex-orange-to`. Font is **DM Sans** (with Outfit as `--font-display`), loaded from Google Fonts in the layout head.
- **Visual fidelity rule:** when building a view, port the HTML structure and Tailwind class lists verbatim from `design/rendered/<view>.html` (the rendered DOM), not from the React JSX. Where the prototype shows data we don't have from Plex (see Gotchas), omit it gracefully rather than faking it.
- **Plex integration:** `app/Services/Plex/`. `PlexClient` (auto discovers the server via plex.tv resources, finds the music section, lists artists/albums/tracks, `playlists()` and `playlistTracks($id)` via `/playlists?playlistType=audio` + `/playlists/{id}/items` — both cached 5min (`PlexCache::TTL_PLAYLISTS`), `searchAll($query)` via `/hubs/search` — not cached, builds stream + thumbnail URLs, `ping()` for status; `addTrackToPlaylist`/`addAlbumToPlaylist` (`PUT /playlists/{id}/items?uri=server://{machineId}/.../metadata/{ratingKey}`), `createPlaylist($title, $seedTrackId)` (`POST /playlists?type=audio&title=&smart=0&uri=...`, returns the new rating key; Plex cannot create an empty playlist, so creation always seeds from one track), `renamePlaylist`/`deletePlaylist`, `machineIdentifier()` (cached, from `/identity`); each playlist mutation busts `plex:playlists` (and `plex:playlist:{id}:items`); `recentlyAddedAlbums($limit = 50)` (`GET /library/sections/{id}/recentlyAdded?type=9` with `X-Plex-Container-Size`; cached 5min under `plex:recently_added:{limit}`); `recentlyPlayedTracks($limit = 50)` (`GET /library/sections/{id}/all?type=10&sort=lastViewedAt:desc` with `X-Plex-Container-Size: 4 * limit`, filtered in PHP to non-zero `lastViewedAt` rows then sliced to `$limit`; cached 5min under `plex:recently_played:{limit}`). `PlexCache` (namespaced cache wrapper, TTL constants). DTOs in `Dto/` (`Artist`, `Album`, `Track`, `Playlist` — `Playlist` has a nullable `summary` (description); `Track` carries `albumId` (`parentRatingKey`) and `artistId` (`grandparentRatingKey`) — all `readonly`, with `fromPlex(array): self` factories; plus `SearchResults`, an aggregate of four DTO collections with `empty()`/`total()`/`isEmpty()`). `PlexClient::queueItem(Track, ?artworkOverride)` builds one `play-track` queue payload item (`{id, url, title, artist, artwork, albumId, artistId}`) and is the single builder used by every view that plays tracks. Exceptions in `Exceptions/` (`PlexException` abstract base, `PlexUnreachableException`, `PlexAuthException`, `PlexNotFoundException`). The Plex token is in `.env` as `PLEX_TOKEN` (gitignored). `PLEX_BASE_URL` is left blank so discovery runs; set it to override.
- **Coding standards:** see `~/.claude/laravel-php-guidelines.md` (PSR-12, typed properties, constructor property promotion, early returns, no `else`, no docblocks on fully typed methods, migrations have only `up()`, etc.). Don't use em dashes or en dashes in markdown docs (use periods, commas, parentheses).

## Gotchas (hard won from M1/M2/M3 — read before touching these areas)

- **`->throw(false)` is a TypeError** in this Laravel version. `PendingRequest::throw()` only accepts `?callable`. Laravel's HTTP client does NOT auto throw on 4xx/5xx by default, so just inspect `$response->status()` / `$response->successful()` yourself.
- **Livewire 4 components need ONE stable root element.** A top level `@if ... @else ... @endif` with no wrapping element causes the morph algorithm to choke after the first update (it throws the component ID as a JS error, silently killing event delegation, so subsequent `wire:click`s do nothing). Always wrap the template body in a single root `<div>` with the conditionals inside it. (This bit us in the library view.)
- **Bare `@island ... @endisland`** (no name) renders the wrapped region empty on initial page load (it becomes a deferred fragment). Don't use it for content that must render on first load. For M2 we just dropped islands entirely; the whole component re-rendering on each click is fine for these data sizes.
- **`#[Computed]` methods do NOT autowire type hinted parameters** in this Livewire version. Resolve services via `app(SomeService::class)` inside the computed. Action and lifecycle methods (`mount`, `boot`, `wire:click` handlers) DO autowire params, so prefer param injection there. The `boot(PlexClient $plex)` + `protected PlexClient $plex` pattern (used by `pages::search`) is cleaner than repeated `app()` calls in computeds; the library view still uses `app()` everywhere, fine to refactor when touching it.
- **Livewire 4 `mount()` does NOT receive query-string parameters** — only `{route}` segment params and DI. To read `?q=` / `?artist=` etc. off the URL, bind a public property with `#[Url]` (use `#[Url(as: 'artist', except: null)]` to alias the query key and keep it out of the URL when null). `pages::search` (`$q`) and `pages::library` (`$selectedArtistId`/`$selectedAlbumId`) do this. Note `Livewire::test` only fills `#[Url]` props via `Livewire::withQueryParams([...])->test(...)`, not via the `mount`-params array. (M3 initially tried `mount(?string $q = null)` — the unit tests passed because they pass the param explicitly, but the real `/search?q=...` page load silently ignored it.)
- **Blade directives need a non-word char before the `@`** (the compiler matches `\B@if(...)` etc.). `Word@if($x)…@endif` renders the `@if`/`@endif` as literal text while their partner compiles, so you get an "unexpected `endif`/`endforeach`" parse error. When interleaving conditional bits into a literal-word string (subtitles like `Album · 2007 · Artist`, `Artist · 5 albums`), build the string in PHP — `{{ collect(['Album', $album->year, $album->artist])->filter()->implode(' · ') }}` — instead of `Album@if($album->year) · {{ $album->year }}@endif`. (Bit M3's search rows.)
- **A `#[Computed]` that sets a public prop as a side effect needs priming.** `pages::search`'s `results()` computed catches `PlexException` and writes `$this->errorMessage`. Livewire copies public props into the view's local `$errorMessage` *before* render, so the template must (a) force the computed to run first — a bare `@php($this->results)` line at the top of the body — and (b) read `$this->errorMessage`, not the local `$errorMessage`. Cleaner alternative if you touch it: do the fetch in `mount()`/`updatedX()` and store on a public prop (the library page's `loadArtists()` pattern). Also: `assertSee()` html-escapes its needle, so a template emitting a literal `"` won't match `assertSee('… "x"')` — emit `&quot;` (search's no-results heading does this).
- **Plex bulk listings don't include child counts or durations.** `/library/sections/{id}/all?type=8` (artists) has no `childCount`. `/library/metadata/{artistId}/children` (albums) has no `leafCount` or `duration`. So artist rows don't show "{N} albums" and album rows don't show "{N} tracks" (those were dropped). The album header computes its "{N} songs, {duration}" from the loaded tracklist (`$this->tracks->count()` / `->sum('durationMs')`) instead. **M3 finding:** `/hubs/search` artist hits DO include `childCount` (album count, used for "Artist · {N} albums" rows) but no track count; album hits include neither `leafCount` nor `duration`, so search album rows show "Album · {year} · {artist}" only (no track count / duration), same as the library.
- **`config/cache.php` has a `serializable_classes` allowlist** (`Collection::class`, `Artist::class`, `Album::class`, `Track::class`, `Playlist::class`) because `PlexCache` stores DTO collections and Laravel 11.41+ blocks unserializing cached objects by default. A cleaner long term fix is to have `PlexCache` store raw arrays and rehydrate DTOs on read, then drop the broad `Collection::class` entry; not done yet. (M4 added `Playlist::class` when `playlists()`/`playlistTracks()` started caching.) **Adding a property to a cached DTO requires `php artisan cache:clear`** (the cache driver here is `database`, so old serialized DTOs persist across deploys; PHP's `unserialize` doesn't run the constructor, so a newly-added typed property comes back uninitialized and the first access throws "must not be accessed before initialization"). The Settings page's "Resync metadata" button (M6) does the same thing via `PlexCache::flushAll()`.
- **`PlexCache::flushAll()` works across requests** (M6). Every `remember()` appends its namespaced key to a `plex:_index` cache entry (a plain string array, TTL 7 days so it outlives the longest tracked entry); `forget()` removes from it; `flushAll()` reads the index, `Cache::forget`s each entry, then drops the index. The old in-memory `$keys` array was per-request and so was a no-op as a button handler. Single-user app, so the read-modify-write race on the index is acceptable. `flushAll()` only nukes `plex:*` keys, not the whole `cache` table.
- **Alpine stores must be registered before Alpine's first DOM walk.** `$store.player` is registered from a plain `<script>` in the layout `<head>` (`document.addEventListener('alpine:init', () => Alpine.store('player', {...}))`), not from a Livewire `@script` block — `@script` runs *after* `alpine:init` has fired and after Alpine has already walked the page, so a `:class`/`x-if` binding on `$store.player` that renders in the initial HTML (e.g. the album tracklist when `?album=` is in the URL) would evaluate against a not-yet-reactive store and never subscribe to it. It only "fixed itself" after a `wire:navigate` re-walked that subtree. Register shared stores early, in `<head>`.
- **DBngin provides MySQL** on `127.0.0.1:3306` (root, no password). Herd serves the PHP site only. The `mysql` CLI may not be on PATH; the Homebrew `mysql-client` is at `/opt/homebrew/Cellar/mysql-client/.../bin/mysql`.
- **The Vite HMR WebSocket error** in the browser console (`wss://plexify.test:5173 failed`) is dev server only and harmless. Run `npm run build` and don't run `npm run dev` to avoid it, or add an HMR host config to `vite.config.js`. The app works fine either way.
- **App-side folders are Eloquent, not Plex.** `Folder` (`name`/`position`/`expanded`) + `FolderPlaylist` (`folder_id`/`plex_playlist_id`/`position`, with a unique index on `plex_playlist_id` so a playlist sits in at most one folder). The sidebar's `folders()` computed eager-loads `folderPlaylists`; mutations `unset($this->folders)` (and `unset($this->playlists)` after a Plex write) to bust the per-request computed cache so the re-render reflects the change. `FolderTest`/`SidebarTest` (and now `RoutesTest` and all `tests/Browser/*`, since the sidebar's `folders()` query runs on every full-page render) need `uses(RefreshDatabase::class)` (it is not global in `tests/Pest.php`).
- **DnD is native HTML5 + Alpine.** Sidebar playlists card is one `x-data` scope; playlist rows, folder headers, and the "New playlist" zone are drop targets (`@dragover.prevent` + `@drop` => `$wire.*`). Track rows in `library`/`playlist-detail`/`search` and the library album cover are sources but their page roots have no `x-data`, so they use a plain `ondragstart="event.dataTransfer.setData('plextune/track'|'plextune/album', id)"` HTML attribute, not `@dragstart`. The sidebar shows the "New playlist" zone only while a `plextune/track` drag is in flight (a `window` `dragstart`/`dragend` listener checking `e.dataTransfer.types`). Drop feedback is a ~700ms green/red ring flash, not a toast (toasts are M7). `Playlist` write actions return `bool` so the Alpine layer can flash without tripping Livewire's error modal.
- **Sidebar context menus are Alpine** (`@contextmenu.prevent` => `fixed` popover, `@click.outside` / Escape to close). The menu state is `menu = { x, y, kind, id }` and the menus use `x-show` + `x-cloak`, not `<template x-if>` (see the "null guard" note below). The "Move to folder" submenu from the prototype is flattened to a flat list of `Move to {folder}` entries plus an always enabled `Remove from folder` (idempotent on a playlist already at root). The original `folderOf` JS map / current folder dot indicator was dropped because it went stale after a move within the same session (an `x-data` initializer freezes at page load and Livewire's morph does not re-evaluate it).
- **Null guard every Alpine reactive expression that references a possibly null state object.** `:style="\`left:${menu.x}px\`"` will throw an uncaught `TypeError: Cannot read properties of null (reading 'x')` the moment `menu` transitions to null, and the uncaught throw kills Alpine for the rest of the page session (`@dragover`, `@drop`, `@click.outside` all stop firing). Write `:style="menu ? { left: menu.x + 'px', top: menu.y + 'px' } : {}"` instead, and prefer object syntax `:style` over template literals. (This silently broke playlist drag into folder and the right click menu position in M5 until the browser console pointed at the throw.)
- **Reusable playlist row** lives at `resources/views/partials/playlist-row.blade.php` (`@include`d by the sidebar; `$this` is not available in an `@include`, so the pre-resolved thumb URL is passed in as `$thumbUrl`). The row's broken image fallback renders the list-music placeholder behind the `<img>` and the `<img>` carries `onerror="this.remove()"`, so a Plex composite that 404s (common for a brand new playlist) reveals the placeholder. The 180px detail page cover (`pages/⚡playlist-detail.blade.php`) uses the same pattern.
- **`wire:navigate` on a draggable `<a>` blocks the native `dragstart`** in this Livewire 4 build, silently (no console output, no error, the drag just never initiates). On draggable rows, drop `wire:navigate` and substitute `@click.prevent="Livewire.navigate('{{ route(...) }}')"`. Keep `href` so right click and open in new tab still work. The sidebar playlist row uses this pattern.
- **Alpine `x-show` with a server rendered JS literal will not re-bind when Livewire morphs the attribute.** Writing `x-show="filter ? true : {{ $folder->expanded ? 'true' : 'false' }}"` captures the rendered literal inside Alpine's reactive effect. On `toggleFolder` the server re-renders with the new `expanded` value baked in and the attribute string changes, but Alpine's effect was created from the old source and never re-evaluates, so the body stays where it was. For state that flips via `wire:click`, either gate the markup with a server side `@if ($folder->expanded || ...)`, or sync the state into Alpine via `@entangle('publicProp')` so the binding has a reactive source. The sidebar filter is therefore a Livewire `public string $filter = ''` (`wire:model.live.debounce.200ms`), not an Alpine local input, which lets the folder bodies use a plain `@if ($folder->expanded || $filter !== '')` gate that still auto expands during filtering.
- **Pest 4 browser plugin** does have `script()` (page.evaluate), `text($selector)`, `assertPresent()`, `visit()`, `click()`, `assertVisible()` (no auto wait), `assertSee()`, `assertUrlIs()`. `click()` uses a strict Playwright locator (errors on multiple matches), so for lists with many buttons drive the interaction via `script()` rather than `click('.some-selector')`. The library drill down browser test (`tests/Browser/LibraryDrillDownTest.php`) is a good reference for this pattern. Browser tests hit the live Plex server, so they fail if it's unreachable.
- **Plex's smart playlists are hidden app-wide.** `Playlist` DTO carries a `smart` bool (read from `$row['smart']`, accepts both bool and numeric); `PlexClient::playlists()` and `PlexClient::searchAll()`'s playlist hub both `->reject(fn ($p) => $p->smart)` so the sidebar and search never surface the auto-generated All Music, Fresh, Recently Added, and Recently Played playlists. These exposed-as-real-playlists were misleading because they cannot be modified the way user playlists can.
- **Recently Played accuracy is bounded by scrobbling.** Scrobble-to-Plex shipped in M7 (see the M7 paragraph above), so `lastViewedAt` now updates for plays in this app when the scrobble toggle is on. Plays from other clients are reflected regardless.
- **`play-track` event payload is `queue` / `index` / `shuffle` / `contextType` / `contextId`.** The `queue` field is an array of objects with shape `{id, url, title, artist, artwork, albumId, artistId}` (build each via `PlexClient::queueItem(Track, ?artworkOverride)` — don't hand-roll the array); `index` is the int position to start from; `shuffle` is bool. `contextType` is `'album'|'playlist'|'search'|'recently-played'` and `contextId` is the album/playlist ratingKey or null — every dispatcher must set these so the context-aware highlight and source indicator work correctly. Every view that plays a track must dispatch the whole relevant list, not a single track. The queue is client-side only (Alpine `audioPlayer()` scope). One syntax trap: `@error="..."` on the `<audio>` element is parsed as the Blade `@error` directive (form validation helper), so the native error event must be bound with `x-on:error="onTrackError()"` instead.
- **Search input must be Alpine-owned (`x-model`), not `wire:model`.** A `wire:model` input gets clobbered when Livewire morphs after a re-render or navigation. The search box lives in a layout-level component (topbar) but the results live in a separate component (`<livewire:search>`); they communicate via the `plextune-search` browser event (Alpine `$dispatch` via `x-on:input.debounce.300ms` → Livewire `#[On]`), and the search component re-renders only itself — no navigation, so the topbar input is never touched. This replaced the old per-keystroke `redirectRoute('search', ..., navigate: true)` which caused the lost-focus and clobbered-character bugs.
- **`Alpine.data(...)` registrations for layout-level components must go in the layout `<head>`'s `alpine:init` listener** (alongside `$store.player`), not in a `@once`/`@script` block in the component's Blade file. `@script` on a Livewire component runs after `alpine:init` has fired, so the data component would not be defined when Alpine first walks the page. `topbarSearch()` is registered this way.
- **Per-section compact toggles are server-side `@if`/`@else` branches**, re-rendered on `wire:click="$set(...)"`. This avoids the Alpine-morph-doesn't-re-evaluate-a-server-rendered-JS-literal gotcha (the same reason the sidebar folder bodies use `@if ($folder->expanded)` rather than `x-show` with a baked-in literal).
- **Adding `mount(PlexClient $plex)` (or any call to `PlexClient`) to a layout-level component** means every full-page render and `Livewire::test('pages::...')` now invokes it. Feature tests that mock `PlexClient` must stub whatever the new mount or computed calls. `<livewire:search />` is now in the layout and calls `searchAll` only when `?q=` is set, but `thumbUrl` is called for overlay results — mock accordingly for tests that render the layout with a query.
- **The persistent player's `audioPlayer().init()` can run more than once** (Livewire re-runs `@script` on component updates), so listener registrations inside it are guarded by a `window.__plexifyPlayerInited` flag set on first init. Skip duplicate registration when the flag is already set.

## Test layout

- `tests/Unit/` — `PlexClientTest` (Http::fake), `PlexCacheTest`, `PlexDtoTest`, `AppSettingTest`, `DurationTest`. Fast, no Plex.
- `tests/Feature/` — `RoutesTest`, `SettingTest`, `PlayerComponentTest`, `LibraryViewTest`, `ServerChipTest`, `SearchComponentTest` (replaced `SearchViewTest`), `TopbarTest`, `PlaylistDetailViewTest`, `SidebarTest`, `FolderTest`, `SettingsViewTest`, `ToastsTest`. Use mocked `PlexClient` (and `Livewire::withQueryParams([...])->test(...)` for `#[Url]` props). Note `Livewire::test('sidebar')` (and `Livewire::test('pages::settings')`, which renders the layout including the sidebar) also renders the nested `<livewire:server-chip />`, so the `PlexClient` mock must stub `ping()` too. The layout now also renders `<livewire:search />` — tests that mock `PlexClient` should stub `searchAll` when rendering a full layout with `?q=` set.
- `tests/Browser/` — `LibraryDrillDownTest`, `SearchTest`, `PlaylistDetailTest`, `SidebarFoldersTest`, `PlayerQueueTest`, `SettingsTest`, `KeyboardShortcutsTest` (Playwright, hit live Plex). `tests/Browser/Screenshots/` is gitignored.
- `phpunit.xml` registers `Unit`, `Feature`, `Browser` testsuites. `tests/Pest.php` `->in('Unit', 'Feature', 'Browser')`.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
