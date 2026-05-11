# Play queue & autoplay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the player a real playback queue so the next track plays automatically when one ends, the skip/shuffle/repeat transport buttons work, and the now-playing strip is driven by the queue.

**Architecture:** The queue lives entirely in the player's Alpine `x-data` scope (which already survives `wire:navigate` via `@persist('player')`). Each view that plays a track now builds the whole relevant track list (with stream URLs pre-resolved server-side, which is just string concatenation) and dispatches it as a single `play-track` Livewire event carrying `queue` (array) + `index` + optional `shuffle`. The player Livewire component is a thin relay: it re-broadcasts that as a `queue-load` browser event, and all transport/queue/shuffle/repeat logic is Alpine.

**Tech Stack:** Laravel 13, Livewire 4 single-file components, Alpine.js, native HTML5 `<audio>`, Pest 4 (+ `pestphp/pest-plugin-browser` / Playwright), Tailwind CSS 4, `mallardduck/blade-lucide-icons`.

**Spec:** `docs/superpowers/specs/2026-05-11-plextune-play-queue-design.md`

**Note on intermediate states:** This is a coordinated interface change. The `play-track` event payload changes from `(url, title, artist, artwork)` to `(queue, index, shuffle)`. Between Task 1 (player) and Tasks 2-6 (views), and likewise if tasks run out of order, the in-browser play button is temporarily broken. That is expected. No automated test exercises a view + the player together (feature tests do not mount the player; the browser tests need a live Plex server and are run at the end). Run the full feature suite after Task 6 and the browser test after Task 7.

---

### Task 1: Player — queue state, transport, and the `queue-load` relay

**Files:**
- Modify (full rewrite): `resources/views/components/⚡player.blade.php`
- Modify (full rewrite): `tests/Feature/PlayerComponentTest.php`

- [ ] **Step 1: Verify the `repeat-1` Lucide icon component exists**

Run: `ls vendor/mallardduck/blade-lucide-icons/resources/svg/ | grep -i '^repeat'`
Expected: lists `repeat.svg` and `repeat-1.svg` (so `<x-lucide-repeat-1 />` is usable). If `repeat-1.svg` is absent, in Step 4 replace the `repeat === 'one'` `<template>` with `<x-lucide-repeat class="w-4 h-4" />` plus a `<span class="text-[8px] font-bold absolute -mt-2 ml-2">1</span>` overlay and add `relative` to the button class; everything else in this task is unchanged.

- [ ] **Step 2: Rewrite `tests/Feature/PlayerComponentTest.php` (the failing test)**

The player Livewire component no longer has `trackUrl` / `trackTitle` / `trackArtist` / `trackArtwork` properties or a `loadTrack()` method; it only relays `play-track` → `queue-load`. Replace the whole file:

```php
<?php

use Livewire\Livewire;

it('relays a play-track event as a queue-load browser event', function () {
    $queue = [
        ['id' => '1', 'url' => 'https://example.com/1.mp3', 'title' => 'One', 'artist' => 'A', 'artwork' => null],
        ['id' => '2', 'url' => 'https://example.com/2.mp3', 'title' => 'Two', 'artist' => 'B', 'artwork' => 'https://example.com/2.jpg'],
    ];

    Livewire::test('player')
        ->dispatch('play-track', queue: $queue, index: 1)
        ->assertDispatched('queue-load', queue: $queue, index: 1, shuffle: false);
});

it('forwards the shuffle flag on the queue-load event', function () {
    $queue = [['id' => '1', 'url' => 'u', 'title' => 't', 'artist' => 'a', 'artwork' => null]];

    Livewire::test('player')
        ->dispatch('play-track', queue: $queue, index: 0, shuffle: true)
        ->assertDispatched('queue-load', shuffle: true);
});

it('defaults index to 0 and shuffle to false when omitted', function () {
    $queue = [['id' => '1', 'url' => 'u', 'title' => 't', 'artist' => 'a', 'artwork' => null]];

    Livewire::test('player')
        ->dispatch('play-track', queue: $queue)
        ->assertDispatched('queue-load', queue: $queue, index: 0, shuffle: false);
});
```

- [ ] **Step 3: Run the test, expect failure**

Run: `php artisan test --compact --filter=PlayerComponentTest`
Expected: FAIL — the current component dispatches `audio-load` (not `queue-load`) and `onPlayTrack` does not accept a `queue` parameter.

- [ ] **Step 4: Rewrite `resources/views/components/⚡player.blade.php`**

Replace the whole file with:

