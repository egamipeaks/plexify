# Reorder tracks within a playlist — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the user drag a track row up or down in the playlist-detail tracklist to reorder it, persisted to the Plex playlist via the `move` endpoint.

**Architecture:** Add a `playlistItemID` field to the `Track` DTO (the per-playlist slot id, distinct from `ratingKey`), a `PlexClient::moveTrack()` wrapping `PUT /playlists/{id}/items/{playlistItemID}/move?after=...`, a `moveTrack()` Livewire action on `pages::playlist-detail` that translates a (dragged, target, before/after) drop into the right `after` id, and native HTML5 DnD wiring on the tracklist scroll container (Alpine `x-data`) with an insertion-line `:class` on the rows.

**Tech Stack:** Laravel 13, Livewire 4 SFC, Alpine.js, Tailwind CSS 4, Pest 4 (+ browser plugin), `Http::fake` for the Plex client.

**Spec:** `docs/superpowers/specs/2026-05-12-plextune-playlist-reorder-design.md`. Read it before starting; it carries the exact code snippets and the Plex API facts.

---

### Task 1: Add `playlistItemId` to the `Track` DTO

**Files:**
- Modify: `app/Services/Plex/Dto/Track.php`
- Test: `tests/Unit/PlexDtoTest.php` (extend)
- Modify (test fixture): `tests/Fixtures/Plex/playlist_items.json`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/PlexDtoTest.php`:

```php
it('maps playlistItemID -> playlistItemId on a Track', function () {
    $track = Track::fromPlex([
        'ratingKey' => '8001',
        'title' => 'Holocene',
        'playlistItemID' => 4242,
        'Media' => [['Part' => [['id' => 1, 'container' => 'flac']]]],
    ]);

    expect($track->playlistItemId)->toBe('4242');
});

it('leaves Track playlistItemId null when the row has no playlistItemID', function () {
    expect(Track::fromPlex([
        'ratingKey' => '8001',
        'title' => 'Holocene',
        'Media' => [['Part' => [['id' => 1, 'container' => 'flac']]]],
    ])->playlistItemId)->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="playlistItemID|playlistItemId"`
Expected: FAIL — `Track` has no `playlistItemId` property / unknown named argument.

- [ ] **Step 3: Add the property and mapping**

In `app/Services/Plex/Dto/Track.php`, add the constructor parameter after `artistId`:

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
    ) {}
```

And in `fromPlex()`, add as the last argument to `new self(...)`:

```php
            playlistItemId: isset($row['playlistItemID']) ? (string) $row['playlistItemID'] : null,
```

- [ ] **Step 4: Update the playlist-items fixture so `playlistTracks()` rows carry the slot id**

In `tests/Fixtures/Plex/playlist_items.json`, add `"playlistItemID": 9001` to the first Metadata row and `"playlistItemID": 9002` to the second (alongside `ratingKey`).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="playlistItemID|playlistItemId"`
Expected: PASS.

Also run the existing DTO + PlexClient suites to confirm the fixture change broke nothing:
Run: `php artisan test --compact --filter="PlexDtoTest|PlexClientTest"`
Expected: PASS.

- [ ] **Step 6: Clear the cache (stale serialized `Track`s lack the new property)**

Run: `php artisan cache:clear`

This is the documented gotcha: the `database` cache driver holds old serialized `Track` DTOs and PHP's `unserialize` does not run the constructor, so the first access of `playlistItemId` on a stale entry would throw "must not be accessed before initialization".

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add app/Services/Plex/Dto/Track.php tests/Unit/PlexDtoTest.php tests/Fixtures/Plex/playlist_items.json
git commit -m "feat(plex): carry playlistItemID on the Track DTO"
```

---

### Task 2: `PlexClient::moveTrack()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php` (add `moveTrack()` near `renamePlaylist`/`deletePlaylist`, around line 384–407)
- Test: `tests/Unit/PlexClientTest.php` (extend)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/PlexClientTest.php` (the `fakePlexWriteEndpoints()` helper already exists in that file and fakes `/playlists*` with a 200; it covers the `/playlists/{id}/items/{itemId}/move` URL too since it's a `/playlists*` wildcard):

```php
it('moves a playlist item after another item via PUT .../move?after=', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:playlist:4242:items', 'stale', 300);

    app(PlexClient::class)->moveTrack('4242', '9002', '9001');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/playlists/4242/items/9002/move?')
        && str_contains($request->url(), 'after=9001'));

    expect(Cache::has('plex:playlist:4242:items'))->toBeFalse();
});

