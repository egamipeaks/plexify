# Reorder playlists and folders in the sidebar — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the left-sidebar playlist list user-orderable by drag and drop — reorder root playlists, reorder within a folder, drop a playlist at a precise position when moving between containers, and reorder folders.

**Architecture:** `folder_playlists.folder_id` becomes nullable so a row means "this playlist's placement" (folder + position; null folder = pinned at root). Two new Livewire actions on the sidebar SFC — `movePlaylist(draggedPlaylistId, ?targetFolderId, ?targetPlaylistId, position)` and `moveFolder(draggedFolderId, targetFolderId, position)` — recompute and persist dense `position` values for the affected container(s). The sidebar Blade gets native HTML5 DnD: playlist rows and folder headers become reorder drop targets showing the `.drop-before`/`.drop-after` accent insertion line (reused from the playlist-track reorder feature), folder headers become a new `plextune/folder` drag source. All ordering is app-side (Eloquent) — no Plex calls.

**Tech Stack:** Laravel 13, Livewire 4 SFC, Alpine.js, Tailwind CSS 4, Pest 4 (+ browser plugin), MySQL (DBngin).

**Spec:** `docs/superpowers/specs/2026-05-12-plextune-sidebar-reorder-design.md`. Read it before starting; it carries the data-model rationale and the full DnD UX.

**Branch:** `feat/sidebar-reorder` (already created; the spec is committed there).

---

## File structure

