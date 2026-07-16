# Plextune: music library picker (and the silent library drift fix)

Date: 2026-07-16

## Problem

The app browses the wrong Plex music library, and it switched on its own with no user action.

`PlexClient::musicSectionId()` derived "the music library" by taking the first `type === 'artist'`
entry out of Plex's `/library/sections` response:

```php
$music = collect($directory)->first(fn (array $d) => ($d['type'] ?? null) === 'artist');
```

That result was cached under `plex:music_section` with `TTL_SECTIONS` (24 hours). Two facts combine
into the bug:

1. The app has no persisted notion of which library the user chose. It re-derives the answer.
2. The derivation runs again every 24 hours when the cache entry expires.

So whenever Plex's response ordering changes for any reason, the app silently adopts a different
library on the next cache expiry.

### Evidence gathered on the live server

The user's server has three `artist` sections. Plex returns them in this order:

| position | key | title     |
|----------|-----|-----------|
| 4        | 14  | Classical |
| 5        | 6   | Music     |
| 6        | 13  | Spoken    |

`plex:music_section` held `14` ("Classical"), while the user's intended library is `6` ("Music").
The full section ordering (`2, 1, 11, 3, 14, 6, 13, 10, 12, 7`) is not sorted by key, title, or type.
It is whatever order the Plex database returns.

The ordering is stable across immediate repeated calls (5 identical responses), so this is not
per-request randomness. Classical's `updatedAt` is 2026-07-09 versus Music's 2026-06-16, which is
suggestive but not proof: the global list does not sort by `updatedAt` either (Classical holds the
newest timestamp yet sits fifth overall). The precise trigger for the reordering is unknown, and
that is the point. The app treats an undocumented, unstable ordering as a stable identity.

## Goals

1. Make the library choice explicit, persisted, and user selectable.
2. Make drift structurally impossible, including for a user who never opens the picker.
3. Keep switching cheap, since the user switches to Classical occasionally for playlist creation.
4. Scope search to the selected library.

## Non-goals

- Multi-server support. `PLEX_BASE_URL` / discovery is unchanged.
- Per-library folders or playlist placements. Plex playlists are server wide, so the sidebar,
  folders, and `FolderPlaylist` records are unaffected by a library switch.
- The prototype's other unbuilt Settings sections (Account, Downloads, Shortcuts, Default sort,
  Hidden artists). Out of scope.

## Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Picker placement | Settings select, plus library name on the existing sidebar server chip | `design/rendered/settings.html` already specs a "Music library" row in the Plex Server card with exact markup. A sidebar dropdown would be invented UI with no design reference. The chip provides the visibility that makes drift noticeable. |
| Cache strategy | Namespace section scoped keys by section id | The user switches occasionally, so both libraries should stay warm. Correct by construction rather than correct if someone remembers to flush. |
| Fallback when nothing is chosen | Lowest artist section key, not persisted | Deterministic and ordering independent, so drift is impossible even before the user visits the picker. Plex assigns keys in creation order, so it means "oldest music library", a reasonable primary guess. On this server it resolves to key 6 ("Music"), the intended library. |
| Search | Scope to the selected library | A picker that leaves search server wide is half a feature. |

## Design

### 1. Resolution

`musicSectionId()` becomes a pure derivation with no caching of its own result:

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

The resolved id is deliberately not cached. It is a `Setting` read plus an already cached section
list, so caching buys nothing, and caching it is exactly what produced the drift. Response order no
longer influences the result at any point.

A saved id that no longer exists on Plex (library deleted or recreated) falls through to the
fallback rather than throwing. The stale `Setting` row is left in place, so if the library returns,
the user's original choice is honoured again.

### 2. New pieces

**`app/Services/Plex/Dto/MusicSection.php`**

```php
final readonly class MusicSection
{
    public function __construct(
        public int $id,
        public string $title,
    ) {}

    public static function fromPlex(array $row): self
    {
        return new self((int) $row['key'], (string) $row['title']);
    }
}
```

**`PlexClient::musicSections(): Collection<int, MusicSection>`**