```blade
<?php

use Livewire\Component;
use Livewire\Attributes\On;

new class extends Component {
    #[On('play-track')]
    public function onPlayTrack(array $queue, int $index = 0, bool $shuffle = false): void
    {
        $this->dispatch('queue-load', queue: $queue, index: $index, shuffle: $shuffle);
    }
};
?>

<div class="bg-base h-[88px] flex items-center px-4 gap-4 flex-none"
     x-data="audioPlayer()"
     x-init="init()">

    {{-- Now-playing --}}
    <div class="flex items-center gap-3 min-w-[280px] max-w-[30%]">
        <template x-if="current && current.artwork">
            <img :src="current.artwork" :alt="current.title"
                 class="w-14 h-14 rounded-md flex-none object-cover">
        </template>
        <template x-if="!(current && current.artwork)">
            <div class="w-14 h-14 rounded-md bg-surface-2 grid place-items-center flex-none">
                <x-lucide-music class="w-6 h-6 text-text-3" />
            </div>
        </template>
        <div class="min-w-0 flex-1">
            <div data-region="now-playing-title" class="truncate text-[14px] font-semibold hover:underline cursor-pointer" x-text="current ? current.title : ''"></div>
            <div data-region="now-playing-artist" class="truncate text-[11px] text-text-2 hover:underline cursor-pointer" x-text="current ? current.artist : ''"></div>
        </div>
        <button type="button" class="text-accent hover:scale-110 transition-transform">
            <x-lucide-heart class="w-4 h-4" />
        </button>
    </div>

    {{-- Transport --}}
    <div class="flex-1 flex flex-col items-center gap-1.5 max-w-[722px] mx-auto">
        <div class="flex items-center gap-5">
            <button type="button" @click="toggleShuffle()"
                    :class="shuffle ? 'text-accent' : 'text-text-2 hover:text-white'">
                <x-lucide-shuffle class="w-4 h-4" />
            </button>
            <button type="button" @click="previous()" :disabled="queue.length === 0"
                    class="text-text-2 hover:text-white disabled:opacity-40 disabled:hover:text-text-2">
                <x-lucide-skip-back class="w-[18px] h-[18px] fill-current" />
            </button>
            <button type="button" @click="togglePlay"
                    class="w-9 h-9 rounded-full bg-white text-black grid place-items-center hover:scale-105 active:scale-100 transition-transform">
                <template x-if="!isPlaying">
                    <x-lucide-play class="w-4 h-4 fill-current" />
                </template>
                <template x-if="isPlaying">
                    <x-lucide-pause class="w-4 h-4 fill-current" />
                </template>
            </button>
            <button type="button" @click="next()" :disabled="queue.length === 0"
                    class="text-text-2 hover:text-white disabled:opacity-40 disabled:hover:text-text-2">
                <x-lucide-skip-forward class="w-[18px] h-[18px] fill-current" />
            </button>
            <button type="button" @click="cycleRepeat()"
                    :class="repeat !== 'off' ? 'text-accent' : 'text-text-2 hover:text-white'">
                <template x-if="repeat !== 'one'">
                    <x-lucide-repeat class="w-4 h-4" />
                </template>
                <template x-if="repeat === 'one'">
                    <x-lucide-repeat-1 class="w-4 h-4" />
                </template>
            </button>
        </div>
        <div class="flex items-center gap-2 w-full text-[11px] text-text-2">
            <span class="tabular-nums w-8 text-right" x-text="formatTime(currentTime)">0:00</span>
            <input type="range" min="0" :max="duration || 0" step="0.1"
                   :value="currentTime"
                   @input="seek($event.target.value)"
                   class="flex-1 accent-white">
            <span class="tabular-nums w-8" x-text="formatTime(duration)">0:00</span>
        </div>
    </div>

    {{-- Right side: queue, cast, volume --}}
    <div class="flex items-center gap-3 min-w-[280px] max-w-[30%] justify-end">
        <button type="button" class="text-text-2 hover:text-white">
            <x-lucide-list-music class="w-4 h-4" />
        </button>
        <button type="button" class="text-text-2 hover:text-white">
            <x-lucide-cast class="w-4 h-4" />
        </button>
        <div class="flex items-center gap-2 group">
            <button type="button" class="text-text-2 hover:text-white">
                <x-lucide-volume-2 class="w-4 h-4" />
            </button>
            <input type="range" min="0" max="1" step="0.01"
                   :value="volume"
                   @input="setVolume($event.target.value)"
                   class="w-24 accent-white">
        </div>
    </div>

    {{-- The audio element. Persistence across wire:navigate is handled by
         @persist('player') in the layout, NOT here — wrapping just <audio>
         in its own @persist re-parents it out of the Alpine x-data scope,
         breaking $refs.audio. --}}
    <audio x-ref="audio"
           @timeupdate="currentTime = $event.target.currentTime"
           @loadedmetadata="duration = $event.target.duration"
           @play="isPlaying = true; consecutiveErrors = 0"
           @pause="isPlaying = false"
           @ended="next()"
           @error="onTrackError()"></audio>
</div>

@script
<script>
    window.audioPlayer = function () {
        return {
            isPlaying: false,
            currentTime: 0,
            duration: 0,
            volume: 1,

            queue: [],         // playback order: [{ id, url, title, artist, artwork }]
            originalQueue: [],  // the unshuffled order, so toggling shuffle off restores it
            index: 0,           // position in `queue` of the current track
            shuffle: false,
            repeat: 'off',      // 'off' | 'all' | 'one'
            consecutiveErrors: 0,

            get current() {
                return this.queue[this.index] ?? null;
            },

            init() {
                // Livewire $dispatch surfaces as a CustomEvent on window with the event name as-is;
                // the payload is in event.detail.
                window.addEventListener('queue-load', (e) => {
                    this.consecutiveErrors = 0;
                    this.originalQueue = e.detail.queue ?? [];
                    const startIndex = e.detail.index ?? 0;
                    if (e.detail.shuffle) {
                        this.shuffle = true;
                    }
                    if (this.shuffle) {
                        this.applyShuffle(startIndex);
                        this.loadAndPlay(0);
                    } else {
                        this.queue = [...this.originalQueue];
                        this.loadAndPlay(startIndex);
                    }
                });
                this.$refs.audio.volume = this.volume;
            },

            loadAndPlay(i) {
                if (i < 0 || i >= this.queue.length) {
                    return;
                }
                this.index = i;
                this.$refs.audio.src = this.queue[i].url;
                this.$refs.audio.play().catch(() => {});
            },

            togglePlay() {
                if (!this.current) {
                    return;
                }
                if (this.$refs.audio.paused) {
                    this.$refs.audio.play();
                } else {
                    this.$refs.audio.pause();
                }
            },

            next() {
                if (this.repeat === 'one') {
                    this.$refs.audio.currentTime = 0;
                    this.$refs.audio.play().catch(() => {});
                    return;
                }
                if (this.index < this.queue.length - 1) {
                    this.loadAndPlay(this.index + 1);
                    return;
                }
                if (this.repeat === 'all' && this.queue.length > 0) {
                    this.loadAndPlay(0);
                    return;
                }
                // End of queue with repeat off: stop, keep the queue loaded.
                this.$refs.audio.pause();
                this.isPlaying = false;
            },

            previous() {
                if (this.$refs.audio.currentTime > 3 || this.index === 0) {
                    this.$refs.audio.currentTime = 0;
                    return;
                }
                this.loadAndPlay(this.index - 1);
            },

            jumpTo(i) {
                this.loadAndPlay(i);
            },

            toggleShuffle() {
                if (!this.shuffle) {
                    this.shuffle = true;
                    if (this.queue.length > 0) {
                        this.applyShuffle(this.index);
                    }
                } else {
                    this.shuffle = false;
                    const currentId = this.current ? this.current.id : null;
                    this.queue = [...this.originalQueue];
                    const newIndex = this.queue.findIndex((t) => t.id === currentId);
                    this.index = newIndex >= 0 ? newIndex : 0;
                }
            },

            // Rebuild this.queue as [track at sourceIndex of originalQueue, ...Fisher-Yates(rest)]
            // and set this.index = 0. Does NOT touch the <audio> element, so playback continues.
            applyShuffle(sourceIndex) {
                const list = [...this.originalQueue];
                if (list.length === 0) {
                    this.queue = [];
                    this.index = 0;
                    return;
                }
                const idx = Math.min(Math.max(sourceIndex, 0), list.length - 1);
                const head = list.splice(idx, 1)[0];
                for (let i = list.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [list[i], list[j]] = [list[j], list[i]];
                }
                this.queue = [head, ...list];
                this.index = 0;
            },

            cycleRepeat() {
                this.repeat = this.repeat === 'off' ? 'all' : (this.repeat === 'all' ? 'one' : 'off');
            },

            onTrackError() {
                if (this.queue.length === 0) {
                    return;
                }
                this.consecutiveErrors++;
                if (this.consecutiveErrors > this.queue.length) {
                    console.warn('audioPlayer: every track in the queue failed to load; stopping.');
                    this.$refs.audio.pause();
                    this.isPlaying = false;
                    return;
                }
                this.next();
            },

            seek(value) {
                this.$refs.audio.currentTime = parseFloat(value);
            },

            setVolume(value) {
                this.volume = parseFloat(value);
                this.$refs.audio.volume = this.volume;
            },

            formatTime(seconds) {
                if (!seconds || isNaN(seconds)) return '0:00';
                const m = Math.floor(seconds / 60);
                const s = Math.floor(seconds % 60).toString().padStart(2, '0');
                return `${m}:${s}`;
            },
        };
    };
</script>
@endscript
```

