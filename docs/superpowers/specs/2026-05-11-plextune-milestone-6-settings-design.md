# Milestone 6: Settings page (design spec)

Date: 2026-05-11
Status: Approved for plan.

## Goal

Build the `/settings` page (currently a 12-line stub). Three real sections only, no prototype decoration. Keep scope tight: this milestone is about shipping a settings *page*, not about wiring every M7 polish item.

## Scope

In scope:

1. **Plex Server** section. Discovered server info (friendly name, address, library section name), token status indicator, and a working "Resync metadata" button that actually clears Plex cache entries across requests.
2. **Display** section. A single Comfortable/Compact density segmented control. Persist-only (no consumer yet; M7 adds the CSS hook).
3. **About** section. App version, repo link, credit line. Static content.

Out of scope (deferred to a later phase):

- Scrobble-to-Plex toggle (intentionally deferred per user; will be revisited when scrobbling itself is built).
- Audio quality, gapless, crossfade, normalization, cellular settings, downloads, account, shortcuts panels from the prototype. The app does not have features these would configure.
- Sidebar-style left navigation inside the settings page. With only three sections a top-to-bottom layout is enough.
- Toast notifications for the resync confirmation. Toasts arrive in M7; inline confirmation text is fine.

## Architecture

### `App\Support\AppSetting`

A thin typed accessor over the existing `Setting` Eloquent model. Single source of truth for the keys this milestone introduces and their defaults.

```
AppSetting::density(): string             // 'comfortable' | 'compact', defaults 'comfortable'
AppSetting::setDensity(string $value): void
```

Behavior:

- `setDensity` validates `$value` against the allowed set and throws `InvalidArgumentException` on a bad value.
- Defaults live in the class as private const arrays. Adding a future setting (e.g. scrobble) means one method pair, not changes in five views.
- The class has no constructor; calls are static. The underlying `Setting` model handles persistence.

Rationale: the `Setting` model is generic key/value with a JSON cast. Calling `Setting::get('density', 'comfortable')` from a Livewire component works, but spreads default values and allowed values across the codebase. `AppSetting` centralizes them so the Livewire view stays a thin shell and tests can target one class.

### `App\Services\Plex\PlexCache` cross-request key index

Today `PlexCache::flushAll()` iterates an in-memory `$keys` array populated by `remember` calls in the current request only. As a button handler it does nothing useful because each Livewire action runs in a fresh request and sees an empty list.

Change: track namespaced keys in the cache itself under a meta key `plex:_index`, an array of strings.

- `remember($key, $ttl, $callback)`: after the underlying `Cache::remember`, read `plex:_index` (default `[]`), append the namespaced key if not present, write it back with a long TTL (use `TTL_TRACKS`, 7 days). This is best-effort bookkeeping, not a strict guarantee.
- `forget($key)`: also remove the namespaced key from `plex:_index`.
- `flushAll()`: read `plex:_index`, iterate, `Cache::forget` each entry, then `Cache::forget('plex:_index')`. Drop the in-memory `$keys` array entirely (the index replaces it).

This keeps the wipe surgical (only Plex keys, not the whole `cache` table) and driver-agnostic.

Note: a concurrent write race could lose an index entry. For a single-user local app this is acceptable, and a stale entry on the next flush is harmless (`Cache::forget` is idempotent on missing keys).

### `pages::settings` Livewire component

`resources/views/pages/⚡settings.blade.php`. Single file component.

State:

- `public string $density` (hydrated from `AppSetting::density()` in `mount`).
- `public ?string $resyncedAt` (display string like "15:42:08", null until first resync). Resets to null on subsequent page visits.

Actions:

- `updatedDensity(string $value)`: `AppSetting::setDensity($value)`. Persist-only; no other side effect.
- `resyncMetadata(PlexCache $cache, PlexClient $plex)`: call `$cache->flushAll()`, then `$plex->ping()` to repopulate the basics (server identity, sections). Set `$resyncedAt` to `now()->format('H:i:s')`. If `ping()` throws `PlexException`, set a public `$resyncError` instead.

Server info is read in a `#[Computed]` method `serverInfo()` that calls `$plex->ping()` and returns a small array (`['name' => ..., 'address' => ..., 'sectionName' => ..., 'reachable' => bool, 'tokenSet' => bool]`). On `PlexException` it returns `['reachable' => false, 'tokenSet' => !empty(config('services.plex.token')), ...]`. Note per the project gotchas, `#[Computed]` methods cannot autowire DI, so resolve via `app(PlexClient::class)` inside the computed.

### Visual layout

Centered single column, `max-w-3xl mx-auto`, vertical stack of three `<section>` cards. Each card:

- `bg-surface-1 rounded-xl p-6`
- `<h2 class="text-xl font-bold mb-4">{Section title}</h2>`
- Section body (rows of label + control)

Page header above the cards: `<h1 class="text-3xl font-black">Settings</h1>` and a `text-text-2` subtitle line.

The prototype renders a seven-item left nav inside settings. We have three sections, all visible at once, so we drop the nav. Spec compliance review should not flag this; it is an intentional simplification noted here.

**Density control:** segmented two-button row. Each option is a button with `:class` toggling `bg-surface-3 text-text-1` when active vs `text-text-2 hover:bg-surface-2` when inactive. `wire:click="$set('density', 'compact')"` (and `'comfortable'`) drives selection. Live updates trigger `updatedDensity`.

**Resync button:** primary button (`bg-accent text-black hover:bg-accent-hover`), label "Resync metadata". `wire:click="resyncMetadata"`. To the right of the button, conditional helper text: `Cleared at 15:42:08` (green) on success, `Cache cleared, but Plex is unreachable.` (red) when the post-flush ping fails. (Wording matches the error-handling section.)

