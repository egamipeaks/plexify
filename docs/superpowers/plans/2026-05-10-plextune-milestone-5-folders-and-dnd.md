# Plextune Milestone 5: Folders + Drag & Drop Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add app-side playlist folders (Eloquent), native HTML5 drag-and-drop (playlists into folders, tracks/albums onto playlists, a track onto a "New playlist" drop zone), context menus on folders and playlist rows, a client-side playlist filter, and full Plex playlist CRUD (create / rename / delete / add-track / add-album) from the sidebar.

**Architecture:** Two new Eloquent models — `Folder` (`name`, `position`, `expanded`) and `FolderPlaylist` (pivot row: `folder_id`, `plex_playlist_id`, `position`). `PlexClient` gains six write/lookup methods (`machineIdentifier`, `addTrackToPlaylist`, `addAlbumToPlaylist`, `createPlaylist`, `renamePlaylist`, `deletePlaylist`), each bustng the `plex:playlists` cache. The `sidebar` Livewire SFC gets `folders()` / `folderOf()` computeds and ~11 action methods; its template is rewritten to render collapsible folders, an "Other" group, the flat fallback, inline-rename inputs, the "New playlist" drop zone, all DnD drop targets, two Alpine context menus, and a filter input. The library / playlist-detail / search track rows and the library album cover get `draggable` + a one-line native `ondragstart`. DnD glue is Alpine on the sidebar (`@dragstart`/`@dragover.prevent`/`@drop` calling `$wire.*`); drop feedback is an inline ~700ms green/red flash on the target. No global toast system, no queue-append — those stay M7.

**Tech Stack:** Laravel 13 Eloquent + migrations (only `up()`), Livewire 4 SFC (`#[Computed]`, `boot()` DI, `unset($this->prop)` to bust computeds, `$wire.*` from Alpine), Alpine.js (native HTML5 DnD), Tailwind 4, `blade-lucide-icons`, Pest 4 (`Http::fake()` for `PlexClient`, `Livewire::test()` + `RefreshDatabase` for the sidebar, Playwright browser plugin via `script()`).

**Spec reference:** `docs/superpowers/specs/2026-05-10-plextune-milestone-5-folders-and-dnd-design.md` (the whole document; it captures the brainstorming decisions).
**Visual reference:** `design/README.md` ("Folder UX" + "Drag-and-drop summary"), `design/plextune-parts.jsx` (`FolderedPlaylists`, `PlaylistRow`, `ContextMenu`), `design/rendered/your-library.html`.
**Prior milestone:** `docs/superpowers/plans/2026-05-10-plextune-milestone-4-playlist-detail.md`

---

## Key facts (confirmed against the codebase / Plex API)

