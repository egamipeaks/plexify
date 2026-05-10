# Plextune Milestone 5: Folders + Drag & Drop — Design

**Date:** 2026-05-10
**Status:** Approved
**Parent spec:** `docs/superpowers/specs/2026-05-09-plextune-design.md`
**Visual reference:** `design/README.md` ("Folder UX" + "Drag-and-drop summary" sections), `design/plextune-parts.jsx` (`FolderedPlaylists`, `PlaylistRow`, `ContextMenu`), `design/rendered/your-library.html` (sidebar DOM).
**Prior milestone:** `docs/superpowers/plans/2026-05-10-plextune-milestone-4-playlist-detail.md`

## Goal

Turn the flat sidebar playlist list into app-side folders, and add native HTML5 drag-and-drop: drag playlists between folders, drag a track row or an album cover onto a playlist to add it, drag a track onto a "New playlist" drop zone to create a playlist seeded with that track. Folders are pure Eloquent (Plex has no server-side folders). Also bring the sidebar's playlist write surface up to full CRUD against Plex: create (via the drop zone), rename (write-through), delete. Context menus on folders and playlist rows. Filter input wired client-side.

End state: you can organize your Plex playlists into collapsible folders that survive a browser reset, drag songs/albums onto playlists, create a playlist by dragging a song into the sidebar, and rename/delete playlists from the sidebar.

## Decisions (from brainstorming)

