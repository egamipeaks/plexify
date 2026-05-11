# Play-queue follow-ups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Highlight the currently-playing track in the library and playlist-detail tracklists, make the now-playing strip navigate to the playing track's album/artist, and make a plain Play (or track-row click) clear the shuffle toggle.

**Architecture:** A new Alpine global store `$store.player` (`{ currentId, isPlaying }`) is written by the player's `audioPlayer()` scope and read by the tracklist rows; the `play-track` queue items gain `albumId`/`artistId` (sourced from a new `Track` DTO field) so the persisted now-playing strip can navigate from anywhere; the `queue-load` handler sets `shuffle` from the incoming payload instead of only ever turning it on.

**Tech Stack:** Laravel 13, Livewire 4 SFCs, Alpine.js, Tailwind CSS 4, Pest 4 (+ browser plugin / Playwright). Reference spec: `docs/superpowers/specs/2026-05-11-plextune-play-queue-followups-design.md`.

---

## File structure

| File | Change |
|---|---|
| `app/Services/Plex/Dto/Track.php` | Add `?string $albumId`, `?string $artistId` props + `fromPlex` mapping. |
| `app/Services/Plex/PlexClient.php` | Add `queueItem(Track, ?string $artworkOverride): array` mapper. |
| `resources/views/pages/⚡library.blade.php` | Build the album queue via `queueItem()`; `x-data="{}"` on the tracklist scroll container; accent the playing row's title; swap the `#` cell for an equalizer when playing. |
| `resources/views/pages/⚡playlist-detail.blade.php` | Same, using `$i + 1` for the row number cell. |
| `resources/views/pages/⚡search.blade.php` | Build the queue via `queueItem()`. |
| `resources/views/pages/⚡recently-played.blade.php` | Build the queue via `queueItem()`. |
| `resources/views/components/⚡sidebar.blade.php` | Build the queue via `queueItem()`. |
| `resources/views/components/⚡player.blade.php` | Register `$store.player`; sync `currentId`/`isPlaying`; `goToAlbum()`/`goToArtist()` + `@click` wiring on the now-playing cover/title/artist; `queue-load` sets `shuffle = !!detail.shuffle`. |
| `resources/css/app.css` | `.eq` equalizer keyframes/classes. |
| `tests/Unit/PlexDtoTest.php` | Cover `Track` `albumId`/`artistId` mapping. |
| `tests/Unit/PlexClientTest.php` | Cover `queueItem()`. |
| `tests/Feature/{LibraryViewTest,PlaylistDetailViewTest,SearchViewTest,RecentlyPlayedTest,SidebarTest}.php` | Expect `albumId`/`artistId` in the dispatched `play-track` payloads. |
| `tests/Browser/PlayerQueueTest.php` | 3 new tests: playing-row highlight, now-playing-title navigation, plain-Play clears shuffle. |
| `CLAUDE.md` | Mark these follow-ups done; note remaining scope; bump test count; update the `play-track` payload note. |
| `~/.claude/projects/-Users-akrzy-Dev-Herd-Plexify/memory/pending_polish_followups.md` + `MEMORY.md` | Drop the "now-playing info should navigate to the album" item (done). |

---

## Task 1: Add `albumId` / `artistId` to the `Track` DTO

**Files:**
- Modify: `app/Services/Plex/Dto/Track.php`
- Test: `tests/Unit/PlexDtoTest.php`

- [ ] **Step 1: Write the failing tests**

Add these to `tests/Unit/PlexDtoTest.php` (after the existing `it('builds Track from Plex JSON row', ...)` block):

```php
it('maps parentRatingKey -> albumId and grandparentRatingKey -> artistId on a Track', function () {
    $track = Track::fromPlex([
        'ratingKey' => '11111',
        'title' => '33 GOD',
        'grandparentTitle' => 'Bon Iver',
        'parentTitle' => '22, A Million',
        'parentRatingKey' => '67890',
        'grandparentRatingKey' => '100',
        'index' => 3,
        'duration' => 213000,
        'Media' => [['Part' => [['id' => 999, 'container' => 'flac']]]],
    ]);

    expect($track->albumId)->toBe('67890');
    expect($track->artistId)->toBe('100');
});

it('leaves Track albumId/artistId null when the parent keys are absent', function () {
    $track = Track::fromPlex([
        'ratingKey' => '1',
        'title' => 'x',
        'grandparentTitle' => 'a',
        'parentTitle' => 'b',
        'Media' => [['Part' => [['id' => 1, 'container' => 'mp3']]]],
    ]);

    expect($track->albumId)->toBeNull();
    expect($track->artistId)->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='maps parentRatingKey|leaves Track albumId'`