it('moves a playlist item to the front via PUT .../move with no after param', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:playlist:4242:items', 'stale', 300);

    app(PlexClient::class)->moveTrack('4242', '9002', null);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/playlists/4242/items/9002/move')
        && ! str_contains($request->url(), 'after='));

    expect(Cache::has('plex:playlist:4242:items'))->toBeFalse();
});

it('maps a 500 from the move endpoint to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242/items/9002/move*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->moveTrack('4242', '9002', '9001'))->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="move a playlist item|move endpoint"`
Expected: FAIL — `moveTrack` method does not exist.

- [ ] **Step 3: Add `moveTrack()`**

In `app/Services/Plex/PlexClient.php`, add this method right after `deletePlaylist()` (before `private function putPlaylistItem`):

```php
    public function moveTrack(string $playlistId, string $playlistItemId, ?string $afterPlaylistItemId): void
    {
        $path = "/playlists/{$playlistId}/items/{$playlistItemId}/move";
        if ($afterPlaylistItemId !== null && $afterPlaylistItemId !== '') {
            $path .= '?'.http_build_query(['after' => $afterPlaylistItemId]);
        }

        try {
            $response = $this->server()->put($path);
        } catch (ConnectionException $e) {
            throw new PlexUnreachableException('Reordering playlist failed: '.$e->getMessage(), previous: $e);
        }

        $this->ensureOk($response, "PUT playlists/{$playlistId}/items/{$playlistItemId}/move");

        $this->cache->forget("playlist:{$playlistId}:items");
    }
```

(`ConnectionException` is already imported at the top of the file; `ensureOk()` and `$this->cache` are already used by the neighbouring mutations. It busts `plex:playlist:{id}:items` only — reordering doesn't change the playlist list, its `trackCount`, or its `durationMs`, so `plex:playlists` is left alone.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="move a playlist item|move endpoint"`
Expected: PASS.

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat(plex): moveTrack reorders a playlist item via the Plex move endpoint"
```

---

### Task 3: `moveTrack` Livewire action on `pages::playlist-detail`

**Files:**
- Modify: `resources/views/pages/⚡playlist-detail.blade.php` (the `<?php … ?>` component block; add the `moveTrack()` action method after `shuffle()`)
- Test: `tests/Feature/PlaylistDetailViewTest.php` (extend; the shared `samplePlaylistTracks()` fixture gains `playlistItemId` values)

- [ ] **Step 1: Give the shared fixture `playlistItemId` values, then write the failing tests**

In `tests/Feature/PlaylistDetailViewTest.php`, update `samplePlaylistTracks()` so each `Track` has a `playlistItemId`:

```php
function samplePlaylistTracks(): Collection
{
    return collect([
        new Track(id: '8001', title: 'Holocene', artist: 'Bon Iver', album: 'Bon Iver, Bon Iver', trackNumber: 6, durationMs: 337000, partId: 770001, container: 'flac', thumb: '/t/8001', albumId: '5001', artistId: '100', playlistItemId: 'i1'),
        new Track(id: '8002', title: 'Skinny Love', artist: 'Bon Iver', album: 'For Emma, Forever Ago', trackNumber: 3, durationMs: 238000, partId: 770002, container: 'flac', thumb: '/t/8002', albumId: '5002', artistId: '100', playlistItemId: 'i2'),
    ]);
}
```

Then append these tests to the same file (they need a mock that records `moveTrack` expectations, so don't reuse `mockPlexForPlaylist()` — build a local mock per test):

```php
it('moveTrack moving item 2 before item 1 calls Plex with after=null and returns true', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->once()->with('4242', 'i2', null);
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'i2', 'i1', 'before')
        ->assertReturned(true);
});

