# Playlist Tracklist Artist/Album Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In the playlist-detail tracklist, make the artist text and the Album column navigate to the library (artist → `/?artist={artistId}`, album → `/?artist={artistId}&album={albumId}`), mirroring the player's now-playing strip.

**Architecture:** Pure Blade change in `resources/views/pages/⚡playlist-detail.blade.php`. The artist/album cells in both the compact and comfortable row variants become `<span>` elements with `@click.stop="Livewire.navigate(route('library', ...))"`. `.stop` keeps the click off the row's `wire:click="playTrack"`. Server-side `@if ($track->artistId)` / `@if ($track->artistId && $track->albumId)` guards fall back to the existing plain `<div>` when the Track DTO's nullable `artistId` (`grandparentRatingKey`) / `albumId` (`parentRatingKey`) is missing. No PHP component, DTO, or route changes.

**Tech Stack:** Laravel 13, Livewire 4 (SFC page component), Alpine.js, Tailwind 4, Pest 4 (+ pest-plugin-browser / Playwright).

---

## Spec

`docs/superpowers/specs/2026-05-12-plextune-playlist-tracklist-links-design.md` (approved 2026-05-12).

## Prerequisites (read once, do not skip)

- The library route is `Route::livewire('/', 'pages::library')->name('library')`, so `route('library', ['artist' => $id])` returns `…/?artist=$id` and `route('library', ['artist' => $a, 'album' => $b])` returns `…/?artist=$a&album=$b`. Confirmed via `php artisan route:list --name=library`.
- `route()` inside a Blade `{{ }}` is HTML-escaped, so the rendered attribute contains `&amp;` not `&` for the two-param URL. The browser decodes it back to `&` when parsing the attribute, so Alpine sees the correct URL. The feature tests below account for this by escaping the expected needle with `e()`.
- The `@foreach` container `<div class="overflow-y-auto scroll flex-1 py-1" x-data="{}">` already provides an Alpine scope, so inline `@click.stop` directives on the rows work. (`@click` is not a registered Blade directive and is preceded by whitespace, so Blade passes it through verbatim — the file already uses `@click="…"` elsewhere.)
- A `<span>` is phrasing content, so nesting it inside the row's `<button>` is valid (the existing `<div>`s technically are not, but we are not touching the title `<div>`). In the compact row the artist/album spans are direct grid items (auto-blockified, so `truncate` works). The comfortable artist sub-line span is *not* a grid item — it gets a `block` class added so `truncate` keeps working.

## File Structure

- **Modify:** `resources/views/pages/⚡playlist-detail.blade.php` — the `@foreach` rows only (compact branch, then comfortable branch). The only production file touched.
- **Modify:** `tests/Feature/PlaylistDetailViewTest.php` — three new `it()` blocks (comfortable links, compact links, null-id fallback). Reuses the existing `samplePlaylist()` / `samplePlaylistTracks()` / `mockPlexForPlaylist()` helpers (the fixture tracks already carry `albumId`/`artistId`).
- **Modify:** `tests/Browser/PlaylistDetailTest.php` — one new `it()` block driving the link spans via `script()`.
- **Modify (docs):** `CLAUDE.md` — append a project-log paragraph.

---

### Task 1: Link the artist and album in the comfortable playlist tracklist

**Files:**
- Modify: `tests/Feature/PlaylistDetailViewTest.php` (add one `it()` after the existing "renders one row per track with the album column" test)
- Modify: `resources/views/pages/⚡playlist-detail.blade.php:341` (comfortable artist sub-line) and `:344` (comfortable Album column)

- [ ] **Step 1: Write the failing feature test**

Add this `it()` to `tests/Feature/PlaylistDetailViewTest.php` (after the existing `it('renders one row per track with the album column', …)` block — around line 65). `e()`, `route()`, `AppSetting`, `Track` are all already available/imported in this file.

```php
it('links the artist and album to the library from each comfortable playlist track row', function () {
    mockPlexForPlaylist();

    $artistUrl = route('library', ['artist' => '100']);
    $albumUrl1 = route('library', ['artist' => '100', 'album' => '5001']);
    $albumUrl2 = route('library', ['artist' => '100', 'album' => '5002']);

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSet('tracksCompact', false)
        ->assertSeeHtml("Livewire.navigate('".e($artistUrl)."')")
        ->assertSeeHtml("Livewire.navigate('".e($albumUrl1)."')")
        ->assertSeeHtml("Livewire.navigate('".e($albumUrl2)."')");
});
```

- [ ] **Step 2: Run the test, verify it FAILS**

Run: `php artisan test --compact --filter="links the artist and album to the library from each comfortable"`
Expected: FAIL — the rendered HTML has plain `<div>{{ $track->artist }}</div>` / `<div>{{ $track->album }}</div>`, no `Livewire.navigate('…?artist=…')`.

