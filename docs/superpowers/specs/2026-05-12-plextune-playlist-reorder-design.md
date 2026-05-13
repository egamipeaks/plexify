# Plextune — reorder tracks within a playlist

Status: approved (2026-05-12). One of the post-M7 polish follow-ups (item 1 in the "pending polish" list).

## Goal

Let the user drag a track row up or down in the playlist-detail tracklist to change its position, persisted to the Plex playlist. Spotify-style: grab a row anywhere, a thin accent insertion line shows where it will land (above or below the row you're hovering, based on which half of that row the cursor is over), drop to commit. Works in both tracklist density variants (compact and comfortable). The playlist-detail rows are already `draggable="true"` (that powers the existing "drag a track onto a sidebar playlist to add it" feature); this adds drop handling on the rows themselves and the server-side move.

Non-goals: no reorder in the library album tracklist (Plex albums are not user-orderable) or in search; no keyboard reordering; no multi-select reorder (Plex's move API is one item at a time); no "drag a library track into a playlist-detail row to insert it there" (that's a separate feature — the row drop targets only handle items already in this playlist). Smart playlists are already hidden app-wide, so they're not a concern.

## Plex API facts

- `GET /playlists/{id}/items` returns `MediaContainer.Metadata[]` where each row has both `ratingKey` (the track's library id, what we already use as `Track->id`) and `playlistItemID` (the id of *this item's slot in this playlist* — distinct, and what the move endpoint takes).
- `PUT /playlists/{playlistId}/items/{playlistItemID}/move` reorders. With `?after={otherPlaylistItemID}` the item is moved to immediately after that item; with no `after` query param it moves to the front. The track's `ratingKey` is not involved in the move call — only `playlistItemID`s.
- The move is applied server-side immediately; a 2xx means it happened.

## Affected files

- `app/Services/Plex/Dto/Track.php` — add a nullable `playlistItemId`.
- `app/Services/Plex/PlexClient.php` — add `moveTrack()`.
- `resources/views/pages/⚡playlist-detail.blade.php` — the component class gains a `moveTrack()` action; the tracklist `<div x-data="{}">` gains real DnD state; both row variants gain drop handlers and an insertion-line `:class`.
- `resources/css/app.css` — two tiny utility classes for the insertion line.
- Tests: `tests/Unit/PlexClientTest.php`, `tests/Unit/PlexDtoTest.php`, `tests/Feature/PlaylistDetailViewTest.php`, `tests/Browser/PlaylistDetailTest.php` (all extended).

## Design

### Data layer

**`Track` DTO** (`app/Services/Plex/Dto/Track.php`). Add `public ?string $playlistItemId = null` as the last constructor parameter (after `artistId`). In `fromPlex()`: `playlistItemId: isset($row['playlistItemID']) ? (string) $row['playlistItemID'] : null`. Library/search/recently-played track rows have no `playlistItemID`, so it's null for them — only `playlistTracks()` rows carry it. `Track` is stored in the cache (`PlexCache`), and `config/cache.php`'s `serializable_classes` allowlist already lists `Track::class`, so no config change — but because old serialized `Track`s persist in the `database` cache driver and PHP's `unserialize` does not run the constructor, **the implementation must `php artisan cache:clear`** after adding the property (otherwise the first access of `playlistItemId` on a stale cached DTO throws "must not be accessed before initialization"). This is the documented gotcha for adding a property to a cached DTO.

**`PlexClient::moveTrack(string $playlistId, string $playlistItemId, ?string $afterPlaylistItemId): void`** (`app/Services/Plex/PlexClient.php`). Mirrors the shape of the other playlist mutations (`renamePlaylist`, `deletePlaylist`, `putPlaylistItem`):

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

It busts `plex:playlist:{playlistId}:items` only — reordering doesn't change the playlist list, its `trackCount`, or its `durationMs`, so `plex:playlists` is left alone. On failure (exception or non-2xx) nothing is busted, so the cached pre-move order remains correct and the page re-renders it unchanged.

### The `moveTrack` Livewire action

In the `pages::playlist-detail` component class (the `<?php … ?>` block at the top of `resources/views/pages/⚡playlist-detail.blade.php`, which already has `protected PlexClient $plex` set in `boot()`):

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

    // No-op if it would land where it already is.
    if ($position === 'before' && $draggedIndex === $targetIndex - 1) {
        return true;
    }
    if ($position === 'after' && $draggedIndex === $targetIndex + 1) {
        return true;
    }

    // The playlistItemId the dragged row should end up immediately after (null = move to front).
    $afterId = $position === 'after'
        ? $targetItemId
        : ($targetIndex === 0 ? null : $tracks[$targetIndex - 1]->playlistItemId);

    if ($afterId === $draggedItemId) {
        return true; // already there
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

Reasoning notes:
- It works off `$this->tracks` (the authoritative current order from the most recent fetch). Plex's `move?after={X}` removes the item and re-inserts it right after `X`, so "insert before the target" is expressed as "move to after the item currently above the target" — correct whether the dragged row was above or below the target. The `$afterId === $draggedItemId` guard catches the case where the item above the target *is* the dragged row (it's already there).
- Returns `bool` (true = applied or no-op, false = couldn't). Throwing is reserved for genuinely unexpected states; a Plex outage is surfaced as a `notify` error toast (the M7 toast component listens for that browser event) and `false`, so the Alpine `@drop` handler flashes red without tripping Livewire's error modal — the same pattern as `addTrackToPlaylist` / `createPlaylistFromTrack` / `movePlaylistToFolder` in the sidebar.
- On success it `unset($this->tracks)` so the re-render re-evaluates the computed and (since `PlexClient::moveTrack` busted the 5-minute cache) re-fetches the new order from Plex. On failure it leaves the cache alone, so the unchanged order re-renders.
- `retry()` already does `unset($this->playlistMeta, $this->tracks)`; no change needed there.

### The drag-and-drop UI

The tracklist scroll container — currently `<div class="overflow-y-auto scroll flex-1 py-1" x-data="{}">` — gets a real Alpine scope (modeled on the sidebar's DnD `x-data`):

```js
x-data="{
    draggedId: null,    // playlistItemId of the row being dragged within this list
    overId: null,       // playlistItemId of the row currently hovered as a drop target
    overPos: null,      // 'before' | 'after'
    flash: {},          // playlistItemId -> 'ok' | 'err' (cleared after ~700ms)
    flashRow(id, ok) { this.flash[id] = ok ? 'ok' : 'err'; setTimeout(() => { this.flash[id] = null; }, 700); },
    onDragOver(e, id) {
        if (!this.draggedId || id === this.draggedId) { this.overId = null; return; }
        const r = e.currentTarget.getBoundingClientRect();
        this.overPos = (e.clientY - r.top) < r.height / 2 ? 'before' : 'after';
        this.overId = id;
    },
    async onDrop(id) {
        const dragged = this.draggedId, pos = this.overPos;
        this.overId = null; this.draggedId = null;
        if (!dragged || !id || id === dragged || !pos) return;
        try { const ok = await $wire.moveTrack(dragged, id, pos); this.flashRow(dragged, ok); }
        catch (_) { this.flashRow(dragged, false); }
    },
}"
```

`$wire` is available inside this scope (it's the Livewire-provided Alpine magic, usable anywhere within the component's DOM).

Each track row (the `<button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')" draggable="true" …>` in both the `@if ($tracksCompact)` and `@else` branches):
- Its current `ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"` HTML attribute is replaced by an Alpine `@dragstart` (the row sits inside the `x-data` scope, so Alpine handles it):
  ```
  @dragstart="$event.dataTransfer.effectAllowed='copy'; $event.dataTransfer.setData('plextune/track', '{{ $track->id }}'); $event.dataTransfer.setData('plextune/playlist-item', '{{ $track->playlistItemId }}'); draggedId = '{{ $track->playlistItemId }}'"
  ```
  It still sets `plextune/track` (so dragging the row onto a sidebar playlist / the New-playlist zone keeps working unchanged — the sidebar's `window` `dragstart` listener checks `types` includes `plextune/track`), and additionally sets `plextune/playlist-item` and the `draggedId` Alpine state. In playlist-detail every track has a `playlistItemId`, so no null guard is needed in this template.
- Gains `@dragend="draggedId = null; overId = null"`, `@dragover.prevent="onDragOver($event, '{{ $track->playlistItemId }}')"`, `@dragleave="if (overId === '{{ $track->playlistItemId }}') overId = null"`, `@drop.prevent="onDrop('{{ $track->playlistItemId }}')"`.
- Gains a dynamic `:class` (the row currently has no `:class` of its own — only its title `<div>` does):
  ```
  :class="{
      'drop-before': overId === '{{ $track->playlistItemId }}' && overPos === 'before',
      'drop-after': overId === '{{ $track->playlistItemId }}' && overPos === 'after',
      'bg-emerald-400/10 ring-1 ring-emerald-400 ring-inset': flash['{{ $track->playlistItemId }}'] === 'ok',
      'bg-red-400/10 ring-1 ring-red-400 ring-inset': flash['{{ $track->playlistItemId }}'] === 'err',
  }"
  ```
  `.drop-before` / `.drop-after` are two new utility classes in `resources/css/app.css`:
  ```css
  .drop-before { box-shadow: inset 0 2px 0 var(--color-accent); }
  .drop-after  { box-shadow: inset 0 -2px 0 var(--color-accent); }
  ```
  Box-shadow (not a border) so the insertion line causes no layout shift. The ~700ms green/red flash matches the sidebar's "flash, not a toast" convention for DnD outcomes (the `notify` error toast from `moveTrack` is the *additional* surfacing of a Plex outage, same as the sidebar's catch blocks dispatch `notify`).

  (`ring-inset` is added to the flash classes so the ring sits inside the row's box rather than overlapping neighbors; if that reads oddly in practice the implementer may drop `ring-inset` and just use the sidebar's exact `ring-1 ring-emerald-400` form — cosmetic.)

Nothing else changes: the library album tracklist and search rows keep their plain `ondragstart` and gain no reorder handlers. The header Play/Shuffle buttons, the artist/album links, the playing-row highlight, the collapse toggle — all untouched.

### Edge cases

- Empty playlist or a one-track playlist: nothing to drop onto / no other position; the drop handlers no-op.
- Dropping a row on itself, or in a spot it already occupies: `moveTrack` returns `true` without calling Plex (and `onDrop` short-circuits the self-drop case before even calling `$wire`).
- A drag that originated outside this tracklist (e.g. from the library): it has no `plextune/playlist-item` in its dataTransfer and never set `draggedId` in this scope, so `onDragOver`/`onDrop` see `draggedId === null` and do nothing — the row is not a drop target for foreign drags.
- Plex unreachable during the move: red flash on the dragged row + a `notify` error toast; the page re-renders the unchanged order (cache wasn't busted).
- A genuinely stale `targetItemId`/`draggedItemId` (shouldn't happen — the page just rendered them): `moveTrack` returns `false` → red flash, no toast.

## Testing

- **Unit — `tests/Unit/PlexClientTest.php`** (extend, `Http::fake`): `moveTrack($id, $itemId, $afterId)` issues `PUT {server}/playlists/{id}/items/{itemId}/move?after={afterId}` (assert the URL and method); `moveTrack($id, $itemId, null)` issues `PUT …/move` with no `after` query; both forget the `plex:playlist:{id}:items` cache key (assert via the cache, or via a follow-up `playlistTracks()` re-hitting Plex); a non-2xx response throws. Also: `Track::fromPlex` (via the existing DTO test, see next) is the place to assert `playlistItemID` mapping — but if `PlexClientTest`'s `playlistTracks` fake fixtures need a `playlistItemID` field to keep other assertions meaningful, add it there too.
- **Unit — `tests/Unit/PlexDtoTest.php`** (extend): `Track::fromPlex(['ratingKey' => …, 'playlistItemID' => 4242, …])` → `playlistItemId === '4242'`; `Track::fromPlex` on a row with no `playlistItemID` → `playlistItemId === null`.
- **Feature — `tests/Feature/PlaylistDetailViewTest.php`** (extend): the shared `samplePlaylistTracks()` fixture's `Track`s gain `playlistItemId` values (e.g. `'i1'`, `'i2'`). Then `Livewire::test('pages::playlist-detail', ['playlist' => '4242'])`:
  - `->call('moveTrack', 'i2', 'i1', 'before')` with a `PlexClient` mock expecting `moveTrack('4242', 'i2', null)` (moving item 2 before item 1 = to the front) → returns `true`.
  - `->call('moveTrack', 'i1', 'i2', 'after')` expecting `moveTrack('4242', 'i1', 'i2')` → returns `true`.
  - `->call('moveTrack', 'i1', 'i1', 'before')` → returns `true`, `moveTrack` on the mock is `never()` called.
  - `->call('moveTrack', 'i1', 'i2', 'before')` (item 1 dropped before item 2 = already there) → returns `true`, mock `never()` called.
  - `->call('moveTrack', 'nope', 'i1', 'before')` → returns `false`, mock `never()` called.
  - With the mock's `moveTrack` throwing a `PlexUnreachableException`: `->call('moveTrack', 'i2', 'i1', 'after')->assertReturned(false)->assertDispatched('notify', type: 'error')`.
- **Browser — `tests/Browser/PlaylistDetailTest.php`** (extend): on a real audio playlist with ≥2 tracks (reuse the existing test's sidebar-scrape-then-visit), read the first two rows' `wire:key`s, simulate a drag of row 2 onto the top half of row 1 by dispatching synthetic `dragstart` → `dragover` → `drop` events with a shared `DataTransfer` in `script()`, wait for the re-render, assert the first row's `wire:key` is now the old row 2's, then perform the inverse move to restore the original order and assert it's back. If synthetic HTML5 drag events prove unreliable through Playwright/the Pest browser plugin, the fallback is to call `$wire.moveTrack(dragged, target, pos)` directly via `script()` (`window.Livewire.find(componentId).call(...)`), assert the re-render, and likewise restore. Either way the test leaves the live playlist's order unchanged. (Browser tests hit the live Plex server; an unreachable server is an environmental failure, like the other `tests/Browser/` tests.)

Plus `vendor/bin/pint --dirty --format agent`, `php artisan cache:clear` (because of the new `Track` property), and `php artisan test --compact` green at the end.

## Self-review notes

- Placeholder scan: none.
- Internal consistency: the `moveTrack` action's `afterId` logic, the `PlexClient::moveTrack` signature, the Blade `@drop` → `$wire.moveTrack(draggedId, id, pos)` call, and the feature-test expectations all use the same `(draggedItemId, targetItemId, position)` / `(playlistId, playlistItemId, afterPlaylistItemId)` shapes.
- Scope: one feature, ~5 files; fits a single plan.
- Ambiguity: "insert before the target" is defined precisely as "Plex `move?after={item above the target}`", with the first-row case = `move` with no `after`. The browser test's synthetic-DnD-vs-`$wire`-fallback is the one place with a stated fallback rather than a single path — acceptable because true HTML5 DnD simulation in headless browsers is genuinely unreliable, and either path leaves the live data restored; the plan should pick one to attempt first (synthetic events) and only fall back if it flakes.
- One risk worth calling out for the plan: replacing `ondragstart` with `@dragstart` on the rows. The codebase's gotcha note says playlist-detail rows use plain `ondragstart` "because their page roots have no `x-data`" — but the tracklist *scroll container* does have `x-data="{}"`, and the rows are its descendants, so Alpine `@dragstart` on them is fine (and is what makes setting `draggedId` possible). The plan should verify this works (the existing `@click.stop` on the artist/album spans inside the same scope already proves Alpine directives bind on these rows).