it('moveTrack moving item 1 after item 2 calls Plex with after=i2 and returns true', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->once()->with('4242', 'i1', 'i2');
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'i1', 'i2', 'after')
        ->assertReturned(true);
});

it('moveTrack on the same item is a no-op that returns true', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->never();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'i1', 'i1', 'before')
        ->assertReturned(true);
});

it('moveTrack to the position it already occupies is a no-op that returns true', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->never();
    });

    // item 1 dropped "before" item 2 — it's already immediately before item 2.
    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'i1', 'i2', 'before')
        ->assertReturned(true);
});

it('moveTrack with an unknown dragged id returns false without calling Plex', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->never();
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'nope', 'i1', 'before')
        ->assertReturned(false);
});

it('moveTrack surfaces a Plex outage as a notify toast and returns false', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([samplePlaylist()]));
        $mock->shouldReceive('playlistTracks')->with('4242')->andReturn(samplePlaylistTracks());
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('streamUrl')->andReturnUsing(fn ($t) => "https://server/{$t->partId}");
        $mock->shouldReceive('moveTrack')->once()->andThrow(new PlexUnreachableException('down'));
    });

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('moveTrack', 'i2', 'i1', 'after')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error');
});
```

(`PlexUnreachableException` is already imported in this test file.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="moveTrack"`
Expected: FAIL — `moveTrack` action does not exist on the component.

- [ ] **Step 3: Add the `moveTrack()` action**

In `resources/views/pages/⚡playlist-detail.blade.php`, inside the `class extends Component` block, add this method after `shuffle()` (and before `retry()`):

```php
    public function moveTrack(string $draggedItemId, string $targetItemId, string $position): bool
    {
        if ($draggedItemId === $targetItemId) {
            return true; // dropped on itself
        }

        $tracks = $this->tracks->values();
        $draggedIndex = $tracks->search(fn ($t) => $t->playlistItemId === $draggedItemId);
        $targetIndex = $tracks->search(fn ($t) => $t->playlistItemId === $targetItemId);

        if ($draggedIndex === false || $targetIndex === false) {
            return false;
        }

        if ($position === 'before' && $draggedIndex === $targetIndex - 1) {
            return true; // already there
        }

        if ($position === 'after' && $draggedIndex === $targetIndex + 1) {
            return true; // already there
        }

        $afterId = $position === 'after'
            ? $targetItemId
            : ($targetIndex === 0 ? null : $tracks[$targetIndex - 1]->playlistItemId);

        if ($afterId === $draggedItemId) {
            return true; // the row above the target is the dragged row — already there
        }

        try {
            $this->plex->moveTrack($this->playlist, $draggedItemId, $afterId);
        } catch (PlexException $e) {
            $this->dispatch('notify', type: 'error', message: "Couldn't reorder the playlist. ".$e->getMessage());

            return false;
        }

        unset($this->tracks);

        return true;
    }
```

