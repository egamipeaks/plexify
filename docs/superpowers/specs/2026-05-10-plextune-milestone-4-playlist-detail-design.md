# Milestone 4 — Playlist detail view (design)

Status: approved 2026-05-10. Next step: write the implementation plan in `docs/superpowers/plans/`.

Scope: read Plex audio playlists, show them in the sidebar as a flat list, and render a playlist detail page (gradient header + tracklist with an Album column). No playlist mutations (rename, edit contents, delete), no folders, no drag and drop. Those are M5 / later.

Reference design: `design/rendered/view-playlist.html` (rendered DOM, source of truth for class lists and icon markup). The older `design/README.md` section 5 describes intent. Port the gradient header and tracklist markup verbatim.

## Decisions locked in brainstorming

- **Read-only.** The "Edit playlist" outlined button and the Download icon in the header are inert visuals (`type="button"`, no handler), like the Download icons already shipped in the library. The title is plain text, not click-to-rename. No `PlexClient` write methods this milestone.
- **Sidebar gets a flat playlist list now.** The existing "Your Playlists" card renders Plex audio playlists as links to `/playlist/{id}`. Folders, DnD, context menus, the New folder / New playlist / filter / Recents controls stay as inert visuals; M5 wires them.
- **Mosaic cover = Plex composite image.** Use the playlist's `composite` thumbnail (already exposed as `Playlist::$thumb`) as a single image filling the 180x180 box. Plex pre-renders it as a 4-up mosaic, so it reads like the prototype's 2x2 grid. Placeholder tile (`x-lucide-list-music`) when null.
- **Play / Shuffle behaviour.** Header Play plays the first track; Shuffle plays a random track; clicking a row plays that track. No queue / auto-advance yet (the player has none; that is later polish). Each is the same `$dispatch('play-track', ...)` the library and search views already use.

## 1. Service layer

### `Playlist` DTO

Add a nullable `summary` field (Plex playlist description), set from `$row['summary'] ?? null` in `fromPlex()`. Everything else unchanged.

### `PlexClient`

- `playlists(): Collection<Playlist>` — `GET /playlists` with `['playlistType' => 'audio']`. Maps `MediaContainer.Metadata` (default `[]`) through `Playlist::fromPlex()`. Cached `PlexCache::TTL_PLAYLISTS` (300 s) under key `playlists`. 404 → `PlexNotFoundException`; other non-2xx → `PlexUnreachableException`; `ConnectionException` → `PlexUnreachableException` — same shape as the existing methods.
- `playlistTracks(string $playlistId): Collection<Track>` — `GET /playlists/{playlistId}/items`. Maps `MediaContainer.Metadata` through `Track::fromPlex()`. Cached 300 s under key `playlist:{playlistId}:items`. Same exception mapping (404 → `PlexNotFoundException`).
- `PlexCache` gains `TTL_PLAYLISTS = 300`.

The detail page does **not** call a per-playlist metadata endpoint; it reads the playlist's title / summary / composite / leafCount / duration from `playlists()->firstWhere('id', $id)` (those fields are present on the `/playlists` list rows).

### Cache config

`config/cache.php` `serializable_classes` allowlist gains `App\Services\Plex\Dto\Playlist::class` (the cached collections now contain `Playlist` DTOs). `Track::class` is already allowlisted.

## 2. Playlist detail page — `resources/views/pages/⚡playlist-detail.blade.php`

Replaces the current stub. `#[Layout('components.layouts.app')]`. `$playlist` stays a plain public string filled by `mount(string $playlist)` from the `{playlist}` route segment (route-segment params *do* reach `mount`, unlike query-string params). `boot(PlexClient $plex)` + `protected PlexClient $plex` (the `pages::search` pattern). Public `?string $errorMessage = null`.

Computeds (both catch `PlexException`, set `$errorMessage`, return null/empty):

