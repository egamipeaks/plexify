# Plextune — link artist/album from the playlist tracklist

Status: approved (2026-05-12). One of the post-M7 polish follow-ups.

## Goal

In the playlist-detail tracklist, the artist text and the Album column are plain text. Make them navigate to the library: the artist text to that artist, the Album column to that artist's album (which opens the album tracklist). This mirrors what the player's now-playing strip already does via `goToAlbum()`/`goToArtist()`.

Non-goals: no changes to the library album tracklist (its artist sub-line stays plain text — the album link there would self-link and the artist link only drops the album you are already viewing), no changes to the search results, no changes to the player's `goToAlbum`/`goToArtist`, no changes to the `Track` DTO or any PHP component class. No right-click / open-in-new-tab support (the now-playing strip does not have it either).

## Affected files

- `resources/views/pages/⚡playlist-detail.blade.php` — the only production file touched (the tracklist `@foreach`, both the `tracksCompact` and the comfortable branches).
- `tests/Feature/PlaylistDetailViewTest.php` — extended.
- `tests/Browser/PlaylistDetailTest.php` — extended.

## Design

### The links

The tracklist row is a single `<button type="button" wire:click="playTrack('{id}')" draggable="true" ...>`. Inside it, the artist and album cells become `<span>` elements with an Alpine click handler:

- `@click.stop="Livewire.navigate('...')"` — `.stop` prevents the click bubbling to the row's `wire:click`, so clicking the artist/album navigates instead of starting playback.
- Append `hover:underline cursor-pointer` to the cell's existing class list (the cells already have `text-text-2 group-hover:text-white truncate` etc.; keep those).
- A `<span>` is not "interactive content" per the HTML spec, so nesting it in a `<button>` is valid (an `<a>` would not be). The row stays one `<button>`.
- The `@foreach` container already has `x-data="{}"`, so inline Alpine directives on the rows work.

URL building uses the named route (the library route is `Route::livewire('/', 'pages::library')->name('library')`, so `route('library', [...])` appends the params as a query string):

- Artist span → `Livewire.navigate('{{ route('library', ['artist' => $track->artistId]) }}')` → `https://plexify.test/?artist={artistId}`
- Album span → `Livewire.navigate('{{ route('library', ['artist' => $track->artistId, 'album' => $track->albumId]) }}')` → `https://plexify.test/?artist={artistId}&album={albumId}`

The library page hydrates `#[Url(as: 'artist', except: null)] $selectedArtistId` and `#[Url(as: 'album', except: null)] $selectedAlbumId` from those query keys, then its `albums()` / `tracks()` / `selectedAlbum()` computeds render the album with its tracklist. This is the exact path the player's now-playing nav already exercises, so it is known to work.

### Both row variants

- **Compact** (`grid-template-columns: 20px 1.4fr 1fr 1fr 50px`): the artist column is `<div class="text-text-2 group-hover:text-white truncate">{{ $track->artist }}</div>` and the album column is the same with `{{ $track->album }}`. Both become linkable spans.
- **Comfortable** (`grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px`): the artist sub-line under the title is `<div class="truncate text-[12px] text-text-2 group-hover:text-white">{{ $track->artist }}</div>` and the Album column is `<div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>`. Both become linkable spans (the artist sub-line keeps its `text-[12px]`).

### Null guards

`Track->albumId` is `parentRatingKey` and `Track->artistId` is `grandparentRatingKey`; both are nullable on the DTO. Rules:

- `artistId` null → the artist cell renders as plain text (current behavior), and the album cell also renders as plain text (the library needs an artist to resolve albums; `/?album=X` alone shows nothing useful).
- `artistId` present, `albumId` null → the artist cell links; the album cell is plain text.
- both present (the normal case for Plex tracks) → both link.

Implement this with a server-side `@if ($track->artistId)` around the artist span (else the plain `<div>`), and `@if ($track->artistId && $track->albumId)` around the album span (else the plain `<div>`). Server-side `@if`, not Alpine — consistent with the project's "Alpine morph won't re-evaluate a server-rendered literal" gotcha (and there is nothing reactive here anyway).

## Testing

### Feature: `tests/Feature/PlaylistDetailViewTest.php`

Mock `PlexClient` so `playlistTracks($id)` returns two `Track`s: one with `parentRatingKey` (album id) and `grandparentRatingKey` (artist id) set, one with both null. Also stub whatever the page and the layout-rendered sidebar/server-chip/search need (the existing tests in this file already do this — follow their setup; `ping()`, `playlists()`, `searchAll` may all need stubbing because rendering the page renders the layout). Then for each density:

- `Livewire::test('pages::playlist-detail', ['playlist' => '123'])` (set the density via `AppSetting` or the `tracksCompact` prop the way the existing tests do).
- `assertSee(route('library', ['artist' => $artistId, 'album' => $albumId]), false)` — the album link is present for the fully-populated track. (`false` = do not escape; `route()` returns a plain URL with no HTML entities, but `&` in the query string is fine either way — use `false` to be safe.)
- `assertSee(route('library', ['artist' => $artistId]), false)` — the artist link is present. Note this URL is a prefix of the album URL, so if you want to assert the artist link specifically you can also `assertSeeHtml` the `@click.stop` span; a prefix match via `assertSee` is acceptable as a first cut.
- `assertDontSee(route('library', ['artist' => $nullTrackArtistId]))` is not meaningful (the id is null). Instead, assert the null track's title still renders (`assertSee($nullTrack->title)`) and that there is no `Livewire.navigate(` occurrence tied to it — simplest: give the two tracks distinct artist/album names and assert the null track's artist name appears as plain text by checking the rendered HTML does not contain `Livewire.navigate` adjacent to it. If that is awkward, at minimum assert the count/shape: the fully-populated track produces the two `route('library', ...)` URLs and the page still renders without error with the null track present.

Keep it simple: the load-bearing assertions are "fully-populated track → album link URL present, artist link URL present" and "page renders fine with a null-id track". Cover both `tracksCompact = false` and `tracksCompact = true`.

### Browser: `tests/Browser/PlaylistDetailTest.php`

Visit a real playlist that has tracks (the existing browser test already navigates to one — reuse that). Then:

- Via `script()` (not `click()` — the strict Playwright locator would match every artist/album span; drive it the way `tests/Browser/LibraryDrillDownTest.php` drives multi-match interactions): click the first track row's album span, then assert the URL is now `/?artist=...&album=...` (use `assertUrlIs` or read `location.pathname + location.search` and assert it starts with `/?artist=` and contains `&album=`).
- Similarly click an artist span and assert the URL became `/?artist=...` (no `&album=`).

Browser tests hit the live Plex server; they fail if it is unreachable, like the others.

## Out of scope / not built

- Library album tracklist links (artist sub-line stays plain text).
- Search results "Tracks" group links.
- Right-click / open-in-new-tab (no `<a href>`).
- Any change to the player's `goToAlbum`/`goToArtist` or the `Track` DTO.