- [ ] **Step 5: Run the test, expect pass**

Run: `php artisan test --compact --filter=PlayerComponentTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/⚡player.blade.php tests/Feature/PlayerComponentTest.php
git commit -m "feat(player): playback queue, transport buttons, shuffle/repeat, autoplay"
```

---

### Task 2: Library view — dispatch the album tracklist as a queue

**Files:**
- Modify: `resources/views/pages/⚡library.blade.php` (the `playTrack` method)
- Test: `tests/Feature/LibraryViewTest.php` (the `dispatches play-track event with stream URL when track clicked` test)

- [ ] **Step 1: Update the failing test in `tests/Feature/LibraryViewTest.php`**

Find the test `it('dispatches play-track event with stream URL when track clicked', ...)`. Replace its `->assertDispatched(...)` call so the whole `Livewire::test(...)` chain reads:

```php
    Livewire::test('pages::library')
        ->call('selectArtist', '100')
        ->call('selectAlbum', '1001')
        ->call('playTrack', '9001')
        ->assertDispatched('play-track',
            queue: [[
                'id' => '9001',
                'url' => 'https://plex/file.flac?X-Plex-Token=t',
                'title' => 'Test',
                'artist' => 'A',
                'artwork' => null,
            ]],
            index: 0,
        );
```

(The mock in that test already returns `tracksForAlbum` → `collect([$track])`, `streamUrl` → `'https://plex/file.flac?X-Plex-Token=t'`, `thumbUrl(null)` → `null`, and the selected album has `thumb: null`, so the album-thumb artwork resolves to `null`.)

- [ ] **Step 2: Run the test, expect failure**

Run: `php artisan test --compact --filter="dispatches play-track event with stream URL"`
Expected: FAIL — `playTrack` still dispatches `url:`/`title:`/`artist:`/`artwork:`, not `queue:`/`index:`.

- [ ] **Step 3: Update `playTrack` in `resources/views/pages/⚡library.blade.php`**

Replace the existing `playTrack` method with:

```php
    public function playTrack(PlexClient $plex, string $trackId): void
    {
        $tracks = $this->tracks->values();
        $i = $tracks->search(fn ($t) => $t->id === $trackId);

        if ($i === false) {
            return;
        }

        $artwork = $this->thumbFor($this->selectedAlbum?->thumb);

        $this->dispatch('play-track',
            queue: $tracks->map(fn ($t) => [
                'id' => $t->id,
                'url' => $plex->streamUrl($t),
                'title' => $t->title,
                'artist' => $t->artist,
                'artwork' => $artwork,
            ])->values()->all(),
            index: $i,
        );
    }
```

- [ ] **Step 4: Run the test, expect pass**

Run: `php artisan test --compact --filter="dispatches play-track event with stream URL"`
Expected: PASS.

- [ ] **Step 5: Run the whole library test file**