- `database/migrations/2026_05_12_000001_make_folder_playlists_folder_id_nullable.php` — **new.** Alter `folder_id` to nullable, change the FK to `nullOnDelete()`.
- `app/Models/FolderPlaylist.php` — `folder_id` cast stays `integer` (now nullable in practice); no structural change. Add a `query scope` only if a task needs it (it doesn't — kept as a note).
- `resources/views/components/⚡sidebar.blade.php` — the two new actions (`movePlaylist`, `moveFolder`) + a private `rootOrderedPlexIds()` helper + a `rootPlacements()` computed; the root render ordering; the `x-data` scope additions; the folder-header `draggable` + DnD attributes; the changed `@drop` on the folder header and the "Other" header; pass `$folderId` into the playlist-row `@include`s.
- `resources/views/partials/playlist-row.blade.php` — new `$folderId` param; reorder `@dragstart`/`@dragend`/`@dragover`/`@dragleave`/`@drop` and the `.drop-before`/`.drop-after` `:class`; the single `@drop` handler branches between "reorder playlist" and the existing "add track/album to this playlist".
- `resources/css/app.css` — **no change** (`.drop-before` / `.drop-after` already exist from `feat/playlist-reorder`).
- `tests/Feature/FolderTest.php` — update the cascade-delete test (the rows now survive as root rows).
- `tests/Feature/SidebarTest.php` — update the "deletes a folder and its pivot rows" test; add the new ordering + `movePlaylist` + `moveFolder` tests.
- `tests/Browser/SidebarFoldersTest.php` — fix the `afterEach` comment; add a reorder browser test.
- `CLAUDE.md` — record the shipped feature; bump the test count.

## Conventions reminder (read once)

- PSR-12, typed properties, early returns, no `else`, no docblocks on fully-typed methods, migrations have **only `up()`**, no em/en dashes in markdown docs.
- Livewire 4 SFC: the `<?php new class extends Component { ... }; ?>` block at the top of `⚡sidebar.blade.php`, then the template. `boot(PlexClient $plex)` already sets `protected PlexClient $plex`. Action methods autowire DI params; `#[Computed]` methods do **not** (use `app()` inside them) — but the sidebar's computeds use `$this->plex` which `boot()` set, so that's fine.
- `Livewire::test('sidebar')` also renders the nested `<livewire:server-chip />`, so any `PlexClient` mock must stub `ping()`. The existing `mockSidebarPlex()` helper in `tests/Feature/SidebarTest.php` already does this — reuse it.
- `php artisan test --compact --filter=...` to run a subset; `vendor/bin/pint --dirty --format agent` after PHP changes; `npm run build` after Blade/JS/CSS changes (the app is served by Herd at `https://plexify.test`).
- The reusable sidebar row partial is `@include`d (not a component), so `$this` is unavailable inside it — pre-resolved values (`$thumbUrl`) and now `$folderId` are passed in.

---

### Task 1: Migration — make `folder_playlists.folder_id` nullable, FK `nullOnDelete()`

**Files:**
- Create: `database/migrations/2026_05_12_000001_make_folder_playlists_folder_id_nullable.php`
- Modify: `tests/Feature/FolderTest.php` (the cascade-delete test)
- Modify: `tests/Feature/SidebarTest.php` (the "deletes a folder and its pivot rows" test)
- Modify: `tests/Browser/SidebarFoldersTest.php` (the `afterEach` comment — code change too)

Behavioral change: a `folder_playlists` row was deleted by FK cascade when its folder was deleted; now the FK is `nullOnDelete()`, so deleting a folder leaves its playlists' rows in place with `folder_id` set to `null` (they become root rows). The `unique('plex_playlist_id')` index is unaffected.

- [ ] **Step 1: Update the two tests to expect the new behavior (they should now fail)**

In `tests/Feature/FolderTest.php`, replace the test currently named `it('cascades deletes to its folder playlists', ...)` with:

```php
it('moves its folder playlists to root when the folder is deleted', function () {
    $folder = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p1', 'position' => 0]);
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p2', 'position' => 1]);

    $folder->delete();

    expect(FolderPlaylist::count())->toBe(2)
        ->and(FolderPlaylist::pluck('folder_id')->all())->toBe([null, null]);
});
```

(Check the top of `FolderTest.php` imports `App\Models\Folder` and `App\Models\FolderPlaylist`; add the `FolderPlaylist` import if it's missing. Keep the other three tests in the file unchanged.)

In `tests/Feature/SidebarTest.php`, replace the body of `it('deletes a folder and its pivot rows', ...)` — rename it and change the final expectation:

```php
it('deletes a folder, leaving its playlists at root', function () {
    mockSidebarPlex([playlist('p1', 'One')]);
    $folder = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p1', 'position' => 0]);

    Livewire::test('sidebar')->call('deleteFolder', $folder->id);

    expect(Folder::count())->toBe(0)
        ->and(FolderPlaylist::count())->toBe(1)
        ->and(FolderPlaylist::first()->folder_id)->toBeNull();
});
```

(If that test currently creates the pivot row a different way — e.g. via `->call('movePlaylistToFolder', ...)` — keep whatever setup it has and only change the trailing `expect(...)` to the three assertions above.)

In `tests/Browser/SidebarFoldersTest.php`, change the `afterEach` block — the FK no longer cascades, so explicitly clear `folder_playlists` too:

```php
afterEach(function () {
    // RefreshDatabase wraps each test in a transaction, but make the intent explicit
    // and guard against future trait changes. (folder_id is nullOnDelete, not cascade,
    // so deleting folders alone would leave orphaned root-placement rows.)
    \App\Models\FolderPlaylist::query()->delete();
    \App\Models\Folder::query()->delete();
});
```

- [ ] **Step 2: Run the updated tests to confirm they fail**

Run: `php artisan test --compact --filter="moves its folder playlists to root|deletes a folder, leaving its playlists at root"`
Expected: FAIL — the rows are still cascade-deleted, so the counts are 0 / `folder_id` assertions can't run.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_05_12_000001_make_folder_playlists_folder_id_nullable.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
};
```

- [ ] **Step 4: Run the migration against the dev DB**

Run: `php artisan migrate`
Expected: the new migration runs without error. (If the test suite uses `RefreshDatabase`, it re-runs all migrations per test run, so the tests pick it up automatically.)

- [ ] **Step 5: Run the updated tests + the full suite to confirm green**

Run: `php artisan test --compact --filter="moves its folder playlists to root|deletes a folder, leaving its playlists at root"`
Expected: PASS.
Run: `php artisan test --compact`
Expected: green (the rest of the suite unaffected — the only tests that referenced cascade behavior were the two updated here).

- [ ] **Step 6: Pint + commit**

Run: `vendor/bin/pint --dirty --format agent`
```bash
git add database/migrations/2026_05_12_000001_make_folder_playlists_folder_id_nullable.php tests/Feature/FolderTest.php tests/Feature/SidebarTest.php tests/Browser/SidebarFoldersTest.php
git commit -m "feat(sidebar): folder_playlists.folder_id nullable; deleting a folder keeps its playlists at root"
```

---

### Task 2: Root render ordering — placed playlists by position, then unplaced in Plex order

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php` (add a `rootPlacements()` computed and a private `rootOrderedPlexIds()` helper; change the root-playlist assembly in the template)
- Test: `tests/Feature/SidebarTest.php` (add one ordering test)

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/SidebarTest.php`:

```php
it('orders root playlists by their saved position, then unplaced ones in Plex order', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two'), playlist('p3', 'Three'), playlist('p4', 'Four')]);
    // Pin p3 to the top of root; p1/p2/p4 stay unplaced (Plex order).
    FolderPlaylist::create(['folder_id' => null, 'plex_playlist_id' => 'p3', 'position' => 0]);

    Livewire::test('sidebar')->assertSeeInOrder(['Three', 'One', 'Two', 'Four']);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter="orders root playlists by their saved position"`
Expected: FAIL — root currently renders in raw Plex order (`One, Two, Three, Four`), so `assertSeeInOrder` fails on `Three` appearing first.

- [ ] **Step 3: Add the computed + helper to the component class**

In the `<?php new class extends Component { ... }; ?>` block of `resources/views/components/⚡sidebar.blade.php`, after the existing `folders()` computed, add:

```php
    /** @return Collection<int, FolderPlaylist> */
    #[Computed]
    public function rootPlacements(): Collection
    {
        return FolderPlaylist::whereNull('folder_id')->orderBy('position')->orderBy('id')->get();
    }

    /**
     * The ordered plex playlist ids for the root container: placed playlists by saved
     * position, then unplaced playlists in Plex order. $allPlexIds is the full ordered
     * list of plex ids from PlexClient::playlists(); $filedIds are ids that live in a folder.
     *
     * @param  list<string>  $allPlexIds
     * @param  list<string>  $filedIds
     * @return list<string>
     */
    protected function rootOrderedPlexIds(array $allPlexIds, array $filedIds): array
    {
        $rootIds = array_values(array_diff($allPlexIds, $filedIds));

        $placedOrder = $this->rootPlacements
            ->pluck('plex_playlist_id')
            ->filter(fn ($id) => in_array($id, $rootIds, true))
            ->values()
            ->all();

        $unplaced = array_values(array_diff($rootIds, $placedOrder));

        return [...$placedOrder, ...$unplaced];
    }
```

- [ ] **Step 4: Use it in the template's `@php` block**

In `resources/views/components/⚡sidebar.blade.php`, the list `@php(...)` block currently has:

```blade
            @php
                $allPlaylists = $this->playlists;
                $byId = $allPlaylists->keyBy('id');
                $filter = trim($this->filter);
                $matches = fn ($title) => $filter === '' || str_contains(mb_strtolower((string) $title), mb_strtolower($filter));
                $filed = $this->folders->flatMap(fn ($f) => $f->folderPlaylists->pluck('plex_playlist_id'))->all();
                $rootPlaylists = $allPlaylists->reject(fn ($p) => in_array($p->id, $filed, true))->values();
                $visibleRoot = $rootPlaylists->filter(fn ($p) => $matches($p->title))->values();
            @endphp
```

Replace the `$rootPlaylists = ...` line with an ordered version built from `rootOrderedPlexIds()`:

```blade
            @php
                $allPlaylists = $this->playlists;
                $byId = $allPlaylists->keyBy('id');
                $filter = trim($this->filter);
                $matches = fn ($title) => $filter === '' || str_contains(mb_strtolower((string) $title), mb_strtolower($filter));
                $filed = $this->folders->flatMap(fn ($f) => $f->folderPlaylists->pluck('plex_playlist_id'))->all();
                $rootPlaylists = collect($this->rootOrderedPlexIds($allPlaylists->pluck('id')->all(), $filed))
                    ->map(fn ($id) => $byId->get($id))->filter()->values();
                $visibleRoot = $rootPlaylists->filter(fn ($p) => $matches($p->title))->values();
            @endphp
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `php artisan test --compact --filter="orders root playlists by their saved position"`
Expected: PASS.
Run: `php artisan test --compact --filter=SidebarTest`
Expected: green (the other sidebar tests still pass — unplaced root playlists still render in Plex order, which is what they expect).

- [ ] **Step 6: Build + pint + commit**

Run: `npm run build`
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add resources/views/components/⚡sidebar.blade.php tests/Feature/SidebarTest.php
git commit -m "feat(sidebar): root playlists render in saved order, unplaced ones in Plex order"
```

---

### Task 3: `movePlaylist` action — root reordering + no-op short-circuits

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php` (add the `movePlaylist` action)
- Test: `tests/Feature/SidebarTest.php` (add the root-reorder + no-op tests)

This task handles the `targetFolderId === null` (root) destination only; Task 4 extends the same method for folder destinations.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SidebarTest.php`:

```php
it('movePlaylist reorders root playlists, materializing a row for each', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two'), playlist('p3', 'Three'), playlist('p4', 'Four')]);

    // Drop p4 immediately before p1 -> order becomes p4, p1, p2, p3
    Livewire::test('sidebar')->call('movePlaylist', 'p4', null, 'p1', 'before');

    $rows = FolderPlaylist::orderBy('position')->get();
    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('folder_id')->unique()->all())->toBe([null])
        ->and($rows->pluck('plex_playlist_id')->all())->toBe(['p4', 'p1', 'p2', 'p3'])
        ->and($rows->pluck('position')->all())->toBe([0, 1, 2, 3]);
});

it('movePlaylist places a playlist at the front of root when targetPlaylistId is null', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two')]);

    Livewire::test('sidebar')->call('movePlaylist', 'p2', null, null, 'before');

    expect(FolderPlaylist::orderBy('position')->pluck('plex_playlist_id')->all())->toBe(['p2', 'p1']);
});

it('movePlaylist is a no-op when the playlist is dropped on itself', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two')]);

    Livewire::test('sidebar')->call('movePlaylist', 'p1', null, 'p1', 'before');

    expect(FolderPlaylist::count())->toBe(0);
});