**Token status indicator:** small dot + label. `bg-accent` + "Connected" when reachable; `bg-red-500` + "Token set, server unreachable" when token present but ping failed; `bg-surface-3` + "No token configured" when `PLEX_TOKEN` is empty.

### About section content

- "Plexify" + `config('app.version')` (new key, see below).
- One paragraph credit line: "Built with Laravel, Livewire, Tailwind, and Lucide icons. Streams from Plex Media Server."
- Repo link as a plain `<a>` to the GitHub URL. Hardcoded for now (single-user app); promotable to config later if needed.

### `config/app.php` `version` key

Add `'version' => env('APP_VERSION', 'dev')` to `config/app.php`. Add `APP_VERSION=dev` to `.env.example`. The user can set this to a real version string (or a git short SHA) when they care to.

## Data flow

```
User toggles density
  -> wire:click sets $density
  -> updatedDensity hook fires
  -> AppSetting::setDensity($density)
  -> Setting model upsert (key='density', value=['comfortable'|'compact'])
  (no consumer yet; M7 will read on layout boot)

User clicks Resync metadata
  -> resyncMetadata action
  -> PlexCache::flushAll() iterates plex:_index, forgets each entry
  -> PlexClient::ping() repopulates server identity + sections cache
  -> $resyncedAt set to current time string
  (next page load that needs artists/albums/etc. hits Plex fresh)

Page load
  -> mount() reads density into public prop
  -> serverInfo() computed pings Plex once
  -> view renders with current state
```

## Error handling

- `PlexClient::ping()` inside `serverInfo()`: catch `PlexException`. Show "unreachable" status; do not bubble.
- `PlexClient::ping()` inside `resyncMetadata`: catch `PlexException`. The flush already succeeded, so the cache is cleared; set `$resyncError` so the UI says "Cache cleared, but Plex is unreachable." Two different messages depending on whether the user gets a green or red post-click state.
- `AppSetting::setDensity` invalid value: throws `InvalidArgumentException`. The view only emits the two valid values, so this is a programming-error guard, not a user-facing flow.

## Testing

### Unit

`tests/Unit/AppSettingTest.php` (new):

- `density()` returns the default when no row exists.
- `density()` returns the stored value.
- `setDensity('compact')` upserts; subsequent `density()` returns `'compact'`.
- `setDensity('invalid')` throws `InvalidArgumentException`.

`tests/Unit/PlexCacheTest.php` (extend):

- `remember` appends the namespaced key to `plex:_index`.
- `remember` does not duplicate an existing key in the index.
- `forget($key)` removes the entry from `plex:_index`.
- `flushAll()` after multiple `remember` calls forgets each tracked key and clears the index.
- `flushAll()` is idempotent when the index is empty / missing.

### Feature

`tests/Feature/SettingsViewTest.php` (new):

- Page renders with title and three section headings.
- Density toggle: starts on `comfortable` by default; updating to `compact` persists via `Setting` model.
- Resync action: mocks `PlexCache::flushAll` (assert called once) and `PlexClient::ping` (assert called once), then sets `$resyncedAt`.
- Resync action with `ping()` throwing `PlexUnreachableException`: `$resyncError` is set, `$resyncedAt` stays null.
- Server info computed: with a fake `PlexClient` returning success, shows the friendly name and "Connected" status.
- Server info computed: when `ping()` throws, shows "unreachable" status.

### Browser

`tests/Browser/SettingsTest.php` (new), uses `RefreshDatabase`:

- Visit `/settings`, see "Plexify" h1 and the three section h2s.
- Click "Compact" density button; assert a `Setting` row with `name=density, value=['compact']` exists.
- Click "Resync metadata"; assert the green confirmation text appears.

Browser tests hit the live Plex server (the `ping()` call). If Plex is unreachable, the resync test should still pass because the cache flush succeeds before the ping; it would assert the error confirmation text instead. We pick one of the two assertions based on `try/catch` in the test, or skip if not reachable. Plan can decide; recommended: hard-pin on success, document that this test requires Plex.

## Files

New:

- `app/Support/AppSetting.php`
- `tests/Unit/AppSettingTest.php`
- `tests/Feature/SettingsViewTest.php`
- `tests/Browser/SettingsTest.php`

Changed:

- `app/Services/Plex/PlexCache.php` (cross-request key index)
- `resources/views/pages/⚡settings.blade.php` (replace stub)
- `config/app.php` (add `version`)
- `.env.example` (add `APP_VERSION=dev`)
- `tests/Unit/PlexCacheTest.php` (new assertions)

No new migrations. No new Eloquent models. No route changes (the route already exists). No sidebar or topbar changes (gear icon already links to `/settings`).

## Open questions

None. All decisions resolved during brainstorming.

## Notes for the implementer

- Follow the Livewire 4 single-root-element rule (the page body must have a single wrapping `<div>`).
- Don't use `wire:navigate` on the repo `<a>` in About; it's an external link, plain `<a href target="_blank" rel="noreferrer">`.
- `PlexCache` mutations in the resync action: inject via the action signature (Livewire DI on actions works, unlike on `#[Computed]`).
- When stubbing `PlexClient` in the feature test, remember the sidebar renders `<livewire:server-chip />` which also calls `ping()`. Stub it on the same mock.
- Don't put values on the page that we don't have. If `serverInfo()` cannot determine the section name (e.g., before a successful ping), omit that row instead of showing "Unknown".
