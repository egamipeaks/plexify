# Plextune Favorites Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the user heart/unheart any track (5-star rating in Plex) and browse all hearted tracks at `/favorites`.

**Architecture:** A new `PlexClient::rateTrack()` writes Plex's rating endpoint (0 or 10); a new `PlexClient::favoriteTracks()` lists 5-star tracks sorted by `lastRatedAt:desc`. Heart state is shown by the server-rendered row's `userRating` and kept in sync across the page by a new `$store.favorites` Alpine store (optimistic flip, rollback + toast on Plex failure). Heart icons live in the library album tracklist, the playlist-detail tracklist, and the now-playing strip. A new Livewire trait `TogglesFavorite` shares the action across components. A sidebar "Favorites" link sits above "Your Library".

**Tech Stack:** Laravel 13, Livewire 4 SFCs, Alpine.js, Tailwind 4, Pest 4 (Unit/Feature/Browser).

**Spec:** `docs/superpowers/specs/2026-05-23-plextune-favorites-design.md`.

---

## File Structure

- Create:
  - `app/Livewire/Concerns/TogglesFavorite.php` — trait with `toggleHeart(string $ratingKey, int $newRating): void`, resolves `PlexClient` from container, dispatches `notify` on failure.
  - `resources/views/pages/⚡favorites.blade.php` — `/favorites` page (Livewire SFC).
  - `resources/views/components/heart-button.blade.php` — anonymous Blade component (NO `⚡` prefix) rendering the heart icon + Alpine click handler.
  - `tests/Feature/FavoritesPageTest.php`
  - `tests/Browser/FavoritesTest.php`
- Modify:
  - `app/Services/Plex/Dto/Track.php` — add `userRating` (int, default 0) and `lastRatedAt` (?string, default null) fields + `fromPlex` parsing.
  - `app/Services/Plex/PlexCache.php` — add `TTL_FAVORITES = 300`.
  - `app/Services/Plex/PlexClient.php` — add `rateTrack()` and `favoriteTracks()`.
  - `resources/views/components/layouts/app.blade.php` — register `$store.favorites` in the existing `alpine:init` block in `<head>`.
  - `resources/views/components/⚡sidebar.blade.php` — add "Favorites" link in the top nav card.
  - `resources/views/components/⚡player.blade.php` — wire the heart slot to `<x-heart-button>` + use the trait.
  - `resources/views/pages/⚡library.blade.php` — replace heart cell with `<x-heart-button>` + use the trait.
  - `resources/views/pages/⚡playlist-detail.blade.php` — same.
  - `routes/web.php` — add `/favorites` route.
  - `tests/Unit/PlexClientTest.php` — `rateTrack` + `favoriteTracks` tests.
  - `tests/Unit/PlexDtoTest.php` — Track `userRating` / `lastRatedAt` parsing.
  - `tests/Feature/RoutesTest.php` — assert `/favorites` resolves.
  - `tests/Feature/LibraryViewTest.php` — heart fill + `toggleHeart` action.
  - `tests/Feature/PlaylistDetailViewTest.php` — same.
  - `tests/Feature/PlayerComponentTest.php` — player heart wires the trait.
  - `tests/Feature/SidebarTest.php` — Favorites link present + active.
- Test fixtures (optional): may add `tests/Fixtures/Plex/favorites.json` if a fixture is needed.

---

## Task 1: Add `userRating` and `lastRatedAt` fields to the Track DTO

**Files:**
- Modify: `app/Services/Plex/Dto/Track.php`
- Modify: `tests/Unit/PlexDtoTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/PlexDtoTest.php`:

```php
it('parses userRating and lastRatedAt on a Track', function () {
    $track = \App\Services\Plex\Dto\Track::fromPlex([
        'ratingKey' => '1',
        'title' => 'Song',
        'grandparentTitle' => 'Artist',
        'parentTitle' => 'Album',
        'index' => 1,
        'duration' => 1000,
        'userRating' => 10,
        'lastRatedAt' => 1716480000,
        'Media' => [['Part' => [['id' => 1, 'container' => 'mp3']]]],
    ]);

    expect($track->userRating)->toBe(10);
    expect($track->lastRatedAt)->toBe('1716480000');
});

it('defaults userRating to 0 and lastRatedAt to null when absent', function () {
    $track = \App\Services\Plex\Dto\Track::fromPlex([
        'ratingKey' => '2',
        'title' => 'Song',
        'grandparentTitle' => 'Artist',
        'parentTitle' => 'Album',
        'index' => 1,
        'duration' => 1000,
        'Media' => [['Part' => [['id' => 1, 'container' => 'mp3']]]],
    ]);

    expect($track->userRating)->toBe(0);
    expect($track->lastRatedAt)->toBeNull();
});
```

- [ ] **Step 2: Run and verify failure**

```
php artisan test --compact --filter="parses userRating"
php artisan test --compact --filter="defaults userRating"
```
Expected: FAIL ("Unknown named parameter $userRating").

- [ ] **Step 3: Add the fields**

In `app/Services/Plex/Dto/Track.php`, add two new constructor properties (keep them after `playlistItemId`, all nullable defaults so existing call sites are unaffected) and parse them in `fromPlex`:

```php
public function __construct(
    public string $id,
    public string $title,
    public string $artist,
    public string $album,
    public int $trackNumber,
    public int $durationMs,
    public int $partId,
    public string $container,
    public ?string $thumb = null,
    public ?string $albumId = null,
    public ?string $artistId = null,
    public ?string $playlistItemId = null,
    public int $userRating = 0,
    public ?string $lastRatedAt = null,
) {}
```

In `fromPlex` add (after `playlistItemId:`):

```php
userRating: (int) ($row['userRating'] ?? 0),
lastRatedAt: isset($row['lastRatedAt']) ? (string) $row['lastRatedAt'] : null,
```