it('movePlaylist is a no-op when the resulting root order is unchanged', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two')]);
    // p1 is already (Plex order) immediately before p2; "p1 before p2" changes nothing.
    Livewire::test('sidebar')->call('movePlaylist', 'p1', null, 'p2', 'before');

    expect(FolderPlaylist::count())->toBe(0);
});
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `php artisan test --compact --filter="movePlaylist"`
Expected: FAIL — `movePlaylist` is not a method on the component.

- [ ] **Step 3: Add the `movePlaylist` action (root case)**

In the component class of `resources/views/components/⚡sidebar.blade.php`, after `movePlaylistToFolder()`, add:

```php
    public function movePlaylist(string $draggedPlaylistId, ?int $targetFolderId, ?string $targetPlaylistId, string $position): void
    {
        if ($draggedPlaylistId === $targetPlaylistId) {
            return;
        }

        $allPlexIds = $this->playlists->pluck('id')->all();
        if (! in_array($draggedPlaylistId, $allPlexIds, true)) {
            return;
        }
        if ($targetPlaylistId !== null && ! in_array($targetPlaylistId, $allPlexIds, true)) {
            return;
        }

        $sourceFolderId = FolderPlaylist::where('plex_playlist_id', $draggedPlaylistId)->value('folder_id');
        // value('folder_id') is null both when there's no row AND when the row is a root row;
        // distinguish with an existence check.
        $hadRow = FolderPlaylist::where('plex_playlist_id', $draggedPlaylistId)->exists();
        $sourceFolderId = $hadRow ? $sourceFolderId : 'unplaced';

        if ($targetFolderId === null) {
            $this->placeInRoot($draggedPlaylistId, $targetPlaylistId, $position, $allPlexIds, $sourceFolderId);
        } else {
            $this->placeInFolder($draggedPlaylistId, $targetFolderId, $targetPlaylistId, $position, $sourceFolderId);
        }

        unset($this->folders, $this->rootPlacements);
    }

    /**
     * @param  list<string>  $allPlexIds
     * @param  int|string|null  $sourceFolderId  int = a folder, null = a root row, 'unplaced' = no row
     */
    protected function placeInRoot(string $draggedPlaylistId, ?string $targetPlaylistId, string $position, array $allPlexIds, int|string|null $sourceFolderId): void
    {
        $filed = $this->folders->flatMap(fn ($f) => $f->folderPlaylists->pluck('plex_playlist_id'))->all();
        $filed = array_values(array_diff($filed, [$draggedPlaylistId]));

        $current = $this->rootOrderedPlexIds($allPlexIds, $filed);
        $newOrder = $this->insertRelative($current, $draggedPlaylistId, $targetPlaylistId, $position);

        if ($sourceFolderId === null && $newOrder === $current) {
            return; // already a root row in this exact spot
        }

        foreach ($newOrder as $i => $plexId) {
            FolderPlaylist::updateOrCreate(
                ['plex_playlist_id' => $plexId],
                ['folder_id' => null, 'position' => $i],
            );
        }

        if (is_int($sourceFolderId)) {
            // It came out of a folder; that folder lost a member — re-densify it.
            $this->renumberContainer($sourceFolderId);
        }
    }

    /**
     * Insert $id into $list immediately before/after $relativeTo (null relativeTo => front).
     * Removes $id first if already present. $relativeTo not found => append.
     *
     * @param  list<string>  $list
     * @return list<string>
     */
    protected function insertRelative(array $list, string $id, ?string $relativeTo, string $position): array
    {
        $list = array_values(array_diff($list, [$id]));

        if ($relativeTo === null) {
            return [$id, ...$list];
        }

        $idx = array_search($relativeTo, $list, true);
        if ($idx === false) {
            return [...$list, $id];
        }

        $at = $position === 'after' ? $idx + 1 : $idx;

        return [...array_slice($list, 0, $at), $id, ...array_slice($list, $at)];
    }

    /** Re-densify a container's folder_playlists rows to positions 0..N. $folderId === null = the root container. */
    protected function renumberContainer(?int $folderId): void
    {
        $query = $folderId === null
            ? FolderPlaylist::whereNull('folder_id')
            : FolderPlaylist::where('folder_id', $folderId);

        $query->orderBy('position')->orderBy('id')->get()
            ->values()
            ->each(fn ($row, $i) => $row->position === $i ? null : $row->update(['position' => $i]));
    }
```

(`placeInFolder()` is added in Task 4 — for now the `else` branch in `movePlaylist` will be dead, but reference it so the method compiles. To keep this task self-contained and compiling, add a stub now:)

```php
    /** @param int|string|null $sourceFolderId */
    protected function placeInFolder(string $draggedPlaylistId, int $targetFolderId, ?string $targetPlaylistId, string $position, int|string|null $sourceFolderId): void
    {
        // Implemented in Task 4.
    }
```

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `php artisan test --compact --filter="movePlaylist"`
Expected: PASS (the four root-case tests).
Run: `php artisan test --compact --filter=SidebarTest`
Expected: green.

- [ ] **Step 5: Build + pint + commit**

Run: `npm run build`
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add resources/views/components/⚡sidebar.blade.php tests/Feature/SidebarTest.php
git commit -m "feat(sidebar): movePlaylist action reorders root playlists"
```

---

### Task 4: `movePlaylist` action — into a folder, between folders, out to root

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php` (implement `placeInFolder()`)
- Test: `tests/Feature/SidebarTest.php` (add the folder-destination tests)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SidebarTest.php`:

```php
it('movePlaylist drops a playlist into a folder at the top, bumping existing members', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two')]);
    $folder = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p2', 'position' => 0]);

    Livewire::test('sidebar')->call('movePlaylist', 'p1', $folder->id, null, 'before');

    $rows = FolderPlaylist::where('folder_id', $folder->id)->orderBy('position')->get();
    expect($rows->pluck('plex_playlist_id')->all())->toBe(['p1', 'p2'])
        ->and($rows->pluck('position')->all())->toBe([0, 1]);
});

it('movePlaylist drops a playlist after a specific member of a folder', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two'), playlist('p3', 'Three')]);
    $folder = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p1', 'position' => 0]);
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p2', 'position' => 1]);

    Livewire::test('sidebar')->call('movePlaylist', 'p3', $folder->id, 'p1', 'after');

    expect(FolderPlaylist::where('folder_id', $folder->id)->orderBy('position')->pluck('plex_playlist_id')->all())
        ->toBe(['p1', 'p3', 'p2']);
});

it('movePlaylist moving a playlist between folders renumbers the source folder', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two'), playlist('p3', 'Three')]);
    $a = Folder::factory()->create();
    $b = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $a->id, 'plex_playlist_id' => 'p1', 'position' => 0]);
    FolderPlaylist::create(['folder_id' => $a->id, 'plex_playlist_id' => 'p2', 'position' => 1]);
    FolderPlaylist::create(['folder_id' => $a->id, 'plex_playlist_id' => 'p3', 'position' => 2]);

    Livewire::test('sidebar')->call('movePlaylist', 'p2', $b->id, null, 'before');

    expect(FolderPlaylist::where('folder_id', $a->id)->orderBy('position')->pluck('plex_playlist_id')->all())->toBe(['p1', 'p3'])
        ->and(FolderPlaylist::where('folder_id', $a->id)->orderBy('position')->pluck('position')->all())->toBe([0, 1])
        ->and(FolderPlaylist::where('folder_id', $b->id)->pluck('plex_playlist_id')->all())->toBe(['p2']);
});

