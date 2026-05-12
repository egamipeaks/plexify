# Plextune Milestone 7 (Polish) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the final "Polish" milestone: keyboard shortcuts, error toasts, scrobble-to-Plex, display-density wiring, a collapsible album/playlist header, a context-aware playing-track highlight + source indicator, an `H:MM:SS` duration helper, and the remaining empty states.

**Architecture:** Mostly small, independent changes across existing files plus three tiny new files (`app/Support/Duration.php`, `resources/views/components/⚡toasts.blade.php`, `tests/Unit/DurationTest.php`). New per-setting accessors go on the existing `App\Support\AppSetting`. The playing-track highlight gains playback *context* (which list a track is playing from) carried on the `play-track` event payload and mirrored into the Alpine `$store.player`. Keyboard shortcuts and the scrobble `fetch` both live inside the existing `audioPlayer()` Alpine scope.

**Tech Stack:** Laravel 13, Livewire 4 (single-file components), Alpine.js, Tailwind CSS 4, Pest 4 (+ `pestphp/pest-plugin-browser` / Playwright), MySQL, native HTML5 `<audio>`.

**Spec:** `docs/superpowers/specs/2026-05-11-plextune-milestone-7-polish-design.md`. Read it before starting.

**Conventions reminder:** Livewire 4 SFCs at `resources/views/components/⚡<name>.blade.php`; routed pages at `resources/views/pages/⚡<name>.blade.php`. PSR-12, typed properties, constructor property promotion, early returns, no `else`, no docblocks on fully-typed methods. Run `vendor/bin/pint --dirty --format agent` after PHP changes. Run tests with `php artisan test --compact` (filter to the touched file). Browser tests hit the live Plex server. Don't use em/en dashes in markdown docs.

---

## File Structure

**New files:**
- `app/Support/Duration.php` — static `format(int $ms): string` → `M:SS` or `H:MM:SS`. Single source of truth for track-duration formatting (replaces four copies of `formatMs`).
- `resources/views/components/⚡toasts.blade.php` — Livewire SFC: fixed-position stack of dismissible error toasts; listens for the `notify` event.
- `tests/Unit/DurationTest.php` — unit tests for `Duration::format`.

**Modified files:**
- `app/Support/AppSetting.php` — add `scrobbleEnabled()`/`setScrobbleEnabled()` and `albumHeaderCollapsed()`/`setAlbumHeaderCollapsed()`.
- `app/Services/Plex/PlexClient.php` — add `scrobbleUrl(string $ratingKey): string`.
- `resources/views/components/⚡player.blade.php` — relay forwards `contextType`/`contextId`; expose `scrobbleEnabled`/`scrobbleUrlTemplate` to the Alpine scope; `audioPlayer()` gains context tracking, the scrobble `fetch` on `ended`, and the keyboard-shortcut listener.
- `resources/views/components/layouts/app.blade.php` — `data-density` attribute on the shell root; `<livewire:toasts />`; `$store.player` initial shape gains `contextType`/`contextId`.
- `resources/views/components/⚡topbar.blade.php` — `id="topbar-search"` on the search `<input>`.
- `resources/views/components/⚡sidebar.blade.php` — `playPlaylist` sets context; DnD/CRUD failures dispatch `notify`; source indicator on the playlist context-menu is not needed, but the partial is.
- `resources/views/partials/playlist-row.blade.php` — now-playing source indicator (`.eq`) when this playlist is the playback source.
- `resources/views/pages/⚡library.blade.php` — `Duration::format`; `playTrack`/`playAlbum`/`shuffleAlbum` set context `'album'`; tracklist row highlight becomes context-aware; album header gets a source indicator + a persisted collapsed (56px) state; no-artists empty state.
- `resources/views/pages/⚡playlist-detail.blade.php` — `Duration::format`; `playTrack`/`playAll`/`shuffle` set context `'playlist'`; tracklist row highlight context-aware; header source indicator + persisted collapsed state; empty-playlist state.
- `resources/views/pages/⚡search.blade.php` — `Duration::format`; `playTrack` sets context `'search'`; Tracks section gets the playing-row highlight (`x-data` + `.eq`).
- `resources/views/pages/⚡recently-played.blade.php` — `Duration::format`; `playTrack` sets context `'recently-played'`; list gets the playing-row highlight.
- `resources/views/pages/⚡recently-added.blade.php` — `Duration::format` (it has its own `formatMs` too? confirm; if so, replace).
- `resources/views/pages/⚡settings.blade.php` — new "Playback" section with a "Scrobble plays to Plex" toggle wired to `AppSetting::setScrobbleEnabled()`.
- `resources/css/app.css` — `[data-density="compact"]` rules (tighter tracklist rows, tighter sidebar rows, smaller album/playlist header padding); styles for the collapsed-header row if any beyond Tailwind classes.
- Tests: `tests/Unit/AppSettingTest.php`, `tests/Unit/PlexClientTest.php`, `tests/Feature/PlayerComponentTest.php`, `tests/Feature/SettingsViewTest.php`, `tests/Feature/LibraryViewTest.php`, `tests/Feature/PlaylistDetailViewTest.php`, `tests/Feature/SidebarTest.php`, `tests/Browser/PlayerQueueTest.php`, `tests/Browser/SettingsTest.php`, plus a new `tests/Browser/KeyboardShortcutsTest.php` and (optionally) `tests/Browser/AlbumHeaderTest.php`.

**Important — already-built things the spec over-listed (do not re-build):**
- `pages/⚡recently-played.blade.php` already has an empty state (`@elseif ($this->tracks->isEmpty())`).
- `components/⚡sidebar.blade.php` already shows "No playlists yet" when there are no playlists.
- `components/⚡topbar.blade.php` already renders the `⌘K` hint badge (`<span ...>&#8984;K</span>`). Task 12 only needs to wire the shortcut that focuses the input; do not add a second badge.
- The `pages/⚡library.blade.php` albums column already has "Select an artist" / "No albums" states. Task 13 only adds the **artists**-column empty state.

---

## Task 1: `App\Support\Duration` helper + replace the four `formatMs` copies

**Files:**
- Create: `app/Support/Duration.php`
- Create: `tests/Unit/DurationTest.php`
- Modify: `resources/views/pages/⚡library.blade.php` (remove `formatMs`, use `\App\Support\Duration::format`)
- Modify: `resources/views/pages/⚡playlist-detail.blade.php` (same)
- Modify: `resources/views/pages/⚡search.blade.php` (same)
- Modify: `resources/views/pages/⚡recently-played.blade.php` (same)
- Modify: `resources/views/pages/⚡recently-added.blade.php` (same, if it has a `formatMs`)

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/DurationTest.php`:

```php
<?php

use App\Support\Duration;

it('formats sub-minute durations', function () {
    expect(Duration::format(7_000))->toBe('0:07');
});

it('formats minute-and-seconds durations', function () {
    expect(Duration::format(187_000))->toBe('3:07');
});

it('rounds to the nearest second', function () {
    expect(Duration::format(187_600))->toBe('3:08');
});

it('formats exactly one hour as H:MM:SS', function () {
    expect(Duration::format(3_600_000))->toBe('1:00:00');
});

it('formats multi-hour durations as H:MM:SS', function () {
    // 2h 3m 4s
    expect(Duration::format((2 * 3600 + 3 * 60 + 4) * 1000))->toBe('2:03:04');
});