- **Plex cannot create an empty playlist.** `POST /playlists` requires `type`, `title`, and a `uri` seed item: `POST /playlists?type=audio&title={t}&smart=0&uri=server://{machineId}/com.plexapp.plugins.library/library/metadata/{ratingKey}`. The response is `MediaContainer.Metadata[0].ratingKey`. Hence the "New playlist" flow is: drag a track onto a drop zone → that track is the seed.
- **Adding items:** `PUT /playlists/{id}/items?uri=server://{machineId}/com.plexapp.plugins.library/library/metadata/{ratingKey}`. The metadata id may be a *track* (one item) or an *album* (Plex expands it to its tracks).
- **Rename:** `PUT /playlists/{id}?title={t}`. **Delete:** `DELETE /playlists/{id}`.
- `server://{machineIdentifier}/...` URIs need the server's `machineIdentifier`, available from `GET /identity` → `MediaContainer.machineIdentifier`. `PlexClient::ping()` already reads this; M5 adds a cached `machineIdentifier()` accessor.
- `PlexClient` does **not** use `->throw()` (this Laravel version's HTTP client doesn't auto-throw on 4xx/5xx; inspect `$response->status()`). `Http::get/put/post/delete` can still raise `Illuminate\Http\Client\ConnectionException` on a connection failure — the new mutators catch it and rethrow as `PlexUnreachableException` (like `searchAll()` does).
- `PlexCache::forget('playlists')` clears `plex:playlists`; `forget("playlist:{$id}:items")` clears `plex:playlist:{$id}:items` (the key `playlistTracks()` uses). New playlists have no items key yet — forgetting a non-existent key is a no-op.
- The `PlexClient` test base URL (after the `tests/Fixtures/Plex/resources.json` discovery) is `https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400` — reuse that host in new `Http::fake()` entries (same as the existing `playlists`/`playlistTracks` cases).
- `Http::fake(['…/playlists*' => …])` matches `POST /playlists?…`, `PUT /playlists/{id}?…`, and `PUT /playlists/{id}/items?…` — one fake entry covers all three; distinguish them in `Http::assertSent()` by `$request->method()` and a URL substring.
- `tests/Pest.php` does **not** apply `RefreshDatabase` globally (it's commented out). DB-touching tests do `uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);` at the top of the file (see `tests/Feature/SettingTest.php`).
- `Livewire::test('sidebar')` also renders the nested `<livewire:server-chip />`, so a sidebar test's `PlexClient` mock must also stub `ping()`. After M5 the sidebar's `folders()` computed hits the DB on every render, so `SidebarTest` must `uses(RefreshDatabase::class)`.
- In the test environment `PLEX_TOKEN` is unset, so a full-page render's `<livewire:sidebar />` swallows the `PlexException` from `playlists()` → empty list. `RoutesTest` etc. stay hermetic; `folders()` just queries an empty `folders` table.
- `#[Computed]` methods don't autowire params — resolve services via `app()` or the `boot(PlexClient $plex)` + `protected PlexClient $plex` pattern (the sidebar already uses the latter).
- The player consumes a **single** track (`play-track` with `url` / `title` / `artist` / `artwork`) — there's no queue plumbing yet. `playPlaylist()` therefore dispatches the playlist's first track in that same shape (mirrors `playlist-detail`'s `playAll()`).
- Alpine `@`/`x-on` listeners need an `x-data` ancestor. The library / playlist-detail / search page roots have **no** `x-data`, so their track rows use a **native** `ondragstart="…"` attribute (not `@dragstart`). The sidebar wraps its playlists card in `x-data`, so its rows/zones/menus use Alpine.
- `[x-cloak]` has no CSS rule yet — Task 7 adds `[x-cloak]{display:none !important}` to `resources/css/app.css`.

---

## File Structure

**New:**
- `database/migrations/2026_05_10_000001_create_folders_table.php` — `folders` table.
- `database/migrations/2026_05_10_000002_create_folder_playlists_table.php` — `folder_playlists` pivot table.
- `app/Models/Folder.php` — `name`/`position`/`expanded`; `hasMany folderPlaylists` (ordered).
- `app/Models/FolderPlaylist.php` — `folder_id`/`plex_playlist_id`/`position`; `belongsTo folder`.
- `database/factories/FolderFactory.php` — folder factory for tests.
- `resources/views/partials/playlist-row.blade.php` — one playlist row (used by the sidebar both inside folders and at root); expects `$p` (Playlist DTO), `$renaming` (bool), `$thumbUrl` (?string).
- `tests/Feature/FolderTest.php` — model + relationship + cascade + defaults.
- `tests/Browser/SidebarFoldersTest.php` — create-folder, persistence, "New playlist" zone reveal, playlist→folder drop.

**Modified:**
- `app/Services/Plex/PlexClient.php` — add `machineIdentifier()`, `addTrackToPlaylist()`, `addAlbumToPlaylist()`, `createPlaylist()`, `renamePlaylist()`, `deletePlaylist()`, private `libraryItemUri()` and `ensureOk()` helpers; new `use Illuminate\Http\Client\Response;`.
- `resources/views/components/⚡sidebar.blade.php` — new public props, `folders()`/`folderOf()` computeds, the action methods; full template rewrite of the playlists card (folders, "Other", flat fallback, inline renames, "New playlist" drop zone, DnD targets, context menus, filter input). Nav card and `<livewire:server-chip />` unchanged.
- `resources/views/pages/⚡library.blade.php` — track `<button>`s get `draggable="true"` + `ondragstart` (`plextune/track`); the album-header 120px cover (`<img>` and placeholder branch) gets `draggable="true"` + `ondragstart` (`plextune/album`).
- `resources/views/pages/⚡playlist-detail.blade.php` — track `<button>`s get `draggable="true"` + `ondragstart` (`plextune/track`).
- `resources/views/pages/⚡search.blade.php` — track `<button>`s (the "Tracks" section rows) get `draggable="true"` + `ondragstart` (`plextune/track`).
- `resources/css/app.css` — add `[x-cloak]{display:none !important}`.
- `tests/Feature/SidebarTest.php` — add `uses(RefreshDatabase::class)`; add folder + playlist-action + render cases.
- `tests/Unit/PlexClientTest.php` — add cases for the six new methods.
- `CLAUDE.md` — milestone table → M5 done; new gotchas; bump the test count.

**No changes to:** `routes/web.php`, `resources/views/components/layouts/app.blade.php`, the player, `config/cache.php` (folders are Eloquent, never cached through `PlexCache`).

---

## Task 1: `Folder` + `FolderPlaylist` models, migrations, factory

**Files:**
- Create: `database/migrations/2026_05_10_000001_create_folders_table.php`
- Create: `database/migrations/2026_05_10_000002_create_folder_playlists_table.php`
- Create: `app/Models/Folder.php`
- Create: `app/Models/FolderPlaylist.php`
- Create: `database/factories/FolderFactory.php`
- Test: `tests/Feature/FolderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FolderTest.php`:

```php
<?php

use App\Models\Folder;
use App\Models\FolderPlaylist;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns folder playlists ordered by position', function () {
    $folder = Folder::factory()->create();
    $folder->folderPlaylists()->create(['plex_playlist_id' => 'b', 'position' => 2]);
    $folder->folderPlaylists()->create(['plex_playlist_id' => 'a', 'position' => 1]);

    expect($folder->folderPlaylists->pluck('plex_playlist_id')->all())->toBe(['a', 'b']);
});

it('cascades deletes to its folder playlists', function () {
    $folder = Folder::factory()->create();
    $folder->folderPlaylists()->create(['plex_playlist_id' => 'x', 'position' => 0]);

    $folder->delete();

    expect(FolderPlaylist::count())->toBe(0);
});

it('defaults a new folder to expanded with position zero', function () {
    $folder = Folder::create(['name' => 'Moods'])->fresh();

    expect($folder->expanded)->toBeTrue()
        ->and($folder->position)->toBe(0);
});

it('enforces one folder per playlist via a unique index', function () {
    $a = Folder::factory()->create();
    $b = Folder::factory()->create();
    $a->folderPlaylists()->create(['plex_playlist_id' => 'p1', 'position' => 0]);

    expect(fn () => $b->folderPlaylists()->create(['plex_playlist_id' => 'p1', 'position' => 0]))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FolderTest`
Expected: FAIL — `Class "App\Models\Folder" not found` (or migration/table missing).

- [ ] **Step 3: Create the migrations**

Create `database/migrations/2026_05_10_000001_create_folders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('expanded')->default(true);
            $table->timestamps();
        });
    }
};
```

Create `database/migrations/2026_05_10_000002_create_folder_playlists_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_playlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained()->cascadeOnDelete();
            $table->string('plex_playlist_id');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique('plex_playlist_id');
        });
    }
};
```

- [ ] **Step 4: Create the models**

Create `app/Models/Folder.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folder extends Model
{
    /** @use HasFactory<\Database\Factories\FolderFactory> */
    use HasFactory;

    protected $fillable = ['name', 'position', 'expanded'];

    protected $casts = [
        'position' => 'integer',
        'expanded' => 'boolean',
    ];

    /** @return HasMany<FolderPlaylist, $this> */
    public function folderPlaylists(): HasMany
    {
        return $this->hasMany(FolderPlaylist::class)->orderBy('position')->orderBy('id');
    }
}
```

Create `app/Models/FolderPlaylist.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolderPlaylist extends Model
{
    protected $fillable = ['folder_id', 'plex_playlist_id', 'position'];

    protected $casts = [
        'folder_id' => 'integer',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }
}
```

- [ ] **Step 5: Create the factory**

Create `database/factories/FolderFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Folder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Folder> */
class FolderFactory extends Factory
{
    protected $model = Folder::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'position' => 0,
            'expanded' => true,
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=FolderTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Folder.php app/Models/FolderPlaylist.php database/migrations/2026_05_10_000001_create_folders_table.php database/migrations/2026_05_10_000002_create_folder_playlists_table.php database/factories/FolderFactory.php tests/Feature/FolderTest.php
git commit -m "feat: Folder + FolderPlaylist models for app-side playlist folders"
```

---

## Task 2: `PlexClient::machineIdentifier()` + `libraryItemUri()` + `ensureOk()` helpers

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Test: `tests/Unit/PlexClientTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/PlexClientTest.php`:

```php
it('reads and caches the server machine identifier', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response(
            ['MediaContainer' => ['machineIdentifier' => 'MACHINE-123']], 200,
        ),
    ]);

    $client = app(PlexClient::class);

    expect($client->machineIdentifier())->toBe('MACHINE-123')
        ->and($client->machineIdentifier())->toBe('MACHINE-123'); // cached, no second /identity call

    Http::assertSentCount(2); // resources + identity, only once each
});

it('throws PlexUnreachableException when /identity fails for machineIdentifier', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->machineIdentifier())->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="machine identifier"`
Expected: FAIL — `Method ...::machineIdentifier() does not exist`.

- [ ] **Step 3: Implement the helpers**

In `app/Services/Plex/PlexClient.php`, add to the imports near the top (after `use Illuminate\Http\Client\PendingRequest;`):

```php
use Illuminate\Http\Client\Response;
```

Add `machineIdentifier()` as a public method (place it just after `playlistTracks()`):

```php
public function machineIdentifier(): string
{
    return $this->cache->remember('machine_identifier', PlexCache::TTL_RESOURCES, function () {
        $response = $this->server()->get('/identity');

        if (! $response->successful()) {
            throw new PlexUnreachableException('identity endpoint returned '.$response->status());
        }

        $id = data_get($response->json(), 'MediaContainer.machineIdentifier');

        if (empty($id)) {
            throw new PlexNotFoundException('Plex /identity did not return a machineIdentifier.');
        }

        return (string) $id;
    });
}
```

Add two private helpers just above `private function server(): PendingRequest`:

```php
private function libraryItemUri(string $ratingKey): string
{
    return sprintf(
        'server://%s/com.plexapp.plugins.library/library/metadata/%s',
        $this->machineIdentifier(),
        $ratingKey,
    );
}

private function ensureOk(Response $response, string $context): void
{
    if ($response->status() === 404) {
        throw new PlexNotFoundException("{$context}: not found.");
    }

    if (in_array($response->status(), [401, 403], true)) {
        throw new PlexAuthException("{$context}: Plex rejected the request (status {$response->status()}).");
    }

    if (! $response->successful()) {
        throw new PlexUnreachableException("{$context} returned {$response->status()}.");
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="machine identifier"`
Expected: PASS (2 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat: PlexClient::machineIdentifier() + URI/response helpers"
```

---

## Task 3: `PlexClient::addTrackToPlaylist()` + `addAlbumToPlaylist()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Test: `tests/Unit/PlexClientTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/PlexClientTest.php`:

```php
function fakePlexWriteEndpoints(): void
{
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response(
            ['MediaContainer' => ['machineIdentifier' => 'M1']], 200,
        ),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists*' => Http::response(
            ['MediaContainer' => ['Metadata' => [['ratingKey' => '7777']]]], 200,
        ),
    ]);
}

it('adds a track to a playlist via PUT /playlists/{id}/items with a server uri', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:playlists', 'stale', 300);

    app(PlexClient::class)->addTrackToPlaylist('4242', '99');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/playlists/4242/items?')
        && str_contains($request->url(), 'uri='.urlencode('server://M1/com.plexapp.plugins.library/library/metadata/99')));

    expect(Cache::has('plex:playlists'))->toBeFalse();
});

it('adds an album to a playlist via PUT /playlists/{id}/items with the album rating key', function () {
    fakePlexWriteEndpoints();

    app(PlexClient::class)->addAlbumToPlaylist('4242', '1001');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/playlists/4242/items?')
        && str_contains($request->url(), 'uri='.urlencode('server://M1/com.plexapp.plugins.library/library/metadata/1001')));
});

it('maps a 404 from add-to-playlist to PlexNotFoundException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'M1']], 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242/items*' => Http::response('nope', 404),
    ]);

    expect(fn () => app(PlexClient::class)->addTrackToPlaylist('4242', '99'))->toThrow(PlexNotFoundException::class);
});

it('maps a 500 from add-to-playlist to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'M1']], 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242/items*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->addTrackToPlaylist('4242', '99'))->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="add a track to a playlist"`
Expected: FAIL — `Method ...::addTrackToPlaylist() does not exist`.

- [ ] **Step 3: Implement the methods**

In `app/Services/Plex/PlexClient.php`, add after `machineIdentifier()`:

```php
public function addTrackToPlaylist(string $playlistId, string $trackId): void
{
    $this->putPlaylistItem($playlistId, $this->libraryItemUri($trackId));
}

public function addAlbumToPlaylist(string $playlistId, string $albumId): void
{
    $this->putPlaylistItem($playlistId, $this->libraryItemUri($albumId));
}

private function putPlaylistItem(string $playlistId, string $uri): void
{
    try {
        $response = $this->server()->put("/playlists/{$playlistId}/items?".http_build_query(['uri' => $uri]));
    } catch (ConnectionException $e) {
        throw new PlexUnreachableException('Adding to playlist failed: '.$e->getMessage(), previous: $e);
    }

    $this->ensureOk($response, "playlists/{$playlistId}/items");

    $this->cache->forget('playlists');
    $this->cache->forget("playlist:{$playlistId}:items");
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="playlist"`
Expected: PASS (the new add cases plus the existing `playlists`/`playlistTracks` cases).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat: PlexClient::addTrackToPlaylist() + addAlbumToPlaylist()"
```

---

## Task 4: `PlexClient::createPlaylist()` + `renamePlaylist()` + `deletePlaylist()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Test: `tests/Unit/PlexClientTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/PlexClientTest.php`:

```php
it('creates an audio playlist seeded from a track and returns the new rating key', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:playlists', 'stale', 300);

    $id = app(PlexClient::class)->createPlaylist('My Mix', '99');

    expect($id)->toBe('7777');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/playlists?')
        && str_contains($request->url(), 'type=audio')
        && str_contains($request->url(), 'smart=0')
        && str_contains($request->url(), 'title=My+Mix')
        && str_contains($request->url(), 'uri='.urlencode('server://M1/com.plexapp.plugins.library/library/metadata/99')));

    expect(Cache::has('plex:playlists'))->toBeFalse();
});

it('renames a playlist via PUT /playlists/{id}?title=', function () {
    fakePlexWriteEndpoints();
    Cache::put('plex:playlists', 'stale', 300);

    app(PlexClient::class)->renamePlaylist('4242', 'Renamed');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/playlists/4242?')
        && str_contains($request->url(), 'title=Renamed'));

    expect(Cache::has('plex:playlists'))->toBeFalse();
});

it('deletes a playlist via DELETE /playlists/{id}', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242' => Http::response('', 200),
    ]);
    Cache::put('plex:playlists', 'stale', 300);

    app(PlexClient::class)->deletePlaylist('4242');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/playlists/4242'));

    expect(Cache::has('plex:playlists'))->toBeFalse();
});

it('maps a 404 from createPlaylist to PlexNotFoundException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'M1']], 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists*' => Http::response('nope', 404),
    ]);

    expect(fn () => app(PlexClient::class)->createPlaylist('X', '99'))->toThrow(PlexNotFoundException::class);
});

it('maps a 500 from renamePlaylist to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/playlists/4242*' => Http::response('boom', 500),
    ]);

    expect(fn () => app(PlexClient::class)->renamePlaylist('4242', 'X'))->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="creates an audio playlist"`
Expected: FAIL — `Method ...::createPlaylist() does not exist`.

- [ ] **Step 3: Implement the methods**

In `app/Services/Plex/PlexClient.php`, add after `putPlaylistItem()`:

```php
public function createPlaylist(string $title, string $seedTrackId): string
{
    try {
        $response = $this->server()->post('/playlists?'.http_build_query([
            'type' => 'audio',
            'title' => $title,
            'smart' => 0,
            'uri' => $this->libraryItemUri($seedTrackId),
        ]));
    } catch (ConnectionException $e) {
        throw new PlexUnreachableException('Creating playlist failed: '.$e->getMessage(), previous: $e);
    }

    $this->ensureOk($response, 'POST playlists');

    $id = data_get($response->json(), 'MediaContainer.Metadata.0.ratingKey');

    if (empty($id)) {
        throw new PlexNotFoundException('Plex did not return a ratingKey for the new playlist.');
    }

    $this->cache->forget('playlists');

    return (string) $id;
}

public function renamePlaylist(string $playlistId, string $title): void
{
    try {
        $response = $this->server()->put("/playlists/{$playlistId}?".http_build_query(['title' => $title]));
    } catch (ConnectionException $e) {
        throw new PlexUnreachableException('Renaming playlist failed: '.$e->getMessage(), previous: $e);
    }

    $this->ensureOk($response, "PUT playlists/{$playlistId}");
    $this->cache->forget('playlists');
}

public function deletePlaylist(string $playlistId): void
{
    try {
        $response = $this->server()->delete("/playlists/{$playlistId}");
    } catch (ConnectionException $e) {
        throw new PlexUnreachableException('Deleting playlist failed: '.$e->getMessage(), previous: $e);
    }

    $this->ensureOk($response, "DELETE playlists/{$playlistId}");
    $this->cache->forget('playlists');
    $this->cache->forget("playlist:{$playlistId}:items");
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=PlexClientTest`
Expected: PASS (all `PlexClientTest` cases).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat: PlexClient::createPlaylist() + renamePlaylist() + deletePlaylist()"
```

---

## Task 5: Sidebar component logic — props, computeds, action methods

This task changes only the PHP class block at the top of `resources/views/components/⚡sidebar.blade.php`. The template (everything below `?>`) is left as the M4 flat list for now — it still renders fine. Tests assert the action behaviors (DB state + dispatched events + return values), not the new rendering (that's Task 6).

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php` (PHP block only)
- Test: `tests/Feature/SidebarTest.php`

- [ ] **Step 1: Write the failing tests**

Replace the entire contents of `tests/Feature/SidebarTest.php` with:

```php
<?php

use App\Models\Folder;
use App\Models\FolderPlaylist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function playlist(string $id, string $title, int $count = 1): Playlist
{
    return new Playlist(id: $id, title: $title, trackCount: $count, durationMs: 0, thumb: null, playlistType: 'audio');
}

function mockSidebarPlex(array $playlists = [], ?Closure $extra = null): void
{
    test()->mock(PlexClient::class, function ($mock) use ($playlists, $extra) {
        $mock->shouldReceive('playlists')->andReturn(collect($playlists));
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('ping')->andReturn(['name' => 'HOME', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'abc']);

        if ($extra) {
            $extra($mock);
        }
    });
}

it('lists the Plex playlists as links into the detail view', function () {
    mockSidebarPlex([playlist('4242', 'Late Night', 12), playlist('4243', 'Bangers', 30)]);

    Livewire::test('sidebar')
        ->assertSee('Late Night')
        ->assertSee('Bangers')
        ->assertSeeHtml('href="'.route('playlist', '4242').'"')
        ->assertSeeHtml('href="'.route('playlist', '4243').'"')
        ->assertDontSee('No playlists yet');
});

it('falls back to the empty placeholder when Plex is unreachable and there are no folders', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andThrow(new PlexUnreachableException('refused'));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn(['name' => 'HOME', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => 'abc']);
    });

    Livewire::test('sidebar')
        ->assertSee('No playlists yet')
        ->assertSee('Your Playlists');
});

it('creates a folder and enters rename mode', function () {
    mockSidebarPlex();

    Livewire::test('sidebar')
        ->call('createFolder')
        ->assertSet('renamingFolderId', fn ($id) => $id === Folder::first()->id);

    expect(Folder::count())->toBe(1)
        ->and(Folder::first()->name)->toBe('New Folder');
});

it('renames a folder and ignores a blank name', function () {
    mockSidebarPlex();
    $folder = Folder::factory()->create(['name' => 'Old']);

    Livewire::test('sidebar')
        ->set('renamingFolderId', $folder->id)
        ->call('renameFolder', $folder->id, '  Moods  ')
        ->assertSet('renamingFolderId', null);
    expect($folder->fresh()->name)->toBe('Moods');

    Livewire::test('sidebar')->call('renameFolder', $folder->id, '   ');
    expect($folder->fresh()->name)->toBe('Moods');
});

it('deletes a folder and its pivot rows', function () {
    mockSidebarPlex();
    $folder = Folder::factory()->create();
    $folder->folderPlaylists()->create(['plex_playlist_id' => 'p1', 'position' => 0]);

    Livewire::test('sidebar')->call('deleteFolder', $folder->id);

    expect(Folder::count())->toBe(0)
        ->and(FolderPlaylist::count())->toBe(0);
});

it('toggles a folder open and closed', function () {
    mockSidebarPlex();
    $folder = Folder::factory()->create(['expanded' => true]);

    Livewire::test('sidebar')->call('toggleFolder', $folder->id);
    expect($folder->fresh()->expanded)->toBeFalse();

    Livewire::test('sidebar')->call('toggleFolder', $folder->id);
    expect($folder->fresh()->expanded)->toBeTrue();
});

it('moves a playlist into a folder, replacing any prior placement, and back to root', function () {
    mockSidebarPlex([playlist('p1', 'One')]);
    $a = Folder::factory()->create();
    $b = Folder::factory()->create();

    $component = Livewire::test('sidebar');

    $component->call('movePlaylistToFolder', 'p1', $a->id);
    expect(FolderPlaylist::where('plex_playlist_id', 'p1')->count())->toBe(1)
        ->and(FolderPlaylist::where('plex_playlist_id', 'p1')->first()->folder_id)->toBe($a->id);

    $component->call('movePlaylistToFolder', 'p1', $b->id);
    expect(FolderPlaylist::where('plex_playlist_id', 'p1')->count())->toBe(1)
        ->and(FolderPlaylist::where('plex_playlist_id', 'p1')->first()->folder_id)->toBe($b->id);

    $component->call('movePlaylistToFolder', 'p1', null);
    expect(FolderPlaylist::where('plex_playlist_id', 'p1')->count())->toBe(0);
});

it('adds a track to a playlist via PlexClient and reports success/failure', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('addTrackToPlaylist')->once()->with('4242', '99')->andReturnNull();
    });

    Livewire::test('sidebar')->call('addTrackToPlaylist', '4242', '99')->assertReturned(true);
});

it('returns false from addTrackToPlaylist when Plex rejects it', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('addTrackToPlaylist')->andThrow(new PlexUnreachableException('refused'));
    });

    Livewire::test('sidebar')->call('addTrackToPlaylist', '4242', '99')->assertReturned(false);
});

it('adds an album to a playlist via PlexClient', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('addAlbumToPlaylist')->once()->with('4242', '1001')->andReturnNull();
    });

    Livewire::test('sidebar')->call('addAlbumToPlaylist', '4242', '1001')->assertReturned(true);
});

it('creates a playlist from a dragged track and enters rename mode on the new id', function () {
    mockSidebarPlex([], function ($mock) {
        $mock->shouldReceive('createPlaylist')->once()->with('New Playlist', '99')->andReturn('5005');
    });

    Livewire::test('sidebar')
        ->call('createPlaylistFromTrack', '99')
        ->assertReturned(true)
        ->assertSet('renamingPlaylistId', '5005');
});

it('renames a playlist through Plex and ignores a blank name', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('renamePlaylist')->once()->with('4242', 'Chill')->andReturnNull();
    });

    Livewire::test('sidebar')
        ->set('renamingPlaylistId', '4242')
        ->call('renamePlaylist', '4242', '  Chill  ')
        ->assertSet('renamingPlaylistId', null);
});

it('deletes a playlist through Plex and clears any folder placement', function () {
    mockSidebarPlex([], function ($mock) {
        $mock->shouldReceive('deletePlaylist')->once()->with('4242')->andReturnNull();
    });
    $folder = Folder::factory()->create();
    $folder->folderPlaylists()->create(['plex_playlist_id' => '4242', 'position' => 0]);

    Livewire::test('sidebar')->call('deletePlaylist', '4242');

    expect(FolderPlaylist::where('plex_playlist_id', '4242')->count())->toBe(0);
});

it('plays the first track of a playlist', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('playlistTracks')->once()->with('4242')->andReturn(collect([
            new Track(id: '1', title: 'Song', artist: 'Band', album: 'Rec', trackNumber: 1, durationMs: 1000, partId: 7, container: 'flac', thumb: '/t'),
        ]));
        $mock->shouldReceive('streamUrl')->andReturn('https://stream/1.flac');
    });

    Livewire::test('sidebar')
        ->call('playPlaylist', '4242')
        ->assertDispatched('play-track', url: 'https://stream/1.flac', title: 'Song', artist: 'Band');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=SidebarTest`
Expected: FAIL — the new `call()`s hit methods that don't exist yet (`createFolder`, etc.), and `RefreshDatabase` plus the `folders` table now require Tasks 1's migrations (already merged).

- [ ] **Step 3: Rewrite the sidebar PHP block**

In `resources/views/components/⚡sidebar.blade.php`, replace everything from `<?php` down to and including the `?>` line (the `new class extends Component { ... };` block) with:

```php
<?php

use App\Models\Folder;
use App\Models\FolderPlaylist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $renamingFolderId = null;

    public ?string $renamingPlaylistId = null;

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    /** @return Collection<int, Playlist> */
    #[Computed]
    public function playlists(): Collection
    {
        try {
            return $this->plex->playlists();
        } catch (PlexException) {
            return collect();
        }
    }

    /** @return Collection<int, Folder> */
    #[Computed]
    public function folders(): Collection
    {
        return Folder::with('folderPlaylists')->orderBy('position')->orderBy('id')->get();
    }

    /** @return array<string, int> Plex playlist id => folder id */
    #[Computed]
    public function folderOf(): array
    {
        $map = [];

        foreach ($this->folders as $folder) {
            foreach ($folder->folderPlaylists as $fp) {
                $map[$fp->plex_playlist_id] = $folder->id;
            }
        }

        return $map;
    }

    public function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }

    public function createFolder(): void
    {
        $folder = Folder::create([
            'name' => 'New Folder',
            'position' => (int) Folder::max('position') + 1,
            'expanded' => true,
        ]);

        unset($this->folders, $this->folderOf);
        $this->renamingFolderId = $folder->id;
    }

    public function renameFolder(int $id, string $name): void
    {
        $name = trim($name);

        if ($name !== '') {
            Folder::whereKey($id)->update(['name' => $name]);
            unset($this->folders);
        }

        $this->renamingFolderId = null;
    }

    public function deleteFolder(int $id): void
    {
        Folder::whereKey($id)->delete();
        unset($this->folders, $this->folderOf);
    }

    public function toggleFolder(int $id): void
    {
        $folder = Folder::find($id);

        if (! $folder) {
            return;
        }

        $folder->update(['expanded' => ! $folder->expanded]);
        unset($this->folders);
    }

    public function movePlaylistToFolder(string $playlistId, ?int $folderId): void
    {
        FolderPlaylist::where('plex_playlist_id', $playlistId)->delete();

        if ($folderId !== null && Folder::whereKey($folderId)->exists()) {
            FolderPlaylist::create([
                'folder_id' => $folderId,
                'plex_playlist_id' => $playlistId,
                'position' => (int) FolderPlaylist::where('folder_id', $folderId)->max('position') + 1,
            ]);
        }

        unset($this->folders, $this->folderOf);
    }

    public function addTrackToPlaylist(string $playlistId, string $trackId): bool
    {
        try {
            $this->plex->addTrackToPlaylist($playlistId, $trackId);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('addTrackToPlaylist failed', ['playlist' => $playlistId, 'track' => $trackId, 'error' => $e->getMessage()]);

            return false;
        }

        unset($this->playlists);

        return true;
    }

    public function addAlbumToPlaylist(string $playlistId, string $albumId): bool
    {
        try {
            $this->plex->addAlbumToPlaylist($playlistId, $albumId);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('addAlbumToPlaylist failed', ['playlist' => $playlistId, 'album' => $albumId, 'error' => $e->getMessage()]);

            return false;
        }

        unset($this->playlists);

        return true;
    }

    public function createPlaylistFromTrack(string $trackId): bool
    {
        try {
            $newId = $this->plex->createPlaylist('New Playlist', $trackId);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('createPlaylistFromTrack failed', ['track' => $trackId, 'error' => $e->getMessage()]);

            return false;
        }

        unset($this->playlists);
        $this->renamingPlaylistId = $newId;

        return true;
    }

    public function renamePlaylist(string $playlistId, string $name): void
    {
        $name = trim($name);

        if ($name !== '') {
            try {
                $this->plex->renamePlaylist($playlistId, $name);
                unset($this->playlists);
            } catch (PlexException $e) {
                Log::channel('plex')->warning('renamePlaylist failed', ['playlist' => $playlistId, 'error' => $e->getMessage()]);
            }
        }

        $this->renamingPlaylistId = null;
    }

    public function deletePlaylist(string $playlistId): void
    {
        try {
            $this->plex->deletePlaylist($playlistId);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('deletePlaylist failed', ['playlist' => $playlistId, 'error' => $e->getMessage()]);

            return;
        }

        FolderPlaylist::where('plex_playlist_id', $playlistId)->delete();
        unset($this->playlists, $this->folders, $this->folderOf);
    }

    public function playPlaylist(string $playlistId): void
    {
        try {
            $tracks = $this->plex->playlistTracks($playlistId);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('playPlaylist failed', ['playlist' => $playlistId, 'error' => $e->getMessage()]);

            return;
        }

        $track = $tracks->first();

        if (! $track instanceof Track) {
            return;
        }

        $this->dispatch('play-track',
            url: $this->plex->streamUrl($track),
            title: $track->title,
            artist: $track->artist,
            artwork: $this->thumbFor($track->thumb),
        );
    }
};
?>
```

(Leave the rest of the file — the `<aside>` template — exactly as it is for now; it still renders the M4 flat list.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=SidebarTest`
Expected: PASS — all the new *action* cases (`createFolder`, `renameFolder`, `deleteFolder`, `toggleFolder`, `movePlaylistToFolder`, `addTrackToPlaylist` ×2, `addAlbumToPlaylist`, `createPlaylistFromTrack`, `renamePlaylist`, `deletePlaylist`, `playPlaylist`) plus the two original `assertSee` cases (the M4 flat template still lists every playlist). The folder *rendering* assertions ("Other" group, folder names, inline rename inputs) are not exercised here — they're added in Task 6.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/⚡sidebar.blade.php tests/Feature/SidebarTest.php
git commit -m "feat: sidebar folder + playlist-CRUD actions (logic)"
```

---

## Task 6: Sidebar template — folders, "Other", inline renames, drop zone, DnD targets, context menus, filter

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php` (template only — everything after `?>`)
- Create: `resources/views/partials/playlist-row.blade.php`
- Test: `tests/Feature/SidebarTest.php` (add the folder-rendering cases)

- [ ] **Step 1: Add the failing render tests**

Append to `tests/Feature/SidebarTest.php`:

```php
it('renders folder contents and an "Other" group', function () {
    mockSidebarPlex([playlist('p1', 'Filed One'), playlist('p2', 'Unfiled Two')]);
    $folder = Folder::factory()->create(['name' => 'Moods']);
    $folder->folderPlaylists()->create(['plex_playlist_id' => 'p1', 'position' => 0]);

    Livewire::test('sidebar')
        ->assertSee('Moods')
        ->assertSee('Filed One')
        ->assertSee('Other')
        ->assertSee('Unfiled Two')
        ->assertSeeHtml('wire:key="folder-'.$folder->id.'"');
});

it('shows the New folder button and the per-folder toggle control', function () {
    mockSidebarPlex();
    $folder = Folder::factory()->create(['name' => 'Drafts']);

    Livewire::test('sidebar')
        ->assertSeeHtml('wire:click="createFolder"')
        ->assertSeeHtml('toggleFolder('.$folder->id.')')
        ->assertSee('Filter playlists'); // the filter input placeholder
});
```

Run: `php artisan test --compact --filter=SidebarTest` — expect the two new tests to FAIL (template not rewritten yet); every other case still passes.

- [ ] **Step 2: Create the playlist-row partial**

Create `resources/views/partials/playlist-row.blade.php`:

```blade
{{--
    One playlist row for the sidebar (used both inside folders and at root).
    Expects: $p (App\Services\Plex\Dto\Playlist), $renaming (bool), $thumbUrl (?string).
    Renders inside the playlists card's x-data scope (uses dropTarget / flash / draggingTrack / openMenu).
--}}
@php($isActive = request()->routeIs('playlist') && (string) request()->route('playlist') === $p->id)
<div wire:key="sidebar-pl-{{ $p->id }}"
     x-show="matches(@js($p->title))"
     :class="{
        'ring-1 ring-emerald-400 rounded-md': flash['pl-{{ $p->id }}'] === 'ok',
        'ring-1 ring-red-400 rounded-md': flash['pl-{{ $p->id }}'] === 'err',
     }">
    @if ($renaming)
        <div class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-text-2">
            <div class="rounded-sm flex-none bg-surface-2 grid place-items-center" style="width: 32px; height: 32px;">
                <x-lucide-list-music class="w-3.5 h-3.5 text-text-3" />
            </div>
            <input type="text" value="{{ $p->title }}"
                   x-init="$el.focus(); $el.select()"
                   @keydown.enter="$el.blur()"
                   @keydown.escape="$wire.set('renamingPlaylistId', null)"
                   @blur="$wire.renamePlaylist('{{ $p->id }}', $event.target.value)"
                   class="flex-1 min-w-0 bg-white/10 ring-1 ring-white/30 rounded px-1.5 py-0.5 text-[13px] font-medium text-white outline-none">
        </div>
    @else
        <a href="{{ route('playlist', $p->id) }}" wire:navigate draggable="true"
           @dragstart="$event.dataTransfer.effectAllowed='move'; $event.dataTransfer.setData('plextune/playlist', '{{ $p->id }}')"
           @contextmenu="openMenu($event, 'playlist', '{{ $p->id }}')"
           @dragover.prevent="if (draggingTrack) dropTarget = 'pl-{{ $p->id }}'"
           @dragleave="dropTarget = null"
           @drop="dropTrackOn('pl-{{ $p->id }}', '{{ $p->id }}', $event)"
           :class="dropTarget === 'pl-{{ $p->id }}' ? 'bg-accent/15 ring-1 ring-accent/40' : ''"
           @class([
               'w-full flex items-center gap-3 px-2 py-1.5 rounded-md transition-colors',
               'text-white bg-surface-2' => $isActive,
               'text-text-2 hover:text-white hover:bg-surface-2' => ! $isActive,
           ])>
            @if ($p->thumb)
                <img src="{{ $thumbUrl }}" alt="{{ $p->title }}" draggable="false"
                     class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 32px; height: 32px;" loading="lazy">
            @else
                <div class="rounded-sm flex-none bg-surface-2 grid place-items-center" style="width: 32px; height: 32px;">
                    <x-lucide-list-music class="w-3.5 h-3.5 text-text-3" />
                </div>
            @endif
            <div class="min-w-0 flex-1">
                <div class="text-[14px] truncate">{{ $p->title }}</div>
                <div class="text-[12px] text-text-3 truncate">Playlist · {{ $p->trackCount }} songs</div>
            </div>
        </a>
    @endif
</div>
```

- [ ] **Step 3: Rewrite the sidebar template**

In `resources/views/components/⚡sidebar.blade.php`, replace everything *after* the closing `?>` (the entire `<aside>…</aside>` block) with:

```blade
<aside class="flex flex-col gap-2 h-full min-h-0">
    {{-- Top nav card --}}
    <div class="bg-surface rounded-lg p-2 flex flex-col gap-1">
        <a href="{{ route('library') }}" wire:navigate
           @class([
               'w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors',
               'text-white bg-surface-2' => request()->routeIs('library'),
               'text-text-2 hover:text-white' => ! request()->routeIs('library'),
           ])>
            <x-lucide-house class="w-5 h-5" />
            <span class="flex-1 text-left">Home</span>
        </a>

        <a href="{{ route('search') }}" wire:navigate
           @class([
               'w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors',
               'text-white bg-surface-2' => request()->routeIs('search'),
               'text-text-2 hover:text-white' => ! request()->routeIs('search'),
           ])>
            <x-lucide-search class="w-5 h-5" />
            <span class="flex-1 text-left">Search</span>
        </a>

        <a href="{{ route('library') }}" wire:navigate
           class="w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors text-text-2 hover:text-white">
            <x-lucide-library class="w-5 h-5" />
            <span class="flex-1 text-left">Your Library</span>
        </a>

        <a href="{{ route('library') }}" wire:navigate
           class="w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors text-text-2 hover:text-white">
            <x-lucide-clock class="w-5 h-5" />
            <span class="flex-1 text-left">Recently Added</span>
        </a>
    </div>

    {{-- Playlists card --}}
    <div class="bg-surface rounded-lg flex-1 min-h-0 flex flex-col"
         x-data="{
            filter: '',
            draggingTrack: false,
            menu: null,
            dropTarget: null,
            flash: {},
            folderOf: {{ Js::from((object) $this->folderOf) }},
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
            matches(name) { return !this.filter || (name || '').toLowerCase().includes(this.filter.toLowerCase()); },
            openMenu(e, kind, id) { e.preventDefault(); e.stopPropagation(); this.menu = { x: e.clientX, y: e.clientY, kind, id }; },
            flashRow(key, ok) { this.flash[key] = ok ? 'ok' : 'err'; setTimeout(() => { this.flash[key] = null; }, 700); },
            async dropTrackOn(key, playlistId, e) {
                e.preventDefault(); this.dropTarget = null;
                const trackId = e.dataTransfer.getData('plextune/track');
                const albumId = e.dataTransfer.getData('plextune/album');
                if (!trackId && !albumId) return;
                try {
                    const ok = trackId
                        ? await $wire.addTrackToPlaylist(playlistId, trackId)
                        : await $wire.addAlbumToPlaylist(playlistId, albumId);
                    this.flashRow(key, ok);
                } catch (_) { this.flashRow(key, false); }
            },
            async dropTrackOnNew(e) {
                e.preventDefault(); this.dropTarget = null;
                const trackId = e.dataTransfer.getData('plextune/track');
                if (!trackId) return;
                try { const ok = await $wire.createPlaylistFromTrack(trackId); this.flashRow('__new', ok); }
                catch (_) { this.flashRow('__new', false); }
            },
            dropPlaylistOn(folderId, e) {
                e.preventDefault(); this.dropTarget = null;
                const playlistId = e.dataTransfer.getData('plextune/playlist');
                if (playlistId) $wire.movePlaylistToFolder(playlistId, folderId);
            },
         }"
         @keydown.escape.window="menu = null">

        {{-- Header --}}
        <div class="flex items-center justify-between px-4 pt-3 pb-2">
            <div class="flex items-center gap-2 text-text-2 hover:text-white cursor-pointer">
                <x-lucide-library class="w-[18px] h-[18px]" />
                <span class="text-[14px] font-bold">Your Playlists</span>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" title="New folder" wire:click="createFolder"
                        class="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-surface-3">
                    <x-lucide-folder class="w-[14px] h-[14px]" />
                </button>
                <button type="button" title="Drag a song here to create a playlist"
                        class="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-surface-3">
                    <x-lucide-plus class="w-4 h-4" />
                </button>
            </div>
        </div>

        {{-- Filter bar --}}
        <div class="px-3 pb-2 flex items-center justify-between gap-2">
            <div class="flex items-center gap-1.5 flex-1 min-w-0 bg-surface-2 rounded-md h-7 px-2 text-text-2">
                <x-lucide-search class="w-[14px] h-[14px] flex-none" />
                <input type="text" x-model="filter" placeholder="Filter playlists"
                       class="bg-transparent outline-none text-[12px] flex-1 min-w-0 text-white placeholder:text-text-3">
            </div>
            <button type="button"
                    class="flex items-center gap-1 text-[12px] text-text-2 hover:text-white font-semibold flex-none">
                Recents
                <x-lucide-list-music class="w-3 h-3" />
            </button>
        </div>

        {{-- List --}}
        <div class="flex-1 overflow-y-auto scroll px-2 pb-2 flex flex-col gap-0.5">
            @php
                $playlists = $this->playlists;
                $byId = $playlists->keyBy('id');
                $filed = $this->folders->flatMap(fn ($f) => $f->folderPlaylists->pluck('plex_playlist_id'))->all();
                $rootPlaylists = $playlists->reject(fn ($p) => in_array($p->id, $filed, true))->values();
            @endphp

            {{-- Folders --}}
            @foreach ($this->folders as $folder)
                @php
                    $items = $folder->folderPlaylists
                        ->map(fn ($fp) => $byId->get($fp->plex_playlist_id))
                        ->filter()->values();
                @endphp
                <div wire:key="folder-{{ $folder->id }}" class="flex flex-col">
                    <div wire:click="toggleFolder({{ $folder->id }})"
                         @contextmenu="openMenu($event, 'folder', {{ $folder->id }})"
                         @dragover.prevent="dropTarget = 'folder-{{ $folder->id }}'"
                         @dragleave="dropTarget = null"
                         @drop="dropPlaylistOn({{ $folder->id }}, $event)"
                         :class="dropTarget === 'folder-{{ $folder->id }}' ? 'bg-accent/15 ring-1 ring-accent/40' : 'hover:bg-surface-2'"
                         class="group w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-left transition-colors cursor-pointer">
                        <x-lucide-chevron-right @class(['w-3 h-3 flex-none text-text-2 transition-transform', 'rotate-90' => $folder->expanded]) />
                        <x-lucide-folder class="w-4 h-4 text-text-2 flex-none" />
                        @if ($renamingFolderId === $folder->id)
                            <input type="text" value="{{ $folder->name }}"
                                   x-init="$el.focus(); $el.select()"
                                   @click.stop
                                   @keydown.enter="$el.blur()"
                                   @keydown.escape="$wire.set('renamingFolderId', null)"
                                   @blur="$wire.renameFolder({{ $folder->id }}, $event.target.value)"
                                   class="flex-1 min-w-0 bg-white/10 ring-1 ring-white/30 rounded px-1.5 py-0.5 text-[13px] font-bold text-white outline-none">
                        @else
                            <span @click.stop
                                  @dblclick="$wire.set('renamingFolderId', {{ $folder->id }})"
                                  class="text-[13px] font-bold text-white flex-1 truncate">{{ $folder->name }}</span>
                        @endif
                        <span class="text-[11px] text-text-3 tabular-nums flex-none">{{ $items->count() }}</span>
                    </div>
                    @if ($folder->expanded)
                        <div class="ml-3 pl-2 border-l border-white/10 flex flex-col gap-0.5 py-0.5">
                            @forelse ($items as $p)
                                @include('partials.playlist-row', ['p' => $p, 'renaming' => $renamingPlaylistId === $p->id, 'thumbUrl' => $this->thumbFor($p->thumb)])
                            @empty
                                <div class="px-2 py-2 text-[11px] text-text-3 italic">Empty &mdash; drop a playlist here</div>
                            @endforelse
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- "Other" header (only when folders exist and there are unfiled playlists) --}}
            @if ($this->folders->isNotEmpty() && $rootPlaylists->isNotEmpty())
                <div @dragover.prevent="dropTarget = '__root'"
                     @dragleave="dropTarget = null"
                     @drop="dropPlaylistOn(null, $event)"
                     :class="dropTarget === '__root' ? 'bg-accent/10 ring-1 ring-accent/30 rounded' : ''"
                     class="px-2 pt-2 pb-1 text-[10px] uppercase tracking-wider text-text-3 font-bold">Other</div>
            @endif

            {{-- Root playlists --}}
            @forelse ($rootPlaylists as $p)
                @include('partials.playlist-row', ['p' => $p, 'renaming' => $renamingPlaylistId === $p->id, 'thumbUrl' => $this->thumbFor($p->thumb)])
            @empty
                @if ($this->folders->isEmpty())
                    <div class="px-3 py-6 text-[12px] text-text-3 text-center">No playlists yet</div>
                @endif
            @endforelse

            {{-- "New playlist" drop zone — only while dragging a track --}}
            <div x-show="draggingTrack" x-cloak data-region="new-playlist-zone"
                 @dragover.prevent="dropTarget = '__new'"
                 @dragleave="dropTarget = null"
                 @drop="dropTrackOnNew($event)"
                 :class="{
                     'bg-accent/15 ring-1 ring-accent/40': dropTarget === '__new',
                     'bg-emerald-400/10 ring-1 ring-emerald-400': flash['__new'] === 'ok',
                     'bg-red-400/10 ring-1 ring-red-400': flash['__new'] === 'err',
                 }"
                 class="mt-1 flex items-center gap-2 px-2 py-2 rounded-md border border-dashed border-white/20 text-text-2 text-[12px] font-semibold">
                <x-lucide-plus class="w-3.5 h-3.5 flex-none" />
                New playlist
            </div>
        </div>

        {{-- Folder context menu --}}
        <template x-if="menu && menu.kind === 'folder'">
            <div class="fixed z-[1000] min-w-[200px] py-1 rounded-md bg-surface-3 ring-1 ring-white/10 shadow-2xl text-[13px]"
                 :style="`left:${menu.x}px; top:${menu.y}px`"
                 @click.outside="menu = null">
                <button type="button" class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10"
                        @click="$wire.set('renamingFolderId', menu.id); menu = null">Rename folder</button>
                <button type="button" class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10"
                        @click="$wire.toggleFolder(menu.id); menu = null">Expand / Collapse</button>
                <div class="my-1 border-t border-white/10"></div>
                <button type="button" class="w-full text-left px-3 py-1.5 text-red-400 hover:bg-red-500/10"
                        @click="$wire.deleteFolder(menu.id); menu = null">Delete folder</button>
            </div>
        </template>

        {{-- Playlist context menu --}}
        <template x-if="menu && menu.kind === 'playlist'">
            <div class="fixed z-[1000] min-w-[210px] py-1 rounded-md bg-surface-3 ring-1 ring-white/10 shadow-2xl text-[13px]"
                 :style="`left:${menu.x}px; top:${menu.y}px`"
                 @click.outside="menu = null">
                <button type="button" class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10"
                        @click="$wire.playPlaylist(menu.id); menu = null">Play</button>
                <div class="my-1 border-t border-white/10"></div>
                @foreach ($this->folders as $folder)
                    <button type="button"
                            class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10 flex items-center gap-2"
                            @click="$wire.movePlaylistToFolder(menu.id, {{ $folder->id }}); menu = null">
                        <x-lucide-folder class="w-3 h-3 text-text-2 flex-none" />
                        <span class="flex-1 truncate">Move to {{ $folder->name }}</span>
                        <span class="text-accent text-[11px]" x-show="folderOf[menu.id] === {{ $folder->id }}">&bull;</span>
                    </button>
                @endforeach
                <button type="button"
                        class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10 disabled:opacity-40 disabled:cursor-not-allowed"
                        x-bind:disabled="!folderOf[menu.id]"
                        @click="if (folderOf[menu.id]) { $wire.movePlaylistToFolder(menu.id, null); } menu = null">Remove from folder</button>
                <button type="button" class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10"
                        @click="$wire.set('renamingPlaylistId', menu.id); menu = null">Rename playlist</button>
                <div class="my-1 border-t border-white/10"></div>
                <button type="button" class="w-full text-left px-3 py-1.5 text-red-400 hover:bg-red-500/10"
                        @click="$wire.deletePlaylist(menu.id); menu = null">Delete playlist</button>
            </div>
        </template>
    </div>

    {{-- Server chip card --}}
    <livewire:server-chip />
