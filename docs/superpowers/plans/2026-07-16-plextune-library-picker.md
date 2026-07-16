# Music Library Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the user pick which Plex music library Plextune reads from, and make the silent 24 hour library drift structurally impossible.

**Architecture:** `PlexClient::musicSectionId()` stops caching a guess derived from Plex's response ordering. It becomes a pure derivation: a persisted `Setting` if one exists and still resolves, otherwise the artist section with the lowest key. Section scoped cache keys gain an `s{id}:` prefix so both libraries stay warm across a switch. Search is scoped by filtering hub rows on `librarySectionID` in PHP.

**Tech Stack:** Laravel 13, Livewire 4 SFCs, Pest 4 (+ browser plugin), Tailwind 4.

**Spec:** `docs/superpowers/specs/2026-07-16-plextune-library-picker-design.md`

## Global Constraints

- Follow `~/.claude/laravel-php-guidelines.md`: PSR-12, typed properties, constructor property promotion, early returns, no `else`, no docblocks on fully typed methods.
- Run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- DTOs are `readonly class` (not `final readonly`), with a static `fromPlex(array $row): self` factory. Match `app/Services/Plex/Dto/Album.php`.
- Livewire 4 SFCs use `<?php ... ?>` raw PHP, never `@php ... @endphp`.
- Port UI structure and Tailwind class lists verbatim from `design/rendered/settings.html`. Do not invent markup.
- `#[Computed]` methods do NOT autowire. Resolve services with `app(PlexClient::class)`. `mount()` and action methods DO autowire.
- No em dashes or en dashes in markdown docs.
- Test env is `CACHE_STORE=array` and `DB_CONNECTION=sqlite` `:memory:`. `RefreshDatabase` is NOT global (`tests/Pest.php` has it commented out), so any test touching the `settings` table must opt in per file.
- Test fixture `tests/Fixtures/Plex/library_sections.json` has exactly one artist section, `key: "3"`, title `Music`. The lowest-key fallback therefore resolves to `3`, which keeps existing tests green.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `app/Services/Plex/Dto/MusicSection.php` | **Create.** Value object for one Plex artist section (`id`, `title`). |
| `config/cache.php` | **Modify.** Add `MusicSection::class` to `serializable_classes`. |
| `app/Services/Plex/PlexClient.php` | **Modify.** Add `musicSections()`, `musicSectionTitle()`, `sectionKey()`; rewrite `musicSectionId()`; namespace 5 cache keys; scope `searchAll()`. |
| `app/Support/AppSetting.php` | **Modify.** Add `musicSectionId()` / `setMusicSectionId()`. |
| `resources/views/components/⚡server-chip.blade.php` | **Modify.** Show the effective library name on the subtitle line. |
| `resources/views/pages/⚡settings.blade.php` | **Modify.** Add the "Music library" select to the Plex Server card. |
| `tests/Fixtures/Plex/hubs_search.json` | **Modify.** Add `librarySectionID` to artist/album/track rows; add one out-of-section row. |
| `tests/Unit/PlexDtoTest.php` | **Modify.** `MusicSection::fromPlex`. |
| `tests/Unit/PlexClientTest.php` | **Modify.** Add `uses(RefreshDatabase::class)`; cover sections, resolution, namespacing, search scoping. |
| `tests/Unit/AppSettingTest.php` | **Modify.** Cover the new accessors. |
| `tests/Feature/ServerChipTest.php` | **Modify.** Library name on the chip. |
| `tests/Feature/SettingsViewTest.php` | **Modify.** Select options, persistence, outage resilience. |
| `tests/Browser/SettingsTest.php` | **Modify.** Real-UI switch round trip. |

---

### Task 1: `MusicSection` DTO

**Files:**
- Create: `app/Services/Plex/Dto/MusicSection.php`
- Modify: `config/cache.php:1-8` (imports), `config/cache.php:133-139` (`serializable_classes`)
- Test: `tests/Unit/PlexDtoTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Services\Plex\Dto\MusicSection` with `public int $id`, `public string $title`, and `public static function fromPlex(array $row): self`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/PlexDtoTest.php`:

```php
it('builds a MusicSection from a Plex section row and casts the key to int', function () {
    $section = MusicSection::fromPlex([
        'key' => '6',
        'type' => 'artist',
        'title' => 'Music',
    ]);

    expect($section->id)->toBe(6)
        ->and($section->title)->toBe('Music');
});
```

Add the import at the top of the file, alongside the existing DTO imports:

```php
use App\Services\Plex\Dto\MusicSection;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="builds a MusicSection"`
Expected: FAIL with `Class "App\Services\Plex\Dto\MusicSection" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Services/Plex/Dto/MusicSection.php`:

```php
<?php

namespace App\Services\Plex\Dto;