Expected: FAIL — `Track::__construct()` has no `albumId`/`artistId`, the new properties don't exist.

- [ ] **Step 3: Add the properties and mapping**

In `app/Services/Plex/Dto/Track.php`, append two promoted properties after `?string $thumb = null,` and map them in `fromPlex`:

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
) {}

public static function fromPlex(array $row): self
{
    $part = $row['Media'][0]['Part'][0] ?? [];

    return new self(
        id: (string) $row['ratingKey'],
        title: $row['title'],
        artist: $row['originalTitle'] ?? $row['grandparentTitle'] ?? '',
        album: $row['parentTitle'] ?? '',
        trackNumber: $row['index'] ?? 0,
        durationMs: $row['duration'] ?? 0,
        partId: (int) ($part['id'] ?? 0),
        container: $part['container'] ?? 'mp3',
        thumb: $row['thumb'] ?? $row['parentThumb'] ?? null,
        albumId: isset($row['parentRatingKey']) ? (string) $row['parentRatingKey'] : null,
        artistId: isset($row['grandparentRatingKey']) ? (string) $row['grandparentRatingKey'] : null,
    );
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='maps parentRatingKey|leaves Track albumId'`
Expected: PASS. Also run `php artisan test --compact tests/Unit/PlexDtoTest.php` — all green.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/Dto/Track.php tests/Unit/PlexDtoTest.php
git commit -m "feat(plex): carry albumId/artistId on the Track DTO"
```

---

## Task 2: Add `PlexClient::queueItem()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php` (add a method near `streamUrl`/`thumbUrl`, around line 263–286)
- Test: `tests/Unit/PlexClientTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/PlexClientTest.php` (anywhere among the existing `it(...)` blocks; it does not need `Http::fake` because `streamUrl`/`thumbUrl` are pure string building — but check the top of the file for any shared `beforeEach`/config helper and follow it; if other tests construct the client via a helper, use that helper):

```php
it('builds a queue item from a Track, falling back to the track thumb', function () {
    $client = app(\App\Services\Plex\PlexClient::class);

    $track = new \App\Services\Plex\Dto\Track(
        id: '9001', title: 'Holocene', artist: 'Bon Iver', album: '22, A Million',
        trackNumber: 3, durationMs: 213000, partId: 660001, container: 'flac',
        thumb: '/t/9001', albumId: '67890', artistId: '100',
    );

    $item = $client->queueItem($track);

    expect($item['id'])->toBe('9001');
    expect($item['title'])->toBe('Holocene');
    expect($item['artist'])->toBe('Bon Iver');
    expect($item['albumId'])->toBe('67890');
    expect($item['artistId'])->toBe('100');
    expect($item['url'])->toBe($client->streamUrl($track));
    expect($item['artwork'])->toBe($client->thumbUrl('/t/9001'));
});

it('uses the artwork override for a queue item when given', function () {
    $client = app(\App\Services\Plex\PlexClient::class);

    $track = new \App\Services\Plex\Dto\Track(
        id: '9001', title: 'x', artist: 'y', album: 'z',
        trackNumber: 1, durationMs: 1000, partId: 1, container: 'flac',
        thumb: '/t/track', albumId: '1001', artistId: '100',
    );

    $item = $client->queueItem($track, 'https://cdn/album-cover.jpg');

    expect($item['artwork'])->toBe('https://cdn/album-cover.jpg');
});
```

Note: if `app(PlexClient::class)` needs a `PLEX_TOKEN` / base URL to build URLs, mirror whatever the existing `PlexClientTest` does to construct a usable client (it already exercises `streamUrl` at line ~202 — copy that setup). If the file uses a `makeClient()` helper or `Http::fake` + config, reuse it.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter='builds a queue item|uses the artwork override'`
Expected: FAIL — `PlexClient::queueItem()` does not exist.

- [ ] **Step 3: Add the method**

In `app/Services/Plex/PlexClient.php`, immediately after the `thumbUrl()` method:

```php
/**
 * @return array{
 *     id: string,
 *     url: string,
 *     title: string,
 *     artist: string,
 *     artwork: ?string,
 *     albumId: ?string,
 *     artistId: ?string,
 * }
 */
public function queueItem(Track $track, ?string $artworkOverride = null): array
{
    return [
        'id' => $track->id,
        'url' => $this->streamUrl($track),
        'title' => $track->title,
        'artist' => $track->artist,
        'artwork' => $artworkOverride ?? $this->thumbUrl($track->thumb),
        'albumId' => $track->albumId,
        'artistId' => $track->artistId,
    ];
}
```

(`Track` is already imported at the top of the file.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter='builds a queue item|uses the artwork override'`
Expected: PASS. Also run `php artisan test --compact tests/Unit/PlexClientTest.php` — all green.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat(plex): add PlexClient::queueItem() queue-payload mapper"
```

---

## Task 3: Route every `play-track` payload through `queueItem()`

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php` (`albumQueue()`, ~lines 77–88)
- Modify: `resources/views/pages/⚡playlist-detail.blade.php` (`queuePayload()`, ~lines 100–108)
- Modify: `resources/views/pages/⚡search.blade.php` (`playTrack()`, ~lines 44–63)
- Modify: `resources/views/pages/⚡recently-played.blade.php` (`playTrack()`, ~lines 27–45)
- Modify: `resources/views/components/⚡sidebar.blade.php` (`playPlaylist()`, ~lines 183–207)
- Test: `tests/Feature/LibraryViewTest.php`, `tests/Feature/PlaylistDetailViewTest.php`, `tests/Feature/SearchViewTest.php`, `tests/Feature/RecentlyPlayedTest.php`, `tests/Feature/SidebarTest.php`

- [ ] **Step 1: Update the feature-test expectations (will fail until the components change)**

These tests assert exact `play-track` payload arrays, so every queue item needs the two new keys, and the `new Track(...)` fixtures need values to assert. Make these edits:

**`tests/Feature/LibraryViewTest.php`**
- The single-track test (`it('dispatches play-track event with stream URL when track clicked', ...)`): change the `$track` construction to add `albumId: '1001', artistId: '100',` and change the expected queue item to:
  ```php
  queue: [[
      'id' => '9001',
      'url' => 'https://plex/file.flac?X-Plex-Token=t',
      'title' => 'Test',
      'artist' => 'A',
      'artwork' => null,
      'albumId' => '1001',
      'artistId' => '100',
  ]],
  ```
- The "plays the whole album" test: add `albumId: '1001', artistId: '100',` to both `$t1` and `$t2`, and change the expected queue array to:
  ```php
  queue: [
      ['id' => '9001', 'url' => 'https://plex/991.flac?X-Plex-Token=t', 'title' => 'One', 'artist' => 'A', 'artwork' => null, 'albumId' => '1001', 'artistId' => '100'],
      ['id' => '9002', 'url' => 'https://plex/992.flac?X-Plex-Token=t', 'title' => 'Two', 'artist' => 'A', 'artwork' => null, 'albumId' => '1001', 'artistId' => '100'],
  ],
  ```
- The "shuffles the album" test uses a closure assertion that only checks `id` — add `albumId: '1001', artistId: '100',` to `$t1`/`$t2` for consistency but no assertion change needed.

**`tests/Feature/PlaylistDetailViewTest.php`**
- The two `new Track(...)` fixtures at the top: add `albumId: '5001', artistId: '100',` to `8001` and `albumId: '5002', artistId: '100',` to `8002` (any stable ids; reuse these consistently below).
- "dispatches play-track for a clicked row" expected queue:
  ```php
  queue: [
      ['id' => '8001', 'url' => 'https://server/library/parts/770001/file.flac?X-Plex-Token=t', 'title' => 'Holocene', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8001', 'albumId' => '5001', 'artistId' => '100'],
      ['id' => '8002', 'url' => 'https://server/library/parts/770002/file.flac?X-Plex-Token=t', 'title' => 'Skinny Love', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8002', 'albumId' => '5002', 'artistId' => '100'],
  ],
  ```
- "plays the first track when Play is pressed" expected queue: same two-element array as above.
- "shuffles the playlist" uses a closure (`count($params['queue']) === 2`) — no change needed.

**`tests/Feature/SearchViewTest.php`**
- In `sampleResults()` add `albumId: '1001', artistId: '100',` to the `new Track(id: '9001', ...)`.
- "dispatches play-track when a track row is clicked" expected queue item:
  ```php
  queue: [[
      'id' => '9001',
      'url' => 'https://server/library/parts/660001/file.flac?X-Plex-Token=t',
      'title' => 'Holocene',
      'artist' => 'Bon Iver',
      'artwork' => 'https://thumb/t/9001',
      'albumId' => '1001',
      'artistId' => '100',
  ]],
  ```

**`tests/Feature/RecentlyPlayedTest.php`**
- In the `recentTrack()` helper, add `albumId: "a{$id}", artistId: 'art1',` to the `new Track(...)` call.
- "playTrack dispatches play-track with the stream URL" expected queue item:
  ```php
  queue: [[
      'id' => '70001',
      'url' => 'https://stream/70001.flac',
      'title' => 'Song',
      'artist' => 'Some Artist',
      'artwork' => 'https://thumb/library/metadata/70001/thumb',
      'albumId' => 'a70001',
      'artistId' => 'art1',
  ]],
  ```

**`tests/Feature/SidebarTest.php`**
- "enqueues a playlist when played": add `albumId: 'alb1', artistId: 'art1',` to the `new Track(id: '1', ...)` fixture, and change the expected queue item to:
  ```php
  queue: [[
      'id' => '1',
      'url' => 'https://stream/1.flac',
      'title' => 'Song',
      'artist' => 'Band',
      'artwork' => 'https://thumb/t',
      'albumId' => 'alb1',
      'artistId' => 'art1',
  ]],
  ```

- [ ] **Step 2: Run the feature tests to verify they fail**

Run: `php artisan test --compact tests/Feature/LibraryViewTest.php tests/Feature/PlaylistDetailViewTest.php tests/Feature/SearchViewTest.php tests/Feature/RecentlyPlayedTest.php tests/Feature/SidebarTest.php`
Expected: FAIL — the dispatched payloads still lack `albumId`/`artistId`.

- [ ] **Step 3: Update the five components to build queue items via `queueItem()`**

**`resources/views/pages/⚡library.blade.php`** — replace the body of `albumQueue()`:
```php
/** @return list<array{id: string, url: string, title: string, artist: string, artwork: ?string, albumId: ?string, artistId: ?string}> */
protected function albumQueue(PlexClient $plex): array
{
    $artwork = $this->thumbFor($this->selectedAlbum?->thumb);

    return $this->tracks->values()->map(fn ($t) => $plex->queueItem($t, $artwork))->all();
}
```

**`resources/views/pages/⚡playlist-detail.blade.php`** — replace the body of `queuePayload()`:
```php
/** @return list<array{id: string, url: string, title: string, artist: string, artwork: ?string, albumId: ?string, artistId: ?string}> */
protected function queuePayload(): array
{
    return $this->tracks->values()->map(fn ($t) => $this->plex->queueItem($t))->all();
}
```

**`resources/views/pages/⚡search.blade.php`** — in `playTrack()`, replace the `queue:` argument:
```php
$this->dispatch('play-track',
    queue: $tracks->map(fn ($t) => $this->plex->queueItem($t))->values()->all(),
    index: $i,
);
```

**`resources/views/pages/⚡recently-played.blade.php`** — in `playTrack()`, replace the `queue:` argument:
```php
$this->dispatch('play-track',
    queue: $tracks->map(fn ($t) => $this->plex->queueItem($t))->values()->all(),
    index: $i,
);
```

**`resources/views/components/⚡sidebar.blade.php`** — in `playPlaylist()`, replace the `queue:` argument:
```php
$this->dispatch('play-track',
    queue: $tracks->map(fn ($t) => $this->plex->queueItem($t))->values()->all(),
    index: 0,
);
```

(If a `thumbFor()` private helper becomes unused in any of these files, remove it; but the templates also call `thumbFor()` for row images, so it almost certainly stays — leave it if it's still referenced.)

- [ ] **Step 4: Run the feature + unit suite to verify green**

Run: `php artisan test --compact tests/Feature/LibraryViewTest.php tests/Feature/PlaylistDetailViewTest.php tests/Feature/SearchViewTest.php tests/Feature/RecentlyPlayedTest.php tests/Feature/SidebarTest.php tests/Feature/PlayerComponentTest.php tests/Unit`
Expected: PASS (PlayerComponentTest is unchanged — the player relay forwards the payload verbatim — but run it to be sure nothing regressed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡library.blade.php resources/views/pages/⚡playlist-detail.blade.php resources/views/pages/⚡search.blade.php resources/views/pages/⚡recently-played.blade.php resources/views/components/⚡sidebar.blade.php tests/Feature/LibraryViewTest.php tests/Feature/PlaylistDetailViewTest.php tests/Feature/SearchViewTest.php tests/Feature/RecentlyPlayedTest.php tests/Feature/SidebarTest.php
git commit -m "feat(player): include albumId/artistId in every play-track queue payload"
```

---

## Task 4: Player — `$store.player`, state sync, now-playing navigation, shuffle fix

**Files:**
- Modify: `resources/views/components/⚡player.blade.php`

No automated test in this task (the changes are Alpine/JS — covered by the browser tests in Task 8 and exercised manually below).

- [ ] **Step 1: Wire `@click` navigation onto the now-playing block**

Replace the now-playing block (the first child `<div class="flex items-center gap-3 min-w-[280px] max-w-[30%]">` ... `</div>`) with:

```blade
{{-- Now-playing --}}
<div class="flex items-center gap-3 min-w-[280px] max-w-[30%]">
    <template x-if="current && current.artwork">
        <img :src="current.artwork" :alt="current.title"
             @click="goToAlbum()"
             class="w-14 h-14 rounded-md flex-none object-cover cursor-pointer">
    </template>
    <template x-if="!(current && current.artwork)">
        <div class="w-14 h-14 rounded-md bg-surface-2 grid place-items-center flex-none cursor-pointer"
             @click="goToAlbum()">
            <x-lucide-music class="w-6 h-6 text-text-3" />
        </div>
    </template>
    <div class="min-w-0 flex-1">
        <div data-region="now-playing-title" class="truncate text-[14px] font-semibold hover:underline cursor-pointer"
             @click="goToAlbum()" x-text="current ? current.title : ''"></div>
        <div data-region="now-playing-artist" class="truncate text-[11px] text-text-2 hover:underline cursor-pointer"
             @click="goToArtist()" x-text="current ? current.artist : ''"></div>
    </div>
    <button type="button" class="text-accent hover:scale-110 transition-transform">
        <x-lucide-heart class="w-4 h-4" />
    </button>
</div>
```

- [ ] **Step 2: Sync the store from the `<audio>` play/pause handlers**

Change the `<audio>` element's `@play` and `@pause` attributes:

```blade
<audio x-ref="audio"
       @timeupdate="currentTime = $event.target.currentTime"
       @loadedmetadata="duration = $event.target.duration"
       @play="isPlaying = true; consecutiveErrors = 0; $store.player.isPlaying = true"
       @pause="isPlaying = false; $store.player.isPlaying = false"
       @ended="next()"
       x-on:error="onTrackError()"></audio>
```

- [ ] **Step 3: Register the store and update `audioPlayer()` inside `@script`**

In the `@script` block:

(a) At the very top of the `<script>` body, before `window.audioPlayer = function () {`, register the store:
```js
if (window.Alpine && !Alpine.store('player')) {
    Alpine.store('player', { currentId: null, isPlaying: false });
}
document.addEventListener('alpine:init', () => {
    if (!Alpine.store('player')) {
        Alpine.store('player', { currentId: null, isPlaying: false });
    }
});
```

(b) In the `queue-load` listener inside `init()`, replace:
```js
if (e.detail.shuffle) {
    this.shuffle = true;
}
```
with:
```js
this.shuffle = !!e.detail.shuffle;
```

(c) In `loadAndPlay(i)`, set the store's `currentId` (after `this.index = i;` and before setting the audio src):
```js
loadAndPlay(i) {
    if (i < 0 || i >= this.queue.length) {
        return;
    }
    this.index = i;
    this.currentTime = 0;
    this.duration = 0;
    Alpine.store('player').currentId = this.queue[i].id;
    this.$refs.audio.src = this.queue[i].url;
    this.$refs.audio.play().catch(() => {});
},
```

(d) Add `goToAlbum()` and `goToArtist()` methods (anywhere in the returned object, e.g. just before `seek(value)`):
```js
goToAlbum() {
    const c = this.current;
    if (!c || !c.albumId) {
        return;
    }
    const params = new URLSearchParams();
    if (c.artistId) {
        params.set('artist', c.artistId);
    }
    params.set('album', c.albumId);
    Livewire.navigate('/?' + params.toString());
},

goToArtist() {
    const c = this.current;
    if (!c || !c.artistId) {
        return;
    }
    Livewire.navigate('/?artist=' + encodeURIComponent(c.artistId));
},
```

- [ ] **Step 4: Rebuild assets and smoke-test manually**

```bash
npm run build
```
Then load `https://plexify.test`, play a track, and confirm: the now-playing cover and title are clickable (cursor-pointer); clicking the title navigates to that album in the library; clicking the artist subtitle navigates to the artist; toggling Shuffle on then clicking a track row turns Shuffle off. (No console errors.)

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/⚡player.blade.php public/build
git commit -m "feat(player): now-playing album/artist navigation, playback store, plain Play clears shuffle"
```

---

## Task 5: Equalizer CSS

**Files:**
- Modify: `resources/css/app.css`

- [ ] **Step 1: Add the `.eq` rules**

Append to `resources/css/app.css` (after the `html, body { ... }` block):

```css
@keyframes eq-bar {
    0%, 100% { transform: scaleY(0.3); }
    50%      { transform: scaleY(1); }
}

.eq {
    display: inline-flex;
    align-items: flex-end;
    gap: 2px;
    height: 14px;
    vertical-align: middle;
}

.eq > span {
    width: 3px;
    height: 100%;
    background: var(--color-accent);
    transform-origin: bottom;
    animation: eq-bar 0.9s ease-in-out infinite;
}

.eq > span:nth-child(2) { animation-duration: 1.1s; animation-delay: -0.45s; }
.eq > span:nth-child(3) { animation-duration: 0.7s; animation-delay: -0.2s; }

.eq.is-paused > span { animation-play-state: paused; }
```

- [ ] **Step 2: Rebuild assets**

```bash
npm run build
```
Expected: builds cleanly with no Tailwind/PostCSS errors.

- [ ] **Step 3: Commit**

```bash
git add resources/css/app.css public/build
git commit -m "feat(player): equalizer animation styles for the playing-track row"
```

---

## Task 6: Playing-track highlight in the library tracklist

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php` (the tracklist `@foreach` region, ~lines 360–393)

- [ ] **Step 1: Add an Alpine scope to the tracklist scroll container**

Change the wrapper around the `@foreach`:
```blade
<div class="overflow-y-auto scroll flex-1 py-1" x-data="{}">
```

- [ ] **Step 2: Accent the playing row's title and swap the `#` cell for the equalizer**

Inside each track row `<button>`:

Replace the `#` cell:
```blade
<span class="tabular-nums text-text-2 text-right">{{ $track->trackNumber }}</span>
```
with:
```blade
<span class="tabular-nums text-text-2 text-right">
    <template x-if="$store.player?.currentId === '{{ $track->id }}'">
        <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
    </template>
    <template x-if="$store.player?.currentId !== '{{ $track->id }}'">
        <span>{{ $track->trackNumber }}</span>
    </template>
</span>
```

Replace the title `<div>`:
```blade
<div class="truncate font-medium text-white">{{ $track->title }}</div>
```
with:
```blade
<div class="truncate font-medium" :class="$store.player?.currentId === '{{ $track->id }}' ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
```

- [ ] **Step 3: Rebuild and verify the existing library tests still pass**

```bash
npm run build
php artisan test --compact tests/Feature/LibraryViewTest.php
```
Expected: PASS (the feature tests assert dispatched events, not DOM; the template still renders).

- [ ] **Step 4: Manual check**

Load `https://plexify.test`, drill into an album, click a track: that row's title turns green and a small animated equalizer replaces its number; skip forward in the player and the highlight follows; pause and the bars freeze. No console errors.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/⚡library.blade.php public/build
git commit -m "feat(library): highlight the currently-playing track in the album tracklist"
```

---

## Task 7: Playing-track highlight in the playlist-detail tracklist

**Files:**
- Modify: `resources/views/pages/⚡playlist-detail.blade.php` (the tracklist `@foreach` region, ~lines 175–207)

- [ ] **Step 1: Add an Alpine scope to the tracklist scroll container**

Change the wrapper around the `@foreach`:
```blade
<div class="overflow-y-auto scroll flex-1 py-1" x-data="{}">
```

- [ ] **Step 2: Accent the playing row's title and swap the `#` cell for the equalizer**

Inside each track row `<button>` (note this template uses `{{ $i + 1 }}` for the row number):

Replace the `#` cell:
```blade
<span class="tabular-nums text-text-2 text-right">{{ $i + 1 }}</span>
```
with:
```blade
<span class="tabular-nums text-text-2 text-right">
    <template x-if="$store.player?.currentId === '{{ $track->id }}'">
        <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
    </template>
    <template x-if="$store.player?.currentId !== '{{ $track->id }}'">
        <span>{{ $i + 1 }}</span>
    </template>
</span>
```

Replace the title `<div>`:
```blade
<div class="truncate font-medium text-white">{{ $track->title }}</div>
```
with:
```blade
<div class="truncate font-medium" :class="$store.player?.currentId === '{{ $track->id }}' ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
```

- [ ] **Step 3: Rebuild and verify the existing playlist-detail tests still pass**

```bash
npm run build
php artisan test --compact tests/Feature/PlaylistDetailViewTest.php
```
Expected: PASS.

- [ ] **Step 4: Manual check**

Open a playlist, click a track: green title + equalizer on that row; advancing the player moves the highlight. No console errors.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/⚡playlist-detail.blade.php public/build
git commit -m "feat(playlist): highlight the currently-playing track in the playlist tracklist"
```

---

## Task 8: Browser tests in `PlayerQueueTest.php`

**Files:**
- Modify: `tests/Browser/PlayerQueueTest.php` (append three `it(...)` blocks; reuse the existing `drillIntoAlbumAndClickTrack($page)` helper at the top of the file)

These hit the live Plex server (same as the rest of `tests/Browser/`); they're skipped/fail if it's unreachable.

- [ ] **Step 1: Add the three tests**

Append to `tests/Browser/PlayerQueueTest.php`:

```php
it('highlights the currently-playing track in the library tracklist and moves the highlight on skip', function () {
    $page = visit('/');

    $nowPlaying = drillIntoAlbumAndClickTrack($page);
    expect(json_decode((string) $nowPlaying, true))->toBeArray("Expected the queue to populate, got: {$nowPlaying}");

    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const playerData = () => Alpine.$data(document.querySelector('[x-data="audioPlayer()"]'));
            const rowHasEq = (i) => {
                const rows = document.querySelectorAll('[data-region=tracklist] button[wire\\:click^="playTrack"]');
                return !!rows[i]?.querySelector('.eq');
            };

            const p = playerData();
            await sleep(300);
            const before = {
                idx: p.index,
                eqOnCurrent: rowHasEq(p.index),
                eqOnOther: rowHasEq(p.index === 0 ? 1 : 0),
            };

            p.next();
            await sleep(500);
            const after = { idx: p.index, eqOnCurrent: rowHasEq(p.index) };

            return JSON.stringify({ before, after });
        })()
    JS);

    $decoded = json_decode((string) $result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");
    expect($decoded['before']['eqOnCurrent'])->toBeTrue('The playing row should show the equalizer marker');
    expect($decoded['before']['eqOnOther'])->toBeFalse('Non-playing rows should not show the equalizer');
    expect($decoded['after']['eqOnCurrent'])->toBeTrue('The equalizer should follow to the current track after skip');
});

it('navigates to the playing track album when the now-playing title is clicked from another page', function () {
    $page = visit('/');

    $nowPlaying = drillIntoAlbumAndClickTrack($page);
    expect(json_decode((string) $nowPlaying, true))->toBeArray("Expected the queue to populate, got: {$nowPlaying}");

    // Move to the search page (the player + queue persist via @persist), then click the now-playing title.
    $page->script("window.Livewire.navigate('/search')");

    $onSearch = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            for (let i = 0; i < 50; i++) {
                if (location.pathname.replace(/\/$/, '').endsWith('/search')) { return 'ON_SEARCH'; }
                await sleep(100);
            }
            return 'STILL_' + location.pathname;
        })()
    JS);
    expect($onSearch)->toBe('ON_SEARCH');

    $page->assertVisible('[data-region=now-playing-title]');
    $page->click('[data-region=now-playing-title]');

    $url = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            for (let i = 0; i < 50; i++) {
                if (/[?&]album=/.test(location.search)) { return location.href; }
                await sleep(100);
            }
            return 'NO_ALBUM_PARAM:' + location.href;
        })()
    JS);
    expect($url)->toMatch('/[?&]album=/');
});

