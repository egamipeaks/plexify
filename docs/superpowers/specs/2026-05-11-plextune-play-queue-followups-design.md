# Plextune play-queue follow-ups — design

Date: 2026-05-11

Three small cross-cutting refinements to the play-queue feature (shipped 2026-05-11, see `2026-05-11-plextune-play-queue-design.md`). This document supersedes the "Play-queue follow-ups" bullet list in `CLAUDE.md`.

## Goals

1. **Highlight the currently-playing track** in the library album tracklist and the playlist-detail tracklist: accent-colored title plus an animated equalizer in the track-number cell while playing (static bars when paused).
2. **Now-playing strip navigates to the album** (cover thumbnail and track title) and to the artist (artist subtitle) of the playing track.
3. **A plain Play clears the shuffle toggle.** Only the Shuffle buttons set or keep `shuffle` on; loading a new context with a normal Play button or a track row turns it off.

Search "Songs" results and the recently-played list do **not** get the playing highlight in this pass (deliberately scoped out). No hover-to-play button, no scrobbling, no toasts.

## Architecture

### The playing-track bridge: an Alpine global store

The player (`audioPlayer()` Alpine scope, lives in the persisted layout) owns the playback state. The tracklists are separate Livewire components rendered elsewhere in the DOM, outside the `audioPlayer()` `x-data`. They are bridged through an Alpine global store rather than a Livewire round-trip:

- The player registers `Alpine.store('player', { currentId: null, isPlaying: false })`.
- `audioPlayer()` updates the store:
  - `currentId` is set in `loadAndPlay(i)` to `this.queue[i]?.id ?? null`, and cleared to `null` if the queue ever becomes empty.
  - `isPlaying` is kept in sync wherever `audioPlayer.isPlaying` changes: the `<audio>` `@play` handler sets it `true`, `@pause` sets it `false`, and the end-of-queue stop path in `next()`/`onTrackError()` sets it `false`.
- The store is registered so it exists before any tracklist row evaluates `$store.player`. Register inside the player component's `@script` (Livewire guarantees Alpine is loaded by then); guard with a check so a re-executed script does not clobber live state (`if (!Alpine.store('player')) Alpine.store('player', { ... })`).

Rejected alternative: dispatching the current track id back to each page component and re-rendering the list on every track change. Heavier (full tracklist re-render on every skip), and the player is a sibling component so it would still need a browser-event relay.

### Track DTO and queue payload

`play-track` queue items currently have the shape `{ id, url, title, artist, artwork }`. Add `albumId` and `artistId` so the now-playing strip can navigate regardless of which view started playback.

- `App\Services\Plex\Dto\Track` gains `?string $albumId = null` and `?string $artistId = null` (appended after `?string $thumb = null`). `Track::fromPlex()` reads `parentRatingKey` → `albumId` and `grandparentRatingKey` → `artistId` (cast to string when present, `null` otherwise). Plex track rows from `/library/.../children`, `/playlists/{id}/items`, `/hubs/search`, and `/library/sections/{id}/all` all carry these keys.
- Five places build the queue array today: `pages::library` (`albumQueue()`), `pages::playlist-detail` (`queuePayload()`), `pages::search` (`playTrack()`), `pages::recently-played` (`playTrack()`), `components::sidebar` (`playPlaylist()`). Extract one mapper and use it everywhere so the shape is defined once:

  ```php
  // PlexClient
  /** @return array{id: string, url: string, title: string, artist: string, artwork: ?string, albumId: ?string, artistId: ?string} */
  public function queueItem(Track $track, ?string $artworkOverride = null): array
  ```

  `library` passes the selected album's resolved thumb as `$artworkOverride` (every row shares the album cover); the other callers pass nothing, so each row uses its own track thumb (`$this->thumbUrl($track->thumb)`). Callers map their track collection through `fn ($t) => $plex->queueItem($t, $artwork)`.

### Now-playing navigation

`audioPlayer()` gains two methods:

```js
goToAlbum() {
    const c = this.current;
    if (!c || !c.albumId) return;
    const params = new URLSearchParams();
    if (c.artistId) params.set('artist', c.artistId);
    params.set('album', c.albumId);
    Livewire.navigate('{{ route('library') }}?' + params.toString());
},
goToArtist() {
    const c = this.current;
    if (!c || !c.artistId) return;
    Livewire.navigate('{{ route('library') }}?artist=' + encodeURIComponent(c.artistId));
},
```

(`route('library')` is the `/` route; `Livewire.navigate` handles the absolute same-origin URL.) Both `artist` and `album` are passed when navigating to an album because the library miller columns need the artist selected to resolve the album — the existing `pages::search` `goToAlbum` already does this.

In `components/⚡player.blade.php` the now-playing cover (`<img>` and the placeholder `<div>`) get `@click="goToAlbum()"` and `cursor-pointer`; the title `<div>` gets `@click="goToAlbum()"`; the artist `<div>` gets `@click="goToArtist()"`. The title and artist already carry `hover:underline cursor-pointer`. Clicks are no-ops when the relevant id is missing.

### Playing-track highlight in the tracklists

Applies to the tracklist in `pages/⚡library.blade.php` (album tracklist) and `pages/⚡playlist-detail.blade.php` only.