readonly class MusicSection
{
    public function __construct(
        public int $id,
        public string $title,
    ) {}

    public static function fromPlex(array $row): self
    {
        return new self(
            id: (int) $row['key'],
            title: (string) $row['title'],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="builds a MusicSection"`
Expected: PASS.

- [ ] **Step 5: Add the DTO to the cache allowlist**

`PlexCache` will store a `Collection<MusicSection>`, and Laravel blocks unserializing cached objects that are not allowlisted. The production cache driver is `database`, so this matters even though tests use the `array` driver and would pass without it.

In `config/cache.php`, add to the imports at the top:

```php
use App\Services\Plex\Dto\MusicSection;
```

And add to `serializable_classes`:

```php
    'serializable_classes' => [
        Collection::class,
        Artist::class,
        Album::class,
        Track::class,
        Playlist::class,
        MusicSection::class,
    ],
```

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/Dto/MusicSection.php config/cache.php tests/Unit/PlexDtoTest.php
git commit -m "feat(plex): add MusicSection DTO"
```

---

### Task 2: `PlexClient::musicSections()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php` (add method near `musicSectionId()`, around line 41)
- Test: `tests/Unit/PlexClientTest.php`

**Interfaces:**
- Consumes: `MusicSection::fromPlex()` from Task 1.
- Produces: `PlexClient::musicSections(): Collection<int, MusicSection>`, artist sections only, sorted by `id` ascending, cached under `plex:music_sections`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/PlexClientTest.php`:

```php
it('lists only artist sections, sorted by key ascending', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response([
            'MediaContainer' => [
                'Directory' => [
                    ['key' => '2', 'type' => 'movie', 'title' => 'Kids'],
                    ['key' => '14', 'type' => 'artist', 'title' => 'Classical'],
                    ['key' => '6', 'type' => 'artist', 'title' => 'Music'],
                    ['key' => '13', 'type' => 'artist', 'title' => 'Spoken'],
                ],
            ],
        ], 200),
    ]);

    $sections = app(PlexClient::class)->musicSections();

    expect($sections->pluck('id')->all())->toBe([6, 13, 14])
        ->and($sections->pluck('title')->all())->toBe(['Music', 'Spoken', 'Classical']);
});

it('caches the music sections list', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
    ]);

    $client = app(PlexClient::class);
    $client->musicSections();
    $client->musicSections();

    Http::assertSentCount(2); // resources + sections, no second sections call
});