| Decision | Choice | Notes |
|---|---|---|
| Plex playlist writes in M5 | Full CRUD: create, rename (write-through), delete, plus add-track / add-album | Rename also reflects on `/playlist/{id}` since `plex:playlists` cache is busted. |
| "New playlist" creation | "New playlist" drop zone — Plex can't create an empty playlist, so the seed item *is* a dragged track | A track drag in progress reveals a "＋ New playlist" target below the playlist list; dropping a track there calls `POST /playlists?type=audio&title=New Playlist&smart=0&uri={track}`, then the new row enters inline rename. The sidebar's `+` header button is decorative in M5 (kept for visual fidelity; `title` hints the drag flow). |
| Playlist-row inline rename | Included, write-through to Plex (`PUT /playlists/{id}?title=`) | Reconciles the two answers ("Full CRUD" wins for the rename piece). |
| Context-menu playback actions | "Play" only | Loads the playlist's tracks server-side and `$dispatch('play-track', trackId: first, queue: [...])`, same shape as the detail page's Play button. "Add to queue" deferred to M7 (needs real queue-append plumbing). |
| Folder–playlist association storage | `FolderPlaylist` pivot model (as in the parent spec) | `plex_playlist_id` is a string column, not an FK (Plex playlists aren't in our DB). `position` is insert order. |
| DnD feedback | Inline flash on the target row (green = added, red = failed) + drop-target highlight while dragging over | No global toast system in M5; that's M7. Matches the prototype's "visual highlight only". |
| `PlaylistOverride` model | Not built | Rename writes through to Plex instead. |

## Data Model

**`Folder`** (`folders` table, migration `up()` only):
- `id`
- `name` (string)
- `position` (unsignedInteger — append order; no reorder UI in M5)
- `expanded` (boolean, default `true`)
- `timestamps`

Factory + states as useful for tests.

**`FolderPlaylist`** (`folder_playlists` table):
- `id`
- `folder_id` (FK → `folders.id`, `cascadeOnDelete`)
- `plex_playlist_id` (string)
- `position` (unsignedInteger — insert order within the folder)
- `timestamps`

Relationships: `Folder hasMany folderPlaylists`. A playlist belongs to at most one folder (moving re-points / replaces its single row). Deleting a `Folder` cascades its `FolderPlaylist` rows; those playlists then render under "Other" because they're no longer referenced anywhere.

## PlexClient additions (`app/Services/Plex/PlexClient.php`)

Plex item URIs for playlist mutations have the form
`server://{machineIdentifier}/com.plexapp.plugins.library/library/metadata/{ratingKey}`.

- `machineIdentifier(): string` — cached (`plex:machine_identifier`, `TTL_RESOURCES`); reads `MediaContainer.machineIdentifier` from `GET /identity` (`ping()` already fetches this).
- `addTrackToPlaylist(string $playlistId, string $trackId): void` — `PUT /playlists/{playlistId}/items?uri={trackUri}`.
- `addAlbumToPlaylist(string $playlistId, string $albumId): void` — `PUT /playlists/{playlistId}/items?uri={albumUri}` (Plex expands the album to its tracks).
- `createPlaylist(string $title, string $seedTrackId): string` — `POST /playlists?type=audio&title={title}&smart=0&uri={trackUri}`; returns the new playlist's `ratingKey` from the response.
- `renamePlaylist(string $playlistId, string $title): void` — `PUT /playlists/{playlistId}?title={title}`.
- `deletePlaylist(string $playlistId): void` — `DELETE /playlists/{playlistId}`.
- Every mutating method busts the `plex:playlists` cache key on success (and `addTrackToPlaylist` / `addAlbumToPlaylist` / `createPlaylist` should also forget `plex:playlist_items:{id}` for the affected playlist if such a key exists) so the sidebar counts and `/playlist/{id}` reflect the change without waiting out the 5-minute TTL.
- Status-code → exception mapping consistent with the existing methods (404 → `PlexNotFoundException`, connection/5xx → `PlexUnreachableException`, 401/403 → `PlexAuthException`). `PendingRequest::throw()` is still not used (this Laravel version's HTTP client doesn't auto-throw; inspect `$response->status()`).

## Sidebar (`resources/views/components/⚡sidebar.blade.php`)

Stays a Livewire 4 SFC with `boot(PlexClient $plex)` + `protected PlexClient $plex`. Single stable root `<aside>` (the Livewire-4 one-root rule).

State:
- `#[Computed] playlists()` — unchanged from M4 (returns `Collection<Playlist>`, swallows `PlexException` to `collect()`).
- `#[Computed] folders()` — `Folder::with('folderPlaylists')->orderBy('position')->get()`.
- public `$renamingFolderId` / `$renamingPlaylistId` (transient inline-edit state). Whether a track drag is in progress is tracked Alpine-side (a `dragstart`/`dragend` listener checking `e.dataTransfer.types`), not on a Livewire prop.

Render inside the playlists card, in order:
1. **Folders** (each): a header row — rotating chevron (rotated when `expanded`), folder icon, name (or an inline `<input>` when `$renamingFolderId === id`), right-aligned item count. The header is a `plextune/playlist` drop target. When `expanded`, an indented (`ml-3 pl-2 border-l border-white/10`) list of that folder's playlist rows; if the folder is empty, an italic "Empty — drop a playlist here" placeholder instead.
2. **"Other" group** — rendered only when at least one folder exists: a small uppercase "Other" label, then the playlists not referenced by any `FolderPlaylist` row. The whole region is a `plextune/playlist` drop target whose drop moves the playlist out to root (deletes its pivot row).
3. **No folders at all** → just the flat playlist list, exactly as M4 renders it today.
4. The **"New playlist" drop zone** — a "＋ New playlist" target rendered below the playlist list, visible only while a `plextune/track` drag is in progress (revealed by the Alpine drag-state listener). Dropping a track on it creates the playlist.

Playlist rows keep their M4 markup (thumbnail/title/"Playlist · N songs" + `wire:navigate` to `/playlist/{id}`, active state when on that route), plus: `draggable="true"` with `@dragstart` setting `plextune/playlist`, an `@contextmenu.prevent` opening the playlist context menu, and an inline `<input>` swap when `$renamingPlaylistId === id`.

Card-header controls:
- **New folder** button → `createFolder()`.
- **New playlist** (`+`) button → decorative in M5 (kept for visual fidelity; `title="Drag a song here to create a playlist"`). No click handler — playlist creation is the drop zone.
- **Filter input** (the magnifier in the header is replaced/expanded into a real text input) → client-side Alpine filter over the rendered rows; no server round-trip. Filtering hides non-matching playlist rows; folders with no visible children collapse to just their header (or hide — implementer's call, match `your-library.html` if it shows one).
- **"Recents ⌄"** sort button — stays inert (M7).

Livewire actions:
- `createFolder(): void` — insert `Folder` with `name: 'New Folder'`, next `position`, `expanded: true`; set `$renamingFolderId` to it.
- `renameFolder(int $id, string $name): void` — trim; ignore empty (keep old name); save; clear `$renamingFolderId`.
- `deleteFolder(int $id): void` — delete (cascades pivots).
- `toggleFolder(int $id): void` — flip and persist `expanded`.
- `movePlaylistToFolder(string $playlistId, ?int $folderId): void` — delete any existing `FolderPlaylist` row for that playlist; if `$folderId` is non-null, create a new row with the next `position` in that folder. (`null` = move to root.)
- `addTrackToPlaylist(string $playlistId, string $trackId): void` — calls `PlexClient::addTrackToPlaylist`; catches `PlexException`, logs to `plex` channel; the caller (Alpine) flashes the row.
- `addAlbumToPlaylist(string $playlistId, string $albumId): void` — likewise via `PlexClient::addAlbumToPlaylist`.
- `createPlaylistFromTrack(string $trackId): void` — `PlexClient::createPlaylist('New Playlist', $trackId)`; on success set `$renamingPlaylistId` to the returned rating key so the new row (now visible because `plex:playlists` was busted) opens in inline rename; catch + log.
- `renamePlaylist(string $playlistId, string $name): void` — trim; ignore empty; `PlexClient::renamePlaylist`; clear `$renamingPlaylistId`; catch + log.
- `deletePlaylist(string $playlistId): void` — `PlexClient::deletePlaylist`; also delete any `FolderPlaylist` row for it; catch + log.
- `playPlaylist(string $playlistId): void` — `PlexClient::playlistTracks($playlistId)`; `$dispatch('play-track', trackId: first->ratingKey, queue: <serialized track list>)` (same payload shape the detail page already dispatches; no-op if empty).

## Drag & Drop

Native HTML5 DnD; Alpine supplies `@dragstart` / `@dragover.prevent` / `@dragleave` / `@drop`. No `wire:sort` (these aren't sortable lists — they're "drop item X onto target Y"). No cross-component Livewire events: the drop always lands on the sidebar's own DOM, so the sidebar's `$wire` handles it; the payload travels in `dataTransfer`.

| Source — adds `draggable="true"` + `@dragstart` | `dataTransfer` key → value | Drop target(s) |
|---|---|---|
| Track row — **library** tracklist (`pages/⚡library.blade.php`), **playlist-detail** tracklist (`pages/⚡playlist-detail.blade.php`), **search** song rows (`pages/⚡search.blade.php`) | `plextune/track` → track rating key | playlist row, "New playlist" drop zone |
| Album cover — **library** album header, both expanded and collapsed states | `plextune/album` → album rating key | playlist row |
| Playlist row — sidebar | `plextune/playlist` → Plex playlist id | folder header, "Other" zone |

Source side: just the `draggable` attribute and a one-line `@dragstart` setting the MIME data and `effectAllowed`. No Livewire needed on the source.

Drag-state detection: the sidebar registers `window` `dragstart`/`dragend` listeners; on `dragstart` it checks `e.dataTransfer.types.includes('plextune/track')` and, if so, sets an Alpine flag that reveals the "New playlist" drop zone (and any other track-only affordances). `dragend` clears it. (The actual track id isn't readable during `dragover`, only the type list — which is enough to decide whether to show the zone.)

Drop target side:
- `@dragover.prevent` (also toggles a `bg-accent/15 ring-1 ring-accent/40` highlight on the target), `@dragleave` clears it, `@drop` clears it and dispatches:
  - playlist row receiving `plextune/track` → `$wire.addTrackToPlaylist(playlistId, trackId)`; receiving `plextune/album` → `$wire.addAlbumToPlaylist(playlistId, albumId)`.
  - "New playlist" drop zone receiving `plextune/track` → `$wire.createPlaylistFromTrack(trackId)`.
  - folder header receiving `plextune/playlist` → `$wire.movePlaylistToFolder(playlistId, folderId)`.
  - "Other" zone receiving `plextune/playlist` → `$wire.movePlaylistToFolder(playlistId, null)`.
- After a `$wire` call resolves: flash the target (the row, or the drop zone) green (success) or red (the action threw / Livewire error) for ~600ms, then return to normal. The sidebar re-renders from the busted cache so playlist counts update and a newly created playlist appears.

## Context Menus

Ported from the prototype's `ContextMenu` — an Alpine-driven `fixed z-[1000]` popover positioned at the cursor (`@contextmenu.prevent` captures `clientX/clientY`), closing on outside-click or Escape. Classes per `plextune-parts.jsx`.

- **Folder header** menu: Rename folder (→ `$renamingFolderId`) · Expand/Collapse (→ `toggleFolder`) · divider · **Delete folder** (danger → `deleteFolder`).
- **Playlist row** menu: Play (→ `playPlaylist`) · divider · Move to folder ▶ — a submenu listing every folder (the playlist's current folder shown checked) plus "Remove from folder" (disabled if the playlist is already at root); each calls `movePlaylistToFolder` · Rename playlist (→ `$renamingPlaylistId`) · divider · **Delete playlist** (danger → `deletePlaylist`). If the hover submenu proves fiddly in Alpine, flatten it to `Move to: {FolderName}` rows + `Move to root` — acceptable deviation.
- Double-clicking a folder name also enters inline rename (prototype behavior). Inline rename inputs commit on Enter / blur, cancel on Escape.

## Error Handling

- `PlexClient` mutating methods throw the existing typed exceptions; the sidebar's action methods catch `PlexException`, `Log::channel('plex')->...` it, and let the DnD layer flash the row red. No app-side rollback needed for adds (we never optimistically mutated DB state for a track add — the only visible change is the busted-cache re-render, which simply won't reflect a failed add).
- Folder mutations are local DB writes; they don't fail in normal operation. No special handling beyond the framework default.
- A failed `createPlaylistFromTrack` flashes the "New playlist" drop zone red and logs; no row is created; the user can drop again.

## Testing

**Unit — `tests/Unit/PlexClientTest.php`** (append, `Http::fake()` against the discovered `*.plex.direct` host already used by the existing cases):
- `machineIdentifier()` — request URL, value, caching.
- `addTrackToPlaylist` / `addAlbumToPlaylist` — `PUT /playlists/{id}/items`, `uri` query param built from `machineIdentifier()` + rating key; `plex:playlists` forgotten.
- `createPlaylist` — `POST /playlists` with `type=audio`, `title`, `smart=0`, `uri`; returns the new rating key; `plex:playlists` forgotten.
- `renamePlaylist` — `PUT /playlists/{id}?title=...`; cache forgotten.
- `deletePlaylist` — `DELETE /playlists/{id}`; cache forgotten.
- Error mapping: 404 → `PlexNotFoundException`, 5xx → `PlexUnreachableException` for the mutators.

**Unit — `tests/Unit/FolderTest.php`** (new): factory creates a folder; `folderPlaylists` relationship; deleting a folder cascades its pivot rows; `position` / `expanded` defaults.

**Feature — `tests/Feature/SidebarTest.php`** (extend the M4 file, `Livewire::test('sidebar')` with a mocked `PlexClient` — remember the mock must also stub `ping()` for the nested `<livewire:server-chip />`):
- `createFolder` persists a row and sets `$renamingFolderId`; `renameFolder` updates it and ignores empty; `deleteFolder` removes it (and its pivots).
- `toggleFolder` persists `expanded`.
- `movePlaylistToFolder($id, $folderId)` creates one pivot row; calling again with a different folder replaces it (still one row); calling with `null` removes it.
- Render: with folders + pivots, each folder shows its playlists and unfiled playlists appear under "Other"; with no folders, the flat list renders.
- `addTrackToPlaylist` / `addAlbumToPlaylist` / `renamePlaylist` / `deletePlaylist` / `playPlaylist` call the mocked `PlexClient` with the right args; `playPlaylist` dispatches `play-track`.
- `createPlaylistFromTrack` calls `PlexClient::createPlaylist('New Playlist', $trackId)` and sets `$renamingPlaylistId` to the returned rating key.

**Feature — existing files:** `RoutesTest` etc. unaffected (sidebar still degrades to an empty list without `PLEX_TOKEN`). Add nothing unless a regression appears.

**Browser — `tests/Browser/`** (Playwright, hits live Plex like the others; skips/fails cleanly when Plex is down):
- New `SidebarFoldersTest`: from `/`, click "New folder", confirm a folder row appears; drag a playlist row onto the folder header (Playwright `dragAndDrop`, or the `script()`-driven dispatch pattern used elsewhere) and confirm that playlist now renders nested under the folder; reload and confirm it's still there (persistence).
- Extend coverage for drag-to-add-track: from `/`, drill into an album so the library tracklist has rows, drag the first track row onto a playlist row, and assert no JS error + the success flash class appears (a real add to a live playlist is acceptable but the assertion should not depend on the playlist's resulting contents). Also drive a track `dragstart` and assert the "New playlist" drop zone becomes visible.

**Not tested:** reorder gestures (none exist), the inert "Recents" sort button, pixel-level layout, the context-menu submenu hover timing.

## Out of Scope

- Reordering folders, or playlists within a folder (the prototype has no such gesture; `position` is just insert order).
- Global toast system (M7 polish) — DnD feedback is the inline row flash.
- "Add to queue" from the context menu (M7 — needs real queue-append plumbing in the player).
- Multi-select drag (drag one item at a time, as the prototype does).
- Persisting the sidebar filter text or the sort mode.
- A track-row context menu / "Add to playlist…" dialog in the library/search/detail views (the drop-onto-playlist gesture covers adding existing tracks; a right-click "Add to playlist ▶ → New playlist…" is a possible later addition, not M5).

## Files

**New:**
- `app/Models/Folder.php`, `app/Models/FolderPlaylist.php`
- `database/factories/FolderFactory.php` (+ `FolderPlaylistFactory.php` if useful)
- `database/migrations/xxxx_create_folders_table.php`, `xxxx_create_folder_playlists_table.php`
- `tests/Unit/FolderTest.php`
- `tests/Browser/SidebarFoldersTest.php`

**Modified:**
- `app/Services/Plex/PlexClient.php` — the six new methods + cache busting.
- `app/Services/Plex/PlexCache.php` — only if a new TTL/key constant is wanted (machine-id can reuse `TTL_RESOURCES`).
- `resources/views/components/⚡sidebar.blade.php` — folders, DnD targets, context menus, filter input, the "New playlist" drop zone + drag-state listener, the new actions.
- `resources/views/pages/⚡library.blade.php` — track rows and the album-header cover get `draggable` + `@dragstart`.
- `resources/views/pages/⚡playlist-detail.blade.php` — track rows get `draggable` + `@dragstart`.
- `resources/views/pages/⚡search.blade.php` — song rows get `draggable` + `@dragstart`.
- `tests/Unit/PlexClientTest.php`, `tests/Feature/SidebarTest.php` — new cases.
- `CLAUDE.md` — milestone table → M5 done; note any new gotchas; bump the test count.

**No changes to:** `routes/web.php`, the layout, the player, `config/cache.php` (no new cached DTO types — folders are Eloquent, not cached through `PlexCache`).
