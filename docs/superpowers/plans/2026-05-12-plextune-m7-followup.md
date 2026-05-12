# Plextune M7 Follow-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land four M7-acceptance fixes on `feat/m7-polish` before merging M7: rebuild search so typing never loses focus or characters (search-as-overlay, Alpine-owned topbar input, no navigation); replace the global density approach with the design's per-section compact toggles + real compact layouts; wire the Media Session API for OS media keys; swap the now-playing source indicator from the animated equalizer to a static green speaker glyph.

**Architecture:** (1) Search: a new `<livewire:search />` component lives in the layout main pane, always rendered, empty when `?q=` is blank; the topbar search `<input>` is Alpine-owned (`x-model`), dispatches a `plextune-search` browser event on debounced input; the search component catches it via `#[On]`, runs the Plex search, and re-renders only itself; `?q=` is synced by the search component's `#[Url]` prop; nothing navigates. (2) Density: four persisted per-section booleans on `AppSetting` (defaulting to the Settings "Density default"), wired to the existing placeholder header toggle buttons; comfortable markup unchanged, compact markup ported from `design/rendered/compact.html`. (3) Media Session: `navigator.mediaSession` metadata + action handlers in `audioPlayer()`. (4) Icon swap: `.eq` → `<x-lucide-volume-1 text-accent>` for source indicators only (tracklist `.eq` stays).

**Tech Stack:** Laravel 13, Livewire 4 (single-file components), Alpine.js, Tailwind CSS 4, Pest 4 (+ `pestphp/pest-plugin-browser`/Playwright), MySQL, native HTML5 `<audio>`, the browser Media Session API.

**Spec:** `docs/superpowers/specs/2026-05-12-plextune-m7-followup-design.md`. Read it first.