- [ ] **Step 4: Run and verify pass**

```
php artisan test --compact --filter="userRating"
```
Expected: PASS (both new tests + the rest of PlexDtoTest still green).

- [ ] **Step 5: Clear the cache (DTO shape changed)**

```
php artisan cache:clear
```
Expected: "Application cache cleared successfully."

- [ ] **Step 6: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/Dto/Track.php tests/Unit/PlexDtoTest.php
git commit -m "feat(plex): add userRating + lastRatedAt to Track DTO"
```

---

## Task 2: Add `PlexCache::TTL_FAVORITES`

**Files:**
- Modify: `app/Services/Plex/PlexCache.php`

This is a one-line constant. No test needed (a value-only constant has no behaviour to test); it's verified by Task 3 which references it.

- [ ] **Step 1: Add the constant**

In `app/Services/Plex/PlexCache.php` near the other TTL constants:

```php
public const TTL_FAVORITES = 300;           // 5 minutes
```

- [ ] **Step 2: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexCache.php
git commit -m "feat(plex): add TTL_FAVORITES cache constant"
```

---

## Task 3: `PlexClient::rateTrack()` — write the heart

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `tests/Unit/PlexClientTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/PlexClientTest.php`:

```php
it('rates a track 5 stars via PUT /:/rating?rating=10', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:favorites:1000', 'stale', 300);

    app(PlexClient::class)->rateTrack('12345', 10);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/:/rating?')
        && str_contains($request->url(), 'key=12345')
        && str_contains($request->url(), 'rating=10')
        && str_contains($request->url(), 'identifier=com.plexapp.plugins.library'));

    expect(Cache::has('plex:favorites:1000'))->toBeFalse();
});

it('clears a rating via PUT /:/rating?rating=0', function () {
    fakePlexWriteEndpoints();

    app(PlexClient::class)->rateTrack('12345', 0);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), 'rating=0'));
});

it('rejects invalid rating values', function () {
    fakePlexWriteEndpoints();

    expect(fn () => app(PlexClient::class)->rateTrack('1', 5))
        ->toThrow(InvalidArgumentException::class);
});

it('maps a 500 from /:/rating to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/:/rating*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->rateTrack('12345', 10))
        ->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 2: Run and verify failure**

```
php artisan test --compact --filter="rates a track"
```
Expected: FAIL ("Call to undefined method ... rateTrack").

- [ ] **Step 3: Implement `rateTrack` on `PlexClient`**

Add to `app/Services/Plex/PlexClient.php` (place near other write methods like `moveTrack`):

```php
public function rateTrack(string $ratingKey, int $rating): void
{
    if (! in_array($rating, [0, 10], true)) {
        throw new \InvalidArgumentException("rateTrack only supports 0 or 10, got {$rating}.");
    }

    $query = http_build_query([
        'key' => $ratingKey,
        'identifier' => 'com.plexapp.plugins.library',
        'rating' => $rating,
    ]);

    try {
        $response = $this->server()->put('/:/rating?'.$query);
    } catch (ConnectionException $e) {
        throw new PlexUnreachableException('Rating track failed: '.$e->getMessage(), previous: $e);
    }

    $this->ensureOk($response, "PUT /:/rating key={$ratingKey}");

    $this->forgetFavoritesCache();
}

private function forgetFavoritesCache(): void
{
    foreach (Cache::get('plex:_index', []) as $namespaced) {
        if (str_starts_with($namespaced, 'plex:favorites:')) {
            $bare = substr($namespaced, strlen('plex:'));
            $this->cache->forget($bare);
        }
    }
}
```

Add `use Illuminate\Support\Facades\Cache;` to the imports if not already present.

- [ ] **Step 4: Run and verify pass**

```
php artisan test --compact --filter="/:/rating|rates a track|clears a rating|rejects invalid rating"
```
Expected: PASS for all four new tests.

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat(plex): rateTrack writes user rating via /:/rating"
```

---

## Task 4: `PlexClient::favoriteTracks()` — list 5-star tracks

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `tests/Unit/PlexClientTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/PlexClientTest.php`:

```php
it('lists favorite tracks (userRating=10) sorted by lastRatedAt desc, cached', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response([
            'MediaContainer' => [
                'Metadata' => [
                    [
                        'ratingKey' => '11', 'title' => 'B', 'grandparentTitle' => 'Artist',
                        'parentTitle' => 'Album', 'index' => 1, 'duration' => 1000,
                        'userRating' => 10, 'lastRatedAt' => 1716500000,
                        'Media' => [['Part' => [['id' => 1, 'container' => 'mp3']]]],
                    ],
                    [
                        'ratingKey' => '12', 'title' => 'A', 'grandparentTitle' => 'Artist',
                        'parentTitle' => 'Album', 'index' => 2, 'duration' => 2000,
                        'userRating' => 10, 'lastRatedAt' => 1716400000,
                        'Media' => [['Part' => [['id' => 2, 'container' => 'mp3']]]],
                    ],
                ],
            ],
        ], 200),
    ]);

    $tracks = app(PlexClient::class)->favoriteTracks(1000);

    expect($tracks)->toBeInstanceOf(Collection::class);
    expect($tracks)->toHaveCount(2);
    expect($tracks->first()->title)->toBe('B');  // Plex returns sorted; we preserve order.
    expect($tracks->first()->userRating)->toBe(10);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'type=10')
        && str_contains($request->url(), 'userRating=10')
        && str_contains($request->url(), 'sort='.urlencode('lastRatedAt:desc'))
        && str_contains($request->url(), 'X-Plex-Container-Size=1000'));

    expect(Cache::has('plex:favorites:1000'))->toBeTrue();
});

it('returns an empty collection when favorites query is 404', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response('not found', 404),
    ]);

    expect(app(PlexClient::class)->favoriteTracks(1000))->toHaveCount(0);
});

it('maps a 500 from the favorites query to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/3/all*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->favoriteTracks(1000))
        ->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 2: Run and verify failure**

```
php artisan test --compact --filter="favorite tracks"
```
Expected: FAIL ("Call to undefined method ... favoriteTracks").

- [ ] **Step 3: Implement `favoriteTracks`**

Add to `app/Services/Plex/PlexClient.php` (place near `recentlyPlayedTracks`):

```php
/**
 * @return Collection<int, Track>
 */