(`PlexException` is already imported in this file; `$this->plex` is set in `boot()`. Reasoning: Plex's `move?after={X}` removes the item and re-inserts it right after `X`, so "insert before the target" = "move to after the item currently above the target", which is correct whether the dragged row was above or below the target. On success `unset($this->tracks)` so the re-render re-fetches the new order from Plex — the cache was just busted by `moveTrack`. On failure the cache is untouched, so the unchanged order re-renders.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="moveTrack"`
Expected: PASS (all 6).

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/⚡playlist-detail.blade.php tests/Feature/PlaylistDetailViewTest.php
git commit -m "feat(playlist): moveTrack action translates a row drop into a Plex move"
```

---

### Task 4: Drag-and-drop UI on the tracklist

**Files:**
- Modify: `resources/views/pages/⚡playlist-detail.blade.php` (the tracklist scroll container `x-data`, and both track-row `<button>` variants)
- Modify: `resources/css/app.css` (two utility classes for the insertion line)

No test in this task — Task 5 is the browser test that exercises the wiring. (The feature-test coverage of the action itself is in Task 3.)

- [ ] **Step 1: Add the insertion-line utility classes to `resources/css/app.css`**

Inside the existing `@layer base { ... }` block (after the `:disabled` rule), add:

```css
    .drop-before { box-shadow: inset 0 2px 0 var(--color-accent); }
    .drop-after  { box-shadow: inset 0 -2px 0 var(--color-accent); }
```

Box-shadow (not a border) so the insertion line causes no layout shift.

- [ ] **Step 2: Replace the tracklist scroll container's `x-data="{}"` with the DnD scope**

In `resources/views/pages/⚡playlist-detail.blade.php`, find:

```blade
                <div class="overflow-y-auto scroll flex-1 py-1" x-data="{}">
```

Replace the `x-data="{}"` with (keep the classes; this is a multi-line `x-data` — mind the quoting: the attribute value uses double quotes so all the JS strings inside use single quotes):

```blade
                <div class="overflow-y-auto scroll flex-1 py-1" x-data="{
                    draggedId: null,
                    overId: null,
                    overPos: null,
                    flash: {},
                    flashRow(id, ok) { this.flash[id] = ok ? 'ok' : 'err'; setTimeout(() => { this.flash[id] = null }, 700) },
                    onDragOver(e, id) {
                        if (!this.draggedId || id === this.draggedId) { this.overId = null; return }
                        const r = e.currentTarget.getBoundingClientRect();
                        this.overPos = (e.clientY - r.top) < r.height / 2 ? 'before' : 'after';
                        this.overId = id;
                    },
                    async onDrop(id) {
                        const dragged = this.draggedId, pos = this.overPos;
                        this.overId = null; this.draggedId = null;
                        if (!dragged || !id || id === dragged || !pos) return;
                        try { const ok = await $wire.moveTrack(dragged, id, pos); this.flashRow(dragged, ok) }
                        catch (_) { this.flashRow(dragged, false) }
                    },
                }">
```

- [ ] **Step 3: Wire the compact-variant row `<button>`**

In the `@if ($tracksCompact)` branch, the row currently is:

```blade
                            <button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')"
                                    draggable="true"
                                    ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"
                                    class="row group w-full grid items-center px-4 py-[3px] rounded text-[13px] text-left hover:bg-white/[0.07] transition-colors"
                                    style="grid-template-columns: 20px 1.4fr 1fr 1fr 50px;">
```

Replace the `ondragstart="..."` attribute with the Alpine handlers below, and add the `:class`. Final form:

```blade
                            <button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')"
                                    draggable="true"
                                    @dragstart="$event.dataTransfer.effectAllowed='copy'; $event.dataTransfer.setData('plextune/track', '{{ $track->id }}'); $event.dataTransfer.setData('plextune/playlist-item', '{{ $track->playlistItemId }}'); draggedId = '{{ $track->playlistItemId }}'"
                                    @dragend="draggedId = null; overId = null"
                                    @dragover.prevent="onDragOver($event, '{{ $track->playlistItemId }}')"
                                    @dragleave="if (overId === '{{ $track->playlistItemId }}') overId = null"
                                    @drop.prevent="onDrop('{{ $track->playlistItemId }}')"
                                    :class="{
                                        'drop-before': overId === '{{ $track->playlistItemId }}' && overPos === 'before',
                                        'drop-after': overId === '{{ $track->playlistItemId }}' && overPos === 'after',
                                        'bg-emerald-400/10 ring-1 ring-emerald-400 ring-inset': flash['{{ $track->playlistItemId }}'] === 'ok',
                                        'bg-red-400/10 ring-1 ring-red-400 ring-inset': flash['{{ $track->playlistItemId }}'] === 'err',
                                    }"
                                    class="row group w-full grid items-center px-4 py-[3px] rounded text-[13px] text-left hover:bg-white/[0.07] transition-colors"
                                    style="grid-template-columns: 20px 1.4fr 1fr 1fr 50px;">