</aside>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=SidebarTest`
Expected: PASS (all SidebarTest cases, including `renders folder contents and an "Other" group` and `New folder button`).

- [ ] **Step 5: Build assets + manual smoke (optional but recommended)**

Run: `npm run build`
Then open `https://plexify.test`: confirm playlists list, "New folder" creates a folder with a focused rename input, Escape cancels, the filter input narrows the list, right-clicking a folder and a playlist shows the context menus.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/⚡sidebar.blade.php resources/views/partials/playlist-row.blade.php tests/Feature/SidebarTest.php
git commit -m "feat: sidebar folders, Other group, inline renames, DnD targets, context menus, filter"
```

---

## Task 7: Make track rows + the album cover draggable; add the x-cloak CSS rule

**Files:**
- Modify: `resources/css/app.css`
- Modify: `resources/views/pages/⚡library.blade.php`
- Modify: `resources/views/pages/⚡playlist-detail.blade.php`
- Modify: `resources/views/pages/⚡search.blade.php`
- Test: `tests/Feature/LibraryViewTest.php`, `tests/Feature/PlaylistDetailViewTest.php`, `tests/Feature/SearchViewTest.php` (add a draggable-attribute assertion to one existing "renders a track row" test in each)

- [ ] **Step 1: Write the failing assertions**

In `tests/Feature/LibraryViewTest.php`, find a test that drills into an album and renders the tracklist (it asserts a track title or `wire:click="playTrack`), and add to its chain:

```php
        ->assertSeeHtml("ondragstart=")        // track rows are draggable
        ->assertSeeHtml('plextune/track')
        ->assertSeeHtml('plextune/album');     // the album-header cover is draggable
```

In `tests/Feature/PlaylistDetailViewTest.php`, find the test that renders track rows and add:

```php
        ->assertSeeHtml('plextune/track');
```

In `tests/Feature/SearchViewTest.php`, find the test that renders the "Tracks" section rows and add:

```php
        ->assertSeeHtml('plextune/track');
```

Run: `php artisan test --compact --filter="LibraryViewTest|PlaylistDetailViewTest|SearchViewTest"`
Expected: FAIL on the new `assertSeeHtml` lines.

- [ ] **Step 2: Add the x-cloak CSS rule**

In `resources/css/app.css`, add (near the top, after the imports / `@theme` block — anywhere top-level is fine):

```css
[x-cloak] { display: none !important; }
```

- [ ] **Step 3: Make the library track rows + album cover draggable**

In `resources/views/pages/⚡library.blade.php`, on the tracklist `<button>` (the one with `wire:click="playTrack('{{ $track->id }}')"`), add these two attributes alongside the existing ones:

```blade
                            draggable="true"
                            ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"
```

On the album-header cover, update both branches. Replace:

```blade
                        @if ($this->selectedAlbum->thumb)
                            <img src="{{ $this->thumbFor($this->selectedAlbum->thumb) }}" alt="{{ $this->selectedAlbum->title }}"
                                 class="rounded-md flex-none shadow-2xl bg-surface-2 object-cover" style="width: 120px; height: 120px;">
                        @else
                            <div class="rounded-md relative overflow-hidden flex-none shadow-2xl bg-surface-2 grid place-items-center" style="width: 120px; height: 120px;">
                                <x-lucide-disc class="w-10 h-10 text-text-3" />
                            </div>
                        @endif
```

with:

```blade
                        @if ($this->selectedAlbum->thumb)
                            <img src="{{ $this->thumbFor($this->selectedAlbum->thumb) }}" alt="{{ $this->selectedAlbum->title }}"
                                 draggable="true" title="Drag onto a playlist to add the whole album"
                                 ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/album', '{{ $this->selectedAlbum->id }}')"
                                 class="rounded-md flex-none shadow-2xl bg-surface-2 object-cover cursor-grab active:cursor-grabbing" style="width: 120px; height: 120px;">
                        @else
                            <div draggable="true" title="Drag onto a playlist to add the whole album"
                                 ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/album', '{{ $this->selectedAlbum->id }}')"
                                 class="rounded-md relative overflow-hidden flex-none shadow-2xl bg-surface-2 grid place-items-center cursor-grab active:cursor-grabbing" style="width: 120px; height: 120px;">
                                <x-lucide-disc class="w-10 h-10 text-text-3" />
                            </div>
                        @endif
```

