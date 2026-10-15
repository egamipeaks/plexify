# Plextune: create a Various Artists album from a playlist

Date: 2026-10-14

## Goal

Right click a user playlist in the sidebar and choose "Create album". Plexify copies the playlist's
tracks into `Various Artists/<Playlist Name>/` inside the active music library on the Plex server,
tags them as a compilation album named after the playlist with track numbers in playlist order, and
normalizes every track to a common loudness and encodes it as MP3 320 kbps CBR. Plex then shows the playlist as a regular album.

This automates what the user already does by hand (for example
`Various Artists/2026-04 April/`, tagged `album_artist=Various Artists`, `album=April 2026`,
`compilation=1`, `date=2026`, but without track numbers).

## Environment facts (verified 2026-10-14)

- Plex runs on a Windows host (`homeserver`, 10.0.0.50). The music library location is `M:\Music`
  (section 6). Other artist sections: `M:\Classical` (14), `M:\Spoken` (13).
- That drive is mounted on the Mac running Plexify over SMB at `/Volumes/music_ssd`, so
  `M:\Music\X` is `/Volumes/music_ssd/Music/X`.
- `/Volumes/music_ssd/Music/Various Artists/` already exists and holds compilation folders.
- `ffmpeg` and `ffprobe` are installed at `/opt/homebrew/bin`.
- `QUEUE_CONNECTION=database`, but no queue worker runs today.

## Decisions

| Topic | Decision |
|---|---|
| Lifecycle | One time snapshot. A "Recreate album" action rebuilds it. No syncing. |
| Format | Every track is encoded as MP3 320 kbps CBR, MP3 sources included (they are re-encoded so their volume can change). |
| Loudness | Two pass `loudnorm` per track to `ALBUM_LOUDNESS_TARGET` (default -14 LUFS), true peak -1 dBTP, linear gain. Added 2026-10-15. |
| Cover art | No `cover.jpg`. Each track keeps its own embedded art. |
| Execution | Queued job with live progress in the sidebar and a toast on completion. |
| Tagging tool | `ffmpeg` only (one invocation per track does tagging and, if needed, transcoding). No new PHP dependencies. |
| Sources | Originals and the Plex playlist are never modified. |

## Architecture and data flow

### Source file paths

Plex's `GET /playlists/{id}/items` rows carry `Media[0].Part[0].file`, the absolute path on the
Plex host (for example `M:\Music\David Bowie\Tonight\03 Loving The Alien.flac`).

- `Track` gains a nullable `?string $filePath`, read in `Track::fromPlex()` from
  `$row['Media'][0]['Part'][0]['file'] ?? null`.
- Adding a property to a cached DTO requires `php artisan cache:clear` (see the CLAUDE.md gotcha).

### Path mapping

`config/services.php` `plex` gains:

```php
'path_from' => env('PLEX_PATH_FROM'),   // e.g. "M:\"
'path_to' => env('PLEX_PATH_TO'),       // e.g. "/Volumes/music_ssd/"
```

A small `App\Services\Albums\PlexPathMapper` converts both ways:

- `toLocal('M:\Music\A\b.flac')` returns `/Volumes/music_ssd/Music/A/b.flac` (prefix swap,
  backslashes become forward slashes). Returns null when the path does not start with `path_from`
  (case insensitive, since Windows paths are).
- `toRemote('/Volumes/music_ssd/Music/Various Artists/Road Trip')` returns
  `M:\Music\Various Artists\Road Trip` (used for the Plex partial scan).

### Target folder

`<active music section location>/Various Artists/<Folder Name>/`.

- `MusicSection` gains `?string $location` (the first `Location[].path` of the section, for example
  `M:\Music`). It is cached under `music_sections`, so this is another DTO change covered by the
  same `cache:clear`.
- The target is `PlexPathMapper::toLocal($section->location) . '/Various Artists/' . $folderName`.
- `$folderName` is the playlist title with Windows forbidden characters `: / \ * ? " < > |`
  replaced by `_`, control characters removed, and trailing dots and spaces trimmed. An empty result
  falls back to `Playlist {ratingKey}`. This matches existing folders like
  `12 Inch Dance_ 70s Groove (2015)`.

### File names