```

(It still sets `plextune/track` so dragging the row onto a sidebar playlist / the New-playlist zone keeps working unchanged. In playlist-detail every track has a `playlistItemId`, so no null guard is needed.)

- [ ] **Step 4: Wire the comfortable-variant row `<button>`**

In the `@else` branch, the row currently is:

```blade
                            <button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')"
                                    draggable="true"
                                    ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"
                                    class="row group w-full grid items-center px-4 py-2 rounded text-[14px] text-left hover:bg-white/[0.07] transition-colors"
                                    style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">
```

Replace it with:

```blade
                            <button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')"
                                    draggable="true"
                                    @dragstart="$event.dataTransfer.effectAllowed='copy'; $event.dataTransfer.setData('plextune/track', '{{ $track->id }}'); $event.dataTransfer.setData('plextune/playlist-item', '{{ $track->playlistItemId }}'); draggedId = '{{ $track->playlistItemId }}'"
                                    @dragend="draggedId = null; overId = null"
                                    @dragover.prevent="onDragOver($event, '{{ $track->playlistItemId }}')"
                                    @dragleave="if (overId === '{{ $track->playlistItemId }}') overId = null"
                                    @drop.prevent="onDrop('{{ $track->playlistItemId }}')"
                                    :class="{
                                        'drop-before': overId === '{{ $track->playlistItemId }}' && overPos === 'before',
                                        'drop-after': overId === '{{ $track->playlistItemId }}' && overPos === 'after',
                                        'bg-emerald-400/10 ring-1 ring-emerald-400 ring-inset': flash['{{ $track->playlistItemId }}'] === 'ok',
                                        'bg-red-400/10 ring-1 ring-red-400 ring-inset': flash['{{ $track->playlistItemId }}'] === 'err',
                                    }"
                                    class="row group w-full grid items-center px-4 py-2 rounded text-[14px] text-left hover:bg-white/[0.07] transition-colors"
                                    style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">