it('clears the shuffle toggle when a plain track-row click loads a new queue', function () {
    $page = visit('/');

    $nowPlaying = drillIntoAlbumAndClickTrack($page);
    expect(json_decode((string) $nowPlaying, true))->toBeArray("Expected the queue to populate, got: {$nowPlaying}");

    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const playerData = () => Alpine.$data(document.querySelector('[x-data="audioPlayer()"]'));

            const p = playerData();
            if (!p.shuffle) { p.toggleShuffle(); }
            await sleep(200);
            const shuffleAfterToggle = playerData().shuffle;

            const rows = document.querySelectorAll('[data-region=tracklist] button[wire\\:click^="playTrack"]');
            rows[0].click();

            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                const d = playerData();
                if (d.queue.length > 0 && d.index === 0) { break; }
                await sleep(100);
            }
            await sleep(200);

            return JSON.stringify({ shuffleAfterToggle, shuffleAfterPlay: playerData().shuffle });
        })()
    JS);

    $decoded = json_decode((string) $result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");
    expect($decoded['shuffleAfterToggle'])->toBeTrue('Shuffle should be on after toggling it');
    expect($decoded['shuffleAfterPlay'])->toBeFalse('Clicking a track row should clear the shuffle toggle');
});
```

- [ ] **Step 2: Run the browser suite**

Run: `php artisan test --compact tests/Browser/PlayerQueueTest.php`
Expected: PASS (requires the Plex server reachable with an album that has at least 2 tracks). If a test reports `QUEUE_TOO_SHORT` / `NO_PLAYABLE_ALBUM`, the Plex library lacks a usable album — note it, but do not weaken the assertions.

- [ ] **Step 3: Commit**

```bash
git add tests/Browser/PlayerQueueTest.php
git commit -m "test(browser): playing-track highlight, now-playing album nav, shuffle reset"
```

---

## Task 9: Update docs and memory

**Files:**
- Modify: `CLAUDE.md`
- Modify: `/Users/akrzy/.claude/projects/-Users-akrzy-Dev-Herd-Plexify/memory/pending_polish_followups.md` and `/Users/akrzy/.claude/projects/-Users-akrzy-Dev-Herd-Plexify/memory/MEMORY.md`

- [ ] **Step 1: Update `CLAUDE.md`**

- In the "Play-queue follow-ups" block: remove the "Highlight the currently-playing track" bullet's "not built" framing and the "plain Play should reset shuffle" bullet and the "(Existing, pre-queue) The now-playing block ... navigate ... when clicked" bullet. Replace with a short note: the playing track is now highlighted (accent title + animated equalizer) in the library album tracklist and the playlist-detail tracklist (`$store.player` Alpine store, `currentId`/`isPlaying`); the same highlight in the search "Songs" group and the recently-played list is still a follow-up. The now-playing cover and title navigate to the playing track's album, the artist subtitle to the artist. A plain Play / track-row click now clears the shuffle toggle (only the Shuffle buttons keep it on).
- Add the new spec/plan paths next to the existing play-queue ones: spec `docs/superpowers/specs/2026-05-11-plextune-play-queue-followups-design.md`, plan `docs/superpowers/plans/2026-05-11-plextune-play-queue-followups.md`.
- In the Gotchas note about the `play-track` event payload, change the queue-item shape from `{id, url, title, artist, artwork}` to `{id, url, title, artist, artwork, albumId, artistId}`, and mention `PlexClient::queueItem(Track, ?artworkOverride)` as the single builder for it.
- In the `Plex integration` paragraph, note the `Track` DTO now also carries `albumId` (parentRatingKey) and `artistId` (grandparentRatingKey).
- Bump the test count: run `php artisan test --compact` first, then write the new total into the two places that mention "160 tests" / "12 Playwright browser tests" (browser count goes 12 → 15).

- [ ] **Step 2: Update memory**

In `pending_polish_followups.md`, remove the "now-playing info should navigate to the album" item (it's done); keep the album-header compact collapsed state item. Update its `MEMORY.md` index line to match. If `pending_polish_followups.md` ends up empty, delete the file and its `MEMORY.md` line.

- [ ] **Step 3: Run the full suite and confirm the count**

Run: `php artisan test --compact`
Expected: all green. Use the printed total to finalize the numbers written in Step 1.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: play-queue follow-ups shipped (playing highlight, now-playing nav, shuffle reset)"
```

---

## Self-review notes

- **Spec coverage:** Goal 1 (highlight) → Tasks 5, 6, 7 (+ store in Task 4). Goal 2 (now-playing nav) → Task 4 (+ payload `albumId`/`artistId` in Tasks 1–3). Goal 3 (plain Play clears shuffle) → Task 4 step 3(b). Tests → Tasks 1, 2, 3 (feature), 8 (browser). Out-of-scope items (search/recently-played highlight) are intentionally untouched and recorded as remaining in Task 9.
- **Type consistency:** `Track` gains `?string $albumId, ?string $artistId` (Task 1), used by `PlexClient::queueItem()` returning the seven-key array (Task 2), consumed unchanged by the five dispatchers (Task 3), surfaced in the Alpine queue items as `current.albumId` / `current.artistId` (Task 4). The store is `Alpine.store('player')` with `{ currentId, isPlaying }` everywhere (Tasks 4, 6, 7).
- **Browser-test caveat:** the navigation test relies on `@persist('player')` keeping the queue alive across `Livewire.navigate('/search')`, then asserts `?album=` appears after clicking the now-playing title (which on `/search` is not already present). The highlight test tolerates a 2-track album (the equalizer stays on the last track when `next()` hits the end with repeat off — still a passing assertion).