it('movePlaylist moving a playlist out of a folder into root materializes root and renumbers the folder', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two'), playlist('p3', 'Three')]);
    $folder = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p2', 'position' => 0]);
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p3', 'position' => 1]);

    // Root currently contains only p1 (p2/p3 are in the folder). Drop p3 after p1 in root.
    Livewire::test('sidebar')->call('movePlaylist', 'p3', null, 'p1', 'after');

    expect(FolderPlaylist::whereNull('folder_id')->orderBy('position')->pluck('plex_playlist_id')->all())->toBe(['p1', 'p3'])
        ->and(FolderPlaylist::where('folder_id', $folder->id)->orderBy('position')->pluck('plex_playlist_id')->all())->toBe(['p2'])
        ->and(FolderPlaylist::where('folder_id', $folder->id)->orderBy('position')->pluck('position')->all())->toBe([0]);
});
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `php artisan test --compact --filter="movePlaylist drops a playlist|movePlaylist moving a playlist"`
Expected: FAIL — `placeInFolder()` is a stub, so the rows aren't moved.

- [ ] **Step 3: Implement `placeInFolder()`**

Replace the `placeInFolder()` stub in `resources/views/components/⚡sidebar.blade.php` with:

```php
    /** @param int|string|null $sourceFolderId  int = a folder, null = a root row, 'unplaced' = no row */
    protected function placeInFolder(string $draggedPlaylistId, int $targetFolderId, ?string $targetPlaylistId, string $position, int|string|null $sourceFolderId): void
    {
        if (! Folder::whereKey($targetFolderId)->exists()) {
            return;
        }

        $current = FolderPlaylist::where('folder_id', $targetFolderId)->orderBy('position')->orderBy('id')->pluck('plex_playlist_id')->all();
        $newOrder = $this->insertRelative($current, $draggedPlaylistId, $targetPlaylistId, $position);

        if ($sourceFolderId === $targetFolderId && $newOrder === $current) {
            return;
        }

        foreach ($newOrder as $i => $plexId) {
            FolderPlaylist::updateOrCreate(
                ['plex_playlist_id' => $plexId],
                ['folder_id' => $targetFolderId, 'position' => $i],
            );
        }

        if (is_int($sourceFolderId) && $sourceFolderId !== $targetFolderId) {
            $this->renumberContainer($sourceFolderId);
        }

        if ($sourceFolderId === null) {
            // It had a root row whose folder_id just changed — the remaining root rows may
            // now have a gap; re-densify them. (Harmless either way; keeps positions clean.)
            $this->renumberContainer(null);
        }
    }
```

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `php artisan test --compact --filter="movePlaylist"`
Expected: PASS (all root + folder tests).
Run: `php artisan test --compact --filter=SidebarTest`
Expected: green.

- [ ] **Step 5: Build + pint + commit**

Run: `npm run build`
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add resources/views/components/⚡sidebar.blade.php tests/Feature/SidebarTest.php
git commit -m "feat(sidebar): movePlaylist handles folder destinations and cross-container moves"
```

---

### Task 5: `moveFolder` action

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php` (add the `moveFolder` action)
- Test: `tests/Feature/SidebarTest.php` (add the folder-reorder tests)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SidebarTest.php`:

```php
it('moveFolder reorders folders and renumbers them densely', function () {
    mockSidebarPlex();
    $f1 = Folder::factory()->create(['name' => 'A', 'position' => 0]);
    $f2 = Folder::factory()->create(['name' => 'B', 'position' => 1]);
    $f3 = Folder::factory()->create(['name' => 'C', 'position' => 2]);

    Livewire::test('sidebar')->call('moveFolder', $f3->id, $f1->id, 'before');

    expect(Folder::orderBy('position')->pluck('name')->all())->toBe(['C', 'A', 'B'])
        ->and(Folder::orderBy('position')->pluck('position')->all())->toBe([0, 1, 2]);
});

it('moveFolder is a no-op when a folder is dropped on itself', function () {
    mockSidebarPlex();
    $f1 = Folder::factory()->create(['name' => 'A', 'position' => 0]);
    $f2 = Folder::factory()->create(['name' => 'B', 'position' => 1]);

    Livewire::test('sidebar')->call('moveFolder', $f1->id, $f1->id, 'after');

    expect(Folder::orderBy('position')->pluck('name')->all())->toBe(['A', 'B']);
});

it('moveFolder is a no-op when the resulting order is unchanged', function () {
    mockSidebarPlex();
    $f1 = Folder::factory()->create(['name' => 'A', 'position' => 0]);
    $f2 = Folder::factory()->create(['name' => 'B', 'position' => 1]);

    Livewire::test('sidebar')->call('moveFolder', $f1->id, $f2->id, 'before');

    expect(Folder::orderBy('position')->pluck('name')->all())->toBe(['A', 'B']);
});
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `php artisan test --compact --filter="moveFolder"`
Expected: FAIL — `moveFolder` is not a method on the component.

- [ ] **Step 3: Add the `moveFolder` action**

In the component class of `resources/views/components/⚡sidebar.blade.php`, after `movePlaylist()` (and its helpers), add:

```php
    public function moveFolder(int $draggedFolderId, int $targetFolderId, string $position): void
    {
        if ($draggedFolderId === $targetFolderId) {
            return;
        }

        $current = Folder::orderBy('position')->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (! in_array((string) $draggedFolderId, $current, true) || ! in_array((string) $targetFolderId, $current, true)) {
            return;
        }

        $newOrder = $this->insertRelative($current, (string) $draggedFolderId, (string) $targetFolderId, $position);
        if ($newOrder === $current) {
            return;
        }

        foreach ($newOrder as $i => $folderId) {
            Folder::whereKey((int) $folderId)->update(['position' => $i]);
        }

        unset($this->folders);
    }
```

(`insertRelative()` is the string-list helper added in Task 3; folder ids are cast to/from strings to reuse it.)

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `php artisan test --compact --filter="moveFolder"`
Expected: PASS.
Run: `php artisan test --compact --filter=SidebarTest`
Expected: green.

- [ ] **Step 5: Build + pint + commit**

Run: `npm run build`
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add resources/views/components/⚡sidebar.blade.php tests/Feature/SidebarTest.php
git commit -m "feat(sidebar): moveFolder action reorders folders"
```

---

### Task 6: DnD UI — playlist-row reordering + the folder-header / "Other"-header playlist drops

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php` (the `x-data` scope additions; pass `$folderId` into the row `@include`s; the folder-header `@dragover`/`@drop` for playlist drags; the "Other" header `@drop`)
- Modify: `resources/views/partials/playlist-row.blade.php` (the `$folderId` param; the reorder drag handlers + insertion-line `:class`; the unified `@drop` handler)

No automated test in this task — Task 8 is the browser test; the action logic is covered by Tasks 3–5. After the edits, smoke-test manually in the browser.

- [ ] **Step 1: Extend the playlists-card `x-data` scope**

In `resources/views/components/⚡sidebar.blade.php`, the playlists card opens with:

