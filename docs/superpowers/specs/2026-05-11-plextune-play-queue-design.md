# Plextune — Play queue & autoplay (design)

Status: approved, ready for implementation planning.

## Goal

Give the player a real playback queue so the next track plays automatically when one ends, the skip forward / skip back buttons work, the shuffle button works, and the repeat button works. No visible or editable queue panel in this iteration (that is a later addition). Treat this as the next piece of work, ahead of the remaining Settings (M6) and Polish (M7) milestones.

## Scope

In scope:

1. An internal playback queue that lives in the player.
2. Clicking a track in a view sets the queue to that view's relevant track list, starting at the clicked track.
3. Auto advance: when a track ends, the next track in the queue plays. When the last track ends (and repeat is off), playback stops with the queue still loaded.
4. Skip forward / skip back transport buttons navigate the queue (currently decorative).
5. Shuffle button: a toggle that randomizes the order of the remaining queue while keeping the current track playing.
6. Repeat button: a three state cycle (off, repeat all, repeat one), currently decorative.

Out of scope (explicitly deferred):

- A visible queue panel / "next up" list, jump to an arbitrary queued track from the UI, remove or reorder queued tracks.
- "Add to queue" from context menus or track rows.
- Scrobbling plays back to Plex (an existing M7 polish item).
- Error toasts (an existing M7 polish item).
- Capping / lazy extending the queue for very large playlists (premature; see Architecture notes).

## Architecture

The queue lives in the player's Alpine `x-data` scope. The player (`resources/views/components/⚡player.blade.php`) is wrapped in `@persist('player')` at the layout level, so its `<audio>` element and Alpine state already survive `wire:navigate` page changes. The queue rides along with that.

### Alpine state (added to `audioPlayer()`)

```
queue:         []      // playback order: array of { id, url, title, artist, artwork }
originalQueue: []      // the unshuffled order, so toggling shuffle off can restore it
index:         0       // position in `queue` of the currently loaded track
shuffle:       false
repeat:        'off'   // 'off' | 'all' | 'one'
```

The existing `isPlaying`, `currentTime`, `duration`, `volume` state stays.

### Dispatching a queue

Today each view does `$this->dispatch('play-track', url: …, title: …, artist: …, artwork: …)` for a single track. That changes to build the whole relevant track list and dispatch it with a starting index:

```php
$this->dispatch('play-track',
    queue: $tracks->map(fn (Track $t) => [
        'id'      => $t->id,
        'url'     => $this->plex->streamUrl($t),
        'title'   => $t->title,
        'artist'  => $t->artist,
        'artwork' => $this->plex->thumbUrl($t->thumb),
    ])->values()->all(),
    index: $clickedIndex,
);
```

`streamUrl()` and `thumbUrl()` are pure string building (no HTTP), so resolving the whole list up front is cheap.

The "relevant track list" per view:

- `pages/⚡library.blade.php` — the loaded album's tracklist (`$this->tracks`). `playTrack($trackId)` dispatches the list with `index` = that track's position.
- `pages/⚡playlist-detail.blade.php` — `$this->tracks`. `playTrack($trackId)` uses that track's index. `playAll()` dispatches the list at index 0. `shuffle()` dispatches the list with `index` = a random position and a `shuffle: true` flag (see Shuffle below); this replaces its current behavior of dispatching a single random track.
- `pages/⚡recently-played.blade.php` — its loaded track list.
- `pages/⚡search.blade.php` — the "Songs" result group (`$results->tracks`).
- `components/⚡sidebar.blade.php` — `playPlaylist($playlistId)` already fetches the playlist's tracks; instead of playing only the first, it enqueues all of them at index 0. (`playlistTracks()` is cached, so the click that opens a sidebar playlist and the one that plays it share the fetch.)

### The player Livewire component

Stays a thin relay. Its `#[On('play-track')]` handler now receives `queue` (array) and `index` (int) and an optional `shuffle` (bool), and re broadcasts a browser event to Alpine (the current `audio-load` event, carrying the queue and index; rename to `queue-load` for clarity). It no longer holds `trackUrl`, `trackTitle`, `trackArtist`, `trackArtwork` as Livewire public properties; the now playing strip reads `queue[index]` from Alpine instead. All transport, queue, shuffle and repeat logic is Alpine; the Livewire component is only the bridge between a Livewire event dispatched by a view and the browser event the Alpine player listens for.