- The scrolling tracklist container gets `x-data="{}"` so descendant `:class` / `x-if` bindings have an Alpine scope. (The page roots intentionally have no `x-data`; a minimal local scope on the list container is enough and does not interfere with the native-DnD `ondragstart` attributes on the rows.)
- Each row's title `<div>` keeps its base classes and adds `:class="$store.player?.currentId === '<track-id>' && 'text-accent'"`. (Use the track id literal rendered by Blade.)
- The track-number cell renders the number normally, but swaps to an equalizer when the row is the playing track:

  ```blade
  <span class="tabular-nums text-text-2 text-right">
      <template x-if="$store.player?.currentId === '{{ $track->id }}'">
          <span class="eq" :class="$store.player.isPlaying || 'is-paused'">
              <span></span><span></span><span></span>
          </span>
      </template>
      <template x-if="$store.player?.currentId !== '{{ $track->id }}'">
          <span>{{ $track->trackNumber }}</span>
      </template>
  </span>
  ```

  (Each tracklist has its own column layout — library: `40px 36px 1.6fr 1fr 16px 60px` with the `#` in column 2; playlist-detail: its own grid with an Album column — so the swap is applied to whichever cell currently shows `$track->trackNumber` in each template, keeping that cell's existing wrapper classes.)
- CSS in `resources/css/app.css` (plain CSS, alongside the existing `@theme` block):

  ```css
  @keyframes eq-bar {
      0%, 100% { transform: scaleY(0.3); }
      50%      { transform: scaleY(1); }
  }
  .eq { display: inline-flex; align-items: flex-end; gap: 2px; height: 14px; vertical-align: middle; }
  .eq > span {
      width: 3px; height: 100%;
      background: var(--color-accent);
      transform-origin: bottom;
      animation: eq-bar 0.9s ease-in-out infinite;
  }
  .eq > span:nth-child(2) { animation-duration: 1.1s; animation-delay: -0.45s; }
  .eq > span:nth-child(3) { animation-duration: 0.7s; animation-delay: -0.2s; }
  .eq.is-paused > span { animation-play-state: paused; }
  ```

### Plain Play clears shuffle

In the `queue-load` handler in `audioPlayer()`, replace:

```js
if (e.detail.shuffle) {
    this.shuffle = true;
}
```

with:

```js
this.shuffle = !!e.detail.shuffle;
```

The Livewire `onPlayTrack` relay always forwards `shuffle` (default `false`), so a normal Play button or a track-row click (neither passes `shuffle:`) now turns the toggle off; the album/playlist Shuffle buttons (which pass `shuffle: true`) keep it on. The `if (this.shuffle) { applyShuffle(...) } else { ... }` branch below it is unchanged.

## Data flow

1. User clicks a track row / Play / Shuffle in any view → that component dispatches `play-track` with `queue` (items now including `albumId`/`artistId`), `index`, `shuffle`.
2. `components::player` `#[On('play-track')]` re-broadcasts `queue-load` as a browser event.
3. `audioPlayer()` `queue-load` handler: sets `shuffle = !!detail.shuffle`, builds `queue`, calls `loadAndPlay()`.
4. `loadAndPlay()` sets `Alpine.store('player').currentId`; `<audio>` `@play` sets `Alpine.store('player').isPlaying = true`.
5. Library / playlist-detail tracklist rows reactively pick up `$store.player` → the matching row shows the accent title and the running equalizer. Auto-advance / skip moves the highlight because step 4 reruns.
6. Clicking the now-playing cover or title → `goToAlbum()` → `Livewire.navigate('/?artist=…&album=…')`.

## Error handling / edge cases

- Track with no `albumId` (or `artistId`): `goToAlbum()` / `goToArtist()` are no-ops; the cursor still shows `cursor-pointer` (acceptable — these are present on virtually all Plex tracks).
- Navigating to `/?album=…` without `artist`: the library view may not fully resolve the album selection. Mitigated by always sending `artist` too; if `artistId` is somehow absent the album column simply will not pre-select, same as today's deep-link behavior.
- Queue emptied or playback never started: `$store.player.currentId` is `null`, so no row matches and every row renders its number normally.
- End of queue with repeat off: `currentId` stays set (that track is still "current"), `isPlaying` is `false`, so the playing row keeps the accent title with a paused equalizer. Acceptable and arguably correct.
- The store registration guard (`if (!Alpine.store('player'))`) prevents a re-evaluated `@script` (e.g. after a Livewire morph of the layout, which should not happen given `@persist`, but defensively) from resetting live state.

## Testing

- **Unit** — `tests/Unit/PlexDtoTest.php`: `Track::fromPlex()` maps `parentRatingKey` → `albumId` and `grandparentRatingKey` → `artistId`, and leaves them `null` when absent.
- **Feature** —
  - `LibraryViewTest`, `PlaylistDetailViewTest`, `SearchViewTest`, `SidebarTest`, the recently-played feature test: the dispatched `play-track` payload's queue items include `albumId` and `artistId` keys with the expected values (mocked `PlexClient`/DTOs).
  - `PlayerComponentTest`: `onPlayTrack` still relays the full payload unchanged to `queue-load`.
- **Browser** — extend `tests/Browser/PlayerQueueTest.php` (hits live Plex):
  - Play a track from the library tracklist → that row carries the playing marker (`.eq` present in the row / accent title); skip forward → the marker moves to the next row.
  - Click the now-playing track title → the URL becomes `/?artist=…&album=…` (assert it contains `album=`).
  - Toggle the player Shuffle button on (accent), then click a track row in the library tracklist → the Shuffle button is no longer accent.

## Out of scope

- Playing highlight in search "Songs" and recently-played.
- Hover-to-play button replacing the track number.
- Scrobbling plays back to Plex.
- M7 error toasts / transitions / keyboard shortcuts.