it('treats zero and negatives as 0:00', function () {
    expect(Duration::format(0))->toBe('0:00');
    expect(Duration::format(-5_000))->toBe('0:00');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=DurationTest`
Expected: FAIL — `Class "App\Support\Duration" not found`.

- [ ] **Step 3: Create the helper**

Create `app/Support/Duration.php`:

```php
<?php

namespace App\Support;

class Duration
{
    public static function format(int $ms): string
    {
        $seconds = max(0, (int) round($ms / 1000));

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --compact --filter=DurationTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Replace `formatMs` in `pages/⚡library.blade.php`**

Delete the `protected function formatMs(int $ms): string { ... }` method (currently around lines 86-93). In the template, replace both `{{ $this->formatMs($this->tracks->sum('durationMs')) }}` and `{{ $this->formatMs($track->durationMs) }}` with `{{ \App\Support\Duration::format(...) }}` — i.e.:

- `{{ \App\Support\Duration::format($this->tracks->sum('durationMs')) }}`
- `{{ \App\Support\Duration::format($track->durationMs) }}`

- [ ] **Step 6: Replace `formatMs` in `pages/⚡playlist-detail.blade.php`**

Delete the `protected function formatMs(...)` method. Replace `{{ $this->formatMs($this->tracks->sum('durationMs')) }}` → `{{ \App\Support\Duration::format($this->tracks->sum('durationMs')) }}` and `{{ $this->formatMs($track->durationMs) }}` → `{{ \App\Support\Duration::format($track->durationMs) }}`.

- [ ] **Step 7: Replace `formatMs` in `pages/⚡search.blade.php`**

Delete the `protected function formatMs(...)` method (around line 99). Replace `{{ $this->formatMs($track->durationMs) }}` → `{{ \App\Support\Duration::format($track->durationMs) }}`.

- [ ] **Step 8: Replace `formatMs` in `pages/⚡recently-played.blade.php`**

Delete the `protected function formatMs(...)` method. Replace `{{ $this->formatMs($track->durationMs) }}` → `{{ \App\Support\Duration::format($track->durationMs) }}`.

- [ ] **Step 9: Replace `formatMs` in `pages/⚡recently-added.blade.php` if present**

Run `grep -n formatMs "resources/views/pages/⚡recently-added.blade.php"`. If it has a `formatMs` method and uses, replace them the same way; if it does not use durations, skip this step.

- [ ] **Step 10: Run the affected test suites**

Run: `php artisan test --compact --filter='LibraryViewTest|PlaylistDetailViewTest|SearchViewTest|RoutesTest|DurationTest'`
Expected: PASS. (If any test asserted a literal duration string, it should still pass — the format for `< 1h` is unchanged.)

- [ ] **Step 11: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/Duration.php tests/Unit/DurationTest.php "resources/views/pages/⚡library.blade.php" "resources/views/pages/⚡playlist-detail.blade.php" "resources/views/pages/⚡search.blade.php" "resources/views/pages/⚡recently-played.blade.php" "resources/views/pages/⚡recently-added.blade.php"
git commit -m "refactor(duration): extract App\Support\Duration with H:MM:SS handling"
```

---

## Task 2: `AppSetting` — scrobble + collapsed-header accessors

**Files:**
- Modify: `app/Support/AppSetting.php`
- Modify: `tests/Unit/AppSettingTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/Unit/AppSettingTest.php` (it already `uses(RefreshDatabase::class)` — confirm; if not, add it at the top):

```php
it('defaults scrobbleEnabled to true', function () {
    expect(AppSetting::scrobbleEnabled())->toBeTrue();
});

it('round-trips scrobbleEnabled', function () {
    AppSetting::setScrobbleEnabled(false);
    expect(AppSetting::scrobbleEnabled())->toBeFalse();

    AppSetting::setScrobbleEnabled(true);
    expect(AppSetting::scrobbleEnabled())->toBeTrue();
});

it('defaults albumHeaderCollapsed to false', function () {
    expect(AppSetting::albumHeaderCollapsed())->toBeFalse();
});

it('round-trips albumHeaderCollapsed', function () {
    AppSetting::setAlbumHeaderCollapsed(true);
    expect(AppSetting::albumHeaderCollapsed())->toBeTrue();

    AppSetting::setAlbumHeaderCollapsed(false);
    expect(AppSetting::albumHeaderCollapsed())->toBeFalse();
});
```

(If the test file does not already import `App\Support\AppSetting`, ensure `use App\Support\AppSetting;` is present.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=AppSettingTest`
Expected: FAIL — `Call to undefined method App\Support\AppSetting::scrobbleEnabled()`.

- [ ] **Step 3: Implement the accessors**

In `app/Support/AppSetting.php`, add (after the density methods, before the closing brace):

```php
    public static function scrobbleEnabled(): bool
    {
        return (bool) Setting::get('scrobble_enabled', true);
    }

    public static function setScrobbleEnabled(bool $value): void
    {
        Setting::set('scrobble_enabled', $value);
    }

    public static function albumHeaderCollapsed(): bool
    {
        return (bool) Setting::get('album_header_collapsed', false);
    }

    public static function setAlbumHeaderCollapsed(bool $value): void
    {
        Setting::set('album_header_collapsed', $value);
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact --filter=AppSettingTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/AppSetting.php tests/Unit/AppSettingTest.php
git commit -m "feat(settings): scrobble + collapsed-header AppSetting accessors"
```

---

## Task 3: `PlexClient::scrobbleUrl()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `tests/Unit/PlexClientTest.php`

- [ ] **Step 1: Add a failing test**

Add to `tests/Unit/PlexClientTest.php` (follow the file's existing setup — it uses `Http::fake` and a configured base URL/token; mirror how an existing test constructs/obtains the client). Example:

```php
it('builds a scrobble url for a rating key', function () {
    // Arrange a client whose baseUrl() resolves to a known value, as the existing
    // streamUrl/thumbUrl tests do (Http::fake the discovery calls if needed).
    $client = app(\App\Services\Plex\PlexClient::class);

    $url = $client->scrobbleUrl('12345');

    expect($url)
        ->toContain('/:/scrobble?')
        ->toContain('key=12345')
        ->toContain('identifier=com.plexapp.plugins.library')
        ->toContain('X-Plex-Token=');
});
```

If the existing `streamUrl` test in this file sets `PLEX_BASE_URL` / `PLEX_TOKEN` config or fakes discovery, copy that arrangement verbatim into this test so `baseUrl()`/`token()` resolve.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter='PlexClientTest'` (or `--filter='scrobble url'`)
Expected: FAIL — `Call to undefined method ...::scrobbleUrl()`.

- [ ] **Step 3: Implement**

In `app/Services/Plex/PlexClient.php`, add next to `streamUrl()` / `thumbUrl()`:

```php
    public function scrobbleUrl(string $ratingKey): string
    {
        return sprintf(
            '%s/:/scrobble?key=%s&identifier=com.plexapp.plugins.library&X-Plex-Token=%s',
            $this->baseUrl(),
            $ratingKey,
            $this->token(),
        );
    }
```

(`token()` is `private` but this method is in the same class, so it is accessible.)

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact --filter='PlexClientTest'`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat(plex): scrobbleUrl() builder"
```

---

## Task 4: Playback-context plumbing (`play-track` payload, relay, `$store.player`, `audioPlayer()`, all dispatchers)

This task adds `contextType` / `contextId` everywhere they need to flow. No visible behaviour change yet — the highlight match conditions update in Task 5. The new fields must be carried through end to end so Task 5's browser test can rely on them.

**Files:**
- Modify: `resources/views/components/⚡player.blade.php` (relay + `audioPlayer()` + `$store.player` fallback)
- Modify: `resources/views/components/layouts/app.blade.php` (`$store.player` initial shape)
- Modify: `resources/views/pages/⚡library.blade.php` (`playTrack`/`playAlbum`/`shuffleAlbum`)
- Modify: `resources/views/pages/⚡playlist-detail.blade.php` (`playTrack`/`playAll`/`shuffle`)
- Modify: `resources/views/pages/⚡search.blade.php` (`playTrack`)
- Modify: `resources/views/pages/⚡recently-played.blade.php` (`playTrack`)
- Modify: `resources/views/components/⚡sidebar.blade.php` (`playPlaylist`)
- Modify: `tests/Feature/PlayerComponentTest.php`
- Modify the 5 dispatch tests (in `LibraryViewTest`, `PlaylistDetailViewTest`, `SearchViewTest`, the recently-played feature test, `SidebarTest`) to assert the new fields.

- [ ] **Step 1: Update `PlayerComponentTest` to expect the relay forwards context**

Open `tests/Feature/PlayerComponentTest.php`. It has a test that dispatches `play-track` to the `player` component and asserts it re-dispatches `queue-load` with `queue`/`index`/`shuffle`. Extend that test to pass `contextType`/`contextId` in and assert they come back out. Example (adapt to the file's existing style):

```php
it('relays play-track to queue-load including playback context', function () {
    Livewire::test('player')
        ->dispatch('play-track', queue: [['id' => '1', 'url' => 'u', 'title' => 't', 'artist' => 'a', 'artwork' => null, 'albumId' => null, 'artistId' => null]], index: 0, shuffle: false, contextType: 'album', contextId: '99')
        ->assertDispatched('queue-load', queue: [['id' => '1', 'url' => 'u', 'title' => 't', 'artist' => 'a', 'artwork' => null, 'albumId' => null, 'artistId' => null]], index: 0, shuffle: false, contextType: 'album', contextId: '99');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=PlayerComponentTest`
Expected: FAIL — the relay does not yet pass `contextType`/`contextId`.

- [ ] **Step 3: Update the relay in `components/⚡player.blade.php`**

Replace the `onPlayTrack` method:

```php
    #[On('play-track')]
    public function onPlayTrack(array $queue, int $index = 0, bool $shuffle = false, ?string $contextType = null, ?string $contextId = null): void
    {
        $this->dispatch('queue-load', queue: $queue, index: $index, shuffle: $shuffle, contextType: $contextType, contextId: $contextId);
    }
```

- [ ] **Step 4: Run to verify the relay test passes**

Run: `php artisan test --compact --filter=PlayerComponentTest`
Expected: PASS.

- [ ] **Step 5: Update `$store.player` shape (layout + player fallback)**

In `resources/views/components/layouts/app.blade.php`, change the `alpine:init` store registration to:

```html
    <script>
        document.addEventListener('alpine:init', () => {
            window.Alpine.store('player', { currentId: null, isPlaying: false, contextType: null, contextId: null });
        });
    </script>
```

In `resources/views/components/⚡player.blade.php`, inside `audioPlayer().init()`, update the fallback registration to match:

```js
            init() {
                if (!Alpine.store('player')) {
                    Alpine.store('player', { currentId: null, isPlaying: false, contextType: null, contextId: null });
                }
```

- [ ] **Step 6: Update the `queue-load` handler in `audioPlayer()` to record context**

In `audioPlayer().init()`, in the `window.addEventListener('queue-load', (e) => { ... })` callback, set the context on the store **before** `loadAndPlay`. Add after `this.shuffle = !!e.detail.shuffle;` and also store on `this` so `loadAndPlay` can re-assert it:

```js
                window.addEventListener('queue-load', (e) => {
                    this.consecutiveErrors = 0;
                    this.originalQueue = e.detail.queue ?? [];
                    const startIndex = e.detail.index ?? 0;
                    this.shuffle = !!e.detail.shuffle;
                    this.contextType = e.detail.contextType ?? null;
                    this.contextId = e.detail.contextId ?? null;
                    Alpine.store('player').contextType = this.contextType;
                    Alpine.store('player').contextId = this.contextId;
                    if (this.shuffle) {
                        this.applyShuffle(startIndex);
                        this.loadAndPlay(0);
                    } else {
                        this.queue = [...this.originalQueue];
                        this.loadAndPlay(startIndex);
                    }
                });
```

Add `contextType: null,` and `contextId: null,` to the `audioPlayer()` returned-object property list (next to `shuffle: false,`). `loadAndPlay` already sets `Alpine.store('player').currentId`; leave it as is (context is set once per `queue-load`, which is correct — shuffle reorders but keeps the same context).

- [ ] **Step 7: Library — `playTrack`/`playAlbum`/`shuffleAlbum` set `contextType: 'album'`**

In `resources/views/pages/⚡library.blade.php`, update the three dispatches to include `contextType: 'album', contextId: $this->selectedAlbumId`:

```php
        $this->dispatch('play-track', queue: $this->albumQueue($plex), index: $i, contextType: 'album', contextId: $this->selectedAlbumId);
```

```php
        $this->dispatch('play-track', queue: $this->albumQueue($plex), index: 0, contextType: 'album', contextId: $this->selectedAlbumId);
```

```php
        $this->dispatch('play-track',
            queue: $this->albumQueue($plex),
            index: random_int(0, $this->tracks->count() - 1),
            shuffle: true,
            contextType: 'album',
            contextId: $this->selectedAlbumId,
        );
```

- [ ] **Step 8: Playlist-detail — `playTrack`/`playAll`/`shuffle` set `contextType: 'playlist'`**

In `resources/views/pages/⚡playlist-detail.blade.php`:

```php
        $this->dispatch('play-track', queue: $this->queuePayload(), index: $i, contextType: 'playlist', contextId: $this->playlist);
```

```php
        $this->dispatch('play-track', queue: $this->queuePayload(), index: 0, contextType: 'playlist', contextId: $this->playlist);
```

```php
        $this->dispatch('play-track',
            queue: $this->queuePayload(),
            index: random_int(0, $this->tracks->count() - 1),
            shuffle: true,
            contextType: 'playlist',
            contextId: $this->playlist,
        );
```

- [ ] **Step 9: Sidebar — `playPlaylist` sets `contextType: 'playlist'`**

In `resources/views/components/⚡sidebar.blade.php`, in `playPlaylist`:

```php
        $this->dispatch('play-track',
            queue: $tracks->map(fn ($t) => $this->plex->queueItem($t))->values()->all(),
            index: 0,
            contextType: 'playlist',
            contextId: $playlistId,
        );
```

- [ ] **Step 10: Search — `playTrack` sets `contextType: 'search'`**

In `resources/views/pages/⚡search.blade.php`, in `playTrack`, add `contextType: 'search', contextId: null` to the `dispatch('play-track', ...)` call. (Find the `playTrack` method near the top; the dispatch currently passes `queue` and `index`.)

- [ ] **Step 11: Recently-played — `playTrack` sets `contextType: 'recently-played'`**

In `resources/views/pages/⚡recently-played.blade.php`, in `playTrack`:

```php
        $this->dispatch('play-track',
            queue: $tracks->map(fn ($t) => $this->plex->queueItem($t))->values()->all(),
            index: $i,
            contextType: 'recently-played',
            contextId: null,
        );
```

- [ ] **Step 12: Update the five dispatch tests to assert the new fields**

In each of `tests/Feature/LibraryViewTest.php`, `tests/Feature/PlaylistDetailViewTest.php`, `tests/Feature/SearchViewTest.php`, the recently-played feature test, and `tests/Feature/SidebarTest.php`: find the test(s) that `->assertDispatched('play-track', queue: ..., index: ...)` and add `contextType: '<expected>'` and `contextId: <expected>` to the assertion (for search and recently-played, `contextId: null`; for library, the selected album id used in the test; for playlist-detail and sidebar, the playlist id used in the test).

- [ ] **Step 13: Run all touched feature tests**

Run: `php artisan test --compact --filter='PlayerComponentTest|LibraryViewTest|PlaylistDetailViewTest|SearchViewTest|SidebarTest|RecentlyPlayed'`
Expected: PASS.

- [ ] **Step 14: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components app/ tests/Feature resources/views/pages
git commit -m "feat(player): carry playback context (type+id) through play-track"
```

---

## Task 5: Context-aware row highlight in tracklists + extend highlight to search Tracks & recently-played

Now make the row highlight read the context. A row lights up only when `currentId` matches **and** the playback context matches this view.

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php` (tracklist row `:class` and `<template x-if>`)
- Modify: `resources/views/pages/⚡playlist-detail.blade.php` (same)
- Modify: `resources/views/pages/⚡search.blade.php` (add `x-data` to the Tracks section + `.eq` markup on each track row)
- Modify: `resources/views/pages/⚡recently-played.blade.php` (add `x-data` to the list + `.eq` markup on each row)
- Modify: `tests/Browser/PlayerQueueTest.php`

This task is verified by a browser test (live Plex). Write the test first; it will fail until the markup is updated.

- [ ] **Step 1: Add a failing browser test**

Add to `tests/Browser/PlayerQueueTest.php` (mirror the existing tests' setup: `visit('/')`, drill into an album via `script()`, etc.). The new test:

1. Navigate to a playlist detail page that has tracks, play the first track from it (so context is `playlist` + that id).
2. Navigate to that track's album page (`/?artist=...&album=...`) — if the track also exists on an album page; otherwise just navigate to any album. Assert no `.eq` element is visible in the album tracklist (because context is `playlist`, not `album`). If picking a known matching album/track from the live server is impractical, instead: play a track from the **library album**, then assert the `.eq` *is* visible there, then navigate to a different album and assert it is *not* visible.
3. Assert the search "Songs" group and recently-played list show `.eq` for the currently-playing track when played from those views.

Concretely (adapt selectors to what the existing browser tests use):

```php
it('only highlights the playing row in the list it is playing from', function () {
    $page = visit('/');
    // ... drill into an album, click the first track row ...
    // assert: $page->assertPresent('[data-region="tracklist"] .eq');
    // navigate to a different album:
    // ... click a different artist/album ...
    // assert the new album's tracklist has no .eq:
    $page->assertScript('document.querySelectorAll(\'[data-region="tracklist"] .eq\').length', 0);
});
```

(Use `assertScript`/`script` since `assertNotPresent` for `.eq` inside `<template x-if>` can be flaky — query the count via JS.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=PlayerQueueTest`
Expected: FAIL (the new test) — the library tracklist still highlights by bare id regardless of context, so after navigating to a non-source album the `.eq` would still show if `currentId` happened to match... actually it will fail because the new test also asserts search/recently-played highlights which don't exist yet. Either way: FAIL.

- [ ] **Step 3: Library tracklist — context-aware match**

In `resources/views/pages/⚡library.blade.php`, in the tracklist `@foreach`, replace the `#`-cell templates and the title `:class` so they also check the context. The match expression is:

`$store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}'`

Replace:

```blade
                                <span class="tabular-nums text-text-2 text-right">
                                    <template x-if="$store.player?.currentId === '{{ $track->id }}'">
                                        <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                                    </template>
                                    <template x-if="$store.player?.currentId !== '{{ $track->id }}'">
                                        <span>{{ $track->trackNumber }}</span>
                                    </template>
                                </span>
```

with:

```blade
                                <span class="tabular-nums text-text-2 text-right"
                                      x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}'; } }">
                                    <template x-if="playing">
                                        <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                                    </template>
                                    <template x-if="!playing">
                                        <span>{{ $track->trackNumber }}</span>
                                    </template>
                                </span>
```

And replace the title `:class`:

```blade
                                        <div class="truncate font-medium" :class="$store.player?.currentId === '{{ $track->id }}' ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
```

with:

```blade
                                        <div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
```

(The `x-data` on the `#`-cell `<span>` is fine — Alpine nests scopes; the surrounding `<div class="overflow-y-auto scroll flex-1 py-1" x-data="{}">` already establishes a scope, and a nested `x-data` getter is a clean way to avoid repeating the long expression. The title cell repeats the expression inline since it is in a sibling subtree without that getter; that is acceptable.)

- [ ] **Step 4: Playlist-detail tracklist — context-aware match**

Same change in `resources/views/pages/⚡playlist-detail.blade.php`, but the context is `'playlist'` and `'{{ $this->playlist }}'`. Replace the `#`-cell `<span>`:

```blade
                        <span class="tabular-nums text-text-2 text-right"
                              x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}'; } }">
                            <template x-if="playing">
                                <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                            </template>
                            <template x-if="!playing">
                                <span>{{ $i + 1 }}</span>
                            </template>
                        </span>
```

And the title `:class`:

```blade
                                <div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
```

- [ ] **Step 5: Search Tracks section — add the highlight**

In `resources/views/pages/⚡search.blade.php`, in the `{{-- Tracks --}}` section, add `x-data="{}"` to the inner `<div class="flex flex-col">` that wraps the `@foreach ($this->results->tracks as $track)`. Then in each track row, before the artwork `@if`, the row's grid is `grid-template-columns: 44px 1fr auto;` — it has no `#` cell. To keep the layout, change the artwork cell so the playing equalizer overlays/replaces it, OR (simpler, matches the design's "Songs" rows which have no track-number column) just colour the title and show a small `.eq` after the title. Use the title-colour + inline `.eq`:

Replace:

```blade
                                    <div class="min-w-0">
                                        <div class="truncate text-[14px] font-medium text-white">{{ $track->title }}</div>
                                        <div class="truncate text-[12px] text-text-2">{{ collect([$track->artist, $track->album])->filter()->implode(' · ') }}</div>
                                    </div>
```

with:

```blade
                                    <div class="min-w-0" x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'search'; } }">
                                        <div class="truncate text-[14px] font-medium flex items-center gap-2" :class="playing ? 'text-accent' : 'text-white'">
                                            <span class="truncate">{{ $track->title }}</span>
                                            <template x-if="playing">
                                                <span class="eq flex-none" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                                            </template>
                                        </div>
                                        <div class="truncate text-[12px] text-text-2">{{ collect([$track->artist, $track->album])->filter()->implode(' · ') }}</div>
                                    </div>
```

(Adding `x-data` on a `<div>` inside a `<button wire:click>` is fine.)

- [ ] **Step 6: Recently-played list — add the highlight**

In `resources/views/pages/⚡recently-played.blade.php`, add `x-data="{}"` to `<div class="overflow-y-auto scroll flex-1 py-1">` (it currently has no `x-data`). Then replace the `#`-cell:

```blade
                            <span class="tabular-nums text-text-2 text-right">{{ $i + 1 }}</span>
```

with:

```blade
                            <span class="tabular-nums text-text-2 text-right"
                                  x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'recently-played'; } }">
                                <template x-if="playing">
                                    <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                                </template>
                                <template x-if="!playing">
                                    <span>{{ $i + 1 }}</span>
                                </template>
                            </span>
```

And the title:

```blade
                                    <div class="truncate font-medium text-white">{{ $track->title }}</div>
```

→

```blade
                                    <div class="truncate font-medium"
                                         :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'recently-played') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
```

- [ ] **Step 7: Run the browser test**

Run: `php artisan test --compact --filter=PlayerQueueTest`
Expected: PASS (requires the live Plex server reachable). Also run the feature suites to make sure nothing regressed: `php artisan test --compact --filter='LibraryViewTest|PlaylistDetailViewTest|SearchViewTest'`.

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/pages tests/Browser/PlayerQueueTest.php
git commit -m "feat(highlight): context-aware playing-row highlight; extend to search & recently-played"
```

---

## Task 6: Now-playing source indicator (sidebar playlist row, library album header, playlist-detail header)

A small accent equalizer next to the list that is the current playback source.

**Files:**
- Modify: `resources/views/partials/playlist-row.blade.php`
- Modify: `resources/views/pages/⚡library.blade.php` (album header)
- Modify: `resources/views/pages/⚡playlist-detail.blade.php` (gradient header)
- Modify: `tests/Browser/PlayerQueueTest.php`

- [ ] **Step 1: Add a failing browser assertion**

Extend the Task 5 test (or add a small new test in `PlayerQueueTest.php`): after playing a track from a playlist via the sidebar context menu (`$wire.playPlaylist`), assert the sidebar playlist row for that playlist contains a `.eq` element, and other playlist rows do not. Use `script()`/`assertScript` to count `.eq` inside `[wire\\:key="sidebar-pl-<id>"]`.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=PlayerQueueTest`
Expected: FAIL.

- [ ] **Step 3: Add the indicator to the sidebar playlist row**

In `resources/views/partials/playlist-row.blade.php`, inside the `@else` branch (the `<a>` row), add an indicator at the end of the `<div class="min-w-0 flex-1">` block or as a trailing flex child. Add after the `<div class="min-w-0 flex-1"> ... </div>` and before `</a>`:

```blade
            <template x-if="$store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $p->id }}'">
                <span class="eq flex-none" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
            </template>
```

(The partial renders inside the sidebar playlists card's `x-data` scope, so `$store.player` is accessible. The `<template x-if>` pattern is required here rather than `x-show` because the partial may render before the store is hot — but the store is registered in `<head>` at `alpine:init`, so it is fine; using `<template x-if>` is consistent with the tracklist rows.)

- [ ] **Step 4: Add the indicator to the library album header**

In `resources/views/pages/⚡library.blade.php`, in the album-header block (`@if ($this->selectedAlbum)`), near the `<h1>` title or the "Album" eyebrow, add (e.g. right after the `<h1 ...>{{ $this->selectedAlbum->title }}</h1>` line, inside the `<div class="flex-1 min-w-0">`):

```blade
                            <template x-if="$store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}'">
                                <span class="eq mt-2 inline-flex" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                            </template>
```

The album header `<div>` is inside the page root `<div ... data-selected-artist=...>` which has no `x-data`. `$store.*` is global so it works without an `x-data`, but `<template x-if>` needs an Alpine scope to be processed. Add `x-data="{}"` to the album header's outermost wrapper (`<div class="relative overflow-hidden rounded-lg" style="...">`) so the templates inside it are walked.

- [ ] **Step 5: Add the indicator to the playlist-detail header**

In `resources/views/pages/⚡playlist-detail.blade.php`, in the gradient header (`@else` branch, `<div class="relative overflow-hidden rounded-lg" style="...">`), add `x-data="{}"` to that wrapper, then add near the `<h1>`:

```blade
                    <template x-if="$store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}'">
                        <span class="eq inline-flex" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                    </template>
```

- [ ] **Step 6: Run the browser test**

Run: `php artisan test --compact --filter=PlayerQueueTest`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views
git commit -m "feat(highlight): now-playing source indicator on sidebar row + album/playlist headers"
```

---

## Task 7: Scrobble on track end

**Files:**
- Modify: `resources/views/components/⚡player.blade.php`
- Modify: `tests/Feature/PlayerComponentTest.php`

- [ ] **Step 1: Add a failing test that the player view exposes scrobble config**

Add to `tests/Feature/PlayerComponentTest.php`. The `player` component should expose `scrobbleEnabled` (bool) and `scrobbleUrlTemplate` (string containing `__KEY__`) to its view. Easiest: have the relay component compute them in a `with()` / `#[Computed]` and assert via `assertSeeHtml`. Implementation choice: add public-ish data via `mount()` injecting `PlexClient` and `AppSetting`. Test:

```php
it('exposes scrobble config to the audio player', function () {
    \App\Support\AppSetting::setScrobbleEnabled(true);

    Livewire::test('player')
        ->assertSet('scrobbleEnabled', true)
        ->assertSeeHtml('__KEY__'); // the scrobble URL template placeholder is rendered into the Alpine init
});
```

(If `Livewire::test('player')` cannot resolve `PlexClient` without Plex reachable, mock it: `$this->mock(PlexClient::class)->shouldReceive('scrobbleUrl')->andReturn('http://plex/:/scrobble?key=__KEY__&identifier=com.plexapp.plugins.library&X-Plex-Token=t');` — and stub anything else the player's `mount` calls.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=PlayerComponentTest`
Expected: FAIL.

- [ ] **Step 3: Add scrobble config to the player component**

In `resources/views/components/⚡player.blade.php`, change the component class to populate two public properties in `mount()`:

```php
use Livewire\Component;
use Livewire\Attributes\On;
use App\Services\Plex\PlexClient;
use App\Support\AppSetting;

new class extends Component {
    public bool $scrobbleEnabled = true;

    public string $scrobbleUrlTemplate = '';

    public function mount(PlexClient $plex): void
    {
        $this->scrobbleEnabled = AppSetting::scrobbleEnabled();
        $this->scrobbleUrlTemplate = $plex->scrobbleUrl('__KEY__');
    }

    #[On('play-track')]
    public function onPlayTrack(array $queue, int $index = 0, bool $shuffle = false, ?string $contextType = null, ?string $contextId = null): void
    {
        $this->dispatch('queue-load', queue: $queue, index: $index, shuffle: $shuffle, contextType: $contextType, contextId: $contextId);
    }
};
```

In the Blade root, pass the config into `audioPlayer()`:

```html
<div class="bg-base h-[88px] flex items-center px-4 gap-4 flex-none"
     x-data="audioPlayer(@js($scrobbleEnabled), @js($scrobbleUrlTemplate))"
     x-init="init()">
```

In the `@script` block, change `window.audioPlayer = function () {` to `window.audioPlayer = function (scrobbleEnabled, scrobbleUrlTemplate) {` and add to the returned object:

```js
            scrobbleEnabled: !!scrobbleEnabled,
            scrobbleUrlTemplate: scrobbleUrlTemplate || '',
```

Add a `scrobbleCurrent()` method:

```js
            scrobbleCurrent() {
                if (!this.scrobbleEnabled || !this.scrobbleUrlTemplate || !this.current) {
                    return;
                }
                try {
                    fetch(this.scrobbleUrlTemplate.replace('__KEY__', encodeURIComponent(this.current.id)), { mode: 'no-cors' }).catch(() => {});
                } catch (_) {}
            },
```

And call it from the `<audio>` `@ended` handler — change `@ended="next()"` to `@ended="scrobbleCurrent(); next()"`. (Scrobble fires only on natural end, never on manual `next()`/`previous()`.)

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact --filter=PlayerComponentTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/components/⚡player.blade.php" tests/Feature/PlayerComponentTest.php
git commit -m "feat(player): scrobble plays to Plex on track end"
```

---

## Task 8: Settings "Playback" section (scrobble toggle)

**Files:**
- Modify: `resources/views/pages/⚡settings.blade.php`
- Modify: `tests/Feature/SettingsViewTest.php`
- Modify: `tests/Browser/SettingsTest.php`

- [ ] **Step 1: Add a failing feature test**

Add to `tests/Feature/SettingsViewTest.php` (it likely mocks `PlexClient::ping()` — keep that):

```php
it('renders the scrobble toggle reflecting the saved value', function () {
    \App\Support\AppSetting::setScrobbleEnabled(false);

    Livewire::test('pages::settings')
        ->assertSet('scrobbleEnabled', false)
        ->assertSee('Scrobble plays to Plex');
});

it('persists the scrobble toggle', function () {
    Livewire::test('pages::settings')
        ->set('scrobbleEnabled', false);

    expect(\App\Support\AppSetting::scrobbleEnabled())->toBeFalse();
});
```

(If `Livewire::test('pages::settings')` needs the `PlexClient` mock stubbed for `ping()`, copy the existing setup in `SettingsViewTest.php`.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=SettingsViewTest`
Expected: FAIL.

- [ ] **Step 3: Implement the Playback section**

In `resources/views/pages/⚡settings.blade.php`, add a public property and lifecycle hook to the component class:

```php
    public bool $scrobbleEnabled = true;
```

In `mount()` add: `$this->scrobbleEnabled = AppSetting::scrobbleEnabled();`

Add:

```php
    public function updatedScrobbleEnabled(bool $value): void
    {
        AppSetting::setScrobbleEnabled($value);
    }
```

In the template, add a new `<section>` between "Plex Server" and "Display":

```blade
        {{-- Playback --}}
        <section class="bg-surface-1 rounded-xl p-6">
            <h2 class="text-xl font-bold mb-4">Playback</h2>

            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-semibold">Scrobble plays to Plex</div>
                    <div class="text-xs text-text-2 mt-1">Report finished tracks back to your Plex server so play counts and Recently Played stay in sync.</div>
                </div>

                <div class="inline-flex rounded-full bg-surface-2 p-1">
                    <button type="button"
                            wire:click="$set('scrobbleEnabled', true)"
                            @class([
                                'px-4 py-1.5 rounded-full text-sm font-semibold transition-colors',
                                'bg-surface-3 text-text-1' => $scrobbleEnabled,
                                'text-text-2 hover:text-text-1' => ! $scrobbleEnabled,
                            ])>
                        On
                    </button>
                    <button type="button"
                            wire:click="$set('scrobbleEnabled', false)"
                            @class([
                                'px-4 py-1.5 rounded-full text-sm font-semibold transition-colors',
                                'bg-surface-3 text-text-1' => ! $scrobbleEnabled,
                                'text-text-2 hover:text-text-1' => $scrobbleEnabled,
                            ])>
                        Off
                    </button>
                </div>
            </div>
        </section>
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact --filter=SettingsViewTest`
Expected: PASS.

- [ ] **Step 5: Add a browser test**

Add to `tests/Browser/SettingsTest.php` a test that visits `/settings`, clicks the "Off" button for the scrobble toggle (drive via `script()` if a strict `click()` selector matches multiple buttons), navigates away and back, and asserts the "Off" button is the active one (e.g. has `bg-surface-3`). Mirror the existing density-persistence test in this file.

- [ ] **Step 6: Run the browser test**

Run: `php artisan test --compact --filter=SettingsTest`
Expected: PASS (live Plex reachable).

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/⚡settings.blade.php" tests/Feature/SettingsViewTest.php tests/Browser/SettingsTest.php
git commit -m "feat(settings): Playback section with scrobble toggle"
```

---

## Task 9: Display-density wiring (`data-density` attribute + Compact CSS)

**Files:**
- Modify: `resources/views/components/layouts/app.blade.php`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/SettingsViewTest.php` or `tests/Feature/RoutesTest.php` (a render assertion)
- Modify: `tests/Browser/SettingsTest.php`

- [ ] **Step 1: Add a failing feature test**

Add to `tests/Feature/SettingsViewTest.php` (or wherever a layout-rendering page test lives — `RoutesTest` renders pages with the layout). Example, using the library page which uses the app layout:

```php
it('puts the density attribute on the app shell', function () {
    // mock PlexClient so the library page renders without Plex
    // ... existing pattern from LibraryViewTest ...
    \App\Support\AppSetting::setDensity('compact');

    $this->get('/')->assertSee('data-density="compact"', false);
});
```

If a simple `$this->get('/')` is awkward because of `PlexClient`, instead assert via `Livewire::test('pages::library')->assertSeeHtml('data-density="compact"')` after stubbing the client (mirror `LibraryViewTest`'s setup).

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=SettingsViewTest` (or the chosen file)
Expected: FAIL.

- [ ] **Step 3: Add the attribute to the layout root**

In `resources/views/components/layouts/app.blade.php`, change:

```html
<body class="h-full bg-base text-text-1">
    <div class="app-shell h-screen w-screen flex flex-col gap-2 p-2">
```

to:

```html
<body class="h-full bg-base text-text-1" data-density="{{ \App\Support\AppSetting::density() }}">
    <div class="app-shell h-screen w-screen flex flex-col gap-2 p-2">
```

(Putting it on `<body>` is fine — `<body>` is not inside any `wire:navigate` morph region, so the attribute only changes on a full reload, which is exactly when the Settings round-trip happens. The CSS selectors below are `[data-density="compact"] ...` and work regardless.)

- [ ] **Step 4: Add the Compact CSS rules**

Append to `resources/css/app.css`:

```css
/* Compact display density: tighter tracklist rows, sidebar rows, and album/playlist header. */
[data-density="compact"] [data-region="tracklist"] .row {
    padding-top: 0.25rem;
    padding-bottom: 0.25rem;
}

[data-density="compact"] [data-region="tracklist"] .row img,
[data-density="compact"] [data-region="tracklist"] .row .rounded-sm {
    width: 28px !important;
    height: 28px !important;
}

[data-density="compact"] aside .group.w-full,
[data-density="compact"] aside a.w-full {
    padding-top: 0.25rem;
    padding-bottom: 0.25rem;
}

[data-density="compact"] [data-album-header] .px-6.py-5,
[data-density="compact"] [data-album-header] .px-6.pb-4,
[data-density="compact"] [data-playlist-header] .p-6,
[data-density="compact"] [data-playlist-header] .px-6.pb-4 {
    padding-top: 0.75rem;
    padding-bottom: 0.75rem;
}
```

For the album/playlist header selectors to work, add a marker attribute to the headers: in `pages/⚡library.blade.php` add `data-album-header` to the album header's outermost wrapper `<div class="relative overflow-hidden rounded-lg" ...>` (the same `<div>` you added `x-data="{}"` to in Task 6 Step 4). In `pages/⚡playlist-detail.blade.php` add `data-playlist-header` to the gradient header's wrapper (same one from Task 6 Step 5). If the exact Tailwind class combos in the selectors above do not match the live markup, adjust the CSS selectors to target child elements by attribute instead (e.g. wrap the header padding section in a `<div data-header-pad>` and target that). Keep it simple: the goal is "compact looks tighter", not pixel-exact.

- [ ] **Step 5: Run the feature test + rebuild assets**

Run: `php artisan test --compact --filter=SettingsViewTest` → PASS.
Run: `npm run build` (so the CSS change is in the manifest for browser tests / manual checking).

- [ ] **Step 6: Browser test**

Add to `tests/Browser/SettingsTest.php`: set Compact on `/settings`, navigate to `/`, assert `document.body.getAttribute('data-density') === 'compact'` via `assertScript`. (Do not assert pixel sizes.)

Run: `php artisan test --compact --filter=SettingsTest` → PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/layouts/app.blade.php resources/css/app.css "resources/views/pages/⚡library.blade.php" "resources/views/pages/⚡playlist-detail.blade.php" tests/Feature tests/Browser/SettingsTest.php
git commit -m "feat(settings): wire Compact density (data-density attribute + CSS)"
```

---

## Task 10: Collapsible album / playlist header (persisted)

The expanded headers stay; add a collapsed 56px-row variant toggled by a chevron, persisted via `AppSetting::albumHeaderCollapsed()`. Port the collapsed layout from `design/plextune-parts.jsx` (`AlbumHeader` collapsed branch) — read that file for the exact structure (40px cover, title, inline meta, inline Play / Shuffle / Expand). Use `@entangle` so the toggle is instant client-side and a lifecycle hook persists it (per the M5 gotcha that Alpine does not re-bind a server-rendered JS literal that Livewire morphs).

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php`
- Modify: `resources/views/pages/⚡playlist-detail.blade.php`
- Modify: `tests/Feature/LibraryViewTest.php`, `tests/Feature/PlaylistDetailViewTest.php`
- (optional) Create: `tests/Browser/AlbumHeaderTest.php`
- Reference: `design/plextune-parts.jsx` (collapsed `AlbumHeader` markup)

- [ ] **Step 1: Add failing feature tests**

In `tests/Feature/LibraryViewTest.php`, add a test that with an album selected and `AppSetting::setAlbumHeaderCollapsed(true)`, the rendered page shows the collapsed marker (give the collapsed row a stable hook, e.g. `data-album-header-collapsed`), and toggling the `headerCollapsed` prop persists:

```php
it('renders the collapsed album header when the setting is on', function () {
    // ... existing LibraryViewTest setup that mocks PlexClient with an artist+album+tracks ...
    \App\Support\AppSetting::setAlbumHeaderCollapsed(true);

    Livewire::withQueryParams(['artist' => '<id>', 'album' => '<id>'])
        ->test('pages::library')
        ->assertSet('headerCollapsed', true)
        ->assertSeeHtml('data-album-header-collapsed');
});

it('persists the album header collapsed toggle', function () {
    // ... same setup ...
    Livewire::withQueryParams(['artist' => '<id>', 'album' => '<id>'])
        ->test('pages::library')
        ->set('headerCollapsed', false);

    expect(\App\Support\AppSetting::albumHeaderCollapsed())->toBeFalse();
});
```

Add the analogous two tests to `tests/Feature/PlaylistDetailViewTest.php` (asserting `data-playlist-header-collapsed`).

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter='LibraryViewTest|PlaylistDetailViewTest'`
Expected: FAIL.

- [ ] **Step 3: Library — add `headerCollapsed` prop + hook + collapsed markup**

In `resources/views/pages/⚡library.blade.php` component class, add:

```php
    public bool $headerCollapsed = false;
```

In `mount()` add: `$this->headerCollapsed = \App\Support\AppSetting::albumHeaderCollapsed();`

Add:

```php
    public function updatedHeaderCollapsed(bool $value): void
    {
        \App\Support\AppSetting::setAlbumHeaderCollapsed($value);
    }
```

In the template, wrap the album-header block so it has an Alpine scope entangled to the prop, and branch on collapsed vs expanded. Replace the current album-header `@if ($this->selectedAlbum) ... @endif` block with:

```blade
        @if ($this->selectedAlbum)
            <div class="px-2 pb-2 flex-none" x-data="{ collapsed: @entangle('headerCollapsed') }" data-album-header>
                {{-- Collapsed 56px row --}}
                <div x-show="collapsed" x-cloak data-album-header-collapsed
                     class="relative flex items-center gap-3 h-14 px-4 rounded-lg" style="background: linear-gradient(180deg, rgba(42, 42, 42, 0.55) 0%, var(--color-surface) 100%);">
                    @if ($this->selectedAlbum->thumb)
                        <img src="{{ $this->thumbFor($this->selectedAlbum->thumb) }}" alt="{{ $this->selectedAlbum->title }}"
                             class="w-10 h-10 rounded flex-none bg-surface-2 object-cover">
                    @else
                        <div class="w-10 h-10 rounded flex-none bg-surface-2 grid place-items-center"><x-lucide-disc class="w-5 h-5 text-text-3" /></div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-[14px] font-bold text-white">{{ $this->selectedAlbum->title }}</div>
                        <div class="truncate text-[11px] text-text-2">{{ collect(['Album', $this->selectedAlbum->artist, $this->selectedAlbum->year])->filter()->implode(' · ') }}</div>
                    </div>
                    <template x-if="$store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}'">
                        <span class="eq flex-none" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                    </template>
                    <button type="button" wire:click="playAlbum" class="w-8 h-8 rounded-full bg-accent hover:bg-accent-hover grid place-items-center text-black flex-none"><x-lucide-play class="w-4 h-4" style="fill: currentColor;" /></button>
                    <button type="button" wire:click="shuffleAlbum" class="w-8 h-8 rounded-full grid place-items-center text-text-2 hover:text-white flex-none"><x-lucide-shuffle class="w-4 h-4" /></button>
                    <button type="button" @click="collapsed = false" title="Expand" class="w-8 h-8 rounded-full grid place-items-center text-text-2 hover:text-white flex-none"><x-lucide-chevron-down class="w-4 h-4" /></button>
                    <button type="button" wire:click="closeAlbum" title="Close album" class="w-8 h-8 rounded-full grid place-items-center text-text-2 hover:text-white flex-none"><x-lucide-x class="w-4 h-4" /></button>
                </div>

                {{-- Expanded header --}}
                <div x-show="!collapsed" class="relative overflow-hidden rounded-lg" style="background: linear-gradient(180deg, rgba(42, 42, 42, 0.55) 0%, var(--color-surface) 100%);">
                    <div class="absolute top-3 right-3 z-10 flex items-center gap-1">
                        <button type="button" @click="collapsed = true" title="Collapse"
                                class="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-black/30 transition-colors">
                            <x-lucide-chevron-up class="w-4 h-4" />
                        </button>
                        <button type="button" wire:click="closeAlbum" title="Close album"
                                class="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-black/30 transition-colors">
                            <x-lucide-x class="w-4 h-4" />
                        </button>
                    </div>
                    {{-- ...existing expanded header inner markup (cover, title, meta, source-indicator template from Task 6, Play/Shuffle/Download buttons)... --}}
                </div>
            </div>
        @endif
```

Keep the existing expanded inner markup (the `<div class="px-6 py-5 ...">` block, the meta `<div>`, the Task-6 source-indicator `<template x-if>`, and the `<div class="px-6 pb-4 ...">` Play/Shuffle/Download row) inside the `x-show="!collapsed"` wrapper. Remove the old standalone close-`×` button (it is now in the `absolute top-3 right-3` group alongside the collapse chevron).

- [ ] **Step 4: Playlist-detail — same treatment**

In `resources/views/pages/⚡playlist-detail.blade.php` component class, add `public bool $headerCollapsed = false;`, set it in `mount()` from `\App\Support\AppSetting::albumHeaderCollapsed()`, and add `updatedHeaderCollapsed(bool $value): void { \App\Support\AppSetting::setAlbumHeaderCollapsed($value); }`.

In the template's `@else` branch (the `@php($meta = $this->playlistMeta)` block), wrap the gradient header in `<div ... x-data="{ collapsed: @entangle('headerCollapsed') }" data-playlist-header>` with a `x-show="collapsed" x-cloak data-playlist-header-collapsed` 56px row (cover 40px, title, `{{ $this->tracks->count() }} songs` meta, the Task-6 source-indicator template, Play (`wire:click="playAll"`), Shuffle (`wire:click="shuffle"`), Expand chevron `@click="collapsed = false"`) and the existing expanded header under `x-show="!collapsed"` with a Collapse chevron `@click="collapsed = true"` added top-right.

- [ ] **Step 5: Run feature tests**

Run: `php artisan test --compact --filter='LibraryViewTest|PlaylistDetailViewTest'`
Expected: PASS.

- [ ] **Step 6 (optional): Browser test**

Add `tests/Browser/AlbumHeaderTest.php` (or extend `LibraryDrillDownTest`): drill into an album, click the Collapse chevron, assert `[data-album-header-collapsed]` is visible; reload the page (`visit` again), assert it is still collapsed; click Expand, assert the expanded header is visible. Mirror the `LibraryDrillDownTest` `script()` patterns for driving the drill-down.

- [ ] **Step 7: Build assets + run browser tests**

Run: `npm run build`
Run: `php artisan test --compact --filter='AlbumHeaderTest|LibraryDrillDownTest|PlaylistDetailTest'` → PASS.

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/⚡library.blade.php" "resources/views/pages/⚡playlist-detail.blade.php" tests/Feature tests/Browser
git commit -m "feat(headers): collapsible album/playlist header, persisted"
```

---

## Task 11: Error toasts component + route DnD/CRUD failures to it

A `toasts` SFC renders a fixed stack of dismissible error toasts triggered by a `notify` event. Sidebar playlist-write failures dispatch `notify` (the existing red ring-flash stays as the immediate cue). No success toasts.

**Files:**
- Create: `resources/views/components/⚡toasts.blade.php`
- Modify: `resources/views/components/layouts/app.blade.php` (render `<livewire:toasts />`)
- Modify: `resources/views/components/⚡sidebar.blade.php` (dispatch `notify` on the `catch` paths)
- Create/Modify: `tests/Feature/ToastsTest.php` (new) and `tests/Feature/SidebarTest.php`

- [ ] **Step 1: Add failing tests**

Create `tests/Feature/ToastsTest.php`:

```php
<?php

use Livewire\Livewire;

it('shows a toast when a notify event arrives', function () {
    Livewire::test('toasts')
        ->dispatch('notify', type: 'error', message: 'Something broke.')
        ->assertSee('Something broke.');
});

it('dismisses a toast', function () {
    $component = Livewire::test('toasts')
        ->dispatch('notify', type: 'error', message: 'Something broke.')
        ->assertSee('Something broke.');

    $component->call('dismiss', 0)->assertDontSee('Something broke.');
});
```

In `tests/Feature/SidebarTest.php`, add (it `uses(RefreshDatabase::class)` and mocks `PlexClient`; mirror the existing addTrack tests):

```php
it('dispatches a notify error when adding a track to a playlist fails', function () {
    // mock PlexClient so addTrackToPlaylist throws a PlexException, and ping() is stubbed (server-chip)
    Livewire::test('sidebar')
        ->call('addTrackToPlaylist', 'pl-1', 'tr-1')
        ->assertDispatched('notify', type: 'error');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter='ToastsTest|SidebarTest'`
Expected: FAIL — `toasts` component does not exist; sidebar does not dispatch `notify`.

- [ ] **Step 3: Create the `toasts` SFC**

Create `resources/views/components/⚡toasts.blade.php`:

```php
<?php

use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    /** @var list<array{type: string, message: string}> */
    public array $toasts = [];

    #[On('notify')]
    public function notify(string $message, string $type = 'error'): void
    {
        $this->toasts[] = ['type' => $type, 'message' => $message];
    }

    public function dismiss(int $index): void
    {
        unset($this->toasts[$index]);
        $this->toasts = array_values($this->toasts);
    }
};
?>

<div class="fixed bottom-24 right-4 z-[2000] flex flex-col gap-2 w-[320px] pointer-events-none">
    @foreach ($toasts as $i => $toast)
        <div wire:key="toast-{{ $i }}"
             x-data
             x-init="setTimeout(() => $wire.dismiss({{ $i }}), 5000)"
             class="pointer-events-auto flex items-start gap-3 rounded-lg bg-surface-2 ring-1 ring-white/10 shadow-2xl px-4 py-3 text-[13px]">
            <x-lucide-circle-alert class="w-4 h-4 text-red-400 flex-none mt-0.5" />
            <div class="flex-1 min-w-0 text-text-1">{{ $toast['message'] }}</div>
            <button type="button" wire:click="dismiss({{ $i }})" class="text-text-3 hover:text-white flex-none">
                <x-lucide-x class="w-3.5 h-3.5" />
            </button>
        </div>
    @endforeach
</div>
```

(No transitions — per spec. The `x-init` setTimeout auto-dismisses; each toast's index is stable enough for this single-user app. If two arrive in quick succession the indices still resolve because `dismiss` re-keys.)

- [ ] **Step 4: Render it in the layout**

In `resources/views/components/layouts/app.blade.php`, add `<livewire:toasts />` just before `@livewireScripts` (outside the `app-shell` div, outside `@persist`):

```html
        {{-- Player region --}}
        <div class="flex-none">
            @persist('player')
                <livewire:player />
            @endpersist
        </div>
    </div>

    <livewire:toasts />

    @livewireScripts
</body>
```

- [ ] **Step 5: Dispatch `notify` from the sidebar `catch` paths**

In `resources/views/components/⚡sidebar.blade.php`, in each of `addTrackToPlaylist`, `addAlbumToPlaylist`, `createPlaylistFromTrack`, `renamePlaylist`, `deletePlaylist`: in the `catch (PlexException $e)` block, after the existing `Log::channel('plex')->warning(...)` line, add a `$this->dispatch('notify', ...)`. Use action-specific messages:

- `addTrackToPlaylist` catch: `$this->dispatch('notify', type: 'error', message: 'Couldn\'t add that song to the playlist. Plex may be unreachable.');`
- `addAlbumToPlaylist` catch: `$this->dispatch('notify', type: 'error', message: 'Couldn\'t add that album to the playlist. Plex may be unreachable.');`
- `createPlaylistFromTrack` catch: `$this->dispatch('notify', type: 'error', message: 'Couldn\'t create the playlist. Plex may be unreachable.');`
- `renamePlaylist` catch: `$this->dispatch('notify', type: 'error', message: 'Couldn\'t rename the playlist. Plex may be unreachable.');`
- `deletePlaylist` catch: `$this->dispatch('notify', type: 'error', message: 'Couldn\'t delete the playlist. Plex may be unreachable.');`

(Also: `playPlaylist`'s catch can get `$this->dispatch('notify', type: 'error', message: 'Couldn\'t start that playlist. Plex may be unreachable.');` for consistency — optional.)

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact --filter='ToastsTest|SidebarTest|RoutesTest'`
Expected: PASS. (`RoutesTest` re-renders pages with the layout, which now includes `<livewire:toasts />` — confirm it still renders. The `toasts` component does not touch Plex, so no extra mock needed; but `RoutesTest` already renders the sidebar+server-chip so its existing `PlexClient` mock covers it.)

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/components/⚡toasts.blade.php" resources/views/components/layouts/app.blade.php "resources/views/components/⚡sidebar.blade.php" tests/Feature
git commit -m "feat(toasts): error toast component; route playlist write failures to it"
```

---

## Task 12: Keyboard shortcuts

Space = play/pause, ArrowRight/ArrowLeft = next/prev, ⌘K / Ctrl+K / `/` = focus the topbar search. Implemented as a `window` keydown listener inside `audioPlayer().init()`. The topbar search `<input>` needs a stable id.

**Files:**
- Modify: `resources/views/components/⚡topbar.blade.php` (add `id="topbar-search"`)
- Modify: `resources/views/components/⚡player.blade.php` (`audioPlayer().init()` keydown listener)
- Create: `tests/Browser/KeyboardShortcutsTest.php`

- [ ] **Step 1: Add a failing browser test**

Create `tests/Browser/KeyboardShortcutsTest.php` (mirror existing browser-test setup; `uses(RefreshDatabase::class)` if needed since the sidebar renders):

```php
<?php

it('focuses the search input on "/"', function () {
    $page = visit('/');
    $page->keys('body', ['/']); // or page.keyboard.press('/') equivalent
    $page->assertScript("document.activeElement && document.activeElement.id", 'topbar-search');
});

it('toggles play/pause on Space', function () {
    $page = visit('/');
    // play something first via the library (mirror PlayerQueueTest's drill-and-click), then:
    // press Space, assert $store.player.isPlaying flipped from true to false
    // (use script()/assertScript to read window.Alpine.store('player').isPlaying)
});
```

Use whatever the Pest browser plugin's key-press API is — check an existing browser test or the plugin docs (`search-docs` with `["browser testing", "keyboard"]`). If there is no direct key-press helper, dispatch a `KeyboardEvent` via `script()`:
`$page->script("window.dispatchEvent(new KeyboardEvent('keydown', { key: '/' }))");` then assert focus.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=KeyboardShortcutsTest`
Expected: FAIL.

- [ ] **Step 3: Add the id to the topbar input**

In `resources/views/components/⚡topbar.blade.php`, change:

```html
            <input type="text"
                   wire:model.live.debounce.300ms="query"
                   placeholder="What do you want to play?"
                   class="bg-transparent outline-none text-[14px] flex-1 placeholder:text-text-2">
```

to add `id="topbar-search"`:

```html
            <input type="text"
                   id="topbar-search"
                   wire:model.live.debounce.300ms="query"
                   placeholder="What do you want to play?"
                   class="bg-transparent outline-none text-[14px] flex-1 placeholder:text-text-2">
```

- [ ] **Step 4: Add the keydown listener in `audioPlayer().init()`**

In `resources/views/components/⚡player.blade.php`, in `audioPlayer().init()`, after the `queue-load` listener and `this.$refs.audio.volume = this.volume;`, add:

```js
                const isTypingTarget = (el) => {
                    if (!el) return false;
                    const tag = el.tagName;
                    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
                };
                window.addEventListener('keydown', (e) => {
                    // Focus search: Cmd/Ctrl+K, or "/" when not typing.
                    if ((e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey)) {
                        e.preventDefault();
                        document.getElementById('topbar-search')?.focus();
                        return;
                    }
                    if (e.key === '/' && !isTypingTarget(e.target) && !e.metaKey && !e.ctrlKey && !e.altKey) {
                        e.preventDefault();
                        document.getElementById('topbar-search')?.focus();
                        return;
                    }
                    if (isTypingTarget(e.target)) return;
                    if (e.metaKey || e.ctrlKey || e.altKey) return;
                    if (e.key === ' ') {
                        const tag = e.target?.tagName;
                        if (tag === 'BUTTON' || (e.target && e.target.getAttribute && e.target.getAttribute('role') === 'button')) return; // let native click handle it
                        e.preventDefault();
                        this.togglePlay();
                        return;
                    }
                    if (e.key === 'ArrowRight') { this.next(); return; }
                    if (e.key === 'ArrowLeft') { this.previous(); return; }
                });
```

- [ ] **Step 5: Build assets + run the browser test**

Run: `npm run build`
Run: `php artisan test --compact --filter=KeyboardShortcutsTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/components/⚡topbar.blade.php" "resources/views/components/⚡player.blade.php" tests/Browser/KeyboardShortcutsTest.php
git commit -m "feat(shortcuts): Space play/pause, arrows next/prev, Cmd-K / slash focus search"
```

---

## Task 13: Empty states (playlist-detail empty playlist; library no-artists)

The other two empty states the spec listed (recently-played, sidebar) already exist — do not touch them. The library albums-column "Select an artist" / "No albums" states also already exist. This task adds: (a) an empty-playlist state in playlist-detail, (b) a no-artists / no-music state in the library artists column.

**Files:**
- Modify: `resources/views/pages/⚡playlist-detail.blade.php`
- Modify: `resources/views/pages/⚡library.blade.php`
- Modify: `tests/Feature/PlaylistDetailViewTest.php`, `tests/Feature/LibraryViewTest.php`

- [ ] **Step 1: Add failing feature tests**

In `tests/Feature/PlaylistDetailViewTest.php` add (mock `PlexClient` so `playlists()` returns one `Playlist` with `trackCount` 0 and `playlistTracks()` returns an empty collection):

```php
it('shows an empty-playlist state for a playlist with no tracks', function () {
    // ... mock PlexClient: playlists() -> collect([$playlistDtoWithZeroTracks]); playlistTracks() -> collect(); ping() stubbed ...
    Livewire::test('pages::playlist-detail', ['playlist' => '<id>'])
        ->assertSee('This playlist is empty');
});
```

In `tests/Feature/LibraryViewTest.php` add (mock `PlexClient::artists()` to return an empty collection, `ping()` stubbed):

```php
it('shows a no-music state when the library has no artists', function () {
    // ... mock PlexClient::artists() -> collect(); ...
    Livewire::test('pages::library')
        ->assertSee('No music found');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter='PlaylistDetailViewTest|LibraryViewTest'`
Expected: FAIL.

- [ ] **Step 3: Playlist-detail — empty-playlist state**

In `resources/views/pages/⚡playlist-detail.blade.php`, in the `@else` branch, after the gradient header `<div class="px-2 pt-2 pb-2 flex-none"> ... </div>` and replacing the tracklist block with a conditional: if `$this->tracks->isEmpty()`, render the empty state instead of the tracklist `<div data-region="tracklist">`:

```blade
    @if ($this->tracks->isEmpty())
        <div class="flex-1 grid place-items-center p-12 text-center">
            <div class="max-w-md">
                <x-lucide-list-music class="w-12 h-12 mx-auto text-text-3 mb-4" />
                <h2 class="text-xl font-bold mb-2">This playlist is empty</h2>
                <p class="text-text-2 text-sm">Add songs from your library by dragging them onto the playlist in the sidebar.</p>
            </div>
        </div>
    @else
        {{-- ...existing <div class="flex-1 min-h-0 flex flex-col px-2 pb-2 overflow-hidden" data-region="tracklist"> ... </div> ... --}}
    @endif
```

- [ ] **Step 4: Library — no-artists state in the artists column**

In `resources/views/pages/⚡library.blade.php`, in the artists column `<div class="overflow-y-auto scroll flex-1" data-region="artists-column">`, wrap the `@foreach ($this->artists as $artist)` so an empty list shows a message:

```blade
                <div class="overflow-y-auto scroll flex-1" data-region="artists-column">
                    @forelse ($this->artists as $artist)
                        {{-- ...existing artist <button> markup... --}}
                    @empty
                        <div class="grid place-items-center h-full text-text-3 text-[12px] px-4 text-center">No music found in your Plex library.</div>
                    @endforelse
                </div>
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter='PlaylistDetailViewTest|LibraryViewTest'`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/⚡playlist-detail.blade.php" "resources/views/pages/⚡library.blade.php" tests/Feature
git commit -m "feat(empty-states): empty-playlist + no-music messages"
```

---

## Task 14: Full suite, manual smoke checkpoint, CLAUDE.md update

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Build assets and run the full suite**

Run: `npm run build`
Run: `php artisan test --compact`
Expected: all green (the 190 prior tests plus the new ones — roughly 210+). Browser tests require the live Plex server reachable; if Plex is down, run `php artisan test --compact --testsuite=Unit,Feature` and note the Browser suite was skipped.

- [ ] **Step 2: Manual smoke-test checkpoint with the user**

Per the project's working agreement, before declaring M7 done, present the user with grouped manual checks (~3-4 per set, one set at a time) and wait for their report. Suggested groups:

1. Keyboard: load any page, press `/` — search focuses. Press `⌘K` — search focuses. Play a track, press Space — pauses/resumes. Press → / ← — next/prev track.
2. Toasts: with Plex reachable, drag a track onto a playlist — green ring flash, no toast. Then (simulate failure if feasible, or just confirm the happy path) — confirm no spurious error toasts appear in normal use.
3. Scrobble: with the Settings > Playback toggle On, play a short track to the end — confirm it advances; check Plex (web) shows the play. Toggle Off, play another to the end — confirm no scrobble. Toggle persists across reload.
4. Density: Settings > Display > Compact — tracklists + sidebar rows + album/playlist header get tighter. Navigate away and back — still compact. Switch back to Comfortable.
5. Collapsible header: open an album — click the collapse chevron — 56px row with Play/Shuffle/Expand. Reload — still collapsed. Expand. Same on a playlist detail page.
6. Highlight: play a track from a playlist — the playlist's sidebar row shows the equalizer; the playlist-detail row is accent-coloured with the equalizer; navigate to that track's album — the album row is NOT highlighted. Play from the album — album row highlighted, playlist row not. Search "Songs" and Recently Played rows highlight the playing track.
7. Empty states: open a playlist with no tracks — "This playlist is empty". (Library no-music state only shows if the library is genuinely empty — skip if not.)
8. Durations: confirm a track/album over an hour shows `H:MM:SS`.

Fix anything the user reports (each fix its own commit) before Step 3.

- [ ] **Step 3: Update `CLAUDE.md`**

In `CLAUDE.md`:
- Milestone status table: change row 7 ("Polish") status from "Next" to "Done".
- Add an "M7 Polish (shipped <date>, branch ...)" paragraph near the other milestone summaries, listing what shipped: `App\Support\Duration` (H:MM:SS, replaces the four `formatMs` copies); error `toasts` SFC + sidebar write failures dispatch `notify`; scrobble-to-Plex on track end + Settings "Playback" toggle (`AppSetting::scrobbleEnabled`, default on) + `PlexClient::scrobbleUrl()`; `data-density` attribute on `<body>` + `[data-density="compact"]` CSS (tighter tracklists/sidebar/header); collapsible album & playlist header (`AppSetting::albumHeaderCollapsed`, `@entangle`d, persisted); playback-context-aware highlight (`contextType`/`contextId` on the `play-track`/`queue-load` payload and `$store.player`; highlight extended to search "Songs" + recently-played; source indicator on the sidebar playlist row + album/playlist headers); keyboard shortcuts (Space, ←/→, ⌘K / `/`); empty-playlist + no-music empty states. New tests: `DurationTest`, `ToastsTest`, `KeyboardShortcutsTest`, extended `AppSettingTest`/`PlexClientTest`/`PlayerComponentTest`/`SettingsViewTest`/`LibraryViewTest`/`PlaylistDetailViewTest`/`SidebarTest`/`PlayerQueueTest`/`SettingsTest`, optional `AlbumHeaderTest`.
- Update the test count line ("`php artisan test` should be green (N tests, ...)").
- Add any new gotchas discovered during implementation (e.g. if the `@entangle`-on-header approach or the keyboard listener needed a workaround).
- Remove the now-shipped items from the memory file note if appropriate (the pending-polish-followups memory) — actually, leave memory edits to the assistant's own judgement at session end; just note in `CLAUDE.md` that items 1-3 of that list shipped in M7.

- [ ] **Step 4: Commit the docs update**

```bash
git add CLAUDE.md
git commit -m "docs(claude): M7 polish shipped"
```

- [ ] **Step 5: Finish the branch**

Use the `superpowers:finishing-a-development-branch` skill to decide how to integrate (merge to `main` / open a PR / etc.), following the project's convention (M6 was a `feat/settings` branch merged with a merge commit).

---

## Self-Review (run before handing off)

- **Spec coverage:** §1 keyboard shortcuts → Task 12 (+ topbar id; ⌘K badge already present, noted). §2 error toasts → Task 11 (scoped to sidebar write failures + the toasts component; page-load failures keep their existing inline full-screen states — this narrows the spec's looser wording, which is acceptable and noted). §3 scrobble → Tasks 3 (`scrobbleUrl`), 7 (player wiring), 8 (Settings toggle); `AppSetting::scrobbleEnabled` in Task 2. §4 density wiring → Task 9. §5 collapsible header → Task 10; `AppSetting::albumHeaderCollapsed` in Task 2. §6 context-aware highlight + source indicator → Tasks 4 (plumbing), 5 (tracklists + search/recently-played), 6 (source indicator). §7 `formatMs` hours + dedup → Task 1. §8 empty states → Task 13 (only the two that don't already exist; the other two noted as pre-existing). Tests + smoke checkpoint + `CLAUDE.md` → Task 14. All covered.
- **Placeholder scan:** No "TBD"/"TODO". A few steps say "mirror the existing test setup in file X" / "adapt selectors to the existing browser tests" — that is deliberate (the implementer must read those files; the patterns are well-established and shown in this repo), not a content gap. The collapsed-header markup in Task 10 references `design/plextune-parts.jsx` for the exact prototype structure — also deliberate; a reasonable port is given inline as the floor.
- **Type/name consistency:** `contextType`/`contextId` used identically across the relay (`onPlayTrack`), `queue-load`, `$store.player`, `audioPlayer()` fields, and every dispatcher. `headerCollapsed` public prop + `updatedHeaderCollapsed` hook + `AppSetting::albumHeaderCollapsed`/`setAlbumHeaderCollapsed` consistent in both pages. `scrobbleEnabled`/`scrobbleUrlTemplate` consistent between the `player` component and `audioPlayer(scrobbleEnabled, scrobbleUrlTemplate)`. `notify` event + `dismiss(int)` consistent between `toasts` and the sidebar dispatches. `Duration::format` consistent everywhere. `__KEY__` placeholder consistent between `PlexClient::scrobbleUrl('__KEY__')` and `scrobbleUrlTemplate.replace('__KEY__', ...)`. `data-density` attribute name consistent between the layout and the CSS. `data-album-header` / `data-album-header-collapsed` / `data-playlist-header` / `data-playlist-header-collapsed` hooks consistent between markup, CSS, and tests.