```

- [ ] **Step 5: Build the assets and smoke-test in the browser**

Run: `npm run build`
Then open `https://plexify.test/playlist/<some-audio-playlist-id>` and confirm: dragging a row shows the green insertion line at the top/bottom of the hovered row depending on cursor position, dropping reorders and flashes the moved row green, and the order persists on reload. (The existing "drag a track onto a sidebar playlist" still works — the drag also carries `plextune/track`.)

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add resources/views/pages/⚡playlist-detail.blade.php resources/css/app.css
git commit -m "feat(playlist): drag-to-reorder UI on the playlist tracklist"
```

---

### Task 5: Browser test + final green

**Files:**
- Test: `tests/Browser/PlaylistDetailTest.php` (extend with a reorder test)

- [ ] **Step 1: Write the browser test**

Append to `tests/Browser/PlaylistDetailTest.php`. It scrapes a non-empty audio playlist from the sidebar (same pattern as the existing tests), reads the first two rows' `wire:key`s, calls `$wire.moveTrack(dragged, target, 'before')` directly via `script()` to move row 2 above row 1, asserts the first row's `wire:key` changed to the old row 2's, then performs the inverse move to restore the original order and asserts it's back — so the live playlist is left unchanged.

(The spec mentions attempting synthetic HTML5 `dragstart`/`dragover`/`drop` events first; in practice these are unreliable through Playwright/headless Chromium, and the `$wire.moveTrack` path is what the spec names as the fallback. Use the `$wire` path directly — it still exercises the Livewire action, the Plex `moveTrack` call, and the re-render. The DnD wiring itself is smoke-tested manually in Task 4 Step 5.)

```php
it('reorders a track in a playlist and restores the original order', function () {
    $page = visit('/');

    $targetHref = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                const rows = [...document.querySelectorAll('[wire\\:key^="sidebar-pl-"]')];
                if (rows.length > 0) {
                    for (const el of rows) {
                        if (!el.textContent.includes('0 songs') && !el.textContent.includes('1 songs')) {
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

    expect($targetHref)->not->toBeNull('Expected an audio playlist with at least 2 tracks in the sidebar (is the Plex server reachable?).');

    $page = visit($targetHref);

    // Wait for the tracklist to render with at least two track rows.
    expect((bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 30000;
            while (Date.now() < deadline) {
                if (document.querySelector('[wire\\:click="retry"]')) return false;
                const region = document.querySelector('[data-region="tracklist"]');
                if (region && region.querySelectorAll('[wire\\:key^="track-"]').length >= 2) return true;
                await sleep(300);
            }
            return false;
        })()
    JS))->toBeTrue('Expected at least 2 track rows on the playlist page.');

    // Move row 2 before row 1, then assert the order swapped, then restore.
    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const keys = () => [...document.querySelectorAll('[data-region="tracklist"] [wire\\:key^="track-"]')].map(el => el.getAttribute('wire:key'));
            const rows = () => [...document.querySelectorAll('[data-region="tracklist"] [wire\\:key^="track-"]')];

            // playlistItemId is encoded only in Alpine state, not the DOM; pull it off the @drop expression.
            // Easier: drive moveTrack via the dragstart attribute we set — but it's not in the DOM either.
            // So instead read the component and use the rendered order: we need playlistItemIds.
            // They are in the @dragstart attribute string: setData('plextune/playlist-item', 'XXX').
            const itemId = el => {
                const a = el.getAttribute('x-on:dragstart') || el.getAttribute('@dragstart') || '';
                const m = a.match(/plextune\/playlist-item',\s*'([^']+)'/);
                return m ? m[1] : null;
            };

            const before = keys();
            const r = rows();
            const dragged = itemId(r[1]);   // second row's playlist-item id
            const target = itemId(r[0]);    // first row's playlist-item id
            if (!dragged || !target) return { ok: false, reason: 'could not read playlist-item ids' };

            const cid = document.querySelector('[wire\\:id]')?.getAttribute('wire:id')
                ?? document.querySelector('[data-region="tracklist"]')?.closest('[wire\\:id]')?.getAttribute('wire:id');
            const comp = window.Livewire.find(
                [...document.querySelectorAll('[wire\\:id]')].map(e => e.getAttribute('wire:id'))
                    .find(id => window.Livewire.find(id)?.name?.includes('playlist-detail'))
            );
            if (!comp) return { ok: false, reason: 'no playlist-detail component' };

            await comp.call('moveTrack', dragged, target, 'before');
            // wait for re-render
            for (let i = 0; i < 50 && keys()[0] === before[0]; i++) await sleep(150);
            const swapped = keys();

            // restore: move it back after the (now) first row
            await comp.call('moveTrack', dragged, itemId(rows()[0]), 'after');
            for (let i = 0; i < 50 && keys()[0] !== before[0]; i++) await sleep(150);
            const restored = keys();

            return { ok: true, before, swapped, restored };
        })()
    JS);

    expect($result['ok'])->toBeTrue($result['reason'] ?? 'reorder script failed');
    expect($result['swapped'][0])->toBe($result['before'][1]); // old row 2 is now first
    expect($result['restored'])->toBe($result['before']);       // order restored
});
```

If `itemId(el)` returns `null` (Alpine may render the listener under a different attribute name in this build), inspect a track row's attributes in the browser devtools and adjust the `getAttribute` calls — the `plextune/playlist-item` string literal is the stable anchor.

- [ ] **Step 2: Run the browser test**

Run: `php artisan test --compact --filter="reorders a track in a playlist"`
Expected: PASS (requires the live Plex server reachable with an audio playlist of ≥2 tracks; an unreachable server is an environmental failure like the other `tests/Browser/` tests).

- [ ] **Step 3: Full suite + Pint**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan cache:clear`
Run: `php artisan test --compact`
Expected: green (the count grows by the new unit/feature/browser tests).