- [ ] **Step 4: Make the playlist-detail track rows draggable**

In `resources/views/pages/⚡playlist-detail.blade.php`, on the tracklist `<button>` (the one with `wire:click="playTrack('{{ $track->id }}')"`), add:

```blade
                            draggable="true"
                            ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"
```

- [ ] **Step 5: Make the search track rows draggable**

In `resources/views/pages/⚡search.blade.php`, on the "Tracks" section `<button>` (the one with `wire:key="search-tr-{{ $track->id }}"` and `wire:click="playTrack('{{ $track->id }}')"`), add:

```blade
                                        draggable="true"
                                        ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter="LibraryViewTest|PlaylistDetailViewTest|SearchViewTest"`
Expected: PASS.

- [ ] **Step 7: Pint + build + commit**

```bash
vendor/bin/pint --dirty --format agent
npm run build
git add resources/css/app.css resources/views/pages/⚡library.blade.php resources/views/pages/⚡playlist-detail.blade.php resources/views/pages/⚡search.blade.php tests/Feature/LibraryViewTest.php tests/Feature/PlaylistDetailViewTest.php tests/Feature/SearchViewTest.php
git commit -m "feat: draggable track rows + album cover for playlist drag-and-drop"
```

---

## Task 8: Browser coverage — folder creation, persistence, "New playlist" zone, playlist→folder drop

