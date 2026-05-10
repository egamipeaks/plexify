# Plexify (Plextune) — project guide

A Spotify style web app for browsing and playing music from a personal Plex Media Server. Desktop first, single user, runs locally on Laravel Herd over HTTPS at `https://plexify.test`.

The product name in the design files is "Plextune". The repo and app title are "Plexify". Treat them as the same thing.

## Where the canonical docs live

- **Design spec:** `docs/superpowers/specs/2026-05-09-plextune-design.md`. Architecture, tech stack, locked in decisions, the 7 milestone breakdown, migration notes. Read this first when picking up the project.
- **Implementation plans:** `docs/superpowers/plans/2026-05-09-plextune-milestone-N-*.md`. One per milestone. M1 and M2 plans exist and were executed (with some mid execution corrections, see Gotchas). M3 onward not yet written.
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
| 4 | Playlist detail view (read Plex playlists) | Next |
| 5 | App side folders + drag and drop | Not started |
| 6 | Settings page | Not started |
| 7 | Polish (keyboard shortcuts, transitions, error toasts, compact/comfortable density, formatMs hour handling, etc.) | Not started |

Git log is the authoritative record of what shipped. `php artisan test` should be green (81 tests as of end of M3, including three Playwright browser tests).

## How work is done here

Milestones are built via the `superpowers:brainstorming` -> `writing-plans` -> `subagent-driven-development` flow: brainstorm decisions with the user, write a detailed plan doc, get approval, then execute task by task with a fresh implementer subagent per task plus a spec compliance review and a code quality review after each. Each task is a small TDD increment with its own commit. Don't skip the plan or the reviews.

## Stack and conventions