- [ ] **Step 4: Commit**

```bash
git add tests/Browser/PlaylistDetailTest.php
git commit -m "test(playlist): browser coverage for drag-to-reorder"
```

---

### Task 6: Update CLAUDE.md and the memory follow-ups note

**Files:**
- Modify: `CLAUDE.md` (add a paragraph recording the shipped feature, matching the style of the other "shipped" paragraphs)
- Modify: `/Users/akrzy/.claude/projects/-Users-akrzy-Dev-Herd-Plexify/memory/pending_polish_followups.md` (strike "reorder playlist tracks" off the open list) and its `MEMORY.md` index line if needed

- [ ] **Step 1: Add the CLAUDE.md paragraph**

Add after the cursor-pointer paragraph in `CLAUDE.md`, in the same terse style: a one-paragraph summary naming the spec/plan/branch, the `Track.playlistItemId` addition (+ the cache:clear gotcha), `PlexClient::moveTrack`, the `moveTrack` Livewire action, the tracklist `x-data` DnD scope + `.drop-before`/`.drop-after` CSS, that the library/search tracklists were intentionally left alone, and the tests touched. Also bump the test count in the "Git log is the authoritative record" line.

- [ ] **Step 2: Update the memory follow-ups note**

In `pending_polish_followups.md`, remove "reorder playlist tracks" from the open list (mark it shipped 2026-05-12, like the volume/links entries). Update the `MEMORY.md` one-line hook if it enumerates the open items.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(claude): record playlist track reordering"
```

(The memory files live outside the repo; the Write tool edit there isn't committed.)

---

## Self-Review

**Spec coverage:**
- `Track.playlistItemId` + `fromPlex` mapping + cache:clear → Task 1. ✓
- `PlexClient::moveTrack()` (with/without `after`, cache bust, error mapping) → Task 2. ✓
- `moveTrack` Livewire action (self-drop, already-there no-ops, unknown id → false, Plex outage → notify + false, `unset($this->tracks)` on success) → Task 3. ✓
- Tracklist `x-data` DnD scope, `@dragstart`/`@dragend`/`@dragover`/`@dragleave`/`@drop` on both row variants, `:class` insertion line + flash, `.drop-before`/`.drop-after` CSS → Task 4. ✓
- "library album tracklist and search rows keep their plain `ondragstart`, gain no reorder handlers" → not touched by any task (correctly). ✓
- Tests: unit (`PlexClientTest`, `PlexDtoTest`), feature (`PlaylistDetailViewTest`), browser (`PlaylistDetailTest`) → Tasks 1, 2, 3, 5. ✓
- `pint --dirty`, `cache:clear`, `php artisan test --compact` green → Tasks 1, 5. ✓

**Placeholder scan:** Task 5's `itemId(el)` has a stated fallback ("inspect attributes and adjust") rather than a single guaranteed selector — acceptable because the exact attribute name Alpine renders `@dragstart` under is build-dependent and can't be known without running it; the `plextune/playlist-item` string literal is the stable anchor and the test fails loudly with a reason string if it can't read the ids. No other "TBD"/"handle errors"/"similar to" placeholders.

**Type consistency:** `moveTrack(string $playlistId, string $playlistItemId, ?string $afterPlaylistItemId)` (PlexClient) ↔ `moveTrack(string $draggedItemId, string $targetItemId, string $position): bool` (Livewire) ↔ `$wire.moveTrack(dragged, id, pos)` (Blade) ↔ feature tests `->call('moveTrack', 'i2', 'i1', 'before')` and mock `->with('4242', 'i2', null)` — all consistent. `playlistItemId` (DTO property, camelCase) ↔ `playlistItemID` (Plex JSON key) — mapping is in `fromPlex`. `.drop-before`/`.drop-after` used identically in CSS and the `:class` bindings.

No gaps found.