### Why client side, not server side

The queue is sent once, as a one shot Livewire event payload, then lives in Alpine memory. There is no recurring cost. A server side queue would have to be a public property of the player Livewire component, which Livewire serializes into the encrypted snapshot it ships to the browser on every Livewire request anywhere on the page. For a large playlist that is a recurring payload cost, and working around it (storing only track IDs, re resolving on each `next()`) reintroduces a server round trip and a possible Plex call between every track. Client side avoids all of that and makes next / previous / auto advance / shuffle instant.

For a playlist of a few thousand tracks the dispatched payload is roughly 600 KB to 1 MB of JSON, sent once, which browsers handle fine. In practice queues here are small to medium (album tracklists are around 10 to 20 tracks, recently played is capped at 50, the search "Songs" group is capped, playlists are at most a few hundred). If a genuinely massive playlist ever became a problem, the escape hatch is capping the enqueued window (for example the first 500 tracks, lazily extended), but that is premature and is not part of this work.

## Behavior

### Loading a track

A single `loadAndPlay(i)` helper: `index = i; $refs.audio.src = queue[i].url; $refs.audio.play().catch(() => {})`. The now playing strip (artwork, title, artist, the heart button area) binds reactively to `queue[index]`, so it updates automatically whenever `index` or `queue` changes. An empty queue means the strip shows nothing, which matches today's behavior.