```blade
    <div class="bg-surface rounded-lg flex-1 min-h-0 flex flex-col"
         x-data="{
            draggingTrack: false,
            menu: null,
            dropTarget: null,
            flash: {},
            init() {
                this._ds = (e) => { try { if ([...(e.dataTransfer?.types ?? [])].includes('plextune/track')) this.draggingTrack = true; } catch (_) {} };
                this._de = () => { this.draggingTrack = false; this.dropTarget = null; };
                window.addEventListener('dragstart', this._ds);
                window.addEventListener('dragend', this._de);
            },
            destroy() {
                window.removeEventListener('dragstart', this._ds);
                window.removeEventListener('dragend', this._de);
            },
            openMenu(e, kind, id) { ... },
            flashRow(key, ok) { ... },
            async dropTrackOn(key, playlistId, e) { ... },
            async dropTrackOnNew(e) { ... },
            dropPlaylistOn(folderId, e) { ... },
         }"
```

Add `draggingPlaylist`, `draggedPlaylistId`, `draggingFolder`, `draggedFolderId`, `overId`, `overPos`, the two `*DragOver` helpers, and the two reorder-drop helpers; extend `init`/`destroy` to also track `plextune/playlist` and `plextune/folder` drags. The new scope (keep the existing members; only the additions/changes are shown — merge them in):

```js
x-data="{
    draggingTrack: false,
    draggingPlaylist: false, draggedPlaylistId: null,
    draggingFolder: false, draggedFolderId: null,
    menu: null,
    dropTarget: null,
    overId: null, overPos: null,
    flash: {},
    init() {
        this._ds = (e) => {
            try {
                const types = [...(e.dataTransfer?.types ?? [])];
                if (types.includes('plextune/track')) this.draggingTrack = true;
                if (types.includes('plextune/playlist')) this.draggingPlaylist = true;
                if (types.includes('plextune/folder')) this.draggingFolder = true;
            } catch (_) {}
        };
        this._de = () => { this.draggingTrack = false; this.draggingPlaylist = false; this.draggingFolder = false; this.draggedPlaylistId = null; this.draggedFolderId = null; this.dropTarget = null; this.overId = null; };
        window.addEventListener('dragstart', this._ds);
        window.addEventListener('dragend', this._de);
    },
    destroy() {
        window.removeEventListener('dragstart', this._ds);
        window.removeEventListener('dragend', this._de);
    },
    openMenu(e, kind, id) { e.preventDefault(); e.stopPropagation(); this.menu = { x: e.clientX, y: e.clientY, kind, id }; },
    flashRow(key, ok) { this.flash[key] = ok ? 'ok' : 'err'; setTimeout(() => { this.flash[key] = null; }, 700); },
    rowDragOver(e, id) {
        if (!this.draggingPlaylist || id === this.draggedPlaylistId) { this.overId = null; return; }
        const r = e.currentTarget.getBoundingClientRect();
        this.overPos = (e.clientY - r.top) < r.height / 2 ? 'before' : 'after';
        this.overId = id;
    },
    folderDragOver(e, id) {
        if (!this.draggingFolder || id === this.draggedFolderId) { this.overId = null; return; }
        const r = e.currentTarget.getBoundingClientRect();
        this.overPos = (e.clientY - r.top) < r.height / 2 ? 'before' : 'after';
        this.overId = id;
    },
    onRowDrop(e, key, playlistId, folderId) {
        e.preventDefault();
        const movedPl = e.dataTransfer.getData('plextune/playlist');
        if (movedPl) {
            const dragged = this.draggedPlaylistId || movedPl, pos = this.overPos;
            this.overId = null; this.draggedPlaylistId = null; this.draggingPlaylist = false; this.dropTarget = null;
            if (!dragged || !pos || dragged === playlistId) return;
            $wire.movePlaylist(dragged, folderId, playlistId, pos);
            return;
        }
        // Existing track/album drop behaviour:
        this.dropTarget = null;
        const trackId = e.dataTransfer.getData('plextune/track');
        const albumId = e.dataTransfer.getData('plextune/album');
        if (!trackId && !albumId) return;
        (async () => {
            try {
                const ok = trackId ? await $wire.addTrackToPlaylist(playlistId, trackId) : await $wire.addAlbumToPlaylist(playlistId, albumId);
                this.flashRow(key, ok);
            } catch (_) { this.flashRow(key, false); }
        })();
    },
    onFolderHeaderDrop(e, folderId) {
        e.preventDefault();
        const movedFolder = e.dataTransfer.getData('plextune/folder');
        if (movedFolder) {
            const dragged = this.draggedFolderId || parseInt(movedFolder, 10), pos = this.overPos;
            this.overId = null; this.draggedFolderId = null; this.draggingFolder = false; this.dropTarget = null;
            if (!dragged || !pos || dragged === folderId) return;
            $wire.moveFolder(dragged, folderId, pos);
            return;
        }
        // A playlist dropped on a folder header -> top of that folder.
        const movedPl = e.dataTransfer.getData('plextune/playlist');
        this.dropTarget = null;
        if (movedPl) $wire.movePlaylist(this.draggedPlaylistId || movedPl, folderId, null, 'before');
    },
    dropTrackOnNew(e) { e.preventDefault(); this.dropTarget = null; const trackId = e.dataTransfer.getData('plextune/track'); if (!trackId) return; (async () => { try { const ok = await $wire.createPlaylistFromTrack(trackId); this.flashRow('__new', ok); } catch (_) { this.flashRow('__new', false); } })(); },
    dropOnOtherHeader(e, lastRootId) {
        e.preventDefault(); this.dropTarget = null;
        const movedPl = e.dataTransfer.getData('plextune/playlist');
        if (!movedPl) return;
        const dragged = this.draggedPlaylistId || movedPl;
        if (lastRootId && dragged !== lastRootId) $wire.movePlaylist(dragged, null, lastRootId, 'after');
        else if (!lastRootId) $wire.movePlaylist(dragged, null, null, 'before');
    },
}"
```

Notes: `dropTrackOnNew` was `async` before — keeping it sync with an inner async IIFE avoids `async` confusing Alpine's `@drop` handler binding (same pattern used for `onRowDrop`). Keep `dropPlaylistOn` only if something still calls it; after this task the folder header uses `onFolderHeaderDrop` and the "Other" header uses `dropOnOtherHeader`, so `dropPlaylistOn` can be removed. (The context-menu "Move to {folder}" / "Remove from folder" entries call `$wire.movePlaylistToFolder(...)` directly, not `dropPlaylistOn`, so they're unaffected.)

- [ ] **Step 2: Pass `$folderId` into the playlist-row `@include`s**

In `resources/views/components/⚡sidebar.blade.php`, the row is `@include`d in two places. The folder-body one:

```blade
                                @forelse ($filter === '' ? $items : $visibleItems as $p)
                                    @include('partials.playlist-row', ['p' => $p, 'renaming' => $renamingPlaylistId === $p->id, 'thumbUrl' => $this->thumbFor($p->thumb)])
```

becomes:

```blade
                                @forelse ($filter === '' ? $items : $visibleItems as $p)
                                    @include('partials.playlist-row', ['p' => $p, 'folderId' => $folder->id, 'renaming' => $renamingPlaylistId === $p->id, 'thumbUrl' => $this->thumbFor($p->thumb)])
```

The root one:

```blade
            @forelse ($visibleRoot as $p)
                @include('partials.playlist-row', ['p' => $p, 'renaming' => $renamingPlaylistId === $p->id, 'thumbUrl' => $this->thumbFor($p->thumb)])
```

becomes:

```blade
            @forelse ($visibleRoot as $p)
                @include('partials.playlist-row', ['p' => $p, 'folderId' => null, 'renaming' => $renamingPlaylistId === $p->id, 'thumbUrl' => $this->thumbFor($p->thumb)])
```

- [ ] **Step 3: Wire the playlist-row partial**

`resources/views/partials/playlist-row.blade.php` — update the doc comment and the `<a>` element. The current `<a>` is:

```blade
        <a href="{{ route('playlist', $p->id) }}" draggable="true"
           @click.prevent="Livewire.navigate('{{ route('playlist', $p->id) }}')"
           @dragstart="$event.dataTransfer.effectAllowed='move'; $event.dataTransfer.setData('plextune/playlist', '{{ $p->id }}')"
           @contextmenu="openMenu($event, 'playlist', '{{ $p->id }}')"
           @dragover.prevent="if (draggingTrack) dropTarget = 'pl-{{ $p->id }}'"
           @dragleave="dropTarget = null"
           @drop="dropTrackOn('pl-{{ $p->id }}', '{{ $p->id }}', $event)"
           :class="dropTarget === 'pl-{{ $p->id }}' ? 'bg-accent/15 ring-1 ring-accent/40' : ''"
           @class([...])>
```

Replace with (and add `@php($folderIdJs = $folderId === null ? 'null' : $folderId)` just above the `<a>`):

```blade
        @php($folderIdJs = $folderId === null ? 'null' : $folderId)
        <a href="{{ route('playlist', $p->id) }}" draggable="true"
           @click.prevent="Livewire.navigate('{{ route('playlist', $p->id) }}')"
           @dragstart="$event.dataTransfer.effectAllowed='move'; $event.dataTransfer.setData('plextune/playlist', '{{ $p->id }}'); draggingPlaylist = true; draggedPlaylistId = '{{ $p->id }}'"
           @dragend="draggingPlaylist = false; draggedPlaylistId = null; overId = null; dropTarget = null"
           @contextmenu="openMenu($event, 'playlist', '{{ $p->id }}')"
           @dragover.prevent="if (draggingPlaylist) rowDragOver($event, '{{ $p->id }}'); else if (draggingTrack) dropTarget = 'pl-{{ $p->id }}'"
           @dragleave="if (!$event.currentTarget.contains($event.relatedTarget)) { dropTarget = null; if (overId === '{{ $p->id }}') overId = null; }"
           @drop="onRowDrop($event, 'pl-{{ $p->id }}', '{{ $p->id }}', {{ $folderIdJs }})"
           :class="{
               'bg-accent/15 ring-1 ring-accent/40': dropTarget === 'pl-{{ $p->id }}',
               'drop-before': overId === '{{ $p->id }}' && overPos === 'before',
               'drop-after': overId === '{{ $p->id }}' && overPos === 'after',
           }"
           @class([
               'w-full flex items-center gap-3 px-2 py-1.5 rounded-md transition-colors',
               'text-white bg-surface-2' => $isActive,
               'text-text-2 hover:text-white hover:bg-surface-2' => ! $isActive,
           ])>
```

Update the partial's leading comment to mention the new `$folderId` expectation and that the row is now also a playlist-reorder drop target.

- [ ] **Step 4: Wire the folder header for playlist drops + change the "Other" header**

In `resources/views/components/⚡sidebar.blade.php`, the folder header currently:

```blade
                        <div wire:click="toggleFolder({{ $folder->id }})"
                             @contextmenu="openMenu($event, 'folder', {{ $folder->id }})"
                             @dragover.prevent="dropTarget = 'folder-{{ $folder->id }}'"
                             @dragleave="dropTarget = null"
                             @drop="dropPlaylistOn({{ $folder->id }}, $event)"
                             :class="dropTarget === 'folder-{{ $folder->id }}' ? 'bg-accent/15 ring-1 ring-accent/40' : 'hover:bg-surface-2'"
                             class="group w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-left transition-colors cursor-pointer">
```

Change the `@dragover`, `@dragleave`, `@drop` to handle a `plextune/playlist` drag (into-folder-at-top) while leaving folder-reorder to Task 7 (the `draggingFolder` branch and the `@dragstart`/`drop-before`/`drop-after` come in Task 7 — but to avoid a half-wired state, add them here so this task leaves the header fully functional for playlist drops and Task 7 only adds the folder-as-source side):

```blade
                        <div wire:click="toggleFolder({{ $folder->id }})"
                             @contextmenu="openMenu($event, 'folder', {{ $folder->id }})"
                             @dragover.prevent="if (draggingPlaylist) dropTarget = 'folder-{{ $folder->id }}'"
                             @dragleave="if (!$event.currentTarget.contains($event.relatedTarget)) dropTarget = null"
                             @drop="onFolderHeaderDrop($event, {{ $folder->id }})"
                             :class="dropTarget === 'folder-{{ $folder->id }}' ? 'bg-accent/15 ring-1 ring-accent/40' : 'hover:bg-surface-2'"
                             class="group w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-left transition-colors cursor-pointer">
```

The "Other" header currently:

```blade
                <div @dragover.prevent="dropTarget = '__root'"
                     @dragleave="dropTarget = null"
                     @drop="dropPlaylistOn(null, $event)"
                     :class="dropTarget === '__root' ? 'bg-accent/10 ring-1 ring-accent/30 rounded' : ''"
                     class="px-2 pt-2 pb-1 text-[10px] uppercase tracking-wider text-text-3 font-bold">Other</div>
```

becomes (it has `$visibleRoot` in scope, non-empty here):

```blade
                <div @dragover.prevent="if (draggingPlaylist) dropTarget = '__root'"
                     @dragleave="if (!$event.currentTarget.contains($event.relatedTarget)) dropTarget = null"
                     @drop="dropOnOtherHeader($event, '{{ $visibleRoot->last()->id }}')"
                     :class="dropTarget === '__root' ? 'bg-accent/10 ring-1 ring-accent/30 rounded' : ''"
                     class="px-2 pt-2 pb-1 text-[10px] uppercase tracking-wider text-text-3 font-bold">Other</div>
```

The empty-folder placeholder currently has no drop handler (it sits inside the folder body, and the folder header above it is the drop target). Leave it as-is — dropping a playlist onto a *visible, expanded, empty* folder still hits the folder header's drop target since the body is below it; if you want the placeholder itself droppable, add `@dragover.prevent="if (draggingPlaylist) dropTarget = 'folder-{{ $folder->id }}'" @drop="onFolderHeaderDrop($event, {{ $folder->id }})"` to it — optional polish, not required.

- [ ] **Step 5: Build, then smoke-test in the browser**

Run: `npm run build`
Open `https://plexify.test`, create a folder, drag a playlist between two root playlists (accent insertion line appears above/below based on cursor half; on drop the order changes and survives reload), drag a playlist onto the folder header (it lands at the top of the folder), drag a playlist inside a folder to reorder it, drag a foldered playlist onto the "Other" header (it moves to the end of root). Confirm the existing behaviors still work: dragging a track row onto a playlist still adds it; the "New playlist" drop zone still appears while dragging a track; the context menus still work; clicking a playlist still navigates.