public function favoriteTracks(int $limit = 1000): Collection
{
    return $this->cache->remember("favorites:{$limit}", PlexCache::TTL_FAVORITES, function () use ($limit) {
        $sectionId = $this->musicSectionId();

        $response = $this->server()->get("/library/sections/{$sectionId}/all", [
            'type' => 10,
            'userRating' => 10,
            'sort' => 'lastRatedAt:desc',
            'X-Plex-Container-Start' => 0,
            'X-Plex-Container-Size' => $limit,
        ]);

        if ($response->status() === 404) {
            return collect();
        }

        $this->ensureOk($response, "library/sections/{$sectionId}/all (favorites)");

        return collect(data_get($response->json(), 'MediaContainer.Metadata', []))
            ->map(fn (array $row) => Track::fromPlex($row))
            ->values();
    });
}
```

- [ ] **Step 4: Run and verify pass**

```
php artisan test --compact --filter="favorite tracks|favorites query"
```
Expected: PASS.

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat(plex): favoriteTracks lists 5-star tracks sorted by lastRatedAt"
```

---

## Task 5: Add `$store.favorites` Alpine store in the layout

**Files:**
- Modify: `resources/views/components/layouts/app.blade.php`

The store tracks ratingKeys that have been toggled this session and their current state, so the heart icon stays consistent across pages without a full reload.

- [ ] **Step 1: Locate the existing `alpine:init` block in `<head>`**

Open `resources/views/components/layouts/app.blade.php`. Find the existing `document.addEventListener('alpine:init', ...)` block in `<head>` that registers `$store.player`. We will add a sibling store inside the same listener (or alongside it).

- [ ] **Step 2: Register `$store.favorites`**

Inside the same `alpine:init` listener (or in a second one below it), add:

```js
Alpine.store('favorites', {
    // Map of ratingKey => current rating (0 or 10). Absent means "use server-rendered value".
    overrides: {},

    isHearted(ratingKey, serverRating) {
        if (this.overrides[ratingKey] !== undefined) {
            return this.overrides[ratingKey] === 10;
        }
        return serverRating === 10;
    },

    set(ratingKey, rating) {
        this.overrides[ratingKey] = rating;
    },

    clear(ratingKey) {
        delete this.overrides[ratingKey];
    },
});
```

- [ ] **Step 3: Verify nothing breaks**

```
php artisan test --compact
```
Expected: All existing tests pass (no behaviour change yet).

- [ ] **Step 4: Commit**

```
git add resources/views/components/layouts/app.blade.php
git commit -m "feat(ui): register \$store.favorites Alpine store"
```

---

## Task 6: Create the `TogglesFavorite` Livewire trait

**Files:**
- Create: `app/Livewire/Concerns/TogglesFavorite.php`