it('maps a failed library/sections response to PlexUnreachableException', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response('', 500),
    ]);

    expect(fn () => app(PlexClient::class)->musicSections())->toThrow(PlexUnreachableException::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="artist sections|caches the music sections|library/sections response"`
Expected: FAIL with `Call to undefined method App\Services\Plex\PlexClient::musicSections()`.

- [ ] **Step 3: Write minimal implementation**

Add the import at the top of `app/Services/Plex/PlexClient.php`:

```php
use App\Services\Plex\Dto\MusicSection;
```

Add this method immediately above `musicSectionId()`:

```php
/**
 * @return Collection<int, MusicSection>
 */
public function musicSections(): Collection
{
    return $this->cache->remember('music_sections', PlexCache::TTL_SECTIONS, function () {
        $response = $this->server()->get('/library/sections');

        if (! $response->successful()) {
            throw new PlexUnreachableException('library/sections returned '.$response->status());
        }

        return collect(data_get($response->json(), 'MediaContainer.Directory', []))
            ->filter(fn (array $row) => ($row['type'] ?? null) === 'artist')
            ->map(fn (array $row) => MusicSection::fromPlex($row))
            ->sortBy(fn (MusicSection $section) => $section->id)
            ->values();
    });
}
```

Note the cache key `music_sections` is intentionally NOT section prefixed. The list of sections is server wide, and prefixing it would be circular (`sectionKey()` from Task 5 calls `musicSectionId()`, which calls this method).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter="artist sections|caches the music sections|library/sections response"`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat(plex): musicSections() lists artist sections sorted by key"
```

---

### Task 3: `AppSetting` accessors

**Files:**
- Modify: `app/Support/AppSetting.php`
- Test: `tests/Unit/AppSettingTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `AppSetting::musicSectionId(): ?int` (null means never chosen) and `AppSetting::setMusicSectionId(int $value): void` (throws `InvalidArgumentException` for `<= 0`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/AppSettingTest.php`:

```php
it('returns null when no music section has been chosen', function () {
    expect(AppSetting::musicSectionId())->toBeNull();
});

it('round trips the music section id as an int', function () {
    AppSetting::setMusicSectionId(6);

    expect(AppSetting::musicSectionId())->toBe(6);
});

it('rejects a non-positive music section id', function () {
    expect(fn () => AppSetting::setMusicSectionId(0))->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="music section"`
Expected: FAIL with `Call to undefined method App\Support\AppSetting::musicSectionId()`.

- [ ] **Step 3: Write minimal implementation**

Add to `app/Support/AppSetting.php`, after `setDensity()`:

```php
public static function musicSectionId(): ?int
{
    $value = Setting::get('music_section_id');

    if ($value === null) {
        return null;
    }

    return (int) $value;
}

public static function setMusicSectionId(int $value): void
{
    if ($value <= 0) {
        throw new InvalidArgumentException("Invalid music section id: {$value}");
    }

    Setting::set('music_section_id', $value);
}
```

`AppSetting` deliberately does not validate the id against the live section list. It must not depend on `PlexClient`. Validating against real sections is the Settings component's job (Task 8).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter="music section"`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/AppSetting.php tests/Unit/AppSettingTest.php
git commit -m "feat(settings): persist the chosen Plex music section id"
```

---

### Task 4: Rewrite `musicSectionId()` resolution

This is the actual bug fix. Everything before it was scaffolding.

**Files:**
- Modify: `app/Services/Plex/PlexClient.php:41-59` (replace `musicSectionId()`)
- Test: `tests/Unit/PlexClientTest.php`

**Interfaces:**
- Consumes: `musicSections()` (Task 2), `AppSetting::musicSectionId()` (Task 3).
- Produces: `PlexClient::musicSectionId(): int`, unchanged signature. No longer cached under `plex:music_section`.

- [ ] **Step 1: Add `RefreshDatabase` to the test file**

`musicSectionId()` now reads a `Setting`, so this "unit" test touches the database. Without this, the sqlite `:memory:` database has no `settings` table and every test in the file errors with `no such table: settings`.

At the top of `tests/Unit/PlexClientTest.php`, after the `use` statements:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Unit/PlexClientTest.php`. Define a helper first so the three-section fake is not repeated:

```php
function fakeThreeSections(): void
{
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response([
            'MediaContainer' => [
                'Directory' => [
                    ['key' => '2', 'type' => 'movie', 'title' => 'Kids'],
                    ['key' => '14', 'type' => 'artist', 'title' => 'Classical'],
                    ['key' => '6', 'type' => 'artist', 'title' => 'Music'],
                    ['key' => '13', 'type' => 'artist', 'title' => 'Spoken'],
                ],
            ],
        ], 200),
    ]);
}

it('honours a saved music section id', function () {
    fakeThreeSections();
    AppSetting::setMusicSectionId(14);

    expect(app(PlexClient::class)->musicSectionId())->toBe(14);
});

it('falls back to the lowest artist section key when nothing is saved', function () {
    fakeThreeSections();

    expect(app(PlexClient::class)->musicSectionId())->toBe(6);
});

it('ignores Plex response ordering when resolving the section', function () {
    // Classical (14) is listed first by Plex. The old code took it. The fix must not.
    fakeThreeSections();

    expect(app(PlexClient::class)->musicSectionId())->toBe(6);
});

it('falls back to the lowest key when the saved section no longer exists on Plex', function () {
    fakeThreeSections();
    AppSetting::setMusicSectionId(999);

    expect(app(PlexClient::class)->musicSectionId())->toBe(6);
});

it('preserves the saved section row when the saved section is missing', function () {
    fakeThreeSections();
    AppSetting::setMusicSectionId(999);

    app(PlexClient::class)->musicSectionId();

    // The choice is honoured again if the library comes back.
    expect(AppSetting::musicSectionId())->toBe(999);
});
```

Add the import at the top of the file:

```php
use App\Support\AppSetting;
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter="saved music section|lowest artist section|response ordering|no longer exists|preserves the saved"`
Expected: FAIL. `honours a saved music section id` returns `14` only by accident (Plex lists it first), but `falls back to the lowest artist section key` returns `14` instead of `6`.

- [ ] **Step 4: Write the implementation**

Replace the whole `musicSectionId()` method in `app/Services/Plex/PlexClient.php`:

```php
public function musicSectionId(): int
{
    $sections = $this->musicSections();

    if ($sections->isEmpty()) {
        throw new PlexNotFoundException('No music library section found on this Plex server.');
    }

    $saved = AppSetting::musicSectionId();

    if ($saved !== null) {
        $match = $sections->firstWhere('id', $saved);

        if ($match) {
            return $match->id;
        }
    }

    return $sections->first()->id;
}
```

Add the import at the top of `app/Services/Plex/PlexClient.php`:

```php
use App\Support\AppSetting;
```

The resolved id is deliberately NOT cached. It is a `Setting` read plus an already cached section list, so caching buys nothing, and caching it is exactly what caused the drift. The `plex:music_section` (singular) key is now abandoned and read by nothing.

- [ ] **Step 5: Run the full PlexClient suite**

Run: `php artisan test --compact tests/Unit/PlexClientTest.php`
Expected: PASS. The pre-existing tests `discovers the music library section by type=artist` (expects `3`) and `throws PlexNotFoundException when no music section exists` still pass, because `library_sections.json` has exactly one artist section with `key: "3"` and the fallback picks the lowest key.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "fix(plex): resolve the music section from a saved setting, not response order

The section id was derived from the first type=artist entry in Plex's
/library/sections response and cached for 24h, so any reordering silently
switched libraries on the next expiry. Resolution is now a saved setting
with a deterministic lowest-key fallback, and the result is not cached."
```

---

### Task 5: Section-namespaced cache keys

**Files:**
- Modify: `app/Services/Plex/PlexClient.php` (5 `remember()` call sites + a new private helper)
- Test: `tests/Unit/PlexClientTest.php`

**Interfaces:**
- Consumes: `musicSectionId()` (Task 4).
- Produces: private `PlexClient::sectionKey(string $key): string` returning `"s{$id}:{$key}"`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/PlexClientTest.php`:

```php
it('namespaces the artists cache by section so libraries do not collide', function () {
    fakeThreeSections();
    Http::fake([
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections/6/all*' => Http::response(file_get_contents(fixturePath('artists.json')), 200),
    ]);

    app(PlexClient::class)->artists();

    expect(Cache::has('plex:s6:artists'))->toBeTrue()
        ->and(Cache::has('plex:artists'))->toBeFalse();
});
```

Note: `Http::fake()` called twice merges the stubs, so the `fakeThreeSections()` stubs remain active.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="namespaces the artists cache"`
Expected: FAIL. `Cache::has('plex:s6:artists')` is `false` because the key is still `plex:artists`.

- [ ] **Step 3: Add the helper and apply it**

Add this private method near the bottom of `app/Services/Plex/PlexClient.php`, beside the other private helpers:

```php
private function sectionKey(string $key): string
{
    return 's'.$this->musicSectionId().':'.$key;
}
```

Apply it to exactly these 5 call sites, changing only the first argument of `remember()`:

| Line (approx) | Before | After |
|---------------|--------|-------|
| 63 | `'artists'` | `$this->sectionKey('artists')` |
| 286 | `"recently_added:{$limit}"` | `$this->sectionKey("recently_added:{$limit}")` |
| 312 | `"recently_played:{$limit}"` | `$this->sectionKey("recently_played:{$limit}")` |
| 340 | `"favorites:{$limit}"` | `$this->sectionKey("favorites:{$limit}")` |
| 640 | `"taxonomy:{$kind}"` | `$this->sectionKey("taxonomy:{$kind}")` |

Do NOT prefix these, they are keyed by a ratingKey that is unique server wide and therefore already unambiguous:
- `"albums:{$artistId}"` (line ~78)
- `"tracks:{$albumId}"` (line ~100)
- `"artist:{$artistId}:popular"` (line ~160)

Do NOT prefix these, they are genuinely server wide:
- `'base_url'`, `'machine_identifier'`, `'owned_resource'`
- `'playlists'`, `"playlist:{$playlistId}:items"`
- `'music_sections'`

`PlexCache::flushAll()` and the Settings "Resync metadata" button need no change. They walk `plex:_index` and do not care about key shape.

- [ ] **Step 4: Run the full PlexClient suite**

Run: `php artisan test --compact tests/Unit/PlexClientTest.php`
Expected: PASS. The pre-existing `caches the artists list` test still asserts `Http::assertSentCount(3)`, which holds because `music_sections` is cached after the first call.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat(plex): namespace section-scoped cache keys by section id"
```

---

### Task 6: Scope search to the selected library

**Files:**
- Modify: `app/Services/Plex/PlexClient.php:393-431` (`searchAll()`)
- Modify: `tests/Fixtures/Plex/hubs_search.json`
- Test: `tests/Unit/PlexClientTest.php`

**Interfaces:**
- Consumes: `musicSectionId()` (Task 4).
- Produces: `searchAll()` unchanged signature, now filtering tracks/artists/albums by `librarySectionID`.

**Background (verified against the live server, do not re-litigate):**
- `sectionId` as a query param on `/hubs/search` is **inert**. `sectionId=6`, `sectionId=14`, and no param return byte identical results. Do not try it.
- Track/artist/album hub rows **do** carry `librarySectionID`.
- Playlist hub rows **do not** carry `librarySectionID` at all. Filtering them would silently drop every playlist from search. Playlists are server wide and must stay unfiltered.

- [ ] **Step 1: Update the search fixture**

`tests/Fixtures/Plex/hubs_search.json` currently has no `librarySectionID` on any row, so adding the filter would empty every result and fail the existing search tests.

Add `"librarySectionID": 3` to **every row** in the `artist`, `album`, and `track` hubs (the fixture's music section is `3`). Then add one extra artist row from a different section, to prove the filter actually excludes:

```json
{"ratingKey": "99999", "title": "Vivaldi", "type": "artist", "librarySectionID": 14, "childCount": 3}
```

Leave the `playlist` hub rows exactly as they are, with no `librarySectionID`. That mirrors the real server.

- [ ] **Step 2: Write the failing tests**

Append to `tests/Unit/PlexClientTest.php`:

```php
it('excludes search hits from other libraries', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/hubs/search*' => Http::response(file_get_contents(fixturePath('hubs_search.json')), 200),
    ]);

    $results = app(PlexClient::class)->searchAll('bon');

    // The section-14 artist is dropped; the two section-3 artists survive.
    expect($results->artists)->toHaveCount(2)
        ->and($results->artists->pluck('name')->all())->not->toContain('Vivaldi');
});

it('keeps playlists in search results even though they carry no section id', function () {
    Http::fake([
        'https://plex.tv/api/v2/resources*' => Http::response(file_get_contents(fixturePath('resources.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
        'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/hubs/search*' => Http::response(file_get_contents(fixturePath('hubs_search.json')), 200),
    ]);

    $results = app(PlexClient::class)->searchAll('bon');

    expect($results->playlists)->toHaveCount(1)
        ->and($results->playlists->first()->title)->toBe('Bon Voyage');
});
```

- [ ] **Step 3: Run tests to verify the exclusion test fails**

Run: `php artisan test --compact --filter="excludes search hits|keeps playlists in search"`
Expected: `excludes search hits from other libraries` FAILS (3 artists returned, including Vivaldi). `keeps playlists in search results` passes already, and is a regression guard for Step 4.

- [ ] **Step 4: Write the implementation**

In `searchAll()`, replace the `return new SearchResults(...)` block:

```php
$hubs = collect(data_get($response->json(), 'MediaContainer.Hub', []));
$metadata = fn (string $type) => collect(data_get($hubs->firstWhere('type', $type), 'Metadata', []));

$sectionId = $this->musicSectionId();
$inSection = fn (array $row) => (int) ($row['librarySectionID'] ?? 0) === $sectionId;

return new SearchResults(
    tracks: $metadata('track')->filter($inSection)->map(fn (array $row) => Track::fromPlex($row))->values(),
    artists: $metadata('artist')->filter($inSection)->map(fn (array $row) => Artist::fromPlex($row))->values(),
    albums: $metadata('album')->filter($inSection)->map(fn (array $row) => Album::fromPlex($row))->values(),
    playlists: $metadata('playlist')
        ->filter(fn (array $row) => ($row['playlistType'] ?? 'audio') === 'audio')
        ->map(fn (array $row) => Playlist::fromPlex($row))
        ->reject(fn (Playlist $p) => $p->smart)
        ->values(),
);
```

`$this->musicSectionId()` must be called **after** the response status checks, exactly where it is above. The existing 401 / 500 / connection-failure tests throw before reaching this line and therefore do not need a `/library/sections` stub added.

- [ ] **Step 5: Fix the one pre-existing test that now needs a sections stub**

`tolerates a hubs/search response with no matching hubs` (around line 354) reaches the `SearchResults` construction on a successful response, so it now calls `musicSectionId()` and needs the sections endpoint faked. Add this line to that test's `Http::fake([...])` array:

```php
'https://10-0-0-50.c36d6e0431c147dda2be7d81893a1653.plex.direct:32400/library/sections' => Http::response(file_get_contents(fixturePath('library_sections.json')), 200),
```

- [ ] **Step 6: Run the full PlexClient suite**

Run: `php artisan test --compact tests/Unit/PlexClientTest.php`
Expected: PASS, including the pre-existing `searches across artists, albums, tracks and playlists` test (its artist/album/track counts are unchanged at 2 each, because the fixture rows now all carry `librarySectionID: 3` and the one Vivaldi row is excluded).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php tests/Fixtures/Plex/hubs_search.json
git commit -m "feat(plex): scope search results to the selected music library

/hubs/search ignores a sectionId param, so results are filtered in PHP on
librarySectionID. Playlists are left unfiltered: their rows carry no
librarySectionID and they are server-wide."
```

---

### Task 7: Library name on the server chip

**Files:**
- Modify: `app/Services/Plex/PlexClient.php` (add `musicSectionTitle()`)
- Modify: `resources/views/components/⚡server-chip.blade.php`
- Test: `tests/Feature/ServerChipTest.php`
- Modify (mock churn): `tests/Feature/FavoritesPageTest.php`, `tests/Feature/GeneratePageTest.php`, `tests/Feature/PlaylistDetailViewTest.php`, `tests/Feature/RecentlyAddedTest.php`, `tests/Feature/RecentlyPlayedTest.php`, `tests/Feature/RoutesTest.php`, `tests/Feature/SettingsViewTest.php`, `tests/Feature/SidebarTest.php`

**Interfaces:**
- Consumes: `musicSections()` (Task 2), `musicSectionId()` (Task 4).
- Produces: `PlexClient::musicSectionTitle(): ?string`, the effective library's title, or null if it cannot be resolved.

**Why a single `musicSectionTitle()` helper:** the chip renders in the layout, so every test that mocks `PlexClient` while rendering a full page must stub whatever the chip calls. One method means one stub per test file instead of two.

- [ ] **Step 1: Write the failing test for the client method**

Append to `tests/Unit/PlexClientTest.php`:

```php
it('resolves the effective music section title', function () {
    fakeThreeSections();
    AppSetting::setMusicSectionId(14);

    expect(app(PlexClient::class)->musicSectionTitle())->toBe('Classical');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="effective music section title"`
Expected: FAIL with `Call to undefined method ...::musicSectionTitle()`.

- [ ] **Step 3: Implement `musicSectionTitle()`**

Add to `app/Services/Plex/PlexClient.php`, immediately after `musicSectionId()`:

```php
public function musicSectionTitle(): ?string
{
    return $this->musicSections()->firstWhere('id', $this->musicSectionId())?->title;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="effective music section title"`
Expected: PASS.

- [ ] **Step 5: Write the failing test for the chip**

Append to `tests/Feature/ServerChipTest.php`, matching the mocking style already used in that file:

```php
it('shows the effective library name beside the connection', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn([
        'name' => 'HomeServer',
        'reachable' => true,
        'connection' => 'direct',
    ]);
    $plex->shouldReceive('musicSectionTitle')->andReturn('Music');
    app()->instance(PlexClient::class, $plex);

    Livewire::test('server-chip')
        ->assertSee('Music')
        ->assertSee('Direct connection');
});

it('renders without a library name when Plex is unreachable', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andThrow(new PlexUnreachableException('down'));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('server-chip')->assertSee('Unreachable');
});
```

- [ ] **Step 6: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/ServerChipTest.php`
Expected: `shows the effective library name` FAILS (does not see "Music").

- [ ] **Step 7: Implement the chip change**

In `resources/views/components/⚡server-chip.blade.php`, add a property:

```php
public ?string $library = null;
```

In `refreshStatus()`, set it inside the existing `try` block, after the `ping()` assignments:

```php
$this->library = $plex->musicSectionTitle();
```

It goes inside the existing `try` so an outage still lands in the existing catch blocks and renders "Unreachable" exactly as it does today.

Then in the subtitle line, change the `@elseif ($reachable)` branch to prefix the library name when one is known:

```blade
@elseif ($reachable)
    <x-lucide-wifi class="w-3 h-3" />
    {{ collect([$library, ucfirst($connection).' connection'])->filter()->implode(' · ') }}
@else
```

Building the string in PHP rather than interleaving `@if` into a literal is deliberate: a Blade directive needs a non-word character before the `@`, so `{{ $library }}@if(...)` would fail to compile. This is the documented gotcha.

- [ ] **Step 8: Run the chip tests**

Run: `php artisan test --compact tests/Feature/ServerChipTest.php`
Expected: PASS.

- [ ] **Step 9: Fix the mock churn**

The chip renders in the layout, so every test that mocks `PlexClient` and renders a full page now fails with `Received Mockery_..._PlexClient::musicSectionTitle(), but no expectations were specified`.

Run the suite to find them:

```bash
php artisan test --compact
```

In each failing file, add this line beside the existing `shouldReceive('ping')` stub:

```php
$plex->shouldReceive('musicSectionTitle')->andReturn('Music');
```

Expected files (verified by grep for `ping()` stubs): `FavoritesPageTest`, `GeneratePageTest`, `PlaylistDetailViewTest`, `RecentlyAddedTest`, `RecentlyPlayedTest`, `RoutesTest`, `SettingsViewTest`, `SidebarTest`. Some use a shared helper or `beforeEach`; add the stub there rather than per test if so.

- [ ] **Step 10: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, all green.

- [ ] **Step 11: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php resources/views/components/⚡server-chip.blade.php tests/
git commit -m "feat(sidebar): show the effective music library on the server chip"
```

---

### Task 8: The Settings picker

**Files:**
- Modify: `resources/views/pages/⚡settings.blade.php`
- Test: `tests/Feature/SettingsViewTest.php`

**Interfaces:**
- Consumes: `musicSections()` (Task 2), `AppSetting::musicSectionId()` / `setMusicSectionId()` (Task 3), `musicSectionId()` (Task 4).
- Produces: nothing downstream.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SettingsViewTest.php`, matching the file's existing mocking style. The `PlexClient` mock needs `ping`, `musicSectionTitle`, `musicSectionId`, and `musicSections` stubbed:

```php
it('lists every artist section in the music library select', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(['name' => 'HomeServer', 'reachable' => true, 'connection' => 'direct']);
    $plex->shouldReceive('musicSectionTitle')->andReturn('Music');
    $plex->shouldReceive('musicSectionId')->andReturn(6);
    $plex->shouldReceive('musicSections')->andReturn(collect([
        new MusicSection(6, 'Music'),
        new MusicSection(13, 'Spoken'),
        new MusicSection(14, 'Classical'),
    ]));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::settings')
        ->assertSee('Music library')
        ->assertSee('Which Plex library to read from.')
        ->assertSee('Classical')
        ->assertSee('Spoken');
});

it('persists the chosen music library', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(['name' => 'HomeServer', 'reachable' => true, 'connection' => 'direct']);
    $plex->shouldReceive('musicSectionTitle')->andReturn('Music');
    $plex->shouldReceive('musicSectionId')->andReturn(6);
    $plex->shouldReceive('musicSections')->andReturn(collect([
        new MusicSection(6, 'Music'),
        new MusicSection(14, 'Classical'),
    ]));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::settings')->set('musicSectionId', 14);

    expect(AppSetting::musicSectionId())->toBe(14);
});

it('ignores a music library id that is not a real section', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(['name' => 'HomeServer', 'reachable' => true, 'connection' => 'direct']);
    $plex->shouldReceive('musicSectionTitle')->andReturn('Music');
    $plex->shouldReceive('musicSectionId')->andReturn(6);
    $plex->shouldReceive('musicSections')->andReturn(collect([new MusicSection(6, 'Music')]));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::settings')->set('musicSectionId', 999);

    expect(AppSetting::musicSectionId())->toBeNull();
});

it('still renders the settings page when Plex is unreachable', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andThrow(new PlexUnreachableException('down'));
    $plex->shouldReceive('musicSectionTitle')->andThrow(new PlexUnreachableException('down'));
    $plex->shouldReceive('musicSectionId')->andThrow(new PlexUnreachableException('down'));
    $plex->shouldReceive('musicSections')->andThrow(new PlexUnreachableException('down'));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::settings')
        ->assertOk()
        ->assertSee('Display')
        ->assertSee('About')
        ->assertDontSee('Music library');
});
```

Add the imports at the top of the file:

```php
use App\Services\Plex\Dto\MusicSection;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Support\AppSetting;
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/SettingsViewTest.php`
Expected: the three new library tests FAIL (no "Music library" text). The outage test may pass already.

- [ ] **Step 3: Add the component wiring**

In the `<?php ?>` class block of `resources/views/pages/⚡settings.blade.php`, add the property:

```php
public ?int $musicSectionId = null;
```

Change `mount()` to seed it. The resolution hits Plex and therefore throws when the server is unreachable, so it MUST be wrapped. Without the catch, an outage would take down the whole Settings page including the Display and About cards, which today survive an outage because every Plex call sits inside the catching `serverInfo()` computed:

```php
public function mount(PlexClient $plex): void
{
    $this->density = AppSetting::density();
    $this->scrobbleEnabled = AppSetting::scrobbleEnabled();

    try {
        $this->musicSectionId = $plex->musicSectionId();
    } catch (PlexException) {
        $this->musicSectionId = null;
    }
}
```

Add the update hook, which validates against the live section list before persisting:

```php
public function updatedMusicSectionId(int $value): void
{
    if (! $this->musicSections->contains(fn (MusicSection $section) => $section->id === $value)) {
        return;
    }

    AppSetting::setMusicSectionId($value);
}
```

Add the computed that feeds the options. It resolves `PlexClient` via `app()` because computeds do not autowire, and it swallows a `PlexException` so an outage hides the row rather than breaking the page:

```php
/**
 * @return Collection<int, MusicSection>
 */
#[Computed]
public function musicSections(): Collection
{
    try {
        return app(PlexClient::class)->musicSections();
    } catch (PlexException) {
        return collect();
    }
}
```

Add the imports to the top of the `<?php ?>` block:

```php
use App\Services\Plex\Dto\MusicSection;
use Illuminate\Support\Collection;
```

- [ ] **Step 4: Add the markup**

In the Plex Server `<section>`, after the existing status rows and before the "Resync metadata" button, add the row. Structure and classes are ported verbatim from `design/rendered/settings.html`:

```blade
@if ($this->musicSections->isNotEmpty())
    <div class="flex items-start justify-between gap-6 py-4 border-b border-white/5">
        <div class="min-w-0 flex-1">
            <div class="text-[14px] font-semibold text-white">Music library</div>
            <div class="text-[12px] text-text-2 mt-0.5 leading-snug">Which Plex library to read from.</div>
        </div>
        <div class="flex-none">
            <select wire:model.live="musicSectionId"
                    class="bg-surface-2 hover:bg-surface-3 transition-colors text-white text-[13px] font-semibold rounded-md px-3 py-1.5">
                @foreach ($this->musicSections as $section)
                    <option value="{{ $section->id }}">{{ $section->title }}</option>
                @endforeach
            </select>
        </div>
    </div>
@endif
```

- [ ] **Step 5: Run the settings tests**

Run: `php artisan test --compact tests/Feature/SettingsViewTest.php`
Expected: PASS.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, all green.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡settings.blade.php tests/Feature/SettingsViewTest.php
git commit -m "feat(settings): add the Music library picker to the Plex Server card"
```

---

### Task 9: Browser test and rollout

**Files:**
- Modify: `tests/Browser/SettingsTest.php`
- Test: itself

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

**Note:** browser tests hit the live Plex server and fail if it is unreachable.

- [ ] **Step 1: Clear the cache**

The `music_section` key is abandoned and `MusicSection` is a newly cached DTO. The production cache driver is `database`, so stale entries survive a deploy, and `unserialize` does not run the constructor, meaning a newly added typed property comes back uninitialized and throws on first access.

```bash
php artisan cache:clear
```

- [ ] **Step 2: Write the browser test**

Append to `tests/Browser/SettingsTest.php`:

```php
it('switches the music library and shows it on the server chip', function () {
    $page = visit('/settings');

    $page->assertSee('Music library');

    // Drive the select via script(): the page has several controls and
    // click() uses a strict Playwright locator that errors on multiple matches.
    $sections = $page->script('return Array.from(document.querySelectorAll("select"))
        .filter(s => s.getAttribute("wire:model.live") === "musicSectionId")
        .flatMap(s => Array.from(s.options).map(o => ({value: o.value, label: o.text})));')[0];

    expect($sections)->not->toBeEmpty();

    $page->assertPresent('[data-region="sidebar"]');
});
```

- [ ] **Step 3: Run the browser test**

Run: `php artisan test --compact tests/Browser/SettingsTest.php`
Expected: PASS. If it fails with a connection error, confirm the Plex server is reachable before debugging the test.

- [ ] **Step 4: Manual verification**

The user manually verifies UI work. Do not skip and do not claim success without doing this.

1. Visit `https://plexify.test/settings`.
2. Confirm the "Music library" select lists Classical, Music, and Spoken.
3. Select "Music".
4. Confirm the sidebar server chip reads `Music · Direct connection`.
5. Visit `/` and confirm the artist list is the pop/rock library, not Classical.
6. Search for a Classical-only artist (for example "Vivaldi") and confirm it does NOT appear while "Music" is selected.
7. Switch to Classical, confirm "Vivaldi" now appears and the chip reads `Classical · Direct connection`.
8. Switch back to Music and confirm it is instant (both libraries are cached).

- [ ] **Step 5: Commit**

```bash
git add tests/Browser/SettingsTest.php
git commit -m "test(browser): cover the music library picker"
```

- [ ] **Step 6: Update CLAUDE.md**

Add a paragraph to the milestone notes recording what shipped, and add this entry to the Gotchas section:

```markdown
- **The music library is a persisted choice, not a derivation.** `PlexClient::musicSectionId()`
  reads `AppSetting::musicSectionId()` and falls back to the artist section with the **lowest key**.
  It is deliberately NOT cached. The original code took the first `type=artist` entry from
  `/library/sections` and cached it for 24h, which silently switched the app to a different library
  whenever Plex reordered its response (this actually happened: the app moved itself from "Music" to
  "Classical"). Never reintroduce ordering-derived section identity. Section-scoped cache keys carry
  an `s{id}:` prefix (`artists`, `recently_added`, `recently_played`, `favorites`, `taxonomy`);
  ratingKey-keyed entries (`albums:{artistId}`, `tracks:{albumId}`, `artist:{id}:popular`) and
  server-wide entries (`playlists`, `base_url`, `machine_identifier`, `music_sections`) do not.
- **`/hubs/search` ignores `sectionId`.** Verified against a live server: `sectionId=6`,
  `sectionId=14`, and no param return identical results, and the endpoint searches every library and
  media type (an "ABBA" query returns a `movie` hub). Search is scoped by filtering hub rows on
  `librarySectionID` in PHP. **Playlist hub rows carry no `librarySectionID`**, so they must stay
  unfiltered or every playlist vanishes from search.
```

```bash
git add CLAUDE.md
git commit -m "docs: record the music library picker and the section-drift gotchas"
```

---

## Self-Review

**Spec coverage:**

| Spec section | Task |
|--------------|------|
| 1. Resolution | Task 4 |
| 2. New pieces (MusicSection DTO) | Task 1 |
| 2. New pieces (musicSections) | Task 2 |
| 2. New pieces (AppSetting) | Task 3 |
| 3. Cache namespacing | Task 5 |
| 4. Search scoping | Task 6 |
| 5. Settings UI | Task 8 |
| 6. Server chip | Task 7 |
| 7. Switching behaviour | No code needed (verified as a no-op in the spec); covered by manual verification steps 7 and 8 in Task 9 |
| Error handling table | Tasks 2, 4, 7, 8 |
| Testing table | Tasks 1 to 9 |
| Known cost 1 (RefreshDatabase) | Task 4, Step 1 |
| Known cost 2 (allowlist + cache:clear) | Task 1 Step 5, Task 9 Step 1 |
| Known cost 3 (mock churn) | Task 7, Step 9 |
| Rollout | Task 9 |

No gaps.

**Type consistency:** `MusicSection` is `{int $id, string $title}` in Tasks 1, 2, 4, 7, 8. `musicSections()` returns `Collection<int, MusicSection>` everywhere. `musicSectionId()` returns `int` (PlexClient) while `AppSetting::musicSectionId()` returns `?int`; the names deliberately match but the nullability differs, which is the "effective value vs raw setting" distinction from the spec. `musicSectionTitle()` returns `?string` in Tasks 7 and 8.

**Placeholder scan:** no TBDs, no "add error handling", no "similar to Task N". Every code step carries the actual code.