Run: `php artisan test --compact --filter=LibraryViewTest`
Expected: PASS (all tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡library.blade.php tests/Feature/LibraryViewTest.php
git commit -m "feat(library): play a track from its album tracklist as a queue"
```

---

### Task 3: Playlist detail view — dispatch the playlist as a queue (play / play all / shuffle)

**Files:**
- Modify: `resources/views/pages/⚡playlist-detail.blade.php` (`playTrack`, `playAll`, `shuffle`; remove `dispatchTrack`)
- Test: `tests/Feature/PlaylistDetailViewTest.php` (the three dispatch tests)

- [ ] **Step 1: Update the three failing tests in `tests/Feature/PlaylistDetailViewTest.php`**

`samplePlaylistTracks()` returns two tracks: `8001` "Holocene" (partId `770001`, thumb `/t/8001`) and `8002` "Skinny Love" (partId `770002`, thumb `/t/8002`); the mock's `streamUrl` builds `https://server/library/parts/{partId}/file.{container}?X-Plex-Token=t` and `thumbUrl` builds `https://thumb{thumb}`.

Replace the `it('dispatches play-track for a clicked row', ...)` body:

```php
it('dispatches play-track for a clicked row', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('playTrack', '8002')
        ->assertDispatched('play-track',
            queue: [
                ['id' => '8001', 'url' => 'https://server/library/parts/770001/file.flac?X-Plex-Token=t', 'title' => 'Holocene', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8001'],
                ['id' => '8002', 'url' => 'https://server/library/parts/770002/file.flac?X-Plex-Token=t', 'title' => 'Skinny Love', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8002'],
            ],
            index: 1,
        );
});
```

Replace the `it('plays the first track when Play is pressed', ...)` body:

```php
it('plays the first track when Play is pressed', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('playAll')
        ->assertDispatched('play-track',
            queue: [
                ['id' => '8001', 'url' => 'https://server/library/parts/770001/file.flac?X-Plex-Token=t', 'title' => 'Holocene', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8001'],
                ['id' => '8002', 'url' => 'https://server/library/parts/770002/file.flac?X-Plex-Token=t', 'title' => 'Skinny Love', 'artist' => 'Bon Iver', 'artwork' => 'https://thumb/t/8002'],
            ],
            index: 0,
        );
});
```

Replace the `it('plays a track from the playlist when Shuffle is pressed', ...)` body:

```php
it('shuffles the playlist when Shuffle is pressed', function () {
    mockPlexForPlaylist();

    Livewire::test('pages::playlist-detail', ['playlist' => '4242'])
        ->call('shuffle')
        ->assertDispatched('play-track', function ($event, $params) {
            return ($params['shuffle'] ?? false) === true
                && in_array($params['index'], [0, 1], true)
                && count($params['queue']) === 2;
        });
});
```

- [ ] **Step 2: Run the tests, expect failure**

Run: `php artisan test --compact --filter=PlaylistDetailViewTest`
Expected: FAIL on the three updated tests (still dispatching the old single-track payload).

- [ ] **Step 3: Update `resources/views/pages/⚡playlist-detail.blade.php`**

Replace the `playTrack`, `playAll` and `shuffle` methods, and **delete** the `protected function dispatchTrack(Track $track): void` helper (it is no longer used):

```php
    public function playTrack(string $trackId): void
    {
        $tracks = $this->tracks->values();
        $i = $tracks->search(fn ($t) => $t->id === $trackId);

        if ($i === false) {
            return;
        }

        $this->dispatch('play-track', queue: $this->queuePayload(), index: $i);
    }

    public function playAll(): void
    {
        if ($this->tracks->isEmpty()) {
            return;
        }

        $this->dispatch('play-track', queue: $this->queuePayload(), index: 0);
    }

    public function shuffle(): void
    {
        if ($this->tracks->isEmpty()) {
            return;
        }

        $this->dispatch('play-track',
            queue: $this->queuePayload(),
            index: random_int(0, $this->tracks->count() - 1),
            shuffle: true,
        );
    }

    /** @return list<array{id: string, url: string, title: string, artist: string, artwork: ?string}> */
    protected function queuePayload(): array
    {
        return $this->tracks->values()->map(fn ($t) => [
            'id' => $t->id,
            'url' => $this->plex->streamUrl($t),
            'title' => $t->title,
            'artist' => $t->artist,
            'artwork' => $this->thumbFor($t->thumb),
        ])->all();
    }
```

(If `use App\Services\Plex\Dto\Track;` becomes unused after deleting `dispatchTrack`, leave it — other code in the file type-hints `Track` in the `tracks()` computed's return docblock. Do not remove imports you have not verified are unused.)

- [ ] **Step 4: Run the tests, expect pass**

Run: `php artisan test --compact --filter=PlaylistDetailViewTest`
Expected: PASS (all tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡playlist-detail.blade.php tests/Feature/PlaylistDetailViewTest.php
git commit -m "feat(playlist): play / play-all / shuffle dispatch the playlist as a queue"
```

---

### Task 4: Search view — dispatch the "Songs" group as a queue

**Files:**
- Modify: `resources/views/pages/⚡search.blade.php` (the `playTrack` method)
- Test: `tests/Feature/SearchViewTest.php` (the `dispatches play-track when a track row is clicked` test)

- [ ] **Step 1: Update the failing test in `tests/Feature/SearchViewTest.php`**

`sampleResults()` has exactly one track: `9001` "Holocene" by "Bon Iver", thumb `/t/9001`; the test's mock returns `streamUrl` → `'https://server/library/parts/660001/file.flac?X-Plex-Token=t'` for that track and `thumbUrl('/t/9001')` → `'https://thumb/t/9001'`. Replace the chain in `it('dispatches play-track when a track row is clicked', ...)`:

```php
    Livewire::test('pages::search', ['q' => 'bon'])
        ->call('playTrack', '9001')
        ->assertDispatched('play-track',
            queue: [[
                'id' => '9001',
                'url' => 'https://server/library/parts/660001/file.flac?X-Plex-Token=t',
                'title' => 'Holocene',
                'artist' => 'Bon Iver',
                'artwork' => 'https://thumb/t/9001',
            ]],
            index: 0,
        );
```

- [ ] **Step 2: Run the test, expect failure**

Run: `php artisan test --compact --filter=SearchViewTest`
Expected: FAIL on the updated test.

- [ ] **Step 3: Update `playTrack` in `resources/views/pages/⚡search.blade.php`**

Replace the existing `playTrack` method with:

```php
    public function playTrack(string $trackId): void
    {
        $tracks = ($this->results?->tracks ?? collect())->values();
        $i = $tracks->search(fn ($t) => $t->id === $trackId);

        if ($i === false) {
            return;
        }

        $this->dispatch('play-track',
            queue: $tracks->map(fn ($t) => [
                'id' => $t->id,
                'url' => $this->plex->streamUrl($t),
                'title' => $t->title,
                'artist' => $t->artist,
                'artwork' => $this->thumbFor($t->thumb),
            ])->values()->all(),
            index: $i,
        );
    }
```

- [ ] **Step 4: Run the test, expect pass**

Run: `php artisan test --compact --filter=SearchViewTest`
Expected: PASS (all tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡search.blade.php tests/Feature/SearchViewTest.php
git commit -m "feat(search): play a track from the Songs group as a queue"
```

---

### Task 5: Recently Played view — dispatch the recently-played list as a queue

**Files:**
- Modify: `resources/views/pages/⚡recently-played.blade.php` (the `playTrack` method)
- Test: `tests/Feature/RecentlyPlayedTest.php` (the `playTrack dispatches play-track with the stream URL` test)

- [ ] **Step 1: Update the failing test in `tests/Feature/RecentlyPlayedTest.php`**

The mock's `streamUrl` returns `https://stream/{id}.flac`, `thumbUrl` returns `https://thumb{thumb}`, and `recentTrack('70001', 'Song')` has artist `'Some Artist'` and thumb `/library/metadata/70001/thumb`. Replace the chain in `it('playTrack dispatches play-track with the stream URL', ...)`:

```php
    Livewire::test('pages::recently-played')
        ->call('playTrack', '70001')
        ->assertDispatched('play-track',
            queue: [[
                'id' => '70001',
                'url' => 'https://stream/70001.flac',
                'title' => 'Song',
                'artist' => 'Some Artist',
                'artwork' => 'https://thumb/library/metadata/70001/thumb',
            ]],
            index: 0,
        );
```

- [ ] **Step 2: Run the test, expect failure**

Run: `php artisan test --compact --filter=RecentlyPlayedTest`
Expected: FAIL on the updated test.

- [ ] **Step 3: Update `playTrack` in `resources/views/pages/⚡recently-played.blade.php`**

Replace the existing `playTrack` method with:

```php
    public function playTrack(string $trackId): void
    {
        $tracks = $this->tracks->values();
        $i = $tracks->search(fn ($t) => $t->id === $trackId);

        if ($i === false) {
            return;
        }

        $this->dispatch('play-track',
            queue: $tracks->map(fn ($t) => [
                'id' => $t->id,
                'url' => $this->plex->streamUrl($t),
                'title' => $t->title,
                'artist' => $t->artist,
                'artwork' => $this->thumbFor($t->thumb),
            ])->values()->all(),
            index: $i,
        );
    }
```

- [ ] **Step 4: Run the test, expect pass**

Run: `php artisan test --compact --filter=RecentlyPlayedTest`
Expected: PASS (all tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/pages/⚡recently-played.blade.php tests/Feature/RecentlyPlayedTest.php
git commit -m "feat(recently-played): play a track from the recently-played list as a queue"
```

---

### Task 6: Sidebar — playing a playlist enqueues all of its tracks

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php` (the `playPlaylist` method)
- Test: `tests/Feature/SidebarTest.php` (the `plays the first track of a playlist` test)

- [ ] **Step 1: Update the failing test in `tests/Feature/SidebarTest.php`**

Replace the `it('plays the first track of a playlist', ...)` body:

```php
it('enqueues a playlist when played', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('playlistTracks')->once()->with('4242')->andReturn(collect([
            new Track(id: '1', title: 'Song', artist: 'Band', album: 'Rec', trackNumber: 1, durationMs: 1000, partId: 7, container: 'flac', thumb: '/t'),
        ]));
        $mock->shouldReceive('streamUrl')->andReturn('https://stream/1.flac');
    });

    Livewire::test('sidebar')
        ->call('playPlaylist', '4242')
        ->assertDispatched('play-track',
            queue: [[
                'id' => '1',
                'url' => 'https://stream/1.flac',
                'title' => 'Song',
                'artist' => 'Band',
                'artwork' => 'https://thumb/t',
            ]],
            index: 0,
        );
});
```

(`mockSidebarPlex` already stubs `thumbUrl` → `https://thumb{thumb}`, so the track's `/t` thumb resolves to `https://thumb/t`. `Track` is already imported at the top of `SidebarTest.php`.)

- [ ] **Step 2: Run the test, expect failure**

Run: `php artisan test --compact --filter=SidebarTest`
Expected: FAIL on the updated test.

- [ ] **Step 3: Update `playPlaylist` in `resources/views/components/⚡sidebar.blade.php`**

Replace the existing `playPlaylist` method with:

```php
    public function playPlaylist(string $playlistId): void
    {
        try {
            $tracks = $this->plex->playlistTracks($playlistId)->values();
        } catch (PlexException $e) {
            Log::channel('plex')->warning('playPlaylist failed', ['playlist' => $playlistId, 'error' => $e->getMessage()]);

            return;
        }

        if ($tracks->isEmpty()) {
            return;
        }

        $this->dispatch('play-track',
            queue: $tracks->map(fn ($t) => [
                'id' => $t->id,
                'url' => $this->plex->streamUrl($t),
                'title' => $t->title,
                'artist' => $t->artist,
                'artwork' => $this->thumbFor($t->thumb),
            ])->values()->all(),
            index: 0,
        );
    }
```

(If `use App\Services\Plex\Dto\Track;` is now unused in the sidebar component after removing the `$track = $tracks->first()` / `instanceof Track` lines, you may remove that import — but only if a search of the file confirms `Track` appears nowhere else.)

- [ ] **Step 4: Run the test, expect pass**

Run: `php artisan test --compact --filter=SidebarTest`
Expected: PASS (all tests).

- [ ] **Step 5: Full feature + unit suite**

Run: `php artisan test --compact --testsuite=Unit,Feature`
Expected: PASS (the whole non-browser suite — this confirms the coordinated `play-track` payload change is consistent across every view and the player).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/⚡sidebar.blade.php tests/Feature/SidebarTest.php
git commit -m "feat(sidebar): playing a playlist enqueues all of its tracks"
```

---

### Task 7: Browser test — queue, autoplay, transport, shuffle, repeat

**Files:**
- Create: `tests/Browser/PlayerQueueTest.php`

This test hits the live Plex server (like the other `tests/Browser/*` tests) and uses `script()` for DOM/Alpine interaction (the established pattern; see `tests/Browser/LibraryDrillDownTest.php`). It drives the player's Alpine API via `Alpine.$data(...)` rather than waiting for real tracks to end.

- [ ] **Step 1: Create `tests/Browser/PlayerQueueTest.php`**

```php
<?php

/**
 * Exercises the playback queue, auto-advance, transport buttons, shuffle and repeat.
 * Like the other Browser tests this needs the Plex server reachable with at least one
 * album that has multiple tracks. Drives the player's Alpine state directly via
 * Alpine.$data() so it does not have to wait for a real track to finish.
 */

use Livewire\Livewire;

function playerData(): string
{
    // Returns the player's Alpine component data object in the page context.
    return "Alpine.\$data(document.querySelector('[x-data=\"audioPlayer()\"]'))";
}

it('builds a queue from the album tracklist and plays the clicked track', function () {
    $page = visit(route('library'));

    // Drill into the first artist, then the first album, then click the *second* track row.
    $nowPlaying = $page->script(<<<'JS'
        (async () => {
            const waitFor = (sel, ms) => new Promise((res) => {
                const t0 = Date.now();
                const tick = () => {
                    if (document.querySelector(sel)) return res(true);
                    if (Date.now() - t0 > ms) return res(false);
                    setTimeout(tick, 100);
                };
                tick();
            });

            if (!await waitFor('[data-region=miller] [data-kind=artist]', 8000)) return 'NO_ARTIST';
            document.querySelector('[data-region=miller] [data-kind=artist]').click();
            if (!await waitFor('[data-region=miller] [data-kind=album]', 8000)) return 'NO_ALBUM';
            document.querySelector('[data-region=miller] [data-kind=album]').click();
            if (!await waitFor('[data-region=tracklist] button[wire\\:click^="playTrack"]', 8000)) return 'NO_TRACKS';

            const rows = document.querySelectorAll('[data-region=tracklist] button[wire\\:click^="playTrack"]');
            (rows[1] ?? rows[0]).click();

            // Wait until the player's queue is populated.
            for (let i = 0; i < 60; i++) {
                const d = Alpine.$data(document.querySelector('[x-data="audioPlayer()"]'));
                if (d && d.queue.length > 0) {
                    return JSON.stringify({ len: d.queue.length, index: d.index, title: d.current ? d.current.title : '' });
                }
                await new Promise((r) => setTimeout(r, 100));
            }
            return 'NO_QUEUE';
        })()
    JS);

    $decoded = json_decode((string) $nowPlaying, true);
    expect($decoded)->toBeArray("Expected the player queue to populate after clicking a track, got: {$nowPlaying}");
    expect($decoded['len'])->toBeGreaterThan(1);
    expect($decoded['index'])->toBeGreaterThanOrEqual(0);
    expect($decoded['title'])->not->toBe('');

    $page->assertVisible('[data-region=now-playing-title]');
    expect(trim((string) $page->text('[data-region=now-playing-title]')))->toBe($decoded['title']);
});

it('auto-advances to the next track when one ends and stops at the end with repeat off', function () {
    $page = visit(route('library'));

    $result = $page->script(<<<'JS'
        (async () => {
            const waitFor = (sel, ms) => new Promise((res) => {
                const t0 = Date.now();
                const tick = () => {
                    if (document.querySelector(sel)) return res(true);
                    if (Date.now() - t0 > ms) return res(false);
                    setTimeout(tick, 100);
                };
                tick();
            });
            const d = () => Alpine.$data(document.querySelector('[x-data="audioPlayer()"]'));

            if (!await waitFor('[data-region=miller] [data-kind=artist]', 8000)) return 'NO_ARTIST';
            document.querySelector('[data-region=miller] [data-kind=artist]').click();
            if (!await waitFor('[data-region=miller] [data-kind=album]', 8000)) return 'NO_ALBUM';
            document.querySelector('[data-region=miller] [data-kind=album]').click();
            if (!await waitFor('[data-region=tracklist] button[wire\\:click^="playTrack"]', 8000)) return 'NO_TRACKS';
            document.querySelector('[data-region=tracklist] button[wire\\:click^="playTrack"]').click();

            for (let i = 0; i < 60 && (!d() || d().queue.length === 0); i++) await new Promise((r) => setTimeout(r, 100));
            if (!d() || d().queue.length < 2) return 'QUEUE_TOO_SHORT';

            const player = d();
            player.repeat = 'off';
            const startIndex = player.index;
            const titleAtStart = player.current.title;

            // Simulate the current track finishing.
            player.next();
            await new Promise((r) => setTimeout(r, 300));
            const advanced = { index: player.index, title: player.current ? player.current.title : '' };

            // Jump to the last track, then end it — playback should stop, queue stays loaded.
            player.jumpTo(player.queue.length - 1);
            await new Promise((r) => setTimeout(r, 300));
            player.next();
            await new Promise((r) => setTimeout(r, 300));
            const atEnd = { isPlaying: player.isPlaying, queueLen: player.queue.length, index: player.index };

            return JSON.stringify({ startIndex, titleAtStart, advanced, atEnd });
        })()
    JS);

    $decoded = json_decode((string) $result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");
    expect($decoded['advanced']['index'])->toBe($decoded['startIndex'] + 1);
    expect($decoded['advanced']['title'])->not->toBe($decoded['titleAtStart']);
    expect($decoded['atEnd']['isPlaying'])->toBeFalse();
    expect($decoded['atEnd']['queueLen'])->toBeGreaterThan(0);
});

it('skip-back restarts the track past 3s and goes to the previous track within 3s; shuffle and repeat toggles work', function () {
    $page = visit(route('library'));

    $result = $page->script(<<<'JS'
        (async () => {
            const waitFor = (sel, ms) => new Promise((res) => {
                const t0 = Date.now();
                const tick = () => {
                    if (document.querySelector(sel)) return res(true);
                    if (Date.now() - t0 > ms) return res(false);
                    setTimeout(tick, 100);
                };
                tick();
            });
            const d = () => Alpine.$data(document.querySelector('[x-data="audioPlayer()"]'));

            if (!await waitFor('[data-region=miller] [data-kind=artist]', 8000)) return 'NO_ARTIST';
            document.querySelector('[data-region=miller] [data-kind=artist]').click();
            if (!await waitFor('[data-region=miller] [data-kind=album]', 8000)) return 'NO_ALBUM';
            document.querySelector('[data-region=miller] [data-kind=album]').click();
            if (!await waitFor('[data-region=tracklist] button[wire\\:click^="playTrack"]', 8000)) return 'NO_TRACKS';
            const rows = document.querySelectorAll('[data-region=tracklist] button[wire\\:click^="playTrack"]');
            (rows[1] ?? rows[0]).click();

            for (let i = 0; i < 60 && (!d() || d().queue.length === 0); i++) await new Promise((r) => setTimeout(r, 100));
            if (!d() || d().queue.length < 2) return 'QUEUE_TOO_SHORT';
            const p = d();

            // --- skip-back past 3s: restart same track ---
            p.$refs.audio.currentTime = 10;
            const idxBefore = p.index;
            p.previous();
            await new Promise((r) => setTimeout(r, 200));
            const restarted = { index: p.index, time: p.$refs.audio.currentTime };

            // --- skip-back within 3s: go to previous track (idxBefore must be >= 1 here) ---
            // Make sure we are on a track that has a predecessor.
            if (p.index === 0) p.jumpTo(1);
            await new Promise((r) => setTimeout(r, 100));
            const idxBeforePrev = p.index;
            p.$refs.audio.currentTime = 1;
            p.previous();
            await new Promise((r) => setTimeout(r, 200));
            const wentBack = { from: idxBeforePrev, to: p.index };

            // --- shuffle on: same track keeps playing, queue order changes ---
            const playingId = p.current.id;
            p.jumpTo(0);
            await new Promise((r) => setTimeout(r, 100));
            const orderBefore = p.queue.map((t) => t.id).join(',');
            const headId = p.current.id;
            p.toggleShuffle();
            await new Promise((r) => setTimeout(r, 100));
            const shuffleOn = { shuffle: p.shuffle, index: p.index, headStillCurrent: p.current.id === headId, orderChanged: p.queue.map((t) => t.id).join(',') !== orderBefore || p.queue.length <= 2 };
            // shuffle off: order is the original again
            p.toggleShuffle();
            await new Promise((r) => setTimeout(r, 100));
            const shuffleOff = { shuffle: p.shuffle, order: p.queue.map((t) => t.id).join(','), originalOrder: p.originalQueue.map((t) => t.id).join(','), stillCurrent: p.current.id === headId };

            // --- repeat cycles off -> all -> one -> off ---
            const r0 = p.repeat; p.cycleRepeat(); const r1 = p.repeat; p.cycleRepeat(); const r2 = p.repeat; p.cycleRepeat(); const r3 = p.repeat;

            return JSON.stringify({ restarted, idxBefore, wentBack, shuffleOn, shuffleOff, repeat: [r0, r1, r2, r3] });
        })()
    JS);

    $decoded = json_decode((string) $result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");

    // skip-back past 3s restarts the same track
    expect($decoded['restarted']['index'])->toBe($decoded['idxBefore']);
    expect($decoded['restarted']['time'])->toBeLessThan(1.0);

    // skip-back within 3s goes to the previous track
    expect($decoded['wentBack']['to'])->toBe($decoded['wentBack']['from'] - 1);

    // shuffle on keeps the current track and (for queues > 2) changes order; off restores it
    expect($decoded['shuffleOn']['shuffle'])->toBeTrue();
    expect($decoded['shuffleOn']['index'])->toBe(0);
    expect($decoded['shuffleOn']['headStillCurrent'])->toBeTrue();
    expect($decoded['shuffleOff']['shuffle'])->toBeFalse();
    expect($decoded['shuffleOff']['order'])->toBe($decoded['shuffleOff']['originalOrder']);
    expect($decoded['shuffleOff']['stillCurrent'])->toBeTrue();

    // repeat cycle
    expect($decoded['repeat'])->toBe(['off', 'all', 'one', 'off']);
});
```

- [ ] **Step 2: Confirm the selectors used by the test exist in the library view**

Run: `grep -n "data-region" resources/views/pages/⚡library.blade.php`
Expected: shows `data-region="tracklist"` and `data-region="now-playing-title"` exists in the player. The test also uses `[data-region=miller] [data-kind=artist]` / `[data-kind=album]` to drill in. **If those exact `data-region` / `data-kind` hooks are not present in `⚡library.blade.php`**, do not invent new ones — instead adapt the test's drill-in selectors to whatever the library view actually exposes (mirror how `tests/Browser/LibraryDrillDownTest.php` finds the artist/album/track elements; reuse its selector strings verbatim). The assertions about `Alpine.$data(...)` and the player are unaffected.

- [ ] **Step 3: Run the browser test (requires the Plex server to be reachable)**

Run: `php artisan test --compact --filter=PlayerQueueTest`
Expected: PASS (3 tests). If it fails because Plex is unreachable, that is the same environmental dependency the other Browser tests have; note it and continue, but do fix any failure that is not "Plex unreachable".

- [ ] **Step 4: Commit**

```bash
git add tests/Browser/PlayerQueueTest.php
git commit -m "test(browser): cover the playback queue, autoplay, transport, shuffle and repeat"
```

---

### Task 8: Update project docs

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update the player notes in `CLAUDE.md`**

In the "Stack and conventions" section, the layout note currently says the player handles "load this track URL" via `#[On('play-track')]`. Replace that clause with a description of the queue: the player Livewire component is a thin relay (`#[On('play-track')]` with a `queue`/`index`/`shuffle` payload → re-broadcasts a `queue-load` browser event); the playback queue, auto-advance, skip forward/back, shuffle and three-state repeat all live in the player's Alpine `audioPlayer()` scope; views build the queue (stream URLs pre-resolved server-side) and dispatch `play-track` with it; the now-playing strip is bound to the Alpine `current` getter.

- [ ] **Step 2: Add a Gotchas entry**

Add a bullet to the "Gotchas" section noting: the `play-track` Livewire event payload is `queue` (array of `{id, url, title, artist, artwork}`), `index` (int), `shuffle` (bool); every view that plays a track must dispatch the whole relevant list, not a single track; the queue is client-side (Alpine), sent once as the event payload, so it is not in the Livewire snapshot; `streamUrl()`/`thumbUrl()` are pure string building so resolving a whole list up front is cheap.

- [ ] **Step 3: Update the milestone table / status note**

Add a row (or a note under the table) recording that the play queue & autoplay work shipped (date 2026-05-11), referencing `docs/superpowers/specs/2026-05-11-plextune-play-queue-design.md` and `docs/superpowers/plans/2026-05-11-plextune-play-queue.md`. Bump the test count mentioned in the "How work is done here" paragraph if it quotes a specific number (it currently says "155 tests ... including 8 Playwright browser tests"; this plan adds 3 browser tests and ~0 net feature tests beyond rewrites, so update to the new totals after running `php artisan test`).

- [ ] **Step 4: Verify the full suite count for the doc update**

Run: `php artisan test --compact`
Expected: PASS. Note the totals it prints and use them in Step 3.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: record the play queue feature and update player notes"
```

---

## Self-Review

**Spec coverage:**
- Internal queue living in the player → Task 1 (Alpine `queue`/`index`/`originalQueue`).
- Clicking a track sets the queue to that view's list, starting at the clicked track → Tasks 2-6 (library, playlist-detail, search, recently-played, sidebar).
- Auto-advance on `ended`, stop at end with repeat off → Task 1 (`@ended="next()"`, `next()` logic) + Task 7 browser test.
- Skip forward/back navigate the queue; Spotify-style skip-back → Task 1 (`next()`/`previous()`) + Task 7.
- Shuffle toggle, current track kept, order restored on toggle off, `shuffle: true` from the playlist Shuffle button → Task 1 (`toggleShuffle`/`applyShuffle`), Task 3 (`shuffle()` dispatches the flag), Task 7.
- Three-state repeat (off / all / one), button visual states, `repeat-1` icon → Task 1 (`cycleRepeat`, `:class` bindings, `<template x-if>` icon) + Task 7.
- Player Livewire component becomes a thin relay; now-playing display moves to Alpine; old per-track public props removed → Task 1.
- Error handling: skip a failing track via `@error`, infinite-loop guard → Task 1 (`onTrackError`, `consecutiveErrors`).
- Testing: feature tests for changed dispatch payloads (Tasks 2-6) + new `PlayerQueueTest` browser test (Task 7).
- `jumpTo(i)` primitive defined now for the future queue panel → Task 1.
- CLAUDE.md notes → Task 8.
- Out of scope (queue panel, add-to-queue, scrobbling, toasts, large-playlist capping) → not in any task, as intended.

**Placeholder scan:** No "TBD"/"TODO"; every code step has full code; commands have expected output. The two "if the icon/selector isn't there, adapt" notes are deliberate environment checks with concrete fallbacks, not placeholders.

**Type/name consistency:** `play-track` payload `(queue: list<array{id,url,title,artist,artwork}>, index: int, shuffle: bool)` is used identically in the player relay (Task 1), every view dispatch (Tasks 2-6), and every test assertion. The `queue-load` browser event carries the same three keys. Alpine method names (`init`, `loadAndPlay`, `togglePlay`, `next`, `previous`, `jumpTo`, `toggleShuffle`, `applyShuffle`, `cycleRepeat`, `onTrackError`, `seek`, `setVolume`, `formatTime`) and state (`queue`, `originalQueue`, `index`, `shuffle`, `repeat`, `consecutiveErrors`, `isPlaying`, `currentTime`, `duration`, `volume`, `current` getter) are referenced consistently between the template and the script in Task 1 and by the Task 7 browser test. `queuePayload()` is defined and used only within `⚡playlist-detail.blade.php` (Task 3).