- [ ] **Step 3: Edit the comfortable artist sub-line**

In `resources/views/pages/⚡playlist-detail.blade.php`, inside the `@else` (comfortable) branch of the `@if ($tracksCompact)` row, find this block (the `<div class="min-w-0">` wrapping the title + artist sub-line; preserve the existing indentation, ~36 spaces):

```blade
                                    <div class="min-w-0">
                                        <div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
                                        <div class="truncate text-[12px] text-text-2 group-hover:text-white">{{ $track->artist }}</div>
                                    </div>
```

Replace it with:

```blade
                                    <div class="min-w-0">
                                        <div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
                                        @if ($track->artistId)
                                            <span class="block truncate text-[12px] text-text-2 group-hover:text-white hover:underline cursor-pointer"
                                                  @click.stop="Livewire.navigate('{{ route('library', ['artist' => $track->artistId]) }}')">{{ $track->artist }}</span>
                                        @else
                                            <div class="truncate text-[12px] text-text-2 group-hover:text-white">{{ $track->artist }}</div>
                                        @endif
                                    </div>
```

- [ ] **Step 4: Edit the comfortable Album column**

In the same comfortable branch, find this block (the Album `<div>` followed by the heart `<span>` — the `<x-lucide-heart>` makes it unambiguous; preserve indentation, ~32 spaces):

```blade
                                </div>
                                <div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>
                                <span class="grid place-items-center text-text-2 hover:text-white">
                                    <x-lucide-heart class="w-3.5 h-3.5" />
                                </span>
```

Replace it with:

```blade
                                </div>
                                @if ($track->artistId && $track->albumId)
                                    <span class="text-text-2 group-hover:text-white truncate hover:underline cursor-pointer"
                                          @click.stop="Livewire.navigate('{{ route('library', ['artist' => $track->artistId, 'album' => $track->albumId]) }}')">{{ $track->album }}</span>
                                @else
                                    <div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>
                                @endif
                                <span class="grid place-items-center text-text-2 hover:text-white">
                                    <x-lucide-heart class="w-3.5 h-3.5" />
                                </span>
```

- [ ] **Step 5: Run the test, verify it PASSES**

Run: `php artisan test --compact --filter="links the artist and album to the library from each comfortable"`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/⚡playlist-detail.blade.php" tests/Feature/PlaylistDetailViewTest.php
git commit -m "feat(playlist): link artist/album from the comfortable tracklist rows"
```

---

### Task 2: Link the artist and album in the compact playlist tracklist

**Files:**
- Modify: `tests/Feature/PlaylistDetailViewTest.php` (add one `it()`)
- Modify: `resources/views/pages/⚡playlist-detail.blade.php:308-309` (compact artist + Album columns)

- [ ] **Step 1: Write the failing feature test**

Add this `it()` to `tests/Feature/PlaylistDetailViewTest.php` (next to the existing `it('renders the compact playlist tracklist when tracksCompact is on', …)` block):

```php
it('links the artist and album to the library in the compact playlist tracklist', function () {
    AppSetting::setPlaylistTracksCompact(true);
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSet('tracksCompact', true)
        ->assertSeeHtml("Livewire.navigate('".e(route('library', ['artist' => '100']))."')")
        ->assertSeeHtml("Livewire.navigate('".e(route('library', ['artist' => '100', 'album' => '5001']))."')");
});
```

- [ ] **Step 2: Run the test, verify it FAILS**

Run: `php artisan test --compact --filter="links the artist and album to the library in the compact"`
Expected: FAIL — the compact row still has plain `<div>` cells.

- [ ] **Step 3: Edit the compact artist + Album columns**

In `resources/views/pages/⚡playlist-detail.blade.php`, inside the `@if ($tracksCompact)` branch of the row, find this block (the title `:class` div, then artist, album, and the compact-only duration div with `text-[12px]` — that last line makes the block unambiguous; preserve indentation, ~32 spaces):

```blade
                                <div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
                                <div class="text-text-2 group-hover:text-white truncate">{{ $track->artist }}</div>
                                <div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>
                                <div class="text-text-2 tabular-nums text-right text-[12px]">{{ \App\Support\Duration::format($track->durationMs) }}</div>
```

Replace it with:

```blade
                                <div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
                                @if ($track->artistId)
                                    <span class="text-text-2 group-hover:text-white truncate hover:underline cursor-pointer"
                                          @click.stop="Livewire.navigate('{{ route('library', ['artist' => $track->artistId]) }}')">{{ $track->artist }}</span>
                                @else
                                    <div class="text-text-2 group-hover:text-white truncate">{{ $track->artist }}</div>
                                @endif
                                @if ($track->artistId && $track->albumId)
                                    <span class="text-text-2 group-hover:text-white truncate hover:underline cursor-pointer"
                                          @click.stop="Livewire.navigate('{{ route('library', ['artist' => $track->artistId, 'album' => $track->albumId]) }}')">{{ $track->album }}</span>
                                @else
                                    <div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>
                                @endif
                                <div class="text-text-2 tabular-nums text-right text-[12px]">{{ \App\Support\Duration::format($track->durationMs) }}</div>