**Files:**
- Create: `tests/Browser/SidebarFoldersTest.php`

> This test hits the live Plex library (needs `PLEX_TOKEN` set and at least one audio playlist), like the other browser tests. It creates a `Folder` and a `FolderPlaylist` pivot row in the **real** app DB, so it cleans those up at the end. It does **not** add any track to a real Plex playlist (that path is covered by `SidebarTest`).

- [ ] **Step 1: Write the test**

Create `tests/Browser/SidebarFoldersTest.php`:

```php
<?php

use App\Models\Folder;

/*
 * End-to-end coverage for the sidebar folders + drag-and-drop UI.
 *
 * Hits the live Plex library configured via PLEX_TOKEN — requires the server to be reachable
 * with at least one audio playlist (same environmental dependency as the other Browser tests).
 * List interactions are driven via script() (page.evaluate) for the same reasons documented in
 * the other browser tests (strict click() locators, no auto-wait on assertVisible()).
 *
 * Cleans up the Folder rows it creates so the dev database is left as it was found.
 */

afterEach(function () {
    Folder::query()->delete(); // cascades folder_playlists
});

it('creates a folder from the sidebar and it survives a reload', function () {
    $page = visit('/');

    $created = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const btn = document.querySelector('button[title="New folder"]');
            if (!btn) return false;
            btn.click();
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                if (document.querySelector('[wire\\:key^="folder-"]')) return true;
                await sleep(150);
            }
            return false;
        })()
    JS);
    expect($created)->toBeTrue('Expected a folder row after clicking "New folder".');

    expect(Folder::count())->toBe(1);

    // Reload — the folder is DB-backed, so it should still be there.
    $page = visit('/');
    $page->assertPresent('[wire\:key^="folder-"]');
});

it('reveals the "New playlist" drop zone while a track is being dragged', function () {
    $page = visit('/');

    $zoneVisible = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            // Get a track row to drag: open the first artist, then the first album.
            const deadline = Date.now() + 15000;
            const clickFirst = (sel) => { const el = document.querySelector(sel); if (el) el.click(); return !!el; };

            // Artists column
            while (Date.now() < deadline && !document.querySelector('[data-region="artists-column"] button')) await sleep(150);
            if (!clickFirst('[data-region="artists-column"] button')) return false;
            while (Date.now() < deadline && !document.querySelector('[data-region="albums-column"] button')) await sleep(150);
            if (!clickFirst('[data-region="albums-column"] button')) return false;
            while (Date.now() < deadline && !document.querySelector('[data-region="tracklist"] button[draggable="true"]')) await sleep(150);

            const row = document.querySelector('[data-region="tracklist"] button[draggable="true"]');
            if (!row) return false;

            const dt = new DataTransfer();
            row.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
            await sleep(150);

            const zone = document.querySelector('[data-region="new-playlist-zone"]');
            const visible = !!zone && getComputedStyle(zone).display !== 'none';

            row.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: dt }));
            return visible;
        })()
    JS);

    expect($zoneVisible)->toBeTrue('Expected the "New playlist" drop zone to appear when dragging a track.');
});

it('drops a playlist into a folder and renders it nested', function () {
    $page = visit('/');

    $nested = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 12000;

            // Ensure there is at least one playlist row.
            while (Date.now() < deadline && !document.querySelector('[wire\\:key^="sidebar-pl-"] a')) await sleep(150);
            const pl = document.querySelector('[wire\\:key^="sidebar-pl-"] a');
            if (!pl) return false;
            const playlistKey = pl.closest('[wire\\:key^="sidebar-pl-"]').getAttribute('wire:key');

            // Create a folder.
            const newFolderBtn = document.querySelector('button[title="New folder"]');
            if (!newFolderBtn) return false;
            newFolderBtn.click();
            while (Date.now() < deadline && !document.querySelector('[wire\\:key^="folder-"]')) await sleep(150);
            const folderEl = document.querySelector('[wire\\:key^="folder-"]');
            if (!folderEl) return false;
            const folderHeader = folderEl.querySelector('div'); // first child div carries the @drop handler

            // Simulate dragging the playlist <a> onto the folder header.
            const dt = new DataTransfer();
            pl.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
            folderHeader.dispatchEvent(new DragEvent('dragover', { bubbles: true, dataTransfer: dt }));
            folderHeader.dispatchEvent(new DragEvent('drop', { bubbles: true, dataTransfer: dt }));
            pl.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: dt }));

            // Wait for the Livewire round-trip: the playlist row should now live inside the folder element.
            const after = Date.now() + 8000;
            while (Date.now() < after) {
                const folder = document.querySelector('[wire\\:key^="folder-"]');
                if (folder && folder.querySelector(`[wire\\:key="${playlistKey}"]`)) return true;
                await sleep(200);
            }
            return false;
        })()
    JS);

    expect($nested)->toBeTrue('Expected the dropped playlist to render nested inside the new folder.');
});
```