- [ ] **Step 6: Pint + commit**

Run: `vendor/bin/pint --dirty --format agent` (no PHP changes here, but run it)
```bash
git add resources/views/components/⚡sidebar.blade.php resources/views/partials/playlist-row.blade.php
git commit -m "feat(sidebar): drag-to-reorder UI for playlists; precise drop into folders/root"
```

---

### Task 7: DnD UI — folder headers as a drag source (reorder folders)

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php` (the folder header gains `draggable` + `@dragstart`/`@dragend`, the folder-reorder `@dragover`/`drop-before`/`drop-after` branch)

No automated test — Task 8's browser test exercises a playlist reorder; folder reorder is covered by the `moveFolder` feature tests (Task 5) and is smoke-tested here.

- [ ] **Step 1: Make the folder header a `plextune/folder` drag source and a folder-reorder drop target**

In `resources/views/components/⚡sidebar.blade.php`, the folder header (after Task 6 it is):

```blade
                        <div wire:click="toggleFolder({{ $folder->id }})"
                             @contextmenu="openMenu($event, 'folder', {{ $folder->id }})"
                             @dragover.prevent="if (draggingPlaylist) dropTarget = 'folder-{{ $folder->id }}'"
                             @dragleave="if (!$event.currentTarget.contains($event.relatedTarget)) dropTarget = null"
                             @drop="onFolderHeaderDrop($event, {{ $folder->id }})"
                             :class="dropTarget === 'folder-{{ $folder->id }}' ? 'bg-accent/15 ring-1 ring-accent/40' : 'hover:bg-surface-2'"
                             class="group w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-left transition-colors cursor-pointer">
```

becomes:

```blade
                        <div wire:click="toggleFolder({{ $folder->id }})" draggable="true"
                             @dragstart="$event.dataTransfer.effectAllowed='move'; $event.dataTransfer.setData('plextune/folder', '{{ $folder->id }}'); draggingFolder = true; draggedFolderId = {{ $folder->id }}"
                             @dragend="draggingFolder = false; draggedFolderId = null; overId = null; dropTarget = null"
                             @contextmenu="openMenu($event, 'folder', {{ $folder->id }})"
                             @dragover.prevent="if (draggingFolder) folderDragOver($event, {{ $folder->id }}); else if (draggingPlaylist) dropTarget = 'folder-{{ $folder->id }}'"
                             @dragleave="if (!$event.currentTarget.contains($event.relatedTarget)) { dropTarget = null; if (overId === {{ $folder->id }}) overId = null; }"
                             @drop="onFolderHeaderDrop($event, {{ $folder->id }})"
                             :class="{
                                 'bg-accent/15 ring-1 ring-accent/40': dropTarget === 'folder-{{ $folder->id }}',
                                 'drop-before': overId === {{ $folder->id }} && overPos === 'before',
                                 'drop-after': overId === {{ $folder->id }} && overPos === 'after',
                                 'hover:bg-surface-2': dropTarget !== 'folder-{{ $folder->id }}' && overId !== {{ $folder->id }},
                             }"
                             class="group w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-left transition-colors cursor-pointer">
```

(Note `overId` is compared to a number for folders — the `@dragstart` sets `draggedFolderId = {{ $folder->id }}` as a JS number, and `folderDragOver` sets `overId = id` where `id` is the numeric `{{ $folder->id }}`. For playlist rows `overId` holds a string id. They never collide because only one of `draggingFolder`/`draggingPlaylist` is true at a time.)

The folder header's `@click.stop` on the rename `<span>`/`<input>` already prevents a click there from toggling; dragging works because a folder name span/input isn't `draggable` (only the header `<div>` is). The `wire:click="toggleFolder"` + `draggable` combination is fine — verify a plain click still toggles and a drag reorders (the track-reorder rows proved this combo).

- [ ] **Step 2: Build, then smoke-test**

Run: `npm run build`
Open `https://plexify.test`, create 2–3 folders, drag a folder header up/down past the others (insertion line on the header you hover, based on cursor half); on drop the folder order changes and survives reload. Confirm a folder dragged onto a playlist row does nothing; a folder dragged onto another folder's header reorders (does NOT nest). Confirm clicking a folder header still toggles it open/closed, and the context menu (right-click) still appears.

- [ ] **Step 3: Pint + commit**

Run: `vendor/bin/pint --dirty --format agent` (no PHP changes; run it)
```bash
git add resources/views/components/⚡sidebar.blade.php
git commit -m "feat(sidebar): drag folder headers to reorder folders"
```

---

### Task 8: Browser test + final green

**Files:**
- Modify: `tests/Browser/SidebarFoldersTest.php` (add a reorder browser test)

- [ ] **Step 1: Add the browser test**