```

- [ ] **Step 4: Run the test, verify it PASSES**

Run: `php artisan test --compact --filter="links the artist and album to the library in the compact"`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/⚡playlist-detail.blade.php" tests/Feature/PlaylistDetailViewTest.php
git commit -m "feat(playlist): link artist/album from the compact tracklist rows"
```

---

### Task 3: Plain-text fallback when a playlist track has no artist/album id

This is a regression-locking test. The `@if ($track->artistId)` / `@if ($track->artistId && $track->albumId)` guards added in Tasks 1–2 already implement the fallback, so this test passes the first time it runs — that is expected. Run it to confirm the `@else` branches behave, then commit.

**Files:**
- Modify: `tests/Feature/PlaylistDetailViewTest.php` (add one `it()`)

- [ ] **Step 1: Write the test**

Add this `it()` to `tests/Feature/PlaylistDetailViewTest.php`:

```php
it('renders the artist and album as plain text when a playlist track has no album/artist ids', function () {
    $this->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(collect([
            new Track(id: '8003', title: 'Local Recording', artist: 'Field Tape', album: 'Untitled', trackNumber: 1, durationMs: 120000, partId: 770003, container: 'mp3', thumb: null, albumId: null, artistId: null),
        ]));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->assertSee('Local Recording')
        ->assertSee('Field Tape')
        ->assertSee('Untitled')
        ->assertDontSee('?artist=');
});
```

- [ ] **Step 2: Run the test, verify it PASSES**