The trait exposes one Livewire-callable method that performs the Plex write. On failure it dispatches a `notify` toast and re-throws nothing (the caller's `$wire.call(...).then(ok => ...)` interprets the absence of an exception as success; the Alpine rollback path handles UX on failure).

- [ ] **Step 1: Create the directory and trait**

Create file `app/Livewire/Concerns/TogglesFavorite.php`:

```php
<?php

namespace App\Livewire\Concerns;

use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Facades\Log;

trait TogglesFavorite
{
    public function toggleHeart(string $ratingKey, int $newRating): bool
    {
        if (! in_array($newRating, [0, 10], true)) {
            return false;
        }

        try {
            app(PlexClient::class)->rateTrack($ratingKey, $newRating);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('toggleHeart failed', [
                'ratingKey' => $ratingKey,
                'newRating' => $newRating,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'Couldn\'t update favorite. Plex may be unreachable.');

            return false;
        }

        return true;
    }
}
```

- [ ] **Step 2: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Livewire/Concerns/TogglesFavorite.php
git commit -m "feat: TogglesFavorite trait shared by heart-bearing components"
```

(Trait is wired into components in later tasks; its behaviour is exercised through those components' feature tests in Tasks 8, 9, 10, 12.)

---

## Task 7: Create the `<x-heart-button>` Blade component

**Files:**
- Create: `resources/views/components/heart-button.blade.php`

A plain Blade anonymous component (no `⚡` prefix, per CLAUDE.md). Renders a button with the heart icon, binds fill state to `$store.favorites`, and `@click.stop`s a `$wire.toggleHeart` call with optimistic rollback.

- [ ] **Step 1: Create the file**

Create `resources/views/components/heart-button.blade.php`:

```blade
@props([
    'ratingKey',
    'rating' => 0,        // server-side userRating (0 or 10)
    'size' => 'sm',       // 'sm' (tracklist) | 'md' (player)
    'alwaysVisible' => false, // true for player; tracklist hearts can fade until hover/filled
])

@php
    $iconSize = $size === 'md' ? 'w-4 h-4' : 'w-3.5 h-3.5';
@endphp

<button type="button"
        @click.stop="
            const cur = $store.favorites.isHearted('{{ $ratingKey }}', {{ (int) $rating }}) ? 10 : 0;
            const next = cur === 10 ? 0 : 10;
            $store.favorites.set('{{ $ratingKey }}', next);
            $wire.toggleHeart('{{ $ratingKey }}', next).then(ok => {
                if (!ok) $store.favorites.set('{{ $ratingKey }}', cur);
            });
        "
        x-data="{ get hearted() { return $store.favorites.isHearted('{{ $ratingKey }}', {{ (int) $rating }}); } }"
        :class="hearted ? 'text-accent' : ({{ $alwaysVisible ? 'true' : 'false' }} ? 'text-text-2 hover:text-white' : 'text-text-3 opacity-0 group-hover:opacity-100 hover:text-white')"
        class="grid place-items-center transition-opacity"
        :aria-label="hearted ? 'Remove from favorites' : 'Add to favorites'">
    <x-lucide-heart ::class="hearted ? '{{ $iconSize }} fill-current' : '{{ $iconSize }}'" />
</button>
```

Note the `::class` (double colon) on the lucide icon — that's Blade-component syntax for passing through an `x-bind:class` directly to the rendered SVG element. Verify the package supports it; if not, fall back to wrapping the icon in a `<span>` with the bind:

```blade
<span :class="hearted ? '{{ $iconSize }} fill-current' : '{{ $iconSize }}'">
    <x-lucide-heart class="w-full h-full" />
</span>
```

Use whichever works in your environment; the wrapper-span fallback is the safest.

- [ ] **Step 2: Confirm component resolves**

```
php artisan view:clear
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap(); echo view('components.heart-button', ['ratingKey' => 'x'])->render();"
```
Expected: HTML containing `<button` and `lucide-heart` SVG markup with no exception.

- [ ] **Step 3: Commit**

```
git add resources/views/components/heart-button.blade.php
git commit -m "feat(ui): heart-button Blade component"
```

---

## Task 8: Wire the heart into the player

**Files:**
- Modify: `resources/views/components/⚡player.blade.php`
- Modify: `tests/Feature/PlayerComponentTest.php`

The now-playing strip currently has a static `<x-lucide-heart>`. Replace it with `<x-heart-button>` driven by the Alpine `current.id` and a new public prop on the component that holds the current track's server-side rating (refreshed when the player advances).

The simplest correct approach: the player doesn't know about the rating server-side at all. We render the button only when `current` is set, key it off `current.id`, and pass `rating="0"` as the server-side fallback. The `$store.favorites` overrides take precedence as the user toggles; on page navigation a fresh row's server-rendered heart wins. (This is consistent with the spec's "single source of truth + per-session overrides" rule.)

- [ ] **Step 1: Write the failing test**

In `tests/Feature/PlayerComponentTest.php` append:

```php
it('uses the TogglesFavorite trait on the player', function () {
    $reflect = new \ReflectionClass(\Livewire\Livewire::new('player'));
    $traits = [];
    foreach ($reflect->getTraitNames() as $t) {
        $traits[] = $t;
    }
    // The SFC anonymous class won't expose the trait via getTraitNames here;
    // assert behaviour instead.
    \App\Services\Plex\PlexClient::class;

    $mock = \Mockery::mock(\App\Services\Plex\PlexClient::class);
    $mock->shouldReceive('scrobbleUrl')->andReturn('http://example.test/scrobble?key=__KEY__');
    $mock->shouldReceive('rateTrack')->with('123', 10)->once();
    app()->instance(\App\Services\Plex\PlexClient::class, $mock);

    \Livewire\Livewire::test('player')
        ->call('toggleHeart', '123', 10)
        ->assertHasNoErrors();
});
```

If `Livewire::new` does not exist in this version, drop the reflection block — the behavioural assertion is sufficient.

- [ ] **Step 2: Run and verify failure**

```
php artisan test --compact --filter="TogglesFavorite trait on the player"
```
Expected: FAIL ("Method toggleHeart does not exist on player").

- [ ] **Step 3: Add the trait to the player component**

In `resources/views/components/⚡player.blade.php`, modify the component class definition (top of file):

```php
use App\Livewire\Concerns\TogglesFavorite;

new class extends Component {
    use TogglesFavorite;

    public bool $scrobbleEnabled = true;
    // ...existing body...
};
```

Then replace the static heart button (currently around line 51-53):

```blade
<button type="button" class="text-accent hover:scale-110 transition-transform">
    <x-lucide-heart class="w-4 h-4" />
</button>
```

with a `<template>` that gates on `current` and renders the component:

```blade
<template x-if="current">
    <x-heart-button ::rating-key="current.id" :rating="0" size="md" :always-visible="true" />
</template>
```

Note: `::rating-key` (double colon) passes the value as a JS expression to the Alpine-scoped child. If your Blade component compiler doesn't pass Alpine bindings down cleanly, render the button outside Blade and bind in Alpine directly — i.e., inline the markup:

```blade
<template x-if="current">
    <button type="button"
            @click.stop="
                const ratingKey = current.id;
                const cur = $store.favorites.isHearted(ratingKey, 0) ? 10 : 0;
                const next = cur === 10 ? 0 : 10;
                $store.favorites.set(ratingKey, next);
                $wire.toggleHeart(ratingKey, next).then(ok => { if (!ok) $store.favorites.set(ratingKey, cur); });
            "
            :class="$store.favorites.isHearted(current?.id, 0) ? 'text-accent' : 'text-text-2 hover:text-white'"
            class="transition-transform hover:scale-110">
        <x-lucide-heart class="w-4 h-4" ::class="$store.favorites.isHearted(current?.id, 0) ? 'fill-current' : ''" />
    </button>
</template>
```

Pick the inline form if the component-based form does not pass attributes through to Alpine correctly. The behaviour is identical.

- [ ] **Step 4: Run and verify pass**

```
php artisan test --compact --filter="player|TogglesFavorite trait on the player"
```
Expected: PASS.

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add resources/views/components/⚡player.blade.php tests/Feature/PlayerComponentTest.php
git commit -m "feat(player): heart button toggles 5-star rating on now-playing track"
```

---

## Task 9: Wire the heart into the library album tracklist

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php`
- Modify: `tests/Feature/LibraryViewTest.php`

The library album tracklist has a heart cell at column 5 of the comfortable tracklist grid (around line 519-521). Replace the static icon with `<x-heart-button>` and add the trait to the component class.

(The compact variant of the library tracklist has no heart column per the M7 compact spec; leave it as-is.)

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/LibraryViewTest.php` append:

```php
it('renders a filled heart on hearted tracks in the album tracklist', function () {
    $hearted = new \App\Services\Plex\Dto\Track(
        id: '99', title: 'A', artist: 'X', album: 'Y',
        trackNumber: 1, durationMs: 1000, partId: 1, container: 'mp3',
        userRating: 10,
    );
    $cold = new \App\Services\Plex\Dto\Track(
        id: '100', title: 'B', artist: 'X', album: 'Y',
        trackNumber: 2, durationMs: 1000, partId: 2, container: 'mp3',
        userRating: 0,
    );

    $mock = \Mockery::mock(\App\Services\Plex\PlexClient::class);
    // ...stub artists/albums/tracksForAlbum/ping/playlists/searchAll/thumbUrl/scrobbleUrl per existing pattern...
    // Keep this block identical to a sibling test in this file; just make tracksForAlbum return collect([$hearted, $cold]).

    // assert the heart-button for $hearted renders with rating=10
    \Livewire\Livewire::withQueryParams(['artist' => 'A1', 'album' => 'AL1'])
        ->test('pages::library')
        ->assertSeeHtml('rating-key="99"')
        ->assertSeeHtml('rating-key="100"');
});

it('calls PlexClient::rateTrack via toggleHeart on the library page', function () {
    $mock = \Mockery::mock(\App\Services\Plex\PlexClient::class);
    $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'M']);
    $mock->shouldReceive('playlists')->andReturn(collect());
    $mock->shouldReceive('artists')->andReturn(collect());
    $mock->shouldReceive('searchAll')->andReturn(\App\Services\Plex\Dto\SearchResults::empty());
    $mock->shouldReceive('rateTrack')->with('77', 10)->once();
    app()->instance(\App\Services\Plex\PlexClient::class, $mock);

    \Livewire\Livewire::test('pages::library')
        ->call('toggleHeart', '77', 10)
        ->assertHasNoErrors();
});
```

(Mirror the existing test's mock setup for the first test; the second test only needs the mocks the action actually touches.)

- [ ] **Step 2: Run and verify failure**

```
php artisan test --compact --filter="library|rateTrack via toggleHeart on the library"
```
Expected: FAIL ("Method toggleHeart does not exist" or "rating-key=...").

- [ ] **Step 3: Modify the library component**

In `resources/views/pages/⚡library.blade.php`:

1. Add the trait at the top of the SFC class:
   ```php
   use App\Livewire\Concerns\TogglesFavorite;
   ```
   And inside the `new class extends Component` body:
   ```php
   use TogglesFavorite;
   ```

2. Around line 519, replace:
   ```blade
   <span class="grid place-items-center text-text-2 hover:text-white">
       <x-lucide-heart class="w-3.5 h-3.5" />
   </span>
   ```
   with:
   ```blade
   <x-heart-button :rating-key="$track->id" :rating="$track->userRating" size="sm" />
   ```

- [ ] **Step 4: Run and verify pass**

```
php artisan test --compact --filter="library"
```
Expected: PASS. Run the full LibraryViewTest file to confirm no regression.

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡library.blade.php tests/Feature/LibraryViewTest.php
git commit -m "feat(library): heart button in album tracklist"
```

---

## Task 10: Wire the heart into the playlist-detail tracklist

**Files:**
- Modify: `resources/views/pages/⚡playlist-detail.blade.php`
- Modify: `tests/Feature/PlaylistDetailViewTest.php`

Same pattern as Task 9 but in playlist-detail. The heart cell is around line 491-493.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/PlaylistDetailViewTest.php`:

```php
it('calls PlexClient::rateTrack via toggleHeart on the playlist-detail page', function () {
    $mock = \Mockery::mock(\App\Services\Plex\PlexClient::class);
    $mock->shouldReceive('ping')->andReturn(['name' => 'T', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'M']);
    $mock->shouldReceive('playlists')->andReturn(collect());
    $mock->shouldReceive('searchAll')->andReturn(\App\Services\Plex\Dto\SearchResults::empty());
    $mock->shouldReceive('playlistTracks')->andReturn(collect());
    $mock->shouldReceive('rateTrack')->with('77', 0)->once();
    app()->instance(\App\Services\Plex\PlexClient::class, $mock);

    \Livewire\Livewire::test('pages::playlist-detail', ['playlist' => '1'])
        ->call('toggleHeart', '77', 0)
        ->assertHasNoErrors();
});
```

- [ ] **Step 2: Run and verify failure**

```
php artisan test --compact --filter="rateTrack via toggleHeart on the playlist-detail"
```
Expected: FAIL ("Method toggleHeart does not exist").

- [ ] **Step 3: Add the trait + heart button**

In `resources/views/pages/⚡playlist-detail.blade.php`:

1. Top of file (use statements + inside class):
   ```php
   use App\Livewire\Concerns\TogglesFavorite;
   // ...
   new class extends Component {
       use TogglesFavorite;
       // ...existing body...
   };
   ```

2. Replace the static heart (around line 491-493):
   ```blade
   <x-heart-button :rating-key="$track->id" :rating="$track->userRating" size="sm" />
   ```

- [ ] **Step 4: Run and verify pass**

```
php artisan test --compact --filter="playlist-detail"
```
Expected: PASS.

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡playlist-detail.blade.php tests/Feature/PlaylistDetailViewTest.php
git commit -m "feat(playlist-detail): heart button in tracklist"
```

---

## Task 11: Add the Favorites link to the sidebar

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php`
- Modify: `tests/Feature/SidebarTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/SidebarTest.php`:

```php
it('shows a Favorites link in the sidebar top nav', function () {
    $mock = \Mockery::mock(\App\Services\Plex\PlexClient::class);
    $mock->shouldReceive('ping')->andReturn(['name' => 'T', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'M']);
    $mock->shouldReceive('playlists')->andReturn(collect());
    $mock->shouldReceive('thumbUrl')->andReturn(null);
    app()->instance(\App\Services\Plex\PlexClient::class, $mock);

    \Livewire\Livewire::test('sidebar')
        ->assertSee('Favorites')
        ->assertSeeHtml('href="'.route('favorites').'"');
});
```

(If `route('favorites')` doesn't resolve yet — it won't, since the route is added in Task 13 — this is fine: Step 2 confirms the failure, and Step 3 adds the link AND we sequence the route registration into the very next task. To unblock this test before Task 13, register the route now: add the route line to `routes/web.php` *in this task's commit*. The Favorites page itself comes in Task 13.)

- [ ] **Step 2: Run and verify failure**

```
php artisan test --compact --filter="Favorites link in the sidebar"
```
Expected: FAIL ("Route [favorites] not defined" first; once the route is added, FAIL with "Failed asserting that page source contains Favorites").

- [ ] **Step 3: Add the route + the sidebar link**

In `routes/web.php`, add (between the existing playlist and settings routes):

```php
Route::livewire('/favorites', 'pages::favorites')->name('favorites');
```

In `resources/views/components/⚡sidebar.blade.php`, add a new link inside the top nav card (above "Your Library", below "Generate"):

```blade
<a href="{{ route('favorites') }}" wire:navigate
   @class([
       'w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors',
       'text-white bg-surface-2' => request()->routeIs('favorites'),
       'text-text-2 hover:text-white' => ! request()->routeIs('favorites'),
   ])>
    <x-lucide-heart class="w-5 h-5" />
    <span class="flex-1 text-left">Favorites</span>
</a>
```

Place it directly **above** the "Your Library" link, **below** "Generate" (the spec says "above Your Library").

The route registration here points at a page component that won't exist until Task 13. Until then, navigating to `/favorites` will throw — but the sidebar test only renders the link, doesn't follow it. Task 13 finishes the page.

- [ ] **Step 4: Run and verify pass**

```
php artisan test --compact --filter="Favorites link in the sidebar"
```
Expected: PASS.

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add resources/views/components/⚡sidebar.blade.php routes/web.php tests/Feature/SidebarTest.php
git commit -m "feat(sidebar): Favorites link above Your Library + register /favorites route"
```

---

## Task 12: Build the `/favorites` page

**Files:**
- Create: `resources/views/pages/⚡favorites.blade.php`
- Create: `tests/Feature/FavoritesPageTest.php`
- Modify: `tests/Feature/RoutesTest.php`

The page is a plain tracklist. Sort comes from Plex; we just render. Use the playlist-detail row markup (comfortable variant — the spec drops the compact toggle here for simplicity; if you later want it, follow the per-section compact pattern).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/FavoritesPageTest.php`:

```php
<?php

use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\PlexClient;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $mock = Mockery::mock(PlexClient::class);
    $mock->shouldReceive('ping')->andReturn([
        'name' => 'Test', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'M1',
    ]);
    $mock->shouldReceive('playlists')->andReturn(collect());
    $mock->shouldReceive('searchAll')->andReturn(SearchResults::empty());
    $mock->shouldReceive('thumbUrl')->andReturn(null);
    $mock->shouldReceive('streamUrl')->andReturn('http://x/stream');
    $mock->shouldReceive('queueItem')->andReturnUsing(fn ($t) => [
        'id' => $t->id, 'url' => 'http://x/stream', 'title' => $t->title,
        'artist' => $t->artist, 'artwork' => null,
        'albumId' => $t->albumId, 'artistId' => $t->artistId,
    ]);
    app()->instance(PlexClient::class, $mock);
});

it('renders the favorites page with the empty state when there are no favorites', function () {
    app(PlexClient::class)->shouldReceive('favoriteTracks')->andReturn(collect());

    \Livewire\Livewire::test('pages::favorites')
        ->assertSee('Favorites')
        ->assertSee('No favorites yet');
});

it('renders each favorite track row', function () {
    app(PlexClient::class)->shouldReceive('favoriteTracks')->andReturn(collect([
        new Track(id: '11', title: 'Heart of Glass', artist: 'Blondie', album: 'Parallel Lines',
            trackNumber: 5, durationMs: 200000, partId: 1, container: 'mp3', userRating: 10),
        new Track(id: '12', title: 'Smalltown Boy', artist: 'Bronski Beat', album: 'Age of Consent',
            trackNumber: 1, durationMs: 300000, partId: 2, container: 'mp3', userRating: 10),
    ]));

    \Livewire\Livewire::test('pages::favorites')
        ->assertSee('Heart of Glass')
        ->assertSee('Smalltown Boy')
        ->assertSee('Blondie')
        ->assertDontSee('No favorites yet');
});

it('dispatches play-track with contextType=favorites when a row is played', function () {
    $track = new Track(id: '11', title: 'X', artist: 'Y', album: 'Z',
        trackNumber: 1, durationMs: 1000, partId: 1, container: 'mp3', userRating: 10);
    app(PlexClient::class)->shouldReceive('favoriteTracks')->andReturn(collect([$track]));

    \Livewire\Livewire::test('pages::favorites')
        ->call('playTrack', '11')
        ->assertDispatched('play-track', fn ($name, $args) => ($args['contextType'] ?? null) === 'favorites');
});

it('toggleHeart on the favorites page calls PlexClient::rateTrack', function () {
    app(PlexClient::class)->shouldReceive('favoriteTracks')->andReturn(collect());
    app(PlexClient::class)->shouldReceive('rateTrack')->with('99', 0)->once();

    \Livewire\Livewire::test('pages::favorites')
        ->call('toggleHeart', '99', 0)
        ->assertHasNoErrors();
});

it('shows a notify toast when favoriteTracks throws', function () {
    app(PlexClient::class)->shouldReceive('favoriteTracks')
        ->andThrow(new \App\Services\Plex\Exceptions\PlexUnreachableException('down'));

    \Livewire\Livewire::test('pages::favorites')
        ->assertDispatched('notify', fn ($name, $args) => ($args['type'] ?? null) === 'error');
});
```

Add to `tests/Feature/RoutesTest.php` (alongside the existing route assertions):

```php
it('resolves /favorites to the favorites page', function () {
    $mock = Mockery::mock(\App\Services\Plex\PlexClient::class);
    $mock->shouldReceive('ping')->andReturn(['name' => 'T', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'M']);
    $mock->shouldReceive('playlists')->andReturn(collect());
    $mock->shouldReceive('searchAll')->andReturn(\App\Services\Plex\Dto\SearchResults::empty());
    $mock->shouldReceive('thumbUrl')->andReturn(null);
    $mock->shouldReceive('favoriteTracks')->andReturn(collect());
    app()->instance(\App\Services\Plex\PlexClient::class, $mock);

    $this->get('/favorites')->assertOk();
});
```

- [ ] **Step 2: Run and verify failure**

```
php artisan test --compact --filter="favorites|/favorites"
```
Expected: FAIL (view not found / route).

- [ ] **Step 3: Implement the page**

Create `resources/views/pages/⚡favorites.blade.php`:

```blade
<?php

use App\Livewire\Concerns\TogglesFavorite;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use App\Support\Duration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

new class extends Component {
    use TogglesFavorite;

    /** @var Collection<int, \App\Services\Plex\Dto\Track> */
    public Collection $tracks;

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    public function mount(): void
    {
        try {
            $this->tracks = $this->plex->favoriteTracks();
        } catch (PlexException $e) {
            Log::channel('plex')->warning('favoriteTracks failed', ['error' => $e->getMessage()]);
            $this->tracks = collect();
            $this->dispatch('notify', type: 'error', message: 'Couldn\'t load favorites. Plex may be unreachable.');
        }
    }

    public function playTrack(string $trackId): void
    {
        $index = $this->tracks->search(fn ($t) => $t->id === $trackId);

        if ($index === false) {
            return;
        }

        $this->dispatch('play-track',
            queue: $this->tracks->map(fn ($t) => $this->plex->queueItem($t))->values()->all(),
            index: $index,
            contextType: 'favorites',
            contextId: null,
        );
    }
};
?>

<div class="flex-1 min-h-0 flex flex-col">
    <div class="px-8 pt-6 pb-4 flex items-end justify-between">
        <div>
            <h1 class="text-[28px] font-bold text-white">Favorites</h1>
            <p class="text-[12px] text-text-2">{{ $tracks->count() }} {{ \Illuminate\Support\Str::plural('song', $tracks->count()) }}</p>
        </div>
    </div>

    @if ($tracks->isEmpty())
        <div class="flex-1 grid place-items-center text-center px-8">
            <div class="max-w-md">
                <x-lucide-heart class="w-12 h-12 mx-auto text-text-3 mb-3" />
                <h2 class="text-white text-[20px] font-bold mb-1">No favorites yet</h2>
                <p class="text-text-2 text-[13px]">Heart a track and it'll show up here.</p>
            </div>
        </div>
    @else
        <div class="flex-1 min-h-0 overflow-y-auto px-4" data-region="favorites-tracklist">
            <div class="grid items-center px-4 py-2 text-[11px] uppercase tracking-wider text-text-3 border-b border-white/[0.07]"
                 style="grid-template-columns: 40px 1.6fr 1fr 1fr 16px 60px;">
                <span class="text-right">#</span>
                <span>Title</span>
                <span>Album</span>
                <span>Added</span>
                <span></span>
                <span class="text-right"><x-lucide-clock-3 class="w-4 h-4 inline" /></span>
            </div>

            @foreach ($tracks as $i => $track)
                <button type="button"
                        wire:key="fav-{{ $track->id }}"
                        wire:click="playTrack('{{ $track->id }}')"
                        class="row group w-full grid items-center px-4 py-2 rounded text-[14px] text-left hover:bg-white/[0.07] transition-colors"
                        style="grid-template-columns: 40px 1.6fr 1fr 1fr 16px 60px;">
                    <span class="tabular-nums text-text-2 text-right"
                          x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'favorites'; } }">
                        <template x-if="playing">
                            <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                        </template>
                        <template x-if="!playing">
                            <span>{{ $i + 1 }}</span>
                        </template>
                    </span>
                    <div class="min-w-0">
                        <div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'favorites') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
                        @if ($track->artistId)
                            <span class="block truncate text-[12px] text-text-2 group-hover:text-white hover:underline cursor-pointer"
                                  @click.stop="Livewire.navigate('{{ route('library', ['artist' => $track->artistId]) }}')">{{ $track->artist }}</span>
                        @else
                            <div class="truncate text-[12px] text-text-2 group-hover:text-white">{{ $track->artist }}</div>
                        @endif
                    </div>
                    @if ($track->artistId && $track->albumId)
                        <span class="text-text-2 group-hover:text-white truncate hover:underline cursor-pointer"
                              @click.stop="Livewire.navigate('{{ route('library', ['artist' => $track->artistId, 'album' => $track->albumId]) }}')">{{ $track->album }}</span>
                    @else
                        <div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>
                    @endif
                    <div class="text-text-2 text-[12px] tabular-nums truncate">
                        @if ($track->lastRatedAt)
                            {{ \Carbon\Carbon::createFromTimestamp((int) $track->lastRatedAt)->diffForHumans() }}
                        @endif
                    </div>
                    <x-heart-button :rating-key="$track->id" :rating="$track->userRating" size="sm" />
                    <div class="text-text-2 tabular-nums text-right">{{ Duration::format($track->durationMs) }}</div>
                </button>
            @endforeach
        </div>
    @endif
</div>
```

- [ ] **Step 4: Run and verify pass**

```
php artisan test --compact --filter="favorites|/favorites"
```
Expected: PASS for all FavoritesPageTest tests and the new RoutesTest assertion.

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡favorites.blade.php tests/Feature/FavoritesPageTest.php tests/Feature/RoutesTest.php
git commit -m "feat: /favorites page lists all 5-star tracks"
```

---

## Task 13: Browser test — live round-trip the heart

**Files:**
- Create: `tests/Browser/FavoritesTest.php`

Hits live Plex. Per the existing pattern (see `tests/Browser/PlaylistDetailTest.php`), it must tolerate the empty case gracefully.

- [ ] **Step 1: Write the test**

Create `tests/Browser/FavoritesTest.php`:

```php
<?php

use function Pest\Browser\visit;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('loads /favorites and shows either rows or the empty state', function () {
    $page = visit('/favorites');
    $page->assertSee('Favorites');

    $hasRows = $page->script('return document.querySelectorAll("[data-region=favorites-tracklist] button[wire\\\\:key^=fav-]").length > 0');

    if (! $hasRows[0]) {
        $page->assertSee('No favorites yet');
    } else {
        // At least one row rendered — round-trip a heart toggle.
        $ratingKey = $page->script('return document.querySelector("[data-region=favorites-tracklist] button[wire\\\\:key^=fav-]").getAttribute("wire:key").replace("fav-", "")')[0];

        // Read pre-state.
        $preHearted = $page->script("return !!document.querySelector('button[aria-label=\"Remove from favorites\"]')")[0];

        // Toggle off via the page action.
        $page->script("window.Livewire.find(document.querySelector('[wire\\\\:id]').getAttribute('wire:id')).call('toggleHeart', '{$ratingKey}', 0);");
        sleep(2);

        // Toggle back on so the state is restored.
        $page->script("window.Livewire.find(document.querySelector('[wire\\\\:id]').getAttribute('wire:id')).call('toggleHeart', '{$ratingKey}', 10);");
        sleep(2);

        // Cheap sanity assertion: page is still up and titled.
        $page->assertSee('Favorites');
    }
});
```

- [ ] **Step 2: Run the test**

```
php artisan test --compact tests/Browser/FavoritesTest.php
```
Expected: PASS (with the live Plex server reachable). On a dead server the existing browser tests would fail too — that's the project's documented constraint.

- [ ] **Step 3: Commit**

```
git add tests/Browser/FavoritesTest.php
git commit -m "test(browser): live round-trip the heart on /favorites"
```

---

## Task 14: Full test sweep + manual smoke

**Files:**
- (none — verification)

- [ ] **Step 1: Run the full suite**

```
php artisan test --compact
```
Expected: all green (the prior baseline + the new tests). If any prior test fails, it's almost certainly a missing mock on `playlists()` / `ping()` / `searchAll()` in a sidebar-rendering test that now also renders the new Favorites link (the link itself doesn't call Plex, but the sidebar still does). Add the mock and re-run.

- [ ] **Step 2: Manual smoke (user verifies)**

Per the project's "manual verification between milestones" pattern, hand off to the user to:

1. Open `/` (library), click a track, click the heart in the row → it fills. Reload, heart stays filled.
2. Click the heart in the now-playing strip → toggles.
3. Open `/favorites` → the track appears at the top of the list.
4. Click its heart → it un-fills, then on next visit to `/favorites` the row is gone.
5. Open a playlist, heart a row inside it → it persists.
6. Disconnect from network briefly, click a heart → toast appears, icon reverts.

- [ ] **Step 3: Final commit (only if anything was tweaked during the sweep)**

```
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "chore: tidy after favorites sweep"
```

---

## Self-Review Notes

- **Spec coverage:** all six spec sections (Behaviour, Heart toggle, Heart state syncing, Favorites page, Sidebar entry, Plex integration, Component changes, Tests) map to Tasks 1-13. Out-of-scope items (search/recently-played hearts, star ratings beyond binary, reorder, live cross-page sync) are explicitly not covered, matching the spec.
- **Cached-DTO gotcha:** Task 1 includes `php artisan cache:clear` and the PR-author should note it.
- **Trait reuse:** the same `TogglesFavorite` trait is added to the player, library, playlist-detail, and favorites components (Tasks 7, 9, 10, 12). One method name (`toggleHeart`), one signature, one error path.
- **Alpine store registration:** Task 5 puts `$store.favorites` in the same `alpine:init` block that already holds `$store.player`, per the documented gotcha.
- **Sidebar route ordering:** Task 11 registers `/favorites` before Task 13 finishes the page, so the sidebar test passes; Task 13 then ships the page itself. The intermediate state breaks `/favorites` for a few commits but the suite stays green.
- **Heart-button component portability:** Task 7 ships two acceptable forms (component-based with `::class` pass-through OR a wrapper-span fallback). The implementer picks whichever the local Blade compiler supports.