- **Laravel 13**, **Livewire 4** (single file components), **Alpine.js**, **Tailwind CSS 4**, **Pest 4** (with `pestphp/pest-plugin-browser` + Playwright), **MySQL** (via DBngin, not Herd), native HTML5 `<audio>`.
- **Livewire 4 SFCs** live at `resources/views/components/⚡<name>.blade.php` (the ⚡ is a literal U+26A1 emoji prefix, the v4 default). `php artisan make:livewire <name>` creates them there. Routed page components are at `resources/views/pages/⚡<name>.blade.php` and routed with `Route::livewire('/path', 'pages::name')`. Component tags self close: `<livewire:name />`.
- **Layout:** `resources/views/components/layouts/app.blade.php`. Three region grid: topbar / (sidebar + main slot) / player. The `<livewire:player />` is wrapped in `@persist('player')` so it (and its audio + Alpine state) survive `wire:navigate` page changes.
- **Icons:** `blade-ui-kit/blade-icons` + `mallardduck/blade-lucide-icons`. Use `<x-lucide-* />`. Never substitute Unicode glyphs for icons.
- **Design tokens** (in `resources/css/app.css`, Tailwind 4 `@theme` block): `bg-base` (#000), `bg-surface` (#121212), `bg-surface-1`..`bg-surface-4`, `text-text-1`/`text-text-2`/`text-text-3`, `bg-accent` (#1ED760), `bg-accent-hover` (#3BE477), `accent-press` (#169c46), `plex-orange-from`/`plex-orange-to`. Font is **DM Sans** (with Outfit as `--font-display`), loaded from Google Fonts in the layout head.
- **Visual fidelity rule:** when building a view, port the HTML structure and Tailwind class lists verbatim from `design/rendered/<view>.html` (the rendered DOM), not from the React JSX. Where the prototype shows data we don't have from Plex (see Gotchas), omit it gracefully rather than faking it.
- **Plex integration:** `app/Services/Plex/`. `PlexClient` (auto discovers the server via plex.tv resources, finds the music section, lists artists/albums/tracks, builds stream + thumbnail URLs, `ping()` for status). `PlexCache` (namespaced cache wrapper, TTL constants). DTOs in `Dto/` (`Artist`, `Album`, `Track`, all `readonly`, with `fromPlex(array): self` factories). Exceptions in `Exceptions/` (`PlexException` abstract base, `PlexUnreachableException`, `PlexAuthException`, `PlexNotFoundException`). The Plex token is in `.env` as `PLEX_TOKEN` (gitignored). `PLEX_BASE_URL` is left blank so discovery runs; set it to override.
- **Coding standards:** see `~/.claude/laravel-php-guidelines.md` (PSR-12, typed properties, constructor property promotion, early returns, no `else`, no docblocks on fully typed methods, migrations have only `up()`, etc.). Don't use em dashes or en dashes in markdown docs (use periods, commas, parentheses).

## Gotchas (hard won from M1/M2/M3 — read before touching these areas)

- **`->throw(false)` is a TypeError** in this Laravel version. `PendingRequest::throw()` only accepts `?callable`. Laravel's HTTP client does NOT auto throw on 4xx/5xx by default, so just inspect `$response->status()` / `$response->successful()` yourself.
- **Livewire 4 components need ONE stable root element.** A top level `@if ... @else ... @endif` with no wrapping element causes the morph algorithm to choke after the first update (it throws the component ID as a JS error, silently killing event delegation, so subsequent `wire:click`s do nothing). Always wrap the template body in a single root `<div>` with the conditionals inside it. (This bit us in the library view.)
- **Bare `@island ... @endisland`** (no name) renders the wrapped region empty on initial page load (it becomes a deferred fragment). Don't use it for content that must render on first load. For M2 we just dropped islands entirely; the whole component re-rendering on each click is fine for these data sizes.
- **`#[Computed]` methods do NOT autowire type hinted parameters** in this Livewire version. Resolve services via `app(SomeService::class)` inside the computed. Action and lifecycle methods (`mount`, `boot`, `wire:click` handlers) DO autowire params, so prefer param injection there. The `boot(PlexClient $plex)` + `protected PlexClient $plex` pattern (used by `pages::search`) is cleaner than repeated `app()` calls in computeds; the library view still uses `app()` everywhere, fine to refactor when touching it.
- **Livewire 4 `mount()` does NOT receive query-string parameters** — only `{route}` segment params and DI. To read `?q=` / `?artist=` etc. off the URL, bind a public property with `#[Url]` (use `#[Url(as: 'artist', except: null)]` to alias the query key and keep it out of the URL when null). `pages::search` (`$q`) and `pages::library` (`$selectedArtistId`/`$selectedAlbumId`) do this. Note `Livewire::test` only fills `#[Url]` props via `Livewire::withQueryParams([...])->test(...)`, not via the `mount`-params array. (M3 initially tried `mount(?string $q = null)` — the unit tests passed because they pass the param explicitly, but the real `/search?q=...` page load silently ignored it.)
- **Plex bulk listings don't include child counts or durations.** `/library/sections/{id}/all?type=8` (artists) has no `childCount`. `/library/metadata/{artistId}/children` (albums) has no `leafCount` or `duration`. So artist rows don't show "{N} albums" and album rows don't show "{N} tracks" (those were dropped). The album header computes its "{N} songs, {duration}" from the loaded tracklist (`$this->tracks->count()` / `->sum('durationMs')`) instead. **M3 finding:** `/hubs/search` artist hits DO include `childCount` (album count, used for "Artist · {N} albums" rows) but no track count; album hits include neither `leafCount` nor `duration`, so search album rows show "Album · {year} · {artist}" only (no track count / duration), same as the library.
- **`config/cache.php` has a `serializable_classes` allowlist** (`Collection::class`, `Artist::class`, `Album::class`, `Track::class`) because `PlexCache` stores DTO collections and Laravel 11.41+ blocks unserializing cached objects by default. A cleaner long term fix is to have `PlexCache` store raw arrays and rehydrate DTOs on read, then drop the broad `Collection::class` entry; not done yet.
- **DBngin provides MySQL** on `127.0.0.1:3306` (root, no password). Herd serves the PHP site only. The `mysql` CLI may not be on PATH; the Homebrew `mysql-client` is at `/opt/homebrew/Cellar/mysql-client/.../bin/mysql`.
- **The Vite HMR WebSocket error** in the browser console (`wss://plexify.test:5173 failed`) is dev server only and harmless. Run `npm run build` and don't run `npm run dev` to avoid it, or add an HMR host config to `vite.config.js`. The app works fine either way.
- **Pest 4 browser plugin** does have `script()` (page.evaluate), `text($selector)`, `assertPresent()`, `visit()`, `click()`, `assertVisible()` (no auto wait), `assertSee()`, `assertUrlIs()`. `click()` uses a strict Playwright locator (errors on multiple matches), so for lists with many buttons drive the interaction via `script()` rather than `click('.some-selector')`. The library drill down browser test (`tests/Browser/LibraryDrillDownTest.php`) is a good reference for this pattern. Browser tests hit the live Plex server, so they fail if it's unreachable.

## Test layout

- `tests/Unit/` — `PlexClientTest` (Http::fake), `PlexCacheTest`, `PlexDtoTest`. Fast, no Plex.
- `tests/Feature/` — `RoutesTest`, `SettingTest`, `PlayerComponentTest`, `LibraryViewTest`, `ServerChipTest`. Use mocked `PlexClient`.
- `tests/Browser/` — `LibraryDrillDownTest` (Playwright, hits live Plex). `tests/Browser/Screenshots/` is gitignored.
- `phpunit.xml` registers `Unit`, `Feature`, `Browser` testsuites. `tests/Pest.php` `->in('Unit', 'Feature', 'Browser')`.