Run: `php artisan test --compact --filter="renders the artist and album as plain text when a playlist track has no"`
Expected: PASS. (`Livewire::test` renders only the component, not the layout, so there is no `?artist=` from anything else; the null-id track produces only the plain `<div>` fallbacks.)

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PlaylistDetailViewTest.php
git commit -m "test(playlist): plain-text artist/album fallback for tracks with no ids"
```

---

### Task 4: Browser coverage + asset rebuild

**Files:**
- Modify: `tests/Browser/PlaylistDetailTest.php` (add one `it()` after the existing test)

- [ ] **Step 1: Rebuild front-end assets**

The new Tailwind utilities (`hover:underline`, `cursor-pointer`, `block`) need to be in the compiled bundle for the live page (browser tests run against built assets, not `npm run dev`).

Run: `npm run build`
Expected: build completes, `public/build/manifest.json` regenerated.

- [ ] **Step 2: Write the browser test**

Append this `it()` to `tests/Browser/PlaylistDetailTest.php` (after the existing `it('navigates from the sidebar into a playlist and plays a track', …)` block). It hits the live Plex library (same environmental dependency as the rest of `tests/Browser/`). It scrapes a non-empty audio playlist href from the sidebar, then on the playlist page clicks the link spans in the first comfortable row (`span.cursor-pointer`: `[0]` = artist sub-line, `[1]` = Album column) and asserts the resulting URL.

```php
it('links the artist and album in a playlist track row to the library', function () {
    $page = visit('/');

    // Scrape a sidebar playlist link whose row text indicates at least one track.
    $targetHref = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                const rows = [...document.querySelectorAll('[wire\\:key^="sidebar-pl-"]')];
                if (rows.length > 0) {
                    for (const el of rows) {
                        if (!el.textContent.includes('0 songs')) {
                            const link = el.querySelector('a[href]') ?? el;
                            return link.getAttribute('href');
                        }
                    }
                }
                await sleep(150);
            }
            return null;
        })()
    JS);

    expect($targetHref)->not->toBeNull('Expected at least one non-empty audio playlist in the sidebar (is the Plex server reachable?).');

    // Reused: wait until the comfortable tracklist has rendered with link spans.
    $waitForLinkRows = '
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 30000;
            while (Date.now() < deadline) {
                if (document.querySelector(\'[wire\\\\:click="retry"]\')) return false;
                const row = document.querySelector(\'[wire\\\\:key^="track-"]\');
                if (row && row.querySelectorAll(\'span.cursor-pointer\').length >= 2) return true;
                await sleep(300);
            }
            return false;
        })()
    ';

    // --- Album link: span index 1 in the first row -> /?artist=...&album=...
    $page = visit($targetHref);
    expect((bool) $page->script($waitForLinkRows))->toBeTrue('Expected comfortable track rows with artist+album link spans on the playlist page.');

    $albumNav = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const row = document.querySelector('[wire\\:key^="track-"]');
            const links = [...row.querySelectorAll('span.cursor-pointer')];
            links[1].click();
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                if (location.pathname === '/' && location.search.startsWith('?artist=')) return location.search;
                await sleep(100);
            }
            return location.pathname + location.search;
        })()
    JS);

    expect($albumNav)->toContain('?artist=');
    expect($albumNav)->toContain('&album=');

    // --- Artist link: span index 0 -> /?artist=... with no &album=
    $page = visit($targetHref);
    expect((bool) $page->script($waitForLinkRows))->toBeTrue('Expected comfortable track rows with artist+album link spans on the playlist page (second visit).');

    $artistNav = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const row = document.querySelector('[wire\\:key^="track-"]');
            const links = [...row.querySelectorAll('span.cursor-pointer')];
            links[0].click();
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                if (location.pathname === '/' && location.search.startsWith('?artist=')) return location.search;
                await sleep(100);
            }
            return location.pathname + location.search;
        })()
    JS);

    expect($artistNav)->toContain('?artist=');
    expect($artistNav)->not->toContain('&album=');
});
```

- [ ] **Step 3: Run the browser test, verify it PASSES**

Run: `php artisan test --compact --filter="links the artist and album in a playlist track row to the library"`
Expected: PASS (requires the Plex server reachable with at least one non-empty audio playlist; a failure there is environmental, not a code bug — same as the other `tests/Browser/` tests).

- [ ] **Step 4: Commit**

```bash
git add tests/Browser/PlaylistDetailTest.php public/build
git commit -m "test(playlist): browser coverage for the tracklist artist/album links"
```

---

### Task 5: Update the project log and run the full suite

**Files:**
- Modify: `CLAUDE.md` (append a paragraph after the "Player volume persistence + mute toggle" paragraph)

- [ ] **Step 1: Append the project-log paragraph to `CLAUDE.md`**

Add this paragraph immediately after the existing `**Player volume persistence + mute toggle (shipped 2026-05-12, …)**` paragraph (and before the `Git log is the authoritative record…` line):

```markdown
**Playlist tracklist artist/album links (shipped 2026-05-12, branch `feat/playlist-tracklist-links`).** Spec: `docs/superpowers/specs/2026-05-12-plextune-playlist-tracklist-links-design.md`. Plan: `docs/superpowers/plans/2026-05-12-plextune-playlist-tracklist-links.md`. In the playlist-detail tracklist (both the compact and comfortable row variants) the artist text and the Album column are now `<span>`s with `@click.stop="Livewire.navigate('{{ route('library', ['artist' => artistId(, 'album' => albumId)]) }}')"`: clicking the artist opens `/?artist=…`, the album opens `/?artist=…&album=…` (the library album tracklist), mirroring the now-playing strip's `goToAlbum()`/`goToArtist()`. `.stop` keeps the click off the row's `wire:click="playTrack"`. Server-side `@if ($track->artistId)` / `@if ($track->artistId && $track->albumId)` guards fall back to plain text when the `Track` DTO's `artistId` (`grandparentRatingKey`) / `albumId` (`parentRatingKey`) is null. Only `pages/⚡playlist-detail.blade.php` changed (no PHP class / DTO changes); the library album tracklist and search results were intentionally left as plain text. Tests: extended `tests/Feature/PlaylistDetailViewTest.php` (+3) and `tests/Browser/PlaylistDetailTest.php` (+1).
```

- [ ] **Step 2: Run the full test suite**

Run: `php artisan test --compact`
Expected: green, ~246 tests (was 242), ~20 Playwright browser tests. (The browser tests need the Plex server reachable; environmental failures there are not code regressions.)

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(claude): record the playlist tracklist artist/album links"
```

---

## Out of scope (per the spec — do not build)

- Library album tracklist links (its artist sub-line stays plain text — the album link would self-link and the artist link only drops the album you are already viewing).
- Search results "Tracks" group links.
- Right-click / open-in-new-tab support (no `<a href>`; the now-playing strip has none either).
- Any change to the player's `goToAlbum`/`goToArtist` or the `Track` DTO.

## Self-review notes

- **Spec coverage:** comfortable links (Task 1), compact links (Task 2), null-id fallback both branches (Task 3), browser nav for album + artist (Task 4), docs (Task 5). All spec sections covered.
- **HTML-escaping of `&`:** handled — feature tests escape the expected needle with `e()`; the browser asserts on `location.search` (post-decode), not on raw HTML.
- **Edit uniqueness:** each `old_string` block includes a branch-specific anchor (`<div class="min-w-0">`, the `text-[12px]` compact duration div, the `<x-lucide-heart>` span) so the comfortable and compact Album `<div>`s — which are otherwise identical strings — are unambiguous.
- **Naming consistency:** `route('library', ['artist' => …, 'album' => …])`, `$track->artistId`, `$track->albumId`, `Livewire.navigate`, `@click.stop` used identically across all tasks.