- `#[Computed] playlistMeta(): ?Playlist` — `$this->plex->playlists()->firstWhere('id', $this->playlist)`.
- `#[Computed] tracks(): Collection<Track>` — `$this->plex->playlistTracks($this->playlist)` (only meaningful when `playlistMeta` resolved; if it didn't, return an empty collection without calling Plex again).

Template body is a single root `<div>` (Livewire 4 needs one stable root). First line `@php($this->tracks)` (and `@php($this->playlistMeta)`) to force the computeds to run so a Plex failure populates `$errorMessage` before the branches; the template reads `$this->errorMessage`, not the local. Branches:

1. `$this->errorMessage` set → "Can't reach your Plex server" panel + Retry button (ported from `pages::search`; `wire:click="retry"` unsets the computeds and clears the error).
2. `playlistMeta` is null (and no error) → "Playlist not found" centered panel (icon + heading + a "Back to library" link).
3. Otherwise → header + tracklist.

### Gradient header

Port the class lists from `design/rendered/view-playlist.html`. Gradient `linear-gradient(180deg, #4a3b6b 0%, #2a2438 50%, var(--bg-surface) 100%)` (use whatever the rendered DOM shows; the design README quotes `#4a3b6b → #2a2438 → var(--surface)`). Contents:

- 180x180 cover, `rounded shadow-2xl object-cover bg-surface-2`. `src` = `$this->plex->thumbUrl($playlistMeta->thumb)`; when null, a `bg-surface-2 grid place-items-center` tile with `x-lucide-list-music`. Not draggable in M4.
- `PLAYLIST` eyebrow (`text-[11px] font-bold uppercase tracking-wider text-white/80`).
- Title `h1`, `text-[clamp(28px,4.2vw,56px)] font-black tracking-tight leading-[1.05] truncate`, plain `{{ $playlistMeta->title }}`.
- Description: `{{ $playlistMeta->summary }}` line, only rendered when non-empty.
- Meta line: `"{count} songs, {duration}"` where `count = $this->tracks->count()` and `duration = formatMs($this->tracks->sum('durationMs'))` — same approach as the library album header. No owner (Plex gives no usable owner name; omit gracefully).
- Action row: 56px accent **Play** circle (`wire:click="playAll"`), **Shuffle** button (`wire:click="shuffle"`), **Edit playlist** outlined button (inert), **Download** icon (inert). No `+`, no `⋯`.

### Tracklist

Same comfortable-mode markup as the library tracklist: sticky header row, `style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;"`, columns grip / `#` / title+art / **Album** / `♥` / time. Each track row is a `<button wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')">` with the same hover (`bg-white/[0.07]`), grip-on-hover, `tabular-nums`, `formatMs($track->durationMs)`. The `#` column shows the **1-based loop index**, not `$track->trackNumber` (playlist order ≠ album track numbers). Album column = `{{ $track->album }}`. No compact toggle (M7 polish); mirror whatever the library page does for its compact branch (an empty stub region is fine).

### Actions

- `playTrack(string $trackId)` — `firstWhere('id', $trackId)` on `$this->tracks`, then `$dispatch('play-track', url: $this->plex->streamUrl($track), title: $track->title, artist: $track->artist, artwork: $this->plex->thumbUrl($track->thumb))`. No-op if not found. (Copied from `pages::search::playTrack`.)
- `playAll()` — same dispatch for `$this->tracks->first()` (no-op if empty).
- `shuffle()` — same dispatch for `$this->tracks->random()` (no-op if empty).
- `retry()` — `unset($this->playlistMeta, $this->tracks); $this->errorMessage = null;`.
- `formatMs(int $ms): string` — copy the helper from the library/search pages.

## 3. Sidebar flat playlist list — `resources/views/components/⚡sidebar.blade.php`

Becomes a real Livewire component (`new class extends Component`) with `#[Computed] playlists(): Collection<Playlist>` resolving `app(PlexClient::class)->playlists()` inside a try/catch that returns an empty collection on `PlexException` (the sidebar must never hard-fail). The nav card, server chip, and the "Your Playlists" card chrome (header, the New folder / Create playlist / filter / Recents controls) stay exactly as they are — those controls remain inert visuals.

The scroll area:

- If `$this->playlists` is non-empty: render each as `<a href="{{ route('playlist', $playlist->id) }}" wire:navigate wire:key="sidebar-pl-{{ $playlist->id }}">` — a row with a 32px `rounded-sm` composite thumb (`$plex->thumbUrl($playlist->thumb)`, placeholder `x-lucide-list-music` tile when null), the name (`truncate text-[14px] text-white`), and a `"Playlist · {N} songs"` subline (`text-[12px] text-text-2`). Active state (`bg-surface-2`, white text) when `request()->routeIs('playlist')` and the current `{playlist}` route param equals `$playlist->id`.
- If empty (no playlists, or Plex unreachable): keep the existing `<div class="px-3 py-6 text-[12px] text-text-3 text-center">No playlists yet</div>` placeholder.

`thumbUrl` access from the sidebar: inject `PlexClient` in `boot` (`protected PlexClient $plex;` + `boot(PlexClient $plex)`), or expose a `thumbFor(?string): ?string` method — match whatever the search view does.

## 4. Tests

- **`PlexClientTest`** (`Http::fake()`): `playlists()` requests `/playlists?playlistType=audio` and maps rows to `Playlist` DTOs; `playlistTracks($id)` requests `/playlists/{id}/items` and maps rows to `Track` DTOs; 404 on either → `PlexNotFoundException`; 5xx → `PlexUnreachableException`. Assert the cache key / TTL the same way the existing artist/album tests do (if they do).
- **`PlexDtoTest`**: `Playlist::fromPlex` populates `summary` (and tolerates its absence → null).
- **`PlaylistDetailViewTest`** (Feature, mocked `PlexClient`): page renders the title, description, "{N} songs, {duration}"; renders one row per track with the album column; `playTrack` / `playAll` / `shuffle` each dispatch `play-track` with the expected `url`/`title`/`artist`/`artwork`; Plex `PlexUnreachableException` during render → error panel visible, `retry()` recovers; an id not in `playlists()` → "Playlist not found" panel.
- **`SidebarTest`** (Feature, mocked `PlexClient`): renders a row per playlist with `href` = `route('playlist', $id)`; on `PlexException` renders "No playlists yet" and does not throw.
- **`RoutesTest`**: ensure `/playlist/{id}` returns 200 with mocked Plex (add if not already there).
- **`PlaylistDetailTest`** (Browser, Playwright, live Plex — skips/fails cleanly when Plex is down, like `LibraryDrillDownTest` / `SearchTest`): from `/`, click the first sidebar playlist row, assert the URL is `/playlist/...`, the header title text is present, and at least one track row renders; then drive a row click via `script()` and assert the `<audio>` element is playing (mirrors the library drill-down browser test).

Run `php artisan test` (expect green, ~+test count over the M3 baseline of 81) and `vendor/bin/pint --dirty` before each commit. Build via the usual `writing-plans` → `subagent-driven-development` flow: one TDD task per increment, spec-compliance + code-quality review after each, its own commit.

## Out of scope (later milestones)

- Playlist mutations: rename (M-later), add/remove tracks, reorder, delete, create. The header "Edit playlist" / Download buttons are inert.
- Sidebar folders, drag and drop, context menus, the New folder / New playlist / filter / Recents controls — M5.
- Player queue / auto-advance / next-prev wiring — M7 polish.
- Compact/comfortable density toggle on the playlist tracklist — M7 polish.