`GET /library/sections`, keep `type === 'artist'`, map through `MusicSection::fromPlex`, sort by
`id` ascending, `values()`. Cached as `plex:music_sections` (server wide key, no section prefix)
with `TTL_SECTIONS`. Non successful responses throw `PlexUnreachableException`, matching the
existing method.

The `plex:music_section` (singular) key is abandoned. It is not read anywhere after this change and
expires on its own; `cache:clear` on rollout removes it immediately.

**`App\Support\AppSetting`**

```php
public static function musicSectionId(): ?int
{
    $value = Setting::get('music_section_id');

    return $value === null ? null : (int) $value;
}

public static function setMusicSectionId(int $value): void
{
    if ($value <= 0) {
        throw new InvalidArgumentException("Invalid music section id: {$value}");
    }

    Setting::set('music_section_id', $value);
}
```

`null` means "never chosen", which is what triggers the fallback. `AppSetting` does not validate the
id against the live section list; that is the Settings component's job, since `AppSetting` must not
depend on `PlexClient`.

### 3. Cache namespacing

A private helper on `PlexClient`:

```php
private function sectionKey(string $key): string
{
    return 's'.$this->musicSectionId().':'.$key;
}
```

Applied to section wide aggregates only:

| key | becomes |
|-----|---------|
| `artists` | `s{id}:artists` |
| `recently_added:{limit}` | `s{id}:recently_added:{limit}` |
| `recently_played:{limit}` | `s{id}:recently_played:{limit}` |
| `favorites:{limit}` | `s{id}:favorites:{limit}` |
| `taxonomy:{kind}` | `s{id}:taxonomy:{kind}` |

Deliberately **not** prefixed, because they are keyed by a ratingKey that is unique server wide and
therefore already unambiguous:

- `albums:{artistId}`
- `tracks:{albumId}`
- `artist:{artistId}:popular`

Deliberately **not** prefixed, because they are genuinely server wide:

- `base_url`, `machine_identifier`, `owned_resource`
- `playlists`, `playlist:{id}:items`
- `music_sections`

`PlexCache::flushAll()` and the Settings "Resync metadata" button need no change. They walk the
`plex:_index` and do not care about key shape.

### 4. Search scoping

`sectionId` as a query parameter on `/hubs/search` was tested against the live server and has no
effect: `sectionId=6`, `sectionId=14`, and no parameter return byte identical hub counts. The
endpoint searches every library and every media type (an "ABBA" search returns a `movie` hub). The
app only survives this today because it cherry picks the track, artist, album, and playlist hubs.

Scoping is therefore a PHP side filter on the raw rows, which do carry `librarySectionID` and
`librarySectionTitle`:

```php
$sectionId = $this->musicSectionId();
$inSection = fn (array $row) => (int) ($row['librarySectionID'] ?? 0) === $sectionId;

return new SearchResults(
    tracks:  $metadata('track')->filter($inSection)->map(...)->values(),
    artists: $metadata('artist')->filter($inSection)->map(...)->values(),
    albums:  $metadata('album')->filter($inSection)->map(...)->values(),
    playlists: $metadata('playlist')-> ... // unfiltered
);
```

**Playlists are not filtered.** Verified against the live server: playlist hub rows have no
`librarySectionID` key at all, so a naive filter would silently drop every playlist from search.
Playlists are server wide, which also matches the sidebar showing all playlists regardless of the
selected library.

Known consequence: Plex applies `limit: 30` before our filter, so a scoped search can return fewer
than 30 results, and a Classical only term searched while "Music" is selected returns nothing. This
is correct behaviour for a scoped search, but it is a visible change from today.

### 5. Settings UI

Port the specced row from `design/rendered/settings.html` into the Plex Server card of
`resources/views/pages/⚡settings.blade.php`, structure and class lists verbatim:

- Title: `Music library`
- Hint: `Which Plex library to read from.`
- Control: `<select>` with `bg-surface-2 hover:bg-surface-3 transition-colors text-white text-[13px] font-semibold rounded-md px-3 py-1.5`

