# Plextune — reorder playlists and folders in the left sidebar

Status: approved (2026-05-12). One of the post-M7 polish follow-ups (item 3 in the "pending polish" list).

## Goal

Make the left-sidebar playlist list user-orderable by drag and drop:

1. Reorder root (un-foldered) playlists among themselves.
2. Reorder playlists within a folder.
3. Drop a playlist at a precise position when moving it between containers (into a folder, out to root, between folders) — not just appended.
4. Reorder folders among themselves (folders aren't draggable today).

Spotify-style: grab a row (or a folder header) anywhere, a thin accent insertion line shows where it will land (above or below the row/header you're hovering, based on which half of it the cursor is over), drop to commit. Reuses the `.drop-before` / `.drop-after` insertion-line utilities already in `resources/css/app.css` (added by the playlist-track reorder feature). All ordering is app-side (Eloquent) — no Plex API calls; Plex has no concept of playlist or folder ordering.

Non-goals: no nested folders (folders only reorder among themselves; a folder dropped on a playlist row is a no-op); no keyboard reordering; the auto-expand-collapsed-folder-on-hover idea was considered and dropped (dropping a playlist on a collapsed folder header just sends it to the top of that folder); the existing context-menu "Move to {folder}" / "Remove from folder" entries stay as coarse "append/prepend to that container" operations (no positional UI in the menu).

## Current state (what exists, what's missing)

- `folders` table: `name`, `position` (unsigned int, default 0), `expanded` (bool), timestamps. `Folder` model casts `position`/`expanded`, has `folderPlaylists(): HasMany` ordered `->orderBy('position')->orderBy('id')`. `createFolder()` sets `position = max('position') + 1`. **No UI to reorder folders.**
- `folder_playlists` table: `id`, `folder_id` (`foreignId()->constrained()->cascadeOnDelete()`, **not nullable**), `plex_playlist_id` (string), `position` (unsigned int, default 0), timestamps, `unique('plex_playlist_id')`. `FolderPlaylist` model casts `folder_id`/`position`, `belongsTo(Folder::class)`. `movePlaylistToFolder($playlistId, ?int $folderId)` deletes any existing row for the playlist then (if `$folderId` is a real folder) creates a new row with `position = max('position') + 1` for that folder. **A playlist can only get a row by being put into a folder; there is no row for a root playlist, and no UI to reorder within a folder.**
- Sidebar render (`resources/views/components/⚡sidebar.blade.php`): `playlists()` computed = `PlexClient::playlists()` (Plex order; smart playlists already rejected upstream). `folders()` computed = `Folder::with('folderPlaylists')->orderBy('position')->orderBy('id')->get()`. The list partials: `$rootPlaylists = $allPlaylists->reject(fn ($p) => in folder)->values()` (so **root playlists render in raw Plex order**); folders render their `folderPlaylists` mapped back to `Playlist` DTOs via `$byId`. The reusable row is `resources/views/partials/playlist-row.blade.php`.
- DnD already present: the playlists card has one `x-data` scope (`draggingTrack`, `menu`, `dropTarget`, `flash`, plus `init()` that registers `window` `dragstart`/`dragend` listeners which set `draggingTrack` by sniffing `e.dataTransfer.types` for `plextune/track`). Track rows elsewhere are `plextune/track` drag sources; the library album cover is a `plextune/album` source. The sidebar playlist row (`partials/playlist-row.blade.php`) is a `plextune/playlist` drag source (`@dragstart="$event.dataTransfer.effectAllowed='move'; $event.dataTransfer.setData('plextune/playlist', '{{ $p->id }}')"`) and you can drop it on a folder header (`dropPlaylistOn(folderId, e)` → `$wire.movePlaylistToFolder(playlistId, folderId)`) or the "Other" header (`dropPlaylistOn(null, e)` → remove from folder). The playlist row uses `@click.prevent="Livewire.navigate(...)"` rather than `wire:navigate` because `wire:navigate` on a draggable `<a>` blocks the native `dragstart` in this Livewire build (documented gotcha). Folder headers are `<div wire:click="toggleFolder({{ $folder->id }})">` — not draggable.

## Design

### Data model — `folder_playlists.folder_id` becomes nullable

A row in `folder_playlists` is reinterpreted as **"the user's placement for this playlist"**:

- `folder_id` non-null → the playlist is in that folder, at `position` (within the folder).
- `folder_id` null → the playlist is pinned at root, at `position` (within root).
- **no row** → the playlist is an "unplaced root playlist": it renders after all *placed* root playlists, in Plex's order.

The `unique('plex_playlist_id')` index stays — a playlist is in at most one place (root or one folder), so it has at most one row.

**Migration** (`database/migrations/2026_05_12_xxxxxx_make_folder_playlists_folder_id_nullable.php`, `up()` only per project convention):

```php
public function up(): void
{
    Schema::table('folder_playlists', function (Blueprint $table) {
        $table->dropForeign(['folder_id']);
    });

    Schema::table('folder_playlists', function (Blueprint $table) {
        $table->foreignId('folder_id')->nullable()->change();
        $table->foreign('folder_id')->references('id')->on('folders')->nullOnDelete();
    });
}
```

Net behavioral change: **deleting a folder moves its playlists to root** — their `folder_playlists` rows survive with `folder_id` set to null (was: the FK cascade deleted those rows, so the playlists became "unplaced" and fell back to Plex order). The migrated rows keep their old (folder-relative) `position` values, which may briefly collide with existing root rows' positions; ties are broken by `id` in the `orderBy`, and the next root reorder densifies the whole root list. This is an accepted edge.

**Model changes:**
- `FolderPlaylist`: the `folder_id` cast stays `integer` (now nullable; Laravel returns `null` cleanly). No other change. The model keeps its name — it is now a slight misnomer for root rows (which have no folder), but renaming it to e.g. `PlaylistPlacement` (and the table to `playlist_placements`) would churn the model, the migration set, `FolderTest`, `SidebarTest`, `FolderFactory`, and every `use` import for no functional gain; the name stays and this paragraph documents the wart.
- `Folder::folderPlaylists()` is unchanged — as a `hasMany` keyed on `folder_id = this.id` it naturally excludes root rows (null `folder_id`).
- The sidebar SFC gains a way to read root placements — either a small `#[Computed] rootPlacements()` returning `FolderPlaylist::whereNull('folder_id')->orderBy('position')->orderBy('id')->get()`, or this is inlined where root playlists are assembled (implementer's choice; a computed is cleaner since the value is read in both `render` and the move actions). Folders already all have a `position` (so they are always "placed").

### Render ordering

- **Folders:** `folders()` already `->orderBy('position')->orderBy('id')` — unchanged.
- **Within a folder:** `$folder->folderPlaylists` already ordered `position` then `id` — unchanged (every foldered playlist has a row).
- **Root playlists:** replace the current "all un-foldered playlists in Plex order" with: the *placed* root playlists (those with a `folder_playlists` row, `folder_id` null) sorted by that row's `position` (ties by `id`), **followed by** the *unplaced* root playlists in `PlexClient::playlists()` order. Concretely: from `$allPlaylists` reject the ones that appear in any folder's `folderPlaylists`; of the remainder, split into "has a root placement row" (ordered by the row's position) and "no row" (Plex order), and concatenate. The filter (`?q=` sidebar filter) still applies on top, as today.

### Backend actions (the `⚡sidebar.blade.php` component class)

Two new public actions, modelled on the `moveTrack` action shipped in `feat/playlist-reorder`:

```php
public function movePlaylist(string $draggedPlaylistId, ?int $targetFolderId, ?string $targetPlaylistId, string $position): void
```

- `targetFolderId` is the destination container — a folder id, or `null` for root.
- `targetPlaylistId` + `position` (`'before'` | `'after'`) identify the sibling the dragged playlist should land next to, *within the destination container*. `targetPlaylistId === null` means "at the front (top) of the destination container" — used when a playlist is dropped onto a folder header (→ top of that folder).
- Behaviour:
  1. Resolve the destination container's current ordered list of plex playlist ids (a folder's `folderPlaylists` ids, or for root: the *placed* root ids in position order then the *unplaced* root ids in Plex order — i.e. the same ordering `render` uses), skipping any id not present in the current `PlexClient::playlists()` (orphaned rows). Remove `draggedPlaylistId` from this list if it's already there.
  2. Find the insertion index from `targetPlaylistId` + `position` (`null` target → index 0). Insert `draggedPlaylistId` there.
  3. Persist: for each id at index `i` in the resulting list, upsert its `folder_playlists` row to `{ folder_id: targetFolderId, position: i }` (create if missing). When the destination is root this materializes a row for *every* current root playlist — a one-time densification, after which root order is fully app-controlled; new Plex playlists created later (no row) keep sorting last in Plex order until the next root reorder snapshots them too.
  4. If the dragged playlist's *old* container differs from the destination (it moved out of a folder, or between folders, or from root into a folder), renumber that old container's remaining members `0..N-1` (a folder: re-`position` its `folderPlaylists`; root: re-`position` its placed root rows — unplaced root playlists are untouched).
  5. No-op short-circuits, like `moveTrack`: if `draggedPlaylistId === targetPlaylistId` (dropped on itself), return; if the computed final position equals where it already is (same container, adjacent in the requested direction), return without writing.
  6. `unset($this->folders)` and the root-placements computed cache so the re-render reflects the change.

```php
public function moveFolder(int $draggedFolderId, int $targetFolderId, string $position): void
```

- Reorders folders: build the current folder order (`Folder::orderBy('position')->orderBy('id')` ids), move `draggedFolderId` to `before`/`after` `targetFolderId`, write `position = index` for every folder. No-op if `draggedFolderId === targetFolderId` or it's already in that spot. `unset($this->folders)`.

Both actions are app-side only (Eloquent writes) — there is no external failure mode, so no `notify` error toast and no `bool` return (the existing Plex-backed DnD writes return `bool` + flash because Plex can be unreachable; these can't be). They return `void`.

The existing `movePlaylistToFolder(string $playlistId, ?int $folderId)` (used by the context-menu "Move to {folder}" / "Remove from folder" entries) stays. Optionally it can be reimplemented as a thin wrapper over `movePlaylist($playlistId, $folderId, null, 'before')` (= "to the top of that container") so the menu and the drag share one code path — the implementer decides in the plan; either way the menu entries remain coarse "move to this container" operations with no position picker.

### Drag-and-drop UI

Reuses `.drop-before { box-shadow: inset 0 2px 0 var(--color-accent) }` / `.drop-after { box-shadow: inset 0 -2px 0 var(--color-accent) }` (already in `resources/css/app.css` — no CSS change needed). Box-shadow, not a border, so the insertion line causes no layout shift.

**Playlists-card `x-data` scope** (`⚡sidebar.blade.php`) gains, alongside the existing `draggingTrack`/`menu`/`dropTarget`/`flash`:

- `draggingPlaylist: false`, `draggedPlaylistId: null` — set when a `plextune/playlist` drag is in flight. `draggingPlaylist` is set in the existing window `dragstart` listener by also sniffing `e.dataTransfer.types` for `plextune/playlist` (the listener already does this for `plextune/track`); `draggedPlaylistId` is set by the dragged row's own `@dragstart` (the window listener doesn't know which row). Both cleared in the window `dragend` listener (and on the row's `@dragend`).
- `draggingFolder: false`, `draggedFolderId: null` — same, for `plextune/folder` drags (a new drag type emitted by folder headers).
- `overId: null`, `overPos: null` — the row/header currently hovered as a reorder drop target and which half (`'before'|'after'`), exactly like the `moveTrack` scope. (`dropTarget` stays for the existing into-folder / into-root highlight; `overId`/`overPos` are the new insertion-line state. They could be unified, but keeping them separate avoids disturbing the existing folder-drop highlight logic.)
- helpers `onRowDragOver(e, id)` / `onFolderDragOver(e, id)` (compute the half via `getBoundingClientRect()`, set `overId`/`overPos`, bail if the drag isn't the right type or `id` is the dragged one), and the drop dispatchers call `$wire.movePlaylist(...)` / `$wire.moveFolder(...)` then clear `overId`/`draggedPlaylistId`/`draggedFolderId`.

**Playlist row** (`partials/playlist-row.blade.php`) — the partial gains a `$folderId` parameter (the id of the folder it's rendered inside, or `null` for root rows; the `@include`s in `⚡sidebar.blade.php` pass it). On the row's `<a>`:
- `@dragstart` additionally sets `draggingPlaylist = true; draggedPlaylistId = '{{ $p->id }}'` (it already sets the `plextune/playlist` dataTransfer; keep that — the folder-drop and "Other"-header-drop paths read it).
- `@dragend="draggingPlaylist = false; draggedPlaylistId = null; overId = null"`.
- While `draggingPlaylist` and `'{{ $p->id }}' !== draggedPlaylistId`: `@dragover.prevent="onRowDragOver($event, '{{ $p->id }}')"`, `@dragleave` (guarded with `!$event.currentTarget.contains($event.relatedTarget)` so the line doesn't flicker when the cursor crosses a child element — the lesson from the track-reorder review), `@drop.prevent` → `$wire.movePlaylist(draggedPlaylistId, {{ $folderId is null ? 'null' : $folderId }}, '{{ $p->id }}', overPos)`.
- `:class` adds `'drop-before'` / `'drop-after'` when `overId === '{{ $p->id }}'` and `overPos` matches (the existing `dropTarget`-based into-folder ring `:class` stays for `plextune/track` drops).
- It continues to ignore `plextune/folder` drags (no nesting) and keeps the existing `plextune/track` "add this song to the playlist" drop behaviour.

**Folder header** (`⚡sidebar.blade.php`, the `<div wire:click="toggleFolder(...)">`):
- becomes `draggable="true"`; `@dragstart="$event.dataTransfer.effectAllowed='move'; $event.dataTransfer.setData('plextune/folder', '{{ $folder->id }}'); draggingFolder = true; draggedFolderId = {{ $folder->id }}"`, `@dragend="draggingFolder = false; draggedFolderId = null; overId = null"`. (`wire:click="toggleFolder"` still works alongside `draggable` — a plain click toggles, a drag reorders; same as the track rows combine `wire:click="playTrack"` with `draggable`.)
- While `draggingFolder` and `{{ $folder->id }} !== draggedFolderId`: insertion-line `@dragover.prevent="onFolderDragOver($event, {{ $folder->id }})"`, guarded `@dragleave`, `@drop.prevent` → `$wire.moveFolder(draggedFolderId, {{ $folder->id }}, overPos)`, and a `.drop-before`/`.drop-after` `:class`.
- While `draggingPlaylist`: the header is a drop target meaning "into this folder at the top" — keep the existing `@dragover.prevent` highlight (`dropTarget = 'folder-{{ $folder->id }}'`), but change the `@drop` from `dropPlaylistOn({{ $folder->id }}, $event)` (which appended via `movePlaylistToFolder`) to call `$wire.movePlaylist(draggedPlaylistId, {{ $folder->id }}, null, 'before')` (no `targetPlaylistId` ⇒ top of the folder). No auto-expand.
- A folder dragged onto a playlist row → no-op (the row only reacts to `plextune/playlist`). A folder dragged onto the "New playlist" zone or the "Other" header → no-op (those only read `plextune/track` / `plextune/playlist`).

**"Other" header** (rendered only when folders exist *and* there are visible root playlists): keep its existing playlist-drop highlight; its `@drop` for a `plextune/playlist` drag = "move to root, at the **end**" → `$wire.movePlaylist(draggedPlaylistId, null, <last visible root playlist id>, 'after')` (or, if root is currently empty, `(draggedPlaylistId, null, null, 'before')`). The implementer can compute "last visible root playlist id" in Blade, or add a small `movePlaylistToRootEnd($id)` action — implementer's choice. (Reordering within root is done by dropping between two root rows, not on this header.) The empty-folder placeholder (`"Empty. Drop a playlist here."`) keeps meaning "into that folder" (only spot; position moot — `(draggedPlaylistId, thatFolderId, null, 'before')`).

**Unchanged:** the "New playlist" drop zone (`draggingTrack`-gated, `plextune/track`), dragging a track onto a playlist (`addTrackToPlaylist`), the library album → playlist drop, both context menus, the playlist row's `@click.prevent="Livewire.navigate(...)"` (kept, not `wire:navigate`).

Optional polish, left to the plan: a brief (~700ms) green flash on the moved playlist row using the existing `flash` map (no red — these moves can't fail). The insertion line plus the row visibly relocating on re-render is already adequate feedback, so the flash is nice-to-have, not required.

### Edge cases

- Drop on self, or in the position it already occupies: `movePlaylist` / `moveFolder` return without writing (and the Alpine `@drop` short-circuits the self-drop before calling `$wire`).
- Cross-folder move: the source folder is renumbered `0..N-1`; the destination folder gets the dragged row at its computed index and is renumbered.
- Move out of a folder into root: the source folder renumbered; root materialized/renumbered with the dragged playlist at its spot.
- A `folder_playlists` row whose `plex_playlist_id` no longer exists in Plex (playlist deleted in another client): already filtered out in `render` (the `$byId->get(...)` lookup returns null and `->filter()` drops it) and skipped while building the ordered list in a move action. (`deletePlaylist` in this app already deletes the row.)
- A brand-new Plex playlist with no row: sorts last among root playlists in Plex order; once you reorder root, it gets a row and a fixed position.
- Folder deleted: its playlists fall to root, keeping their rows with `folder_id` null (via `nullOnDelete`), retaining their old position numbers until the next root reorder densifies.
- `?q=` filter active: dropping is still allowed; the move action works off the *full* container order (not the filtered view), so the result is well-defined. (The insertion line only appears on rows that are currently visible, which is fine.)
- Drag a `plextune/folder` while no other folders exist, or a `plextune/playlist` with only one root playlist: no valid target, the drop is a no-op.

## Affected files (~8)

- `database/migrations/2026_05_12_xxxxxx_make_folder_playlists_folder_id_nullable.php` — new (alter `folder_id` nullable + FK `nullOnDelete()`).
- `app/Models/FolderPlaylist.php` — `folder_id` cast unchanged but now nullable in practice; no structural change (documented misnomer).
- `app/Models/Folder.php` — likely no change (the root-placements query lives in the SFC; add a scope only if it reads cleaner).
- `resources/views/components/⚡sidebar.blade.php` — `movePlaylist` + `moveFolder` actions; the root-placements computed; root render ordering; folder-header `draggable` + DnD; the `@drop` change on the folder header and the "Other" header; the new `x-data` scope state (`draggingPlaylist`/`draggingFolder`/`draggedPlaylistId`/`draggedFolderId`/`overId`/`overPos` + helpers); pass `$folderId` into the playlist-row `@include`s.
- `resources/views/partials/playlist-row.blade.php` — `$folderId` param; reorder `@dragstart`/`@dragend`/`@dragover`/`@dragleave`/`@drop` and the `.drop-before`/`.drop-after` `:class`.
- `resources/css/app.css` — no change (`.drop-before` / `.drop-after` already exist).
- `tests/Feature/SidebarTest.php` — extend (the move actions + the new root ordering; mock `PlexClient::playlists()` with a known list, the rest is DB).
- `tests/Feature/FolderTest.php` — extend (deleting a folder leaves its members as root `folder_playlists` rows; the nullable column).
- `tests/Browser/SidebarFoldersTest.php` — extend (reorder two root playlists by driving `$wire.movePlaylist(...)` via `script()`, assert the rendered `[wire:key^="sidebar-pl-"]` order changed, then restore — the same pattern as `tests/Browser/PlaylistDetailTest.php`'s reorder test; live-Plex / env-dependent like the other browser tests; `RefreshDatabase` keeps `folder_playlists` clean per test).

## Testing

- **Feature — `tests/Feature/SidebarTest.php`** (extend; `uses(RefreshDatabase::class)` is already required for sidebar tests). Mock `PlexClient` with a deterministic `playlists()` (say four playlists `p1..p4`, none smart) plus `ping()` (the nested `<livewire:server-chip />` calls it) and `thumbUrl()`:
  - `render` orders root playlists as: placed (by `folder_playlists.position`) then unplaced (Plex order). E.g. create a root placement row for `p3` at position 0 → the rendered root order starts `p3`, then `p1`, `p2`, `p4`.
  - `->call('movePlaylist', 'p4', null, 'p1', 'before')` → `folder_playlists` now has rows for all four root playlists; `p4` is positioned immediately before `p1`; the rendered order reflects it.
  - `->call('movePlaylist', 'p1', $folder->id, null, 'before')` (with a folder containing `p2` at position 0) → `p1` gets a row with `folder_id = $folder->id, position = 0`; `p2` is bumped to position 1; root no longer lists `p1`.
  - `->call('movePlaylist', 'p2', $folderB->id, 'pX', 'after')` where `p2` was in `$folderA` → `$folderA` renumbered without `p2`; `p2` now in `$folderB` after `pX`.
  - `->call('movePlaylist', 'p1', null, 'p1', 'before')` → no-op (dropped on itself); `folder_playlists` unchanged.
  - `->call('movePlaylist', 'p1', null, 'p2', 'before')` when `p1` is already immediately before `p2` in root → no-op.
  - `->call('moveFolder', $f2->id, $f1->id, 'before')` (folders `f1` pos 0, `f2` pos 1, `f3` pos 2) → folder order becomes `f2, f1, f3` with dense positions.
  - `->call('moveFolder', $f1->id, $f1->id, 'after')` → no-op.
- **Feature — `tests/Feature/FolderTest.php`** (extend): create a folder with two `folder_playlists` rows, `deleteFolder($id)` → the folder is gone but the two rows survive with `folder_id === null` (they became root rows). (Confirms the `nullOnDelete` FK / nullable column.)
- **Browser — `tests/Browser/SidebarFoldersTest.php`** (extend): on the live sidebar (requires ≥2 Plex playlists), read the first two `[wire:key^="sidebar-pl-"]` rows' keys, find the *sidebar* Livewire component id via the `[wire:id]` element wrapping the playlists card, `await comp.call('movePlaylist', <secondId>, null, <firstId>, 'before')` via `script()`, wait for the re-render, assert the first rendered sidebar playlist key is now the old second one, then `comp.call('movePlaylist', <secondId>, null, <whatever is now second>, 'after')` to restore and assert. (Synthetic HTML5 DnD through Playwright is unreliable — calling the action directly still exercises the action, the DB writes, and the re-render; the DnD wiring is smoke-tested manually. `RefreshDatabase` is already used by `SidebarFoldersTest`, so the `folder_playlists` table is clean per run; the restore step is belt-and-braces.)

Plus `vendor/bin/pint --dirty --format agent`, `php artisan migrate` against the dev DB, and `php artisan test --compact` green at the end. (No `php artisan cache:clear` needed — no cached-DTO change.)

## Self-review notes

- Placeholder scan: none.
- Internal consistency: the `movePlaylist(draggedPlaylistId, ?targetFolderId, ?targetPlaylistId, position)` shape, the `moveFolder(draggedFolderId, targetFolderId, position)` shape, the Blade `@drop` → `$wire.movePlaylist(...)` / `$wire.moveFolder(...)` call sites (row reorder, folder-header "into folder at top", "Other" header "to root at end", folder reorder), and the feature-test calls all use the same argument order. The `targetPlaylistId === null ⇒ top of container` convention is used uniformly (folder-header drop, empty-folder placeholder).
- Internal consistency 2: render ordering (placed root by position, then unplaced by Plex order) and `movePlaylist`'s "resolve the destination container's current ordered list" use the *same* root-ordering rule — stated once, referenced.
- Scope: one feature, ~8 files; comparable to the M5 folder feature; fits a single plan.
- Ambiguity check: "drop a playlist on a folder header" is pinned to "top of that folder, no auto-expand"; "drop a playlist on the Other header" is pinned to "root, at the end"; "reorder within root" is pinned to "drop between two root rows" (not the Other header). The one place with implementer latitude is whether `movePlaylistToFolder` is reimplemented over `movePlaylist` and whether "Other"-header-drop uses a Blade-computed last-id or a dedicated tiny action — both are local choices that don't affect behavior.
- One risk worth calling out for the plan: the folder header gaining `draggable="true"` while it already has `wire:click="toggleFolder"`. The track-reorder feature proved `draggable` + `wire:click` coexist on the same element (the track rows do `wire:click="playTrack"` + `draggable`), so this is expected to work; the plan should verify it (a click still toggles, a drag still reorders).
- Another risk: the `folder_id` nullable migration uses drop-FK / `->change()` / re-add-FK. Laravel 11+ supports `->change()` natively (no doctrine/dbal). On MySQL (this project's DB, via DBngin) `dropForeign(['folder_id'])` then re-adding works; the plan should run `php artisan migrate` and a quick `php artisan migrate:rollback`/re-migrate to confirm (the project writes `up()`-only migrations, so "rollback" means `migrate:fresh` in practice — the plan can just run `migrate:fresh` then the test suite).