- [ ] **Step 2: Run the browser tests**

Run: `php artisan test --compact --filter=SidebarFoldersTest`
Expected: PASS (requires a reachable Plex server with ≥1 audio playlist). If the third test ("drops a playlist into a folder") proves flaky in CI-less local runs because of `DragEvent`/`DataTransfer` quirks, it is acceptable to mark just that one `->skip('flaky synthetic DnD; covered by SidebarTest::movePlaylistToFolder')` — the other two and the full `SidebarTest` cover the behavior.

- [ ] **Step 3: Commit**

```bash
git add tests/Browser/SidebarFoldersTest.php
git commit -m "test: browser coverage for sidebar folders + drag-and-drop"
```

---

## Task 9: Docs — CLAUDE.md milestone status, gotchas, test count

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Run the full suite and note the count**

Run: `php artisan test`
Expected: all green. Note the total test count printed (e.g. "Tests: 1xx passed").

- [ ] **Step 2: Update the milestone table**

In `CLAUDE.md`, change the milestone-5 row from `| 5 | App side folders + drag and drop | Next |` to `| 5 | App side folders + drag and drop (Folder/FolderPlaylist models, collapsible sidebar folders, native HTML5 DnD, playlist context menus, playlist CRUD via Plex) | Done |`, and change the milestone-6 row's status from `Not started` to `Next`.

- [ ] **Step 3: Update the test-count sentence**

In `CLAUDE.md`, find "`php artisan test` should be green (101 tests as of end of M4, including four Playwright browser tests)." and replace `101 tests as of end of M4, including four Playwright browser tests` with `<N> tests as of end of M5, including <M> Playwright browser tests` using the actual numbers from Step 1 (M5 adds the three `SidebarFoldersTest` cases to the browser count).

- [ ] **Step 4: Add the M5 stack/gotchas notes**

In `CLAUDE.md`, in the "Stack and conventions" → "Plex integration" paragraph, after the existing `searchAll(...)` description, append a sentence like: "`addTrackToPlaylist`/`addAlbumToPlaylist` (`PUT /playlists/{id}/items?uri=server://{machineId}/.../metadata/{ratingKey}`), `createPlaylist($title, $seedTrackId)` (`POST /playlists?type=audio&title=&smart=0&uri=...`, returns the new rating key — Plex can't create an *empty* playlist, so creation always seeds from one track), `renamePlaylist`/`deletePlaylist`, `machineIdentifier()` (cached, from `/identity`); each playlist mutation busts `plex:playlists` (+ `plex:playlist:{id}:items`)."

Add to the "Gotchas" list these bullets:

- "**App-side folders are Eloquent, not Plex.** `Folder` (`name`/`position`/`expanded`) + `FolderPlaylist` (`folder_id`/`plex_playlist_id`/`position`, with a unique index on `plex_playlist_id` so a playlist sits in at most one folder). The sidebar's `folders()` computed eager-loads `folderPlaylists`; mutations `unset($this->folders)` (and `unset($this->playlists)` after a Plex write) to bust the per-request computed cache so the re-render reflects the change. `FolderTest`/`SidebarTest` need `uses(RefreshDatabase::class)` (it's not global in `tests/Pest.php`)."
- "**DnD is native HTML5 + Alpine.** Sidebar playlists card is one `x-data` scope; playlist rows / folder headers / the 'New playlist' zone are drop targets (`@dragover.prevent` + `@drop` → `$wire.*`). Track rows in `library`/`playlist-detail`/`search` and the library album cover are *sources* but their page roots have no `x-data`, so they use a plain `ondragstart="event.dataTransfer.setData('plextune/track'|'plextune/album', id)"` HTML attribute, not `@dragstart`. The sidebar shows the 'New playlist' zone only while a `plextune/track` drag is in flight (a `window` `dragstart`/`dragend` listener checking `e.dataTransfer.types`). Drop feedback is a ~700ms green/red ring flash, not a toast (toasts are M7). `Playlist` write actions return `bool` so the Alpine layer can flash without tripping Livewire's error modal."
- "**Sidebar context menus are Alpine** (`@contextmenu.prevent` → `fixed` popover, `@click.outside` / Escape to close). The 'Move to folder' submenu from the prototype is flattened to a flat list of `Move to {folder}` entries + `Remove from folder` (per the design's allowed deviation); the JS-side `folderOf` map (`{plexPlaylistId: folderId}`) is rendered from the `folderOf()` computed via `Js::from()`."
- "**Reusable playlist row** lives at `resources/views/partials/playlist-row.blade.php` (`@include`d by the sidebar; `$this` isn't available in an `@include`, so the pre-resolved thumb URL is passed in as `$thumbUrl`)."

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: mark milestone 5 (folders + drag-and-drop) done; capture M5 notes"
```

---

## Self-Review notes (for the implementer)

- **Spec coverage:** Folder model + pivot → Task 1. `machineIdentifier` / add-track / add-album / create / rename / delete on `PlexClient` → Tasks 2–4. Sidebar folders rendering, "Other" group, flat fallback, empty-folder placeholder, inline rename (folder + playlist), New-folder button, decorative `+`, filter input, "Recents" inert → Task 6. `movePlaylistToFolder` + all playlist write actions + `playPlaylist` + `toggleFolder` → Task 5. DnD source markup (track rows ×3 views, album cover) + `x-cloak` CSS → Task 7. DnD targets + "New playlist" zone + drag-state listener + context menus → Task 6. Tests: unit (`PlexClientTest`), feature (`FolderTest`, `SidebarTest`), browser (`SidebarFoldersTest`) → throughout. Docs → Task 9.
- **Deliberate deviation from the spec:** the playlist context-menu "Move to folder ▶" submenu is flattened to flat `Move to {folder}` rows (the design explicitly allows this if the hover submenu is fiddly in Alpine).
- **`playPlaylist` payload:** the design's prose mentioned a `queue: [...]` arg, but the player has no queue plumbing yet — `playPlaylist` dispatches `play-track` with `url`/`title`/`artist`/`artwork` for the first track only, matching `playlist-detail`'s `playAll()`. Queue support stays M7.
- **Folder-test placement:** the design said `tests/Unit/FolderTest.php`; it hits the DB, so this plan puts it in `tests/Feature/` (Laravel convention; `tests/Pest.php` doesn't apply `RefreshDatabase` to Unit either, so it'd need the trait there too — Feature is the cleaner home).

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-10-plextune-milestone-5-folders-and-dnd.md`. Two execution options:

1. **Subagent-Driven (recommended)** — fresh subagent per task, with a spec-compliance review and a code-quality review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session with checkpoints for review.

Which approach?