Wiring follows the existing density pattern: a `public ?int $musicSectionId` prop, `wire:model.live`,
and an `updatedMusicSectionId(int $value)` hook that validates the id against `musicSections()` and
calls `AppSetting::setMusicSectionId()`.

The prop is seeded in `mount()` from the resolved `PlexClient::musicSectionId()`, which is the
effective value rather than the raw setting, so the select reflects what the app is actually using
even when nothing has been chosen yet. That resolution hits Plex and therefore throws when the
server is unreachable, so it must be wrapped:

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

Without the catch, an unreachable Plex server would take down the entire Settings page, including
the Display and About cards. Today the page survives an outage precisely because every Plex call
sits inside the catching `serverInfo()` computed, and this change must preserve that property.

The options come from a `#[Computed] musicSections()` that resolves `PlexClient` via `app()`
(computeds do not autowire). On `PlexException` the computed returns an empty array and the row is
omitted, since the card already renders an unreachable state.

No cache flush on switch. The namespaced keys make it unnecessary.

### 6. Server chip

`resources/views/components/⚡server-chip.blade.php` gains the effective library title on its
existing subtitle line, reusing the current structure:

```
Music · Direct connection
```

`refreshStatus()` resolves the title from `musicSections()` plus `musicSectionId()`, wrapped in the
existing try/catch so an unreachable server still renders "Unreachable" as it does now.

### 7. Switching behaviour

- Playback is unaffected. Stream URLs are absolute and already resolved into the Alpine queue.
- Sidebar playlists and folders are unaffected. Plex playlists are server wide.
- No stale selection state. Library view selection lives in `?artist=` / `?album=` URL params, and
  the user is on `/settings` when switching, so there is nothing to reset.

## Error handling

| Case | Behaviour |
|------|-----------|
| No `artist` sections on the server | `PlexNotFoundException`, same as today |
| Plex unreachable while rendering Settings | Library row omitted, existing unreachable state shown |
| Plex unreachable while rendering the server chip | Existing "Unreachable" subtitle, no library name |
| Saved section id no longer exists | Fall back to lowest key, setting row preserved |
| Invalid id submitted to `updatedMusicSectionId` | Validated against `musicSections()`, ignored if unknown |

## Testing

| File | Coverage |
|------|----------|
| `tests/Unit/PlexDtoTest.php` | `MusicSection::fromPlex` casts key to int |
| `tests/Unit/PlexClientTest.php` | `musicSections()` filters to artist and sorts by key; `musicSectionId()` honours a saved id, falls back to lowest key when unset, falls back when the saved id is missing, throws when no artist sections exist; cache keys are section namespaced; `searchAll` filters tracks/artists/albums by section and keeps playlists |
| `tests/Unit/AppSettingTest.php` | `musicSectionId()` defaults to null, round trips, rejects `<= 0` |
| `tests/Feature/SettingsViewTest.php` | Select renders one option per artist section, selecting persists, row omitted when Plex is unreachable, and the page itself still renders (Display and About cards intact) during an outage |
| `tests/Feature/ServerChipTest.php` | Subtitle shows the effective library name |
| `tests/Browser/SettingsTest.php` | Switching the library in the real UI round trips |

## Known costs

Recorded up front rather than discovered mid build.

1. **`tests/Unit/PlexClientTest.php` needs `uses(RefreshDatabase::class)`.** `musicSectionId()` now
   reads a `Setting`, so this "unit" test touches the database. Unavoidable while `PlexClient`
   resolves the section internally, which it must, since `artists()` and friends call it.
2. **`config/cache.php` `serializable_classes` gains `MusicSection::class`**, and
   **`php artisan cache:clear` is required on rollout** (new cached DTO, plus the abandoned
   `music_section` key). This is the documented stale cached DTO gotcha.
3. **Mock churn.** The server chip now calls `musicSections()` and renders in the layout, so every
   test that mocks `PlexClient` while rendering a full layout needs that stub added.

## Rollout

1. `php artisan cache:clear`
2. Open `/settings`, confirm the Music library select lists Classical, Music, and Spoken
3. Select "Music", confirm the server chip reads `Music · Direct connection`