`{NN} - {Artist} - {Title}.mp3`, where `NN` is the 1 based playlist position zero padded to two
digits (three digits when the playlist has more than 99 tracks). Artist and title are sanitized
with the same rules as the folder name. To stay well inside Windows path limits over SMB, the
folder name is capped at 100 characters, the artist part at 60 and the title part at 90. The track artist is the `Track` DTO's artist (falling back
to `Unknown Artist`).

### Tags

Every output file gets its source tags cleared (`-map_metadata -1`) and exactly these set:

| Tag | Value |
|---|---|
| `title` | track title |
| `artist` | track artist |
| `album` | playlist title (unsanitized) |
| `album_artist` | `Various Artists` |
| `compilation` | `1` |
| `track` | `N/Total` |
| `date` | current year |

Clearing old tags is deliberate: a leftover `disc`, `genre` or MusicBrainz album id can make Plex
split the album or match it to the source album. Tags are written as ID3v2.3
(`-id3v2_version 3`) for broad player compatibility.

### ffmpeg invocation

Built by `App\Services\Albums\FfmpegCommand` (a pure argument builder, unit tested) and run through
Laravel's `Process` facade. Each track takes two runs. The first measures loudness:

```
ffmpeg -nostdin -hide_banner -nostats -i <source> -map 0:a:0
  -af loudnorm=I=<target>:TP=-1:LRA=20:print_format=json -f null -
```

`LoudnessMeasurement::fromFfmpegOutput()` parses the JSON block from stderr (`input_i`, `input_tp`,
`input_lra`, `input_thresh`, `target_offset`). It returns null when a value is not finite (a silent
track), and that track is then encoded without the filter. The second run encodes:

```
ffmpeg -nostdin -y -i <source>
  -map 0:a:0 -map 0:v? -c:v copy -disposition:v attached_pic
  -map_metadata -1 -metadata title=... (etc.)
  -id3v2_version 3
  [-af loudnorm=...:measured_I=...:measured_TP=...:measured_LRA=...:measured_thresh=...:offset=...:linear=true
   -ar 44100]                          # omitted for a silent track
  -c:a libmp3lame -b:a 320k
  <target>.mp3
```

`-map 0:v?` keeps embedded art when present and is a no op when absent. The binary path comes from
`config('services.ffmpeg.path')` (`FFMPEG_PATH`, default `ffmpeg`), since Herd's PHP may not have
Homebrew on its `PATH`.

`linear=true` applies one fixed gain per track, so dynamics are kept. If a quiet, very dynamic track
cannot reach the target without exceeding the true peak limit, ffmpeg falls back to dynamic mode for
that track (light compression). `-ar 44100` is needed because `loudnorm` resamples to 192 kHz
internally.

### Build, then swap

The job builds the whole album in a local temp directory,
`storage/app/album-builds/{exportId}/`, reading sources over SMB. Only after every track has
succeeded does it:

1. copy the temp directory to a hidden staging folder on the share,
   `Various Artists/.plexify-build-{exportId}`,
2. delete the previous album folder if this is a recreate (only the `folder_path` of the previous
   `done` export for the same playlist, never anything else),
3. rename the staging folder to the target folder,
4. delete the local temp directory.

If a folder with the target name already exists and is not that previous folder (for example one
the user made by hand), the job fails with "A folder named X already exists in Various Artists."
and touches nothing. Staging on the share means a failed copy (share dropped mid copy) never
costs the previous album.

Plex never scans a half written album, and a failed recreate leaves the previous album intact.

### Plex refresh