Append to `tests/Browser/SidebarFoldersTest.php` (after the existing tests; the file already `uses(RefreshDatabase::class)` and has the `afterEach` cleanup updated in Task 1). It drives `$wire.movePlaylist(...)` directly via `script()` — synthetic HTML5 DnD through Playwright is unreliable, and calling the action still exercises the Livewire action, the DB writes, and the re-render (the DnD wiring is smoke-tested manually in Tasks 6–7). It needs ≥2 Plex playlists (same live-server dependency as the file's other tests).

```php
it('reorders two root playlists from the sidebar and restores the original order', function () {
    $page = visit('/');

    // Wait for at least two sidebar playlist rows to render.
    expect((bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 10000;
            while (Date.now() < deadline) {
                if (document.querySelectorAll('[wire\\:key^="sidebar-pl-"]').length >= 2) return true;
                await sleep(150);
            }
            return false;
        })()
    JS))->toBeTrue('Expected at least 2 playlists in the sidebar (is the Plex server reachable?).');

    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const rows = () => [...document.querySelectorAll('[wire\\:key^="sidebar-pl-"]')];
            const keys = () => rows().map(el => el.getAttribute('wire:key'));
            const plId = el => (el.getAttribute('wire:key') || '').replace('sidebar-pl-', '');

            // The sidebar Livewire component: the [wire:id] element wrapping the playlists card.
            const compId = [...document.querySelectorAll('[wire\\:id]')]
                .map(e => e.getAttribute('wire:id'))
                .find(id => (window.Livewire.find(id)?.name || '') === 'sidebar');
            const comp = compId ? window.Livewire.find(compId) : null;
            if (!comp || typeof comp.call !== 'function') return { ok: false, reason: 'no sidebar Livewire component' };

            const before = keys();
            const firstId = plId(rows()[0]);
            const secondId = plId(rows()[1]);
            if (!firstId || !secondId) return { ok: false, reason: 'could not read playlist ids from wire:key' };

            // Move the 2nd root playlist before the 1st.
            await comp.call('movePlaylist', secondId, null, firstId, 'before');
            for (let i = 0; i < 60 && keys()[0] === before[0]; i++) await sleep(150);
            const swapped = keys();

            // Restore: move it back to after whatever is now first.
            await comp.call('movePlaylist', secondId, null, plId(rows()[0]), 'after');
            for (let i = 0; i < 60 && keys()[0] !== before[0]; i++) await sleep(150);
            const restored = keys();

            return { ok: true, reason: '', before, swapped, restored };
        })()
    JS);

    expect($result['ok'])->toBeTrue($result['reason'] ?? 'reorder script failed');
    expect($result['swapped'][0])->toBe($result['before'][1], 'The second playlist should be first after moving it before the first.');
    expect($result['restored'])->toBe($result['before'], 'The original sidebar playlist order should be restored.');
});
```

(If `comp.call` errors because the component object's API differs in this Livewire build, fall back to `window.Livewire.find(compId).$wire.movePlaylist(...)` — but `.call(...)` is what `tests/Browser/PlaylistDetailTest.php`'s reorder test uses successfully, so try it first.)

- [ ] **Step 2: Run the browser test**

Run: `php artisan test --compact --filter="reorders two root playlists from the sidebar"`
Expected: PASS (requires the live Plex server reachable with ≥2 audio playlists; an unreachable server is an environmental failure like the file's other tests). If it fails on "could not read playlist ids" or "no sidebar Livewire component", inspect the rendered DOM in a browser and adjust the selector (the `wire:key="sidebar-pl-{id}"` prefix and the component `name === 'sidebar'` are the stable anchors).

- [ ] **Step 3: Full suite + pint**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan migrate` (ensure the dev DB has the new migration; harmless if already run)
Run: `php artisan test --compact`
Expected: green. (The test count grows by the new feature tests in Tasks 1–5 and the browser test here.)

- [ ] **Step 4: Commit**

```bash
git add tests/Browser/SidebarFoldersTest.php
git commit -m "test(sidebar): browser coverage for drag-to-reorder root playlists"
```

---

### Task 9: Docs

**Files:**
- Modify: `CLAUDE.md` (add a paragraph; bump the test count)
- Modify: `/Users/akrzy/.claude/projects/-Users-akrzy-Dev-Herd-Plexify/memory/pending_polish_followups.md` (mark item 3 shipped) and its `MEMORY.md` index line

- [ ] **Step 1: Add the CLAUDE.md paragraph**

Add a paragraph after the "Reorder tracks within a playlist (shipped 2026-05-12, branch `feat/playlist-reorder`)" paragraph in `CLAUDE.md`, in the same terse style: name the spec/plan/branch; the `folder_playlists.folder_id` nullable migration + the `nullOnDelete` behavior change (deleting a folder keeps its playlists at root); the `movePlaylist`/`moveFolder` Livewire actions and how root ordering works (placed-by-position then unplaced-by-Plex-order, materialized on first reorder); the `plextune/folder` drag type + folder-header `draggable`; that the row partial gained a `$folderId` param; the reused `.drop-before`/`.drop-after` insertion line; and the tests touched (`FolderTest`, `SidebarTest`, `SidebarFoldersTest`). Update the "`php artisan test` should be green (N tests, ...)" line to the new count (run `php artisan test --compact` to get it; it grows by the new feature tests + the one browser test).

- [ ] **Step 2: Update the memory follow-ups note**

In `/Users/akrzy/.claude/projects/-Users-akrzy-Dev-Herd-Plexify/memory/pending_polish_followups.md`, change item 3 ("Reorder playlists in the left sidebar") to a struck-through "DONE 2026-05-12 (`feat/sidebar-reorder`): ..." line, mirroring how items 1/2/5 are marked. Update the `MEMORY.md` one-line hook to drop "reorder sidebar playlists" from the open list. (These files are outside the repo — not committed.)

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(claude): record sidebar playlist/folder reordering"
```

---

## Self-Review

**Spec coverage:**
- `folder_id` nullable + `nullOnDelete` migration → Task 1. ✓
- "deleting a folder moves its playlists to root" → Task 1 (migration + the two updated tests). ✓
- Root render ordering (placed-by-position, then unplaced-by-Plex-order) + `rootPlacements()` computed → Task 2. ✓
- `movePlaylist(draggedPlaylistId, ?targetFolderId, ?targetPlaylistId, position)` — root reorder, materialize-all-root, no-op short-circuits → Task 3; folder destination, into-folder-at-top, between-folders renumber-source, out-to-root → Task 4. ✓
- `moveFolder(draggedFolderId, targetFolderId, position)` + no-ops → Task 5. ✓
- DnD UI: playlist-row reorder drop targets + insertion line; `x-data` additions (`draggingPlaylist`/`draggedPlaylistId`/`draggingFolder`/`draggedFolderId`/`overId`/`overPos` + helpers); `$folderId` param on the row partial; folder-header drop = into-folder-at-top; "Other" header drop = to-root-at-end; the unified row `@drop` branching between reorder and add-track → Task 6. Folder headers `draggable` (`plextune/folder`) + folder-reorder drop targets + insertion line → Task 7. ✓
- `.drop-before`/`.drop-after` reused, no CSS change → stated in Tasks 6/7. ✓
- "Move to {folder}" / "Remove from folder" menu entries unchanged (`movePlaylistToFolder` kept as-is) → noted in Task 6 Step 1. ✓
- Tests: feature (`SidebarTest`, `FolderTest`), browser (`SidebarFoldersTest`) → Tasks 1–5, 8. ✓
- `pint`, `migrate`, `php artisan test --compact` green; no `cache:clear` needed → Task 8. ✓

**Placeholder scan:** Task 8's `comp.call` has a stated fallback (`.$wire.movePlaylist(...)`) and the test fails loudly with a `reason` string if the selector breaks — acceptable, the `wire:key` prefix and component `name === 'sidebar'` are stable anchors. Task 6 Step 4's empty-folder-placeholder droppability is marked "optional polish, not required" — that's a deliberate scope note, not a placeholder. No "TBD"/"handle errors"/"similar to Task N" placeholders; every code step has the full code.

**Type consistency:** `movePlaylist(string, ?int, ?string, string): void` — used identically in the action definition (Task 3), the `placeInRoot`/`placeInFolder` dispatch (Tasks 3–4), the Blade `@drop` → `$wire.movePlaylist(dragged, folderId, playlistId, pos)` calls (Task 6: row drop with `folderId` = `null` or an int, folder-header drop with `(dragged, folderId, null, 'before')`, "Other" drop with `(dragged, null, lastRootId, 'after')`), the browser test `comp.call('movePlaylist', secondId, null, firstId, 'before')` (Task 8), and the feature tests (Tasks 3–4). `moveFolder(int, int, string): void` — consistent across Task 5's definition, Task 7's `$wire.moveFolder(dragged, folderId, pos)`, and Task 5's tests. `insertRelative(list<string>, string, ?string, string): list<string>` — used by `placeInRoot`, `placeInFolder`, and `moveFolder` (folder ids cast to/from string). `rootOrderedPlexIds(list<string>, list<string>): list<string>` — defined in Task 2, used by Task 2's render `@php` block and Task 3's `placeInRoot`. `renumberContainer(?int): void` — defined in Task 3, used by `placeInRoot` (Task 3) and `placeInFolder` (Task 4); `null` arg = the root container. `rootPlacements()` computed — defined Task 2, `unset()`-busted in `movePlaylist` (Task 3). Helper names: `rowDragOver`/`folderDragOver`/`onRowDrop`/`onFolderHeaderDrop`/`dropOnOtherHeader`/`dropTrackOnNew` — all defined in the Task 6 Step 1 `x-data` block and referenced consistently in Tasks 6–7.

No gaps found.