**Conventions:** Livewire 4 SFCs at `resources/views/components/⚡<name>.blade.php` (⚡ = literal U+26A1); routed pages at `resources/views/pages/⚡<name>.blade.php`; `Route::livewire('/path', 'pages::name')`. PSR-12, typed properties, constructor property promotion, early returns, no `else`, no docblocks on fully-typed methods, migrations only `up()`. Pest `it()` style. After PHP changes run `vendor/bin/pint --dirty --format agent`. Run tests with `php artisan test --compact` filtered to the touched file. After Blade/CSS changes run `npm run build` (don't run `npm run dev`). Browser tests hit the live Plex server at `https://plexify.test`. Never substitute Unicode glyphs for icons — use `<x-lucide-*>`. Port UI markup/classes verbatim from `design/rendered/<view>.html`. Don't use em/en dashes in markdown docs. Known gotchas: a Livewire 4 component needs ONE stable root element (conditionals go *inside* it); `#[Computed]` methods don't autowire params (use `app()`); `mount()`/action methods DO autowire; Alpine reactive expressions on `$store.player` must be `?.`-guarded; the player is `@persist`ed so `audioPlayer().init()` runs once.

---

## File Structure

**New files:**
- `resources/views/components/⚡search.blade.php` — Livewire SFC: the search-results overlay. Owns `q` (`#[Url]`), `filter`, `results()` computed, `setFilter`/`playTrack`/`goToAlbum`/`goToArtist`/`showSection`/`thumbFor`; listens for `plextune-search`; renders empty root when `q===''`, else the ported search UI overlaying the main pane.
- `tests/Feature/SearchComponentTest.php` — feature tests for the new `search` component (replaces/absorbs `tests/Feature/SearchViewTest.php`'s coverage).

**Modified files:**
- `resources/views/components/layouts/app.blade.php` — `<main>` content wrapper gets `relative`; `<livewire:search />` rendered after `{{ $slot }}`.
- `resources/views/pages/⚡search.blade.php` — gutted to a thin stub (the overlay covers it).
- `resources/views/components/⚡topbar.blade.php` — search `<input>` becomes Alpine-owned (`topbarSearch()` x-data, `x-model="term"`, debounced `$dispatch('plextune-search', {term})`, clear `×` button); removes `#[Url] query` + `updatedQuery`.
- `app/Support/AppSetting.php` — four per-section compact accessors (`artistsCompact`/`albumsCompact`/`libraryTracksCompact`/`playlistTracksCompact` + setters), each defaulting to `density() === 'compact'` when unset.
- `resources/views/pages/⚡library.blade.php` — `artistsCompact`/`albumsCompact`/`tracksCompact` props + `updated*` hooks + wired toggle buttons; comfortable/compact branches for the artist rows, album rows, and album tracklist.
- `resources/views/pages/⚡playlist-detail.blade.php` — `tracksCompact` prop + `updatedTracksCompact` hook + a compact-toggle button group in the tracklist header; comfortable/compact branch for the playlist tracklist (`PL_COLS_COMPACT = '20px 1.4fr 1fr 1fr 50px'`).
- `resources/views/pages/⚡settings.blade.php` — relabel the Density section to "Density default" + help text.
- `resources/css/app.css` — remove the `[data-density="compact"] ...` block (superseded).
- `resources/views/components/⚡player.blade.php` — `audioPlayer()`: Media Session metadata in `loadAndPlay`, action handlers in `init`, `playbackState` sync; (no behavior change to existing shortcuts).
- `resources/views/partials/playlist-row.blade.php`, `resources/views/pages/⚡library.blade.php`, `resources/views/pages/⚡playlist-detail.blade.php` — source-indicator `.eq` → `<x-lucide-volume-1 ... text-accent>` (sidebar row + album/playlist headers, expanded + collapsed).
- Tests: `tests/Feature/SearchViewTest.php` (delete or repurpose), `tests/Feature/TopbarTest.php`, `tests/Feature/RoutesTest.php`, `tests/Feature/AppSettingTest.php`, `tests/Feature/LibraryViewTest.php`, `tests/Feature/PlaylistDetailViewTest.php`, `tests/Feature/SettingsViewTest.php`, `tests/Browser/SearchTest.php`, `tests/Browser/PlayerQueueTest.php`, plus optionally a new `tests/Browser/CompactTest.php`.

---

## Task 1: New `search` Livewire component (logic + template, not yet wired into the layout)

Create the component that will become the search-results overlay. It's not referenced anywhere yet (Task 2 wires it in), so the suite stays green: it's tested in isolation.

**Files:**
- Create: `resources/views/components/⚡search.blade.php`
- Create: `tests/Feature/SearchComponentTest.php`

- [ ] **Step 1: Scaffold and write the component**

Run `php artisan make:livewire search --no-interaction` — confirm it created `resources/views/components/⚡search.blade.php`. Replace its contents with:

```php
<?php

use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** all | playlists | albums | artists | tracks */
    public string $filter = 'all';

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    #[On('plextune-search')]
    public function applyTerm(string $term): void
    {
        $term = trim($term);

        if ($term === $this->q) {
            return;
        }

        $this->q = $term;
        $this->filter = 'all';
        unset($this->results);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function showSection(string $key): bool
    {
        return $this->filter === 'all' || $this->filter === $key;
    }

    public function playTrack(string $trackId): void
    {
        $tracks = ($this->results?->tracks ?? collect())->values();
        $i = $tracks->search(fn ($t) => $t->id === $trackId);

        if ($i === false) {
            return;
        }

        $this->dispatch('play-track',
            queue: $tracks->map(fn ($t) => $this->plex->queueItem($t))->values()->all(),
            index: $i,
            contextType: 'search',
            contextId: null,
        );
    }

    public function goToArtist(string $artistId): void
    {
        $this->redirectRoute('library', ['artist' => $artistId], navigate: true);
    }

    public function goToAlbum(?string $artistId, string $albumId): void
    {
        $this->redirectRoute('library', array_filter([
            'artist' => $artistId,
            'album' => $albumId,
        ]), navigate: true);
    }

    #[Computed]
    public function results(): ?SearchResults
    {
        if (trim($this->q) === '') {
            return null;
        }

        try {
            return $this->plex->searchAll($this->q);
        } catch (PlexException $e) {
            $this->dispatch('notify', type: 'error', message: 'Search failed. '.$e->getMessage());

            return null;
        }
    }

    protected function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }
};
?>

<div>
    @if (trim($q) !== '')
        <div class="absolute inset-0 z-30 bg-base overflow-y-auto" data-region="search-overlay">
            {{-- Port the body of design/rendered/new-search.html here: the sticky filter-tab pills row
                 and the grouped result lists (Playlists / Albums / Artists / Tracks), plus the
                 "Search your library" / "No results" states. Reuse the exact markup that
                 resources/views/pages/⚡search.blade.php currently has for the `@else` (results) branch
                 — the filter pills `@php($pills = [...])` loop, the four `<section>`s, the Songs-group
                 playing-row highlight (the `x-data="{ get playing() ... contextType === 'search' ... }"`
                 pattern), the no-results heading using &quot; — but DROP the `errorMessage` red-panel
                 branch (errors now go to a toast) and DROP the `@elseif (trim($q) === '')` empty-state
                 branch (the overlay only renders when `$q !== ''`, so that state is unreachable here).
                 Resolve the computed up front with @php($this->results) at the top so a Plex failure's
                 toast dispatch happens during render. --}}
            @php($this->results)
            @if ($this->results === null)
                {{-- The toast already fired in results(); show a minimal inline note too. --}}
                <div class="pt-16 text-center">
                    <div class="text-[20px] font-bold text-white mb-2">Couldn't search right now</div>
                    <div class="text-text-2 text-[14px]">Your Plex server may be unreachable.</div>
                </div>
            @elseif ($this->results->isEmpty())
                <div class="pt-16 text-center px-6">
                    <div class="text-[20px] font-bold text-white mb-2">No results found for &quot;{{ $q }}&quot;</div>
                    <div class="text-text-2 text-[14px]">Please make sure your words are spelled correctly, or use fewer or different keywords.</div>
                </div>
            @else
                {{-- ...filter pills + grouped sections, ported from the current pages::search results branch... --}}
            @endif
        </div>
    @endif
</div>
```

**Important for the implementer:** open the *current* `resources/views/pages/⚡search.blade.php` and `design/rendered/new-search.html`, and move the entire results-branch markup (the sticky `<div class="sticky top-0 z-10 bg-base/95 ...">` filter pills block, the `@if ($this->results->isEmpty())` no-results block, and the `@else` block with the four `<section>`s including the Songs-group `x-data` playing-row highlight from M7) into the `@else` branch above. The `wire:click` handlers (`setFilter`, `goToAlbum`, `goToArtist`, `playTrack`) and `$this->thumbFor(...)` calls all work the same since this component defines those methods. The component's root `<div>` is always the same element; the `@if (trim($q) !== '')` is inside it.

- [ ] **Step 2: Write the failing feature test**

Create `tests/Feature/SearchComponentTest.php`:

```php
<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

function fakeSearchResults(): SearchResults
{
    return new SearchResults(
        collect([Track::fromPlex(['ratingKey' => 'tr1', 'title' => 'Song One', 'grandparentTitle' => 'Artist X', 'parentTitle' => 'Album Y', 'duration' => 200000, 'Media' => [['Part' => [['id' => 1, 'container' => 'mp3']]]]])]),
        collect([Album::fromPlex(['ratingKey' => 'al1', 'title' => 'Album Y', 'parentTitle' => 'Artist X', 'parentRatingKey' => 'ar1', 'year' => 2020])]),
        collect([Artist::fromPlex(['ratingKey' => 'ar1', 'title' => 'Artist X', 'childCount' => 3])]),
        collect([Playlist::fromPlex(['ratingKey' => 'pl1', 'title' => 'Mix', 'leafCount' => 5, 'smart' => false])]),
    );
}

beforeEach(function () {
    $this->mock(PlexClient::class, function ($m) {
        $m->shouldReceive('searchAll')->andReturn(fakeSearchResults())->byDefault();
        $m->shouldReceive('thumbUrl')->andReturn(null)->byDefault();
        $m->shouldReceive('queueItem')->andReturnUsing(fn ($t) => ['id' => $t->id, 'url' => 'u', 'title' => $t->title, 'artist' => $t->artist, 'artwork' => null, 'albumId' => $t->albumId, 'artistId' => $t->artistId])->byDefault();
    });
});

it('renders nothing when there is no query', function () {
    Livewire::test('search')
        ->assertDontSee('No results found')
        ->assertDontSee('Song One');
});

it('renders results for a query from the URL', function () {
    Livewire::withQueryParams(['q' => 'song'])
        ->test('search')
        ->assertSee('Song One')
        ->assertSee('Album Y');
});

it('updates the query and re-runs the search when it receives the plextune-search event', function () {
    Livewire::test('search')
        ->dispatch('plextune-search', term: 'song')
        ->assertSet('q', 'song')
        ->assertSee('Song One');
});

it('switches result filter', function () {
    Livewire::withQueryParams(['q' => 'song'])
        ->test('search')
        ->call('setFilter', 'albums')
        ->assertSet('filter', 'albums');
});

it('dispatches play-track with the search context when a track is played', function () {
    Livewire::withQueryParams(['q' => 'song'])
        ->test('search')
        ->call('playTrack', 'tr1')
        ->assertDispatched('play-track', contextType: 'search', contextId: null);
});

it('toasts and shows the failure note when Plex search throws', function () {
    $this->mock(PlexClient::class, function ($m) {
        $m->shouldReceive('searchAll')->andThrow(new \App\Services\Plex\Exceptions\PlexUnreachableException('down'));
        $m->shouldReceive('thumbUrl')->andReturn(null);
    });

    Livewire::withQueryParams(['q' => 'song'])
        ->test('search')
        ->assertDispatched('notify', type: 'error')
        ->assertSee("Couldn't search right now");
});
```

(Adjust the `Track::fromPlex`/`Album::fromPlex`/etc. payload keys to match the actual DTO `fromPlex` factories — check `app/Services/Plex/Dto/*.php`; the keys above are best-effort. If the existing `tests/Feature/SearchViewTest.php` has helper builders for these DTOs, reuse them.)

- [ ] **Step 3: Run to verify**

Run: `php artisan test --compact --filter=SearchComponentTest`
Expected: PASS (the component exists, renders, the event/filter/play/error paths work). If the results-branch markup port is incomplete, the `assertSee('Song One')`/`assertSee('Album Y')` will fail — finish the port until green.

- [ ] **Step 4: Run the whole suite to confirm nothing else broke**

Run: `php artisan test --compact`
Expected: still green (the new component isn't wired anywhere yet; `pages::search` still works as before).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/components/⚡search.blade.php" tests/Feature/SearchComponentTest.php
git commit -m "feat(search): standalone search component (overlay; not wired yet)"
```

---

## Task 2: Wire the search overlay into the layout; gut `pages::search` to a stub

**Files:**
- Modify: `resources/views/components/layouts/app.blade.php`
- Modify: `resources/views/pages/⚡search.blade.php`
- Modify: `tests/Feature/RoutesTest.php`
- Delete: `tests/Feature/SearchViewTest.php` (its coverage moved to `SearchComponentTest.php`)

- [ ] **Step 1: Update `RoutesTest` for the new world**

Open `tests/Feature/RoutesTest.php`. The `/search` route test currently probably hits `/search?q=...` and asserts on `pages::search`'s rendered results. Change it to: `/search` (no query) returns 200 and renders the layout (the search component renders its empty root, the page stub renders); `/search?q=foo` returns 200 — and since the layout now renders `<livewire:search />` which calls `PlexClient::searchAll('foo')` when `?q=` is set, the test's `PlexClient` mock needs `->shouldReceive('searchAll')->andReturn(...)` (return an empty `SearchResults` or a fake one) **and** `->shouldReceive('thumbUrl')->andReturn(null)` (the search overlay calls `thumbFor`). Also keep the existing `scrobbleUrl` / `ping` stubs the route tests already have. If `RoutesTest` has a shared `PlexClient` mock helper, add `searchAll` there.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=RoutesTest`
Expected: FAIL (`<livewire:search />` isn't in the layout yet, OR the mock isn't set up — depends; the point is the test now expects the new structure).

- [ ] **Step 3: Add the search overlay to the layout**

In `resources/views/components/layouts/app.blade.php`, change the `<main>` block from:

```html
            <main class="flex-1 min-w-0 bg-surface rounded-lg flex flex-col overflow-hidden">
                <livewire:topbar />
                <div class="flex-1 min-h-0 overflow-hidden">
                    {{ $slot }}
                </div>
            </main>
```

to:

```html
            <main class="flex-1 min-w-0 bg-surface rounded-lg flex flex-col overflow-hidden">
                <livewire:topbar />
                <div class="flex-1 min-h-0 overflow-hidden relative">
                    {{ $slot }}
                    <livewire:search />
                </div>
            </main>
```

- [ ] **Step 4: Gut `pages::search` to a stub**

Replace `resources/views/pages/⚡search.blade.php` entirely with:

```php
<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    //
};
?>

<div class="h-full grid place-items-center text-center px-6">
    <div>
        <div class="text-[20px] font-bold text-white mb-1">Search your library</div>
        <div class="text-text-2 text-[14px]">Find any song, artist, or album from your Plex server.</div>
    </div>
</div>
```

(When `?q=` is set the `<livewire:search />` overlay covers this stub; when it's not, the user sees this hint. The `//` empty class body is allowed for a stub page component — no `__construct`.)

- [ ] **Step 5: Delete the superseded feature test**

```bash
git rm tests/Feature/SearchViewTest.php
```

(Its assertions are now covered by `tests/Feature/SearchComponentTest.php`. If `SearchViewTest.php` has unique DTO-builder helpers worth keeping, copy them into `SearchComponentTest.php` first.)

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact --filter='RoutesTest|SearchComponentTest'` then `php artisan test --compact`
Expected: PASS. (`phpunit.xml` / `tests/Pest.php` reference the test suites by directory, not by file, so deleting `SearchViewTest.php` is fine.)

- [ ] **Step 7: Build + commit**

```bash
npm run build
vendor/bin/pint --dirty --format agent
git add resources/views/components/layouts/app.blade.php "resources/views/pages/⚡search.blade.php" tests/Feature
git commit -m "feat(search): render the search overlay in the layout; /search page becomes a stub"
```

---

## Task 3: Rework the topbar — Alpine-owned search input, no navigation

**Files:**
- Modify: `resources/views/components/⚡topbar.blade.php`
- Modify: `tests/Feature/TopbarTest.php`
- Modify: `tests/Browser/SearchTest.php`

- [ ] **Step 1: Update `TopbarTest`**

Open `tests/Feature/TopbarTest.php`. It currently asserts the topbar has a `query` property bound with `#[Url(as: 'q', except: '')]` and that `updatedQuery` / setting `query` triggers a `redirectRoute('search', ...)` navigate. Replace those assertions: the topbar no longer has a `query` property and no longer redirects. Keep/add: the topbar renders the search `<input id="topbar-search">`, the settings link, and the back/forward buttons. If a test asserted the navigate behavior, delete it (that behavior is gone — search is event-driven now). Add a simple assertion that the topbar component still renders without error (`Livewire::test('topbar')->assertOk()` — note: the topbar is rendered standalone in `Livewire::test`, no layout, so no `PlexClient` needed... but check whether the topbar's `render()` touches anything; the new topbar shouldn't).

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=TopbarTest`
Expected: FAIL (asserts old behavior).

- [ ] **Step 3: Rework the topbar template + class**

Replace `resources/views/components/⚡topbar.blade.php` with:

```php
<?php

use Livewire\Component;

new class extends Component {
    //
};
?>

<div class="flex items-center gap-4 px-6 h-16 flex-none">
    <div class="flex gap-2">
        <button type="button" onclick="history.back()"
                class="w-8 h-8 rounded-full bg-black/60 hover:bg-black grid place-items-center text-white">
            <x-lucide-chevron-left class="w-4 h-4" />
        </button>
        <button type="button" onclick="history.forward()"
                class="w-8 h-8 rounded-full bg-black/60 hover:bg-black grid place-items-center text-text-3">
            <x-lucide-chevron-right class="w-4 h-4" />
        </button>
    </div>

    <div class="flex-1 max-w-[760px]"
         x-data="topbarSearch(@js(request()->query('q', '')))">
        <div class="flex items-center gap-3 bg-surface-1 hover:bg-surface-2 transition-colors rounded-full h-11 px-4 ring-1 ring-transparent focus-within:ring-white/30">
            <x-lucide-search class="w-[18px] h-[18px] text-white" />
            <input type="text"
                   id="topbar-search"
                   x-ref="searchInput"
                   x-model="term"
                   x-on:input.debounce.300ms="$dispatch('plextune-search', { term })"
                   placeholder="What do you want to play?"
                   class="bg-transparent outline-none text-[14px] flex-1 placeholder:text-text-2">
            <button type="button" x-show="term !== ''" x-cloak
                    @click="term = ''; $refs.searchInput.focus(); $dispatch('plextune-search', { term: '' })"
                    class="text-text-3 hover:text-white flex-none">
                <x-lucide-x class="w-4 h-4" />
            </button>
            <span x-show="term === ''" class="text-[11px] text-text-3 border border-text-3/40 rounded px-1.5 py-0.5">&#8984;K</span>
        </div>
    </div>

    <div class="flex-1"></div>

    <a href="{{ route('settings') }}" wire:navigate
       title="Settings"
       class="w-9 h-9 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 grid place-items-center text-white text-[13px] font-bold hover:ring-2 hover:ring-white/40 transition-all">P</a>
</div>

@once
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('topbarSearch', (initialTerm) => ({
            term: initialTerm || '',
        }));
    });
</script>
@endonce
```

(`@js(request()->query('q', ''))` renders the current `?q=` value as a JS string literal — so on a direct visit to `/search?q=foo`, the input shows `foo`. `@once` ensures the `alpine:init` registration is emitted once even though the topbar renders on every page. `x-cloak` on the clear button prevents a flash; `[x-cloak]` is already globally hidden in `app.css`.)

- [ ] **Step 4: Run the feature test**

Run: `php artisan test --compact --filter=TopbarTest`
Expected: PASS. Also run `php artisan test --compact` — expected green (no other component referenced the topbar's old `query` prop... but double-check `RoutesTest`/`SearchComponentTest` don't; if anything referenced `topbar` `query`, fix it).

- [ ] **Step 5: Update the search browser test**

Open `tests/Browser/SearchTest.php`. Replace the navigate-based assertions with overlay-based ones. New tests (mirror existing browser-test setup; `uses(RefreshDatabase::class)` since the sidebar renders):

```php
it('shows search results as an overlay without navigating away, keeping focus', function () {
    $page = visit('/');
    $page->script("document.getElementById('topbar-search').focus()");
    // type a query; use whatever the plugin's typing API is, or set value + dispatch input:
    $page->script("const i = document.getElementById('topbar-search'); i.value = 'the'; i.dispatchEvent(new Event('input', { bubbles: true }));");
    // wait for the overlay to appear (the search component round-trip), then assert:
    // 1) the overlay rendered
    // 2) the URL still ends with / (we did NOT navigate to /search) OR has ?q=the appended without a page change
    // 3) the topbar input still has focus and still reads 'the'
    $page->assertPresent('[data-region="search-overlay"]');
    $page->assertScript("document.activeElement.id", 'topbar-search');
    $page->assertScript("document.getElementById('topbar-search').value", 'the');
});

it('clears the overlay with the clear button', function () {
    $page = visit('/?q=the')  // or visit('/') then type — pick what works; if /?q=the doesn't trigger search, use /search?q=the
        ->assertPresent('[data-region="search-overlay"]');
    // click the clear button (the x-show'd × inside the topbar search box)
    $page->script("[...document.querySelectorAll('#topbar-search ~ button, button')].find(b => b.querySelector('svg') && b.closest('[x-data*=topbarSearch]'))?.click()");
    // simpler: give the clear button a data attr in step 3 above (e.g. data-region="search-clear") and click that
    $page->assertScript("document.querySelectorAll('[data-region=\"search-overlay\"]').length", 0);
});

it('opens the overlay on a direct visit to /search?q=', function () {
    visit('/search?q=the')->assertPresent('[data-region="search-overlay"]');
});
```

For the clear-button test to be reliable, in Step 3 add `data-region="search-clear"` to the clear `<button>` in the topbar template, then in the test `script("document.querySelector('[data-region=search-clear]').click()")` and assert the overlay count is 0 and `document.getElementById('topbar-search').value === ''`. Also keep an adapted version of the M7 regression test "typing a second char after a pause keeps both chars" — now there's no navigation so it's trivially true, but assert it: type 'a', wait 400ms, type 'b', assert the input value is 'ab' and `document.activeElement.id === 'topbar-search'`.

- [ ] **Step 6: Build + run browser tests**

Run: `npm run build`
Run: `php artisan test --compact --filter='SearchTest|PlayerQueueTest'`
Expected: PASS (live Plex up). If `SearchTest` can't run because Plex is down, commit anyway and note it.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/components/⚡topbar.blade.php" tests/Feature/TopbarTest.php tests/Browser/SearchTest.php
git commit -m "feat(search): Alpine-owned topbar input dispatching plextune-search; no navigation"
```

---

## Task 4: `AppSetting` — four per-section compact accessors

**Files:**
- Modify: `app/Support/AppSetting.php`
- Modify: `tests/Unit/AppSettingTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/Unit/AppSettingTest.php`:

```php
it('per-section compact flags default to the density default when unset', function () {
    AppSetting::setDensity('comfortable');
    expect(AppSetting::artistsCompact())->toBeFalse();
    expect(AppSetting::albumsCompact())->toBeFalse();
    expect(AppSetting::libraryTracksCompact())->toBeFalse();
    expect(AppSetting::playlistTracksCompact())->toBeFalse();

    AppSetting::setDensity('compact');
    expect(AppSetting::artistsCompact())->toBeTrue();
    expect(AppSetting::albumsCompact())->toBeTrue();
    expect(AppSetting::libraryTracksCompact())->toBeTrue();
    expect(AppSetting::playlistTracksCompact())->toBeTrue();
});

it('an explicitly-set per-section compact flag overrides the density default', function () {
    AppSetting::setDensity('comfortable');
    AppSetting::setArtistsCompact(true);
    expect(AppSetting::artistsCompact())->toBeTrue();

    // changing the default does not move an explicitly-set section
    AppSetting::setDensity('compact');
    AppSetting::setArtistsCompact(false);
    expect(AppSetting::artistsCompact())->toBeFalse();
});

it('round-trips each per-section compact flag', function () {
    foreach (['ArtistsCompact', 'AlbumsCompact', 'LibraryTracksCompact', 'PlaylistTracksCompact'] as $name) {
        $get = lcfirst($name);
        $set = 'set'.$name;
        AppSetting::$set(true);
        expect(AppSetting::$get())->toBeTrue();
        AppSetting::$set(false);
        expect(AppSetting::$get())->toBeFalse();
    }
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=AppSettingTest`
Expected: FAIL — undefined methods.

- [ ] **Step 3: Implement**

In `app/Support/AppSetting.php`, add (after the existing accessors, before the closing brace):

```php
    public static function artistsCompact(): bool
    {
        return self::sectionCompact('artists_compact');
    }

    public static function setArtistsCompact(bool $value): void
    {
        Setting::set('artists_compact', $value);
    }

    public static function albumsCompact(): bool
    {
        return self::sectionCompact('albums_compact');
    }

    public static function setAlbumsCompact(bool $value): void
    {
        Setting::set('albums_compact', $value);
    }

    public static function libraryTracksCompact(): bool
    {
        return self::sectionCompact('library_tracks_compact');
    }

    public static function setLibraryTracksCompact(bool $value): void
    {
        Setting::set('library_tracks_compact', $value);
    }

    public static function playlistTracksCompact(): bool
    {
        return self::sectionCompact('playlist_tracks_compact');
    }

    public static function setPlaylistTracksCompact(bool $value): void
    {
        Setting::set('playlist_tracks_compact', $value);
    }

    private static function sectionCompact(string $key): bool
    {
        $value = Setting::get($key);

        if ($value === null) {
            return self::density() === self::DENSITY_COMPACT;
        }

        return (bool) $value;
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact --filter=AppSettingTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/AppSetting.php tests/Unit/AppSettingTest.php
git commit -m "feat(settings): per-section compact accessors defaulting to the density default"
```

---

## Task 5: Library — per-section compact for the artists and albums miller columns

Wire the existing placeholder grid-2x2 / 3-line buttons in the Artists and Albums column headers to `artistsCompact` / `albumsCompact` props, and branch the row markup comfortable vs compact (compact = no thumbnail/metadata, `px-3 py-[3px] text-[13px]`, selected = `bg-accent/15 text-accent`). Tracklist compact is Task 6.

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php`
- Modify: `tests/Feature/LibraryViewTest.php`
- Reference: `design/rendered/compact.html`, `design/plextune-parts.jsx` (`MillerCol`)

- [ ] **Step 1: Add failing feature tests**

In `tests/Feature/LibraryViewTest.php`, add (mirror the file's existing `PlexClient` mock setup which already stubs `artists`/`albumsForArtist`/`tracksForAlbum`/`thumbUrl`/`ping`/`scrobbleUrl`):

```php
it('renders the compact artist row layout when artistsCompact is on', function () {
    AppSetting::setArtistsCompact(true);
    // mock returns >=1 artist
    Livewire::test('pages::library')
        ->assertSet('artistsCompact', true)
        ->assertSeeHtml('py-[3px]');   // compact row padding marker
});

it('persists the artists compact toggle', function () {
    Livewire::test('pages::library')->set('artistsCompact', true);
    expect(AppSetting::artistsCompact())->toBeTrue();
});

it('persists the albums compact toggle', function () {
    Livewire::test('pages::library')->set('albumsCompact', true);
    expect(AppSetting::albumsCompact())->toBeTrue();
});
```

(Ensure `use App\Support\AppSetting;` and `uses(RefreshDatabase::class)` are present in the file — they should be from M7.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=LibraryViewTest`
Expected: FAIL.

- [ ] **Step 3: Add the props + hooks to the component class**

In `resources/views/pages/⚡library.blade.php`, add to the component class:

```php
    public bool $artistsCompact = false;

    public bool $albumsCompact = false;
```

In `mount(PlexClient $plex)` add:

```php
        $this->artistsCompact = \App\Support\AppSetting::artistsCompact();
        $this->albumsCompact = \App\Support\AppSetting::albumsCompact();
```

(`mount` already takes `PlexClient $plex` and calls `loadArtists($plex)` plus, from M7, `headerCollapsed = AppSetting::albumHeaderCollapsed()`. Add these two lines alongside.)

Add hooks:

```php
    public function updatedArtistsCompact(bool $value): void
    {
        \App\Support\AppSetting::setArtistsCompact($value);
    }

    public function updatedAlbumsCompact(bool $value): void
    {
        \App\Support\AppSetting::setAlbumsCompact($value);
    }
```

- [ ] **Step 4: Wire the Artists column toggle buttons + branch the row markup**

In the Artists column header, the placeholder buttons currently look like:

```blade
                        <div class="flex items-center bg-surface-2 rounded p-0.5">
                            <button type="button" title="Comfortable" class="w-6 h-6 grid place-items-center rounded bg-surface-4 text-white">
                                <x-lucide-grid-2x2 class="w-[11px] h-[11px]" />
                            </button>
                            <button type="button" title="Compact list" class="w-6 h-6 grid place-items-center rounded text-text-2 hover:text-white">
                                <x-lucide-menu class="w-[11px] h-[11px]" />
                            </button>
                        </div>
```

Change them to:

```blade
                        <div class="flex items-center bg-surface-2 rounded p-0.5">
                            <button type="button" title="Comfortable" wire:click="$set('artistsCompact', false)"
                                    @class(['w-6 h-6 grid place-items-center rounded', 'bg-surface-4 text-white' => ! $artistsCompact, 'text-text-2 hover:text-white' => $artistsCompact])>
                                <x-lucide-grid-2x2 class="w-[11px] h-[11px]" />
                            </button>
                            <button type="button" title="Compact list" wire:click="$set('artistsCompact', true)"
                                    @class(['w-6 h-6 grid place-items-center rounded', 'bg-surface-4 text-white' => $artistsCompact, 'text-text-2 hover:text-white' => ! $artistsCompact])>
                                <x-lucide-menu class="w-[11px] h-[11px]" />
                            </button>
                        </div>
```

Then in the artists list `@forelse ($this->artists as $artist) ... @empty ... @endforelse`, branch the `<button>` row on `$artistsCompact`:

```blade
                    @forelse ($this->artists as $artist)
                        @if ($artistsCompact)
                            <button type="button" wire:key="artist-{{ $artist->id }}" wire:click="selectArtist('{{ $artist->id }}')"
                                    @class([
                                        'w-full flex items-center gap-3 px-3 py-[3px] text-[13px] text-left transition-colors',
                                        'bg-accent/15 text-accent' => $selectedArtistId === $artist->id,
                                        'text-text-2 hover:text-white hover:bg-surface-2' => $selectedArtistId !== $artist->id,
                                    ])>
                                <span class="flex-1 min-w-0 truncate">{{ $artist->name }}</span>
                            </button>
                        @else
                            {{-- ...existing comfortable artist <button> markup (thumb/placeholder + name)... --}}
                        @endif
                    @empty
                        <div class="grid place-items-center h-full text-text-3 text-[12px] px-4 text-center">No music found in your Plex library.</div>
                    @endforelse
```

Keep the existing comfortable markup verbatim as the `@else` body.

- [ ] **Step 5: Do the same for the Albums column**

Wire its placeholder buttons to `$set('albumsCompact', false|true)` with the `@class` active-state, and branch the album `<button>` row inside `@foreach ($this->albums as $album)` on `$albumsCompact`: compact = `px-3 py-[3px] text-[13px]`, selected = `bg-accent/15 text-accent`, body = just `<span class="flex-1 min-w-0 truncate">{{ $album->title }}</span>` (no thumbnail, no year sub-line); comfortable = the existing markup. (The albums column's `@if (! $selectedArtistId) ... @elseif ($this->albums->isEmpty()) ... @else @foreach ... @endif` structure stays; only the `<button>` inside the `@foreach` branches on compact.)

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact --filter=LibraryViewTest`
Expected: PASS. Then `php artisan test --compact` — green.

- [ ] **Step 7: Build + commit**

```bash
npm run build
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/⚡library.blade.php" tests/Feature/LibraryViewTest.php
git commit -m "feat(compact): per-section compact for the artists & albums miller columns"
```

---

## Task 6: Library album tracklist — compact layout

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php`
- Modify: `tests/Feature/LibraryViewTest.php`
- Reference: `design/rendered/compact.html` (the `grid-template-columns: 20px 1.4fr 1fr 50px` tracklist), `design/plextune-views.jsx`

- [ ] **Step 1: Add a failing feature test**

In `tests/Feature/LibraryViewTest.php`, add (the file's mock already returns an album + tracks when an album is selected; mirror that):

```php
it('renders the compact tracklist layout when tracksCompact is on', function () {
    AppSetting::setLibraryTracksCompact(true);
    Livewire::withQueryParams(['artist' => '<artistId used in the mock>', 'album' => '<albumId used in the mock>'])
        ->test('pages::library')
        ->assertSet('tracksCompact', true)
        ->assertSeeHtml('grid-template-columns: 20px 1.4fr 1fr 50px');
});

it('persists the library tracklist compact toggle', function () {
    Livewire::withQueryParams(['artist' => '<artistId>', 'album' => '<albumId>'])
        ->test('pages::library')->set('tracksCompact', true);
    expect(AppSetting::libraryTracksCompact())->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=LibraryViewTest`
Expected: FAIL.

- [ ] **Step 3: Add the prop + hook**

In the component class: `public bool $tracksCompact = false;`. In `mount()`: `$this->tracksCompact = \App\Support\AppSetting::libraryTracksCompact();`. Add:

```php
    public function updatedTracksCompact(bool $value): void
    {
        \App\Support\AppSetting::setLibraryTracksCompact($value);
    }
```

- [ ] **Step 4: Wire the tracklist toggle buttons + branch the layout**

The album tracklist currently has a placeholder button group `<div class="absolute right-2 top-1.5 z-20 flex items-center bg-surface-2 rounded p-0.5"><button title="Comfortable" ...><x-lucide-grid-2x2 /></button><button title="Compact list" ...><x-lucide-menu /></button></div>`. Wire them to `$set('tracksCompact', false|true)` with the `@class` active-state pattern (same as Task 5 Step 4).

Then branch the tracklist on `$tracksCompact`:
- **Header row:** comfortable = the existing `<div class="grid items-center px-4 py-2 ..." style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">...6 spans...</div>`. Compact = `<div class="grid items-center px-4 py-1.5 text-[11px] uppercase tracking-wider text-text-2 border-b border-white/10 sticky top-0 bg-surface z-10 pr-20" style="grid-template-columns: 20px 1.4fr 1fr 50px;"><span class="text-right pr-1">#</span><span>Title</span><span>Artist</span><span class="text-right"><x-lucide-clock class="w-[14px] h-[14px] inline" /></span></div>` (ported from `compact.html`).
- **Each track `<button>`:** comfortable = the existing 6-column markup (with cover, like ♥, the `x-data` playing getter and `.eq`). Compact = `<button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')" draggable="true" ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')" class="row group w-full grid items-center px-4 py-[3px] rounded text-[13px] text-left hover:bg-white/[0.07] transition-colors" style="grid-template-columns: 20px 1.4fr 1fr 50px;">` containing four cells:
  1. `#` cell with the playing highlight (reuse the M7 pattern): `<span class="tabular-nums text-text-2 text-right pr-1" x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}'; } }"><template x-if="playing"><span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span></template><template x-if="!playing"><span>{{ $track->trackNumber }}</span></template></span>`
  2. title: `<div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>`
  3. artist: `<div class="text-text-2 group-hover:text-white truncate">{{ $track->artist }}</div>`
  4. time: `<div class="text-text-2 tabular-nums text-right text-[12px]">{{ \App\Support\Duration::format($track->durationMs) }}</div>`

  No cover, no album column, no like column. The outer `<div class="overflow-y-auto scroll flex-1 py-1" x-data="{}">` wrapper stays (already there).

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter=LibraryViewTest`
Expected: PASS. Then `php artisan test --compact` — green.

- [ ] **Step 6: Build + commit**

```bash
npm run build
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/⚡library.blade.php" tests/Feature/LibraryViewTest.php
git commit -m "feat(compact): compact album tracklist layout"
```

---

## Task 7: Playlist-detail tracklist — compact toggle + layout

**Files:**
- Modify: `resources/views/pages/⚡playlist-detail.blade.php`
- Modify: `tests/Feature/PlaylistDetailViewTest.php`
- Reference: `design/plextune-views.jsx` (`PL_COLS_COMPACT = '20px 1.4fr 1fr 1fr 50px'`, `PlaylistTrackRow`/`PlaylistTracklist`), `design/rendered/compact.html`

- [ ] **Step 1: Add failing feature tests**

In `tests/Feature/PlaylistDetailViewTest.php` (mirror its mock setup — `playlists()` returns a playlist, `playlistTracks()` returns >=1 track, `thumbUrl`/`ping`/`scrobbleUrl` stubbed):

```php
it('renders the compact playlist tracklist when tracksCompact is on', function () {
    AppSetting::setPlaylistTracksCompact(true);
    Livewire::test('pages::playlist-detail', ['playlist' => '<id used in mock>'])
        ->assertSet('tracksCompact', true)
        ->assertSeeHtml('grid-template-columns: 20px 1.4fr 1fr 1fr 50px');
});

it('persists the playlist tracklist compact toggle', function () {
    Livewire::test('pages::playlist-detail', ['playlist' => '<id>'])->set('tracksCompact', true);
    expect(AppSetting::playlistTracksCompact())->toBeTrue();
});
```

(Ensure `use App\Support\AppSetting;` + `uses(RefreshDatabase::class)` are present — they are from M7.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=PlaylistDetailViewTest`
Expected: FAIL.

- [ ] **Step 3: Add the prop + hook**

In the component class: `public bool $tracksCompact = false;`. In `mount(string $playlist)`: `$this->tracksCompact = \App\Support\AppSetting::playlistTracksCompact();` (alongside the M7 `headerCollapsed` init). Add:

```php
    public function updatedTracksCompact(bool $value): void
    {
        \App\Support\AppSetting::setPlaylistTracksCompact($value);
    }
```

- [ ] **Step 4: Add a compact-toggle button group to the tracklist header + branch the layout**

The playlist-detail tracklist header currently is `<div class="grid items-center px-4 py-2 text-[11px] uppercase tracking-wider text-text-2 border-b border-white/10 sticky top-0 bg-surface z-10" style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">...6 spans...</div>` inside `<div class="flex-1 min-h-0 flex flex-col relative bg-surface rounded-lg overflow-hidden">`. Add a button group like the library tracklist's, positioned the same way: `<div class="absolute right-2 top-1.5 z-20 flex items-center bg-surface-2 rounded p-0.5"><button type="button" title="Comfortable" wire:click="$set('tracksCompact', false)" @class([...active...])><x-lucide-grid-2x2 class="w-[11px] h-[11px]" /></button><button type="button" title="Compact list" wire:click="$set('tracksCompact', true)" @class([...active...])><x-lucide-menu class="w-[11px] h-[11px]" /></button></div>` — and add `pr-20` to the header `<div>` (like the library one) so the buttons don't overlap the columns.

Branch the tracklist on `$tracksCompact`:
- **Header row:** comfortable = existing 6-column. Compact = `<div class="grid items-center px-4 py-1.5 text-[11px] uppercase tracking-wider text-text-2 border-b border-white/10 sticky top-0 bg-surface z-10 pr-20" style="grid-template-columns: 20px 1.4fr 1fr 1fr 50px;"><span class="text-right pr-1">#</span><span>Title</span><span>Artist</span><span>Album</span><span class="text-right"><x-lucide-clock class="w-[14px] h-[14px] inline" /></span></div>`
- **Each track `<button>`:** comfortable = existing 6-column markup. Compact = `class="row group w-full grid items-center px-4 py-[3px] rounded text-[13px] text-left hover:bg-white/[0.07] transition-colors" style="grid-template-columns: 20px 1.4fr 1fr 1fr 50px;"` with five cells:
  1. `#` cell with the playing highlight (M7 pattern, `contextType === 'playlist'`, `contextId === '{{ $this->playlist }}'`): `<span class="tabular-nums text-text-2 text-right pr-1" x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}'; } }"><template x-if="playing"><span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span></template><template x-if="!playing"><span>{{ $i + 1 }}</span></template></span>`
  2. title: `<div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>`
  3. artist: `<div class="text-text-2 group-hover:text-white truncate">{{ $track->artist }}</div>`
  4. album: `<div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>`
  5. time: `<div class="text-text-2 tabular-nums text-right text-[12px]">{{ \App\Support\Duration::format($track->durationMs) }}</div>`

  The `@foreach ($this->tracks as $i => $track)` loop and the `<div class="overflow-y-auto scroll flex-1 py-1" x-data="{}">` wrapper stay.

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter=PlaylistDetailViewTest`
Expected: PASS. Then `php artisan test --compact` — green.

- [ ] **Step 6: Build + commit**

```bash
npm run build
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/⚡playlist-detail.blade.php" tests/Feature/PlaylistDetailViewTest.php
git commit -m "feat(compact): playlist tracklist compact toggle + layout"
```

---

## Task 8: Settings — relabel "Density" to "Density default"; remove the dead `data-density` wiring

**Files:**
- Modify: `resources/views/pages/⚡settings.blade.php`
- Modify: `resources/views/components/layouts/app.blade.php`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/SettingsViewTest.php`

- [ ] **Step 1: Update the feature test**

In `tests/Feature/SettingsViewTest.php`:
- The test that asserts `data-density="compact"` is rendered (added in M7) — change it to assert `data-density` is NOT on the body anymore: e.g. `$this->get('/settings')->assertDontSee('data-density=', false)`. (Or just delete that test.)
- Keep the Density-toggle persistence test (it still calls `AppSetting::setDensity`).
- Add (or update) an assertion that the Display section now says "Density default" — e.g. `Livewire::test('pages::settings')->assertSee('Density default')`.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=SettingsViewTest`
Expected: FAIL.

- [ ] **Step 3: Relabel the Display section**

In `resources/views/pages/⚡settings.blade.php`, in the Display `<section>`, change the row label/help from:

```blade
                    <div class="text-sm font-semibold">Density</div>
                    <div class="text-xs text-text-2 mt-1">Comfortable spaces rows out. Compact fits more on screen.</div>
```

to:

```blade
                    <div class="text-sm font-semibold">Density default</div>
                    <div class="text-xs text-text-2 mt-1">Default for lists you haven't individually adjusted. Each artist, album, and track list has its own Comfortable / Compact toggle in its header.</div>
```

(The Comfortable/Compact buttons and `wire:click="$set('density', ...)"` and `updatedDensity` stay as-is.)

- [ ] **Step 4: Remove the dead `data-density` attribute from the layout**

In `resources/views/components/layouts/app.blade.php`, change `<body class="h-full bg-base text-text-1" data-density="{{ \App\Support\AppSetting::density() }}">` back to `<body class="h-full bg-base text-text-1">`.

- [ ] **Step 5: Remove the dead Compact CSS block**

In `resources/css/app.css`, delete the `/* Compact display density: ... */` block and all `[data-density="compact"] ...` rules (they're superseded by the per-section Blade-conditional markup). Leave the `.eq` styles and everything else.

- [ ] **Step 6: Run tests + build**

Run: `php artisan test --compact --filter='SettingsViewTest|RoutesTest'` then `php artisan test --compact`
Expected: PASS. Run `npm run build`.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/⚡settings.blade.php" resources/views/components/layouts/app.blade.php resources/css/app.css tests/Feature/SettingsViewTest.php
git commit -m "refactor(compact): retire the global data-density wiring; relabel Settings to 'Density default'"
```

---

## Task 9: Media Session API in `audioPlayer()`

**Files:**
- Modify: `resources/views/components/⚡player.blade.php`
- Modify: `tests/Browser/PlayerQueueTest.php` (optional small assertion)

- [ ] **Step 1: Add the Media Session wiring to `audioPlayer()`**

In `resources/views/components/⚡player.blade.php`, in the `@script` block's `audioPlayer()` returned object:

In `init()`, after the existing setup (the `queue-load` listener, the keydown listener, `this.$refs.audio.volume = ...`), add (guard with `__plexifyPlayerInited` is already there for the keydown listener; you can put this inside that same `if (firstInit)` block or guard separately — but Media Session handlers are idempotent to re-register, so it's fine either way):

```js
                if ('mediaSession' in navigator) {
                    navigator.mediaSession.setActionHandler('play', () => { if (this.$refs.audio.paused) this.$refs.audio.play().catch(() => {}); });
                    navigator.mediaSession.setActionHandler('pause', () => this.$refs.audio.pause());
                    navigator.mediaSession.setActionHandler('previoustrack', () => this.previous());
                    navigator.mediaSession.setActionHandler('nexttrack', () => this.next());
                }
```

In `loadAndPlay(i)`, after `Alpine.store('player').currentId = this.queue[i].id;`, add:

```js
                if ('mediaSession' in navigator) {
                    const t = this.queue[i];
                    navigator.mediaSession.metadata = new MediaMetadata({
                        title: t.title || '',
                        artist: t.artist || '',
                        artwork: t.artwork ? [{ src: t.artwork }] : [],
                    });
                }
```

In the `<audio>` `@play` handler, append `; if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'playing'` — and in `@pause`, append `; if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'paused'`. (The `@play` handler is currently `@play="isPlaying = true; consecutiveErrors = 0; $store.player.isPlaying = true"` and `@pause="isPlaying = false; $store.player.isPlaying = false"` — extend them.)

- [ ] **Step 2: Build + (optional) browser assertion**

Run: `npm run build`. Optionally add to `tests/Browser/PlayerQueueTest.php` a tiny check inside an existing "play a track" test: after playing, `$page->assertScript("navigator.mediaSession.metadata?.title ?? ''", '<the expected title>')` — only if it's not flaky; if uncertain, skip and rely on the manual smoke check.

- [ ] **Step 3: Run + commit**

Run: `php artisan test --compact --filter=PlayerQueueTest` (live Plex; if down, note it).
```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/components/⚡player.blade.php" tests/Browser/PlayerQueueTest.php
git commit -m "feat(player): Media Session API (OS media keys + Now Playing widget)"
```

---

## Task 10: Now-playing source indicator — static speaker glyph instead of the animated equalizer

The `.eq` animated equalizer stays in the tracklist `#` columns; only the **source indicators** (sidebar playlist row + album/playlist headers) change to a static accent-green speaker icon.

**Files:**
- Modify: `resources/views/partials/playlist-row.blade.php`
- Modify: `resources/views/pages/⚡library.blade.php`
- Modify: `resources/views/pages/⚡playlist-detail.blade.php`
- Modify: `tests/Browser/PlayerQueueTest.php`

- [ ] **Step 1: Swap the icon in the sidebar playlist row**

In `resources/views/partials/playlist-row.blade.php`, the source-indicator `<template x-if="$store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $p->id }}'">` currently contains `<span class="eq flex-none" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>`. Replace that `<span class="eq ...">...</span>` with:

```blade
                <x-lucide-volume-1 class="w-3.5 h-3.5 text-accent flex-none" data-source-indicator />
```

- [ ] **Step 2: Swap the icon in the library album header (expanded + collapsed variants)**

In `resources/views/pages/⚡library.blade.php`, both the expanded album header and the collapsed 56px row have a `<template x-if="$store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}'">` containing `<span class="eq ...">...</span>`. Replace each `.eq` span with `<x-lucide-volume-1 class="w-4 h-4 text-accent flex-none" data-source-indicator />` (size up slightly for the expanded header — `w-4 h-4` — your call; keep `flex-none` where it's in a flex row, drop `flex-none` and use `inline-flex`/`inline` where it was `inline-flex`). Keep the `<template x-if>` condition unchanged.

- [ ] **Step 3: Swap the icon in the playlist-detail header (expanded + collapsed variants)**

In `resources/views/pages/⚡playlist-detail.blade.php`, same swap: the `<template x-if="$store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}'">` source-indicator `.eq` spans (expanded header + collapsed row) become `<x-lucide-volume-1 class="w-4 h-4 text-accent flex-none" data-source-indicator />`.

**Do NOT touch** the `.eq` spans inside tracklist `#` cells in `⚡library.blade.php`, `⚡playlist-detail.blade.php`, `⚡search.blade.php`, `⚡recently-played.blade.php` — those stay animated. (Tell them apart: the source-indicator `.eq`s are the ones inside a `<template x-if="...contextType...contextId...">` that is NOT inside a track-row `@foreach`; the tracklist ones are inside a per-track `x-data="{ get playing() ... }"` `<template x-if="playing">`.)

- [ ] **Step 4: Update the browser test assertions**

In `tests/Browser/PlayerQueueTest.php`, the source-indicator tests added in M7 assert `.eq` count inside the sidebar row / album header. Change those to assert `[data-source-indicator]` instead (e.g. `document.querySelectorAll('[wire\\:key^="sidebar-pl-"] [data-source-indicator]').length`). The tracklist `.eq` assertions (the playing-row highlight tests) stay as `.eq`. (Note: lucide's `<x-lucide-volume-1>` renders an `<svg>`; adding `data-source-indicator` to the component tag passes it through to the `<svg>` — verify with a quick render; if blade-icons strips unknown attrs, instead wrap the icon in a `<span data-source-indicator>...</span>` and assert on that.)

- [ ] **Step 5: Build + run + commit**

Run: `npm run build` then `php artisan test --compact --filter=PlayerQueueTest` (live Plex; if down, commit anyway and note it).
```bash
vendor/bin/pint --dirty --format agent
git add resources/views tests/Browser/PlayerQueueTest.php
git commit -m "feat(highlight): static speaker glyph for the now-playing source indicator"
```

---

## Task 11: Full suite, manual smoke checkpoint, CLAUDE.md update, finish the branch

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Build + full suite**

Run: `npm run build` then `php artisan test --compact`
Expected: all green (the M7 suite plus this follow-up's new/changed tests; browser tests require live Plex). If Plex is down, run `php artisan test --compact --testsuite=Unit,Feature` and note the Browser suite was skipped.

- [ ] **Step 2: Manual smoke checkpoint with the user**

Present grouped manual checks (~3-4 per set, one set at a time), wait for the user's report, fix anything that comes up (each fix its own commit). Cover:
1. **Search:** From the library, type into the topbar search box — results appear as an overlay over the main pane, no navigation, cursor stays in the box, no characters lost even when typing slowly. The `×` clears the box and the overlay. `⌘K` / `/` focuses the box. Direct visit to `/search?q=foo` shows the overlay with the box pre-filled. Click an album result — navigates to the library album and the overlay closes.
2. **Compact density:** In the library, click the 3-line (compact) button in the Artists column header — the artist list becomes single-line, no thumbnails; the active button highlights. Reload — it stuck. Same for the Albums column and the album tracklist (tracklist compact = `# | Title | Artist | time`, no covers, tighter rows, like the Spotify screenshot). Same on a playlist detail page (compact = `# | Title | Artist | Album | time`). Settings → Display → "Density default" still flips the default for lists you haven't individually adjusted.
3. **Media keys:** With a track playing, press F7 (previous) and F9 (next) on the keyboard — the player should change tracks. F8 still does play/pause. The macOS Now Playing widget (Control Center / lock screen) should show the current track title/artist and respond to its prev/play/next buttons.
4. **Source indicator:** Play a track from a playlist — the playlist's sidebar row and the playlist/album header show a small static green speaker icon (not the animated equalizer). The tracklist's playing row still shows the animated equalizer in the `#` column.

- [ ] **Step 3: Update `CLAUDE.md`**

Add a paragraph for this follow-up: search reworked (overlay model, Alpine-owned topbar input, `<livewire:search>` component, `plextune-search` event, no navigation; `/search` is a thin stub route; the old `data-density` wiring removed); per-section compact (four `AppSetting` accessors `artistsCompact`/`albumsCompact`/`libraryTracksCompact`/`playlistTracksCompact` defaulting to the Settings "Density default"; the placeholder header toggle buttons are now wired; compact = the reduced layout ported from `design/rendered/compact.html`); Media Session API in `audioPlayer()` (OS media keys + Now Playing widget); now-playing source indicator is now `<x-lucide-volume-1 text-accent>`, the animated `.eq` stays only in tracklist `#` columns. Update the test count line. Add any new gotchas (e.g. anything learned about the Alpine-owned-input pattern, or `@js(request()->query('q'))` in the topbar).

- [ ] **Step 4: Commit + finish the branch**

```bash
git add CLAUDE.md
git commit -m "docs(claude): M7 + follow-up shipped"
```

Then use the `superpowers:finishing-a-development-branch` skill to integrate `feat/m7-polish` into `main` per the project's convention (prior milestones used a merge commit, e.g. `Merge feat/settings: ...`).

---

## Self-Review (run before handing off)

- **Spec coverage:** §1 search rework → Tasks 1 (component), 2 (layout wiring + page stub), 3 (Alpine topbar). §2 per-section compact → Task 4 (`AppSetting` accessors), 5 (artists/albums miller columns), 6 (library tracklist), 7 (playlist tracklist), 8 (Settings relabel + remove old `data-density`). §3 media keys → Task 9. §4 source-indicator icon → Task 10. Tests + smoke + `CLAUDE.md` + merge → Task 11. All covered.
- **Placeholder scan:** No "TBD"/"TODO". Several steps say "port the markup from `design/rendered/compact.html`" / "reuse the current `pages::search` results-branch markup" — deliberate, the project convention is to port verbatim from the rendered DOM and the implementer must open those files; the grid-template-columns and the cell structures are spelled out inline. The browser-test steps say "use whatever the plugin's typing API is" — also deliberate (mirror the existing `tests/Browser/*` patterns; `script()` dispatching an `input` event is given as the concrete fallback).
- **Type/name consistency:** `plextune-search` event name used consistently between the topbar's `$dispatch` and the search component's `#[On('plextune-search')] applyTerm(string $term)`. `?q=` synced via `#[Url(as: 'q', except: '')]` on the search component (and the topbar reads `request()->query('q', '')` for its `x-init` seed — they don't both `#[Url]`-bind, only the component does). `artistsCompact`/`albumsCompact`/`tracksCompact` props on `pages::library` map to `AppSetting::artistsCompact`/`albumsCompact`/`libraryTracksCompact` (note: the library tracklist prop is `tracksCompact` but its accessor is `libraryTracksCompact` — consistent within each file, intentional, the prop is page-local). `tracksCompact` on `pages::playlist-detail` ↔ `AppSetting::playlistTracksCompact`. `data-region="search-overlay"` / `data-region="search-clear"` / `data-source-indicator` hooks used consistently between markup and tests. The grid-template-columns strings (`20px 1.4fr 1fr 50px` library, `20px 1.4fr 1fr 1fr 50px` playlist) match between the header row, the track rows, and the feature-test `assertSeeHtml`.