The incoming `queue-load` browser event sets `originalQueue` from the payload, sets `queue` (applying the shuffle transform if the payload's `shuffle` flag is set or `shuffle` is already on, see below), then calls `loadAndPlay` for the right index.

### Transport

- `next()`:
  - If `repeat === 'one'`: restart the current track (`currentTime = 0`, `play()`).
  - Else if `index < queue.length - 1`: `loadAndPlay(index + 1)`.
  - Else (at the end of the queue): if `repeat === 'all'`, `loadAndPlay(0)`; otherwise stop (`pause()`, `isPlaying = false`), leaving `currentTime` at the end and the queue still loaded so the user can scrub back or press play again.
- `previous()` (Spotify behavior):
  - If `currentTime > 3`: restart the current track (`currentTime = 0`).
  - Else if `index > 0`: `loadAndPlay(index - 1)`.
  - Else (already on the first track, less than 3 seconds in): restart it (`currentTime = 0`).
- `jumpTo(i)`: `loadAndPlay(i)`. Not wired to any UI in this iteration (no queue panel), but it is the primitive `next` / `previous` / the incoming event all build on, and it is what the future queue panel needs, so it is cheap to define now.
- Auto advance: the `<audio>` element's `@ended` handler changes from setting `isPlaying = false` to calling `next()`. With `repeat === 'one'` that re plays the same track.
- Disabled state: skip back and skip forward get `:disabled="queue.length === 0"` and a dimmed style when disabled, keeping the existing hover styles otherwise. Skip forward is not disabled on the last track (with repeat all it wraps, and with repeat off pressing it just stops, which matches Spotify keeping the button active).

### Shuffle

The shuffle button gets `@click="toggleShuffle()"` and an active state style (accent color, matching how active states are treated elsewhere in the design; the implementer pulls the exact classes from `design/rendered/`).

- `toggleShuffle()`:
  - Turning on: take the current track out of the list, Fisher-Yates shuffle the remaining tracks, set `queue = [currentTrack, ...shuffledRest]` and `index = 0`. `originalQueue` is unchanged (it is already the pristine order). Playback continues uninterrupted: same track, same `currentTime`.
  - Turning off: `queue = [...originalQueue]`, `index` = the current track's position in it (found by `id`). Playback continues uninterrupted.
- When a new queue arrives via `queue-load` while `shuffle` is on (or the payload carries `shuffle: true`): store the incoming list as `originalQueue`, apply the same "current track first, rest shuffled" transform anchored on the dispatched starting index, set `shuffle = true`.
- `playlist-detail`'s "Shuffle" header button dispatches the playlist's full track list with `index` = a random position and `shuffle: true`. Effect: shuffle on, playing from a random track. This replaces its current behavior (dispatch one random track) with what that button should do.

### Repeat

The repeat button gets `@click="cycleRepeat()"` cycling `off -> all -> one -> off`, with three visual states:

- `off`: default (`text-text-2`).
- `all`: active (`text-accent`).
- `one`: active plus a small "1" indicator. Lucide has `repeat-1`, so `<x-lucide-repeat-1 />` when in the `one` state and `<x-lucide-repeat />` otherwise; the implementer verifies the icon component is available in `mallardduck/blade-lucide-icons` and falls back to `<x-lucide-repeat />` with a superscript "1" span if not.

Repeat semantics are entirely consumed inside `next()` and the `@ended` handler as described under Transport.

### Errors

If a track's stream fails to load, the `<audio>` element fires an `error` event. Add `@error="onTrackError()"`: log to the console and call `next()` to skip the bad track. No toast (toasts are M7). Guard against an infinite loop when every track errors: keep a `consecutiveErrors` counter, reset it on a successful `play`, and if it exceeds `queue.length` stop and `console.warn` instead of advancing again.

## Testing

The queue, transport, shuffle and repeat logic is client side Alpine, so the meaningful coverage is browser tests (Pest 4 plus `pestphp/pest-plugin-browser` / Playwright, hitting the live Plex server, like the existing `tests/Browser/*`), plus feature tests for the changed Livewire dispatch payloads.

### Feature tests (`tests/Feature/`, mocked `PlexClient`)

- `PlayerComponentTest` — the `play-track` listener now takes `queue` and `index` (and optional `shuffle`); assert it re broadcasts the `queue-load` browser event with that payload. The removed `trackUrl` / `trackTitle` / `trackArtist` / `trackArtwork` public properties no longer exist; update or drop the assertions that referenced them.
- `LibraryViewTest`, `PlaylistDetailViewTest`, `SearchViewTest`, `RecentlyPlayedTest`, `SidebarTest` — assert that `playTrack($id)` (and `playAll()` / `shuffle()` on playlist detail, and the sidebar's `playPlaylist()`) now `assertDispatched('play-track', …)` with the full track list as `queue` and the correct starting `index`, instead of a single track payload. For the sidebar, assert the mocked `playlistTracks()` is called.

### Browser tests (`tests/Browser/`, live Plex) — new `PlayerQueueTest`

- Play a track partway down an album tracklist; assert the now playing strip shows that track; via `script()` read the Alpine `queue.length` and `index` and assert they match the tracklist and the clicked row.
- Auto advance: rather than waiting for a real track to end, drive it via `script()` (call the player's `next()`, or set `audio.currentTime` near the end and dispatch `ended`); assert the now playing strip advanced to the next track and `isPlaying` is true.
- `next()` at the end of the queue with `repeat === 'off'`: playback stops, the queue is still loaded.
- Skip back more than 3 seconds in restarts the current track; less than 3 seconds in goes to the previous track.
- Toggle shuffle: `queue` order changes but `queue[index].id` (the playing track) is unchanged and `currentTime` continues; toggle off and the order matches `originalQueue` with the playing track still current.
- `cycleRepeat()` cycles through the three states and the button's classes reflect each state.

Browser tests use `script()` for anything involving many matching elements or reading Alpine state, per the project's established pattern (`tests/Browser/LibraryDrillDownTest.php`).

## Affected files (anticipated)

- `resources/views/components/⚡player.blade.php` — Alpine queue state and methods; now playing strip bound to `queue[index]`; transport buttons wired; `@ended` / `@error` handlers; removal of the per track Livewire public properties; `#[On('play-track')]` payload change.
- `resources/views/pages/⚡library.blade.php` — `playTrack()` dispatches the album tracklist as a queue.
- `resources/views/pages/⚡playlist-detail.blade.php` — `playTrack()`, `playAll()`, `shuffle()` dispatch the playlist as a queue (shuffle with the `shuffle: true` flag).
- `resources/views/pages/⚡recently-played.blade.php` — `playTrack()` dispatches the recently played list as a queue.
- `resources/views/pages/⚡search.blade.php` — `playTrack()` dispatches the "Songs" group as a queue.
- `resources/views/components/⚡sidebar.blade.php` — `playPlaylist()` enqueues all of the playlist's tracks.
- `tests/Feature/PlayerComponentTest.php`, `tests/Feature/LibraryViewTest.php`, `tests/Feature/PlaylistDetailViewTest.php`, `tests/Feature/SearchViewTest.php`, `RecentlyPlayedTest`, `tests/Feature/SidebarTest.php` — updated dispatch assertions.
- `tests/Browser/PlayerQueueTest.php` — new.
- `CLAUDE.md` — update the player notes and the milestone table / gotchas once this lands.