After the swap, `PlexClient::refreshSectionPath(int $sectionId, string $remotePath)` calls
`GET /library/sections/{id}/refresh?path={remotePath}` for a partial scan of just the new folder.
A failure here does not fail the export (Plex's own scheduled scan will pick it up). It is stored
as a note on the row instead.

### State: `album_exports` table

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `plex_playlist_id` | string, indexed | |
| `title` | string | playlist title at creation time |
| `folder_path` | string, nullable | local target path, set when the job starts |
| `status` | string | `queued`, `running`, `done`, `failed` (backed by an `AlbumExportStatus` enum) |
| `progress` | unsigned int | tracks finished |
| `total` | unsigned int | |
| `error` | text, nullable | failure reason or post swap note |
| `notified_at` | timestamp, nullable | set when the completion toast has been shown |
| timestamps | | |

Model `App\Models\AlbumExport`. The latest row per playlist is the one that counts. Recreate creates
a new row and reuses the previous `done` row's `folder_path` as the folder to replace.

## UI

### Context menu

The sidebar playlist context menu (`resources/views/components/⚡sidebar.blade.php`, the
`menu.kind === 'playlist'` block) gets a "Create album" entry. It reads "Recreate album" when the
playlist's latest export is `done`. The labels come from a sidebar `#[Computed]` map of playlist id
to latest export status, so the menu needs no extra request.

Clicking it calls a new sidebar action `createAlbum(string $playlistId): bool`, which:

1. rejects (toast + `false`) when an export for that playlist is `queued` or `running`,
2. loads `playlistTracks($playlistId)` and rejects an empty playlist,
3. rejects when `PLEX_PATH_TO` does not exist on disk ("Music share is not mounted"),
4. rejects when any track has no `filePath`, its path does not map, or the mapped file does not
   exist on the share, naming the first offending track,
5. creates the `album_exports` row (`queued`, `total` = track count) and dispatches the
   `CreateAlbumFromPlaylist` job.

No confirmation prompt for recreate: the old album is only replaced after a successful build.

### Progress and completion

- While a playlist's latest export is `queued` or `running`, its sidebar row subtitle is replaced
  by a muted `Creating album… {progress}/{total}`.
- The sidebar root gets `wire:poll.2s` only while some export is `queued` or `running`, so nothing
  polls when idle.
- On each render, exports that are `done` or `failed` and have a null `notified_at` dispatch a
  `notify` toast and get `notified_at` set. Success: `Album "{title}" created`. Failure:
  `Couldn't create album "{title}": {error}`.
- The toasts component already accepts a `type`. It gains a success style (accent coloured icon),
  since only errors are styled today.

## Job: `App\Jobs\CreateAlbumFromPlaylist`

- `public int $tries = 1; public int $timeout = 3600;` (two ffmpeg passes per track)
- Constructor takes the `AlbumExport` id.
- Steps: mark `running`; resolve the target folder; fetch the playlist tracks via `playlistTracks()`
  (the 5 minute cache is acceptable); run ffmpeg per track into the temp dir, incrementing
  `progress` after each; swap; refresh Plex; mark `done`.
- On any exception: delete the temp dir, mark `failed` with a short message (for ffmpeg failures,
  the last few lines of stderr), and leave the share untouched.
- `failed()` handles worker level failures (timeout) the same way.

## Queue worker

`solo.yml` gains a `Queue` process, `php artisan queue:work --timeout=3600 --tries=1`, with
`auto_start: true`. Without a running worker the export stays `queued`; the sidebar shows
`Creating album… 0/N` which is the cue to start it.

## Out of scope

- Keeping the album in sync with later playlist changes.
- Cover art (`cover.jpg`) and stripping embedded art.
- A Settings UI for the path mapping (it lives in `.env`).
- Smart playlists (already hidden app wide).
- Deleting an album from inside Plexify.

## Testing

- **Unit:** `PlexPathMapper` (both directions, case insensitive prefix, unmapped returns null);
  folder and file name sanitizing and padding; `FfmpegCommand` (remux vs transcode arguments, tag
  set, art mapping, loudness measure and normalize arguments); `LoudnessMeasurement` parsing (normal,
  silent, unparseable); `Track::fromPlex` reading `filePath`; `MusicSection` reading `location`;
  `PlexClient::refreshSectionPath()` with `Http::fake`.
- **Feature:** the job with `Process::fake()` and temp directories standing in for the share (happy
  path writes files and marks `done`; a failing track marks `failed` and keeps an existing album
  untouched; recreate replaces the previous folder; Plex refresh failure still ends `done` with a
  note). Sidebar: each `createAlbum` rejection branch, the happy path dispatching the job
  (`Queue::fake()`), the menu label switching to "Recreate album", the progress subtitle, polling
  only while active, and the completion toast firing once.
- **Integration (opt in, skipped when `ffmpeg` is missing):** generate a one second FLAC with
  `ffmpeg`, run the real command builder against it in a temp dir, and assert the output tags and
  codec with `ffprobe`; normalize a quiet MP3 and assert it re-measures within 1 LU of -14 at 44.1 kHz. Never touches the share.
- No browser test: it would write to the real Plex share.
