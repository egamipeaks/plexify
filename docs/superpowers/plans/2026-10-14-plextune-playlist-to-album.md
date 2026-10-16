# Playlist to Album Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Right click a playlist in the sidebar, choose "Create album", and get a tagged Various Artists compilation album (MP3 320k, track numbers in playlist order) written to the Plex music share.

**Architecture:** A sidebar Livewire action validates the playlist and queues `CreateAlbumFromPlaylist`. The job maps Plex's Windows file paths to the Mac SMB mount, runs one `ffmpeg` per track into a local temp dir, copies the result to a hidden staging folder on the share, swaps it into `Various Artists/<Playlist>/`, then asks Plex for a partial scan. An `album_exports` table carries status and progress, which the sidebar polls while a job is active.

**Tech Stack:** Laravel 13 queues (database driver), Livewire 4 SFC, Alpine, `ffmpeg` via the `Process` facade, Pest 4.

**Spec:** `docs/superpowers/specs/2026-10-14-plextune-playlist-to-album-design.md`

## Global Constraints

- No new Composer or npm dependencies. Tagging and transcoding are `ffmpeg` only.
- MP3 sources are remuxed with `-c:a copy`. Every other source is encoded `-c:a libmp3lame -b:a 320k`.
- Tags written: `title`, `artist`, `album` (playlist title), `album_artist=Various Artists`, `compilation=1`, `track=N/Total`, `date=<current year>`. Source tags cleared with `-map_metadata -1`. ID3v2.3. Embedded art kept (`-map 0:v?`), no `cover.jpg`.
- File names: `{NN} - {Artist} - {Title}.mp3`, two digit padding, three when the playlist has more than 99 tracks.
- Folder: `<active section location mapped to local>/Various Artists/<sanitized playlist title>`. Characters `: / \ * ? " < > |` become `_`.
- Never modify source files or the Plex playlist. Never delete any folder on the share except the folder recorded on a previous `done` export of the same playlist, and the job's own `.plexify-build-{id}` staging folder.
- Follow `~/.claude/laravel-php-guidelines.md`: typed properties, early returns, no `else`, curly braces always, migrations have only `up()`, no comments in tests, no "what" comments.
- Run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- Commit messages end with:
  ```
  Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01LZeznwEUTKyGRUcxCMeWJk
  ```

## Review Focus

1. A folder with the playlist's name already exists in `Various Artists` but was not made by Plexify (the user makes these by hand): the job must fail with "already exists" and leave that folder untouched. Pinned in Task 8.
2. The playlist was renamed between "Create album" and "Recreate album": the old folder must be removed and the new one created under the new name, not left as a duplicate album in Plex. Pinned in Task 8.
3. A track's file is missing on the share (Plex's database is stale) or the share is unmounted: refuse up front with a toast naming the track, instead of a half built album. Pinned in Tasks 7 and 10.
4. The worker dies or times out mid job: the export must end `failed` (not stuck `running` with the sidebar polling forever) and the temp build dir must be removed. Pinned in Task 8.
5. Very long titles or artists (classical works, extended mix names) blowing past Windows path limits over SMB: name parts are capped (folder 100, artist 60, title 90 characters). Pinned in Task 4.

---

### Task 1: Carry file paths and section locations on the Plex DTOs

**Files:**
- Modify: `app/Services/Plex/Dto/Track.php`
- Modify: `app/Services/Plex/Dto/MusicSection.php`
- Test: `tests/Unit/PlexDtoTest.php`

**Interfaces:**
- Produces: `Track::$filePath` (`?string`, last constructor param, default `null`), `MusicSection::$location` (`?string`, last constructor param, default `null`).

- [ ] **Step 1: Write the failing tests** (append to `tests/Unit/PlexDtoTest.php`)

```php
it('reads the Plex host file path off a track part', function () {
    $track = Track::fromPlex([
        'ratingKey' => '1',
        'title' => 'Loving The Alien',
        'Media' => [['Part' => [['id' => 9, 'container' => 'flac', 'file' => 'M:\\Music\\David Bowie\\Tonight\\01.flac']]]],
    ]);

    expect($track->filePath)->toBe('M:\\Music\\David Bowie\\Tonight\\01.flac');
});

it('leaves the file path null when Plex sends no part file', function () {
    $track = Track::fromPlex(['ratingKey' => '1', 'title' => 'x']);

    expect($track->filePath)->toBeNull();
});

it('reads the first location path of a music section', function () {
    $section = MusicSection::fromPlex([
        'key' => '6',
        'title' => 'Music',
        'Location' => [['id' => 1, 'path' => 'M:\\Music'], ['id' => 2, 'path' => 'N:\\More']],
    ]);

    expect($section->location)->toBe('M:\\Music');
});

it('leaves the section location null when Plex sends none', function () {
    expect(MusicSection::fromPlex(['key' => '6', 'title' => 'Music'])->location)->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Unit/PlexDtoTest.php`
Expected: 4 FAIL, "Undefined property ... filePath" / "location".

- [ ] **Step 3: Implement**

In `Track.php` add as the last promoted param `public ?string $filePath = null,` and in `fromPlex()` add `filePath: $part['file'] ?? null,` as the last named argument.

In `MusicSection.php`:

```php
    public function __construct(
        public int $id,
        public string $title,
        public ?string $location = null,
    ) {}

    public static function fromPlex(array $row): self
    {
        return new self(
            id: (int) $row['key'],
            title: (string) $row['title'],
            location: $row['Location'][0]['path'] ?? null,
        );
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact tests/Unit/PlexDtoTest.php`
Expected: PASS.

- [ ] **Step 5: Clear the dev cache and commit**

Both DTOs are cached serialized (see the CLAUDE.md gotcha). Run `php artisan cache:clear`.

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/Dto/Track.php app/Services/Plex/Dto/MusicSection.php tests/Unit/PlexDtoTest.php
git commit -m "feat(plex): carry track file paths and section locations on DTOs"
```

---

### Task 2: `PlexClient::refreshSectionPath()`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php` (add public method after `moveTrack()`)
- Test: `tests/Unit/PlexClientTest.php`

**Interfaces:**
- Produces: `PlexClient::refreshSectionPath(int $sectionId, string $remotePath): void`. Throws `PlexUnreachableException` / `PlexAuthException` / `PlexNotFoundException` via `ensureOk()`.

- [ ] **Step 1: Write the failing tests** (append to `tests/Unit/PlexClientTest.php`)

```php
it('asks Plex for a partial scan of one folder', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake(['https://server.plex.direct:32400/library/sections/6/refresh*' => Http::response('', 200)]);

    app(PlexClient::class)->refreshSectionPath(6, 'M:\\Music\\Various Artists\\Road Trip');

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains(urldecode($request->url()), '/library/sections/6/refresh?path=M:\\Music\\Various Artists\\Road Trip'));
});

it('throws when the partial scan request fails', function () {
    config()->set('services.plex.base_url', 'https://server.plex.direct:32400');
    Http::fake(['https://server.plex.direct:32400/library/sections/6/refresh*' => Http::response('', 500)]);

    app(PlexClient::class)->refreshSectionPath(6, 'M:\\Music\\X');
})->throws(PlexUnreachableException::class);
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Unit/PlexClientTest.php --filter="partial scan"`
Expected: FAIL, "Call to undefined method".

- [ ] **Step 3: Implement**

```php
    public function refreshSectionPath(int $sectionId, string $remotePath): void
    {
        try {
            $response = $this->server()->get("/library/sections/{$sectionId}/refresh", ['path' => $remotePath]);
        } catch (ConnectionException $e) {
            throw new PlexUnreachableException('Refreshing the library failed: '.$e->getMessage(), previous: $e);
        }

        $this->ensureOk($response, "library/sections/{$sectionId}/refresh");
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact tests/Unit/PlexClientTest.php --filter="partial scan"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat(plex): partial library scan for a single folder"
```

---

### Task 3: Path mapping config and `PlexPathMapper`

**Files:**
- Modify: `config/services.php` (`plex` array gains two keys, new `ffmpeg` array)
- Modify: `.env.example`
- Modify: `app/Providers/AppServiceProvider.php` (`register()`)
- Create: `app/Services/Albums/PlexPathMapper.php`
- Test: `tests/Unit/PlexPathMapperTest.php`

**Interfaces:**
- Produces: `config('services.plex.path_from')`, `config('services.plex.path_to')`, `config('services.ffmpeg.path')`.
- Produces: `PlexPathMapper` with `__construct(string $remotePrefix, string $localPrefix)`, `isConfigured(): bool`, `localRoot(): string`, `toLocal(string $remotePath): ?string`, `toRemote(string $localPath): ?string`. Container resolves it from config (bind, not singleton, so tests can change config).

- [ ] **Step 1: Write the failing tests** (`tests/Unit/PlexPathMapperTest.php`)

```php
<?php

use App\Services\Albums\PlexPathMapper;

it('maps a Plex host path to the local mount', function () {
    $mapper = new PlexPathMapper('M:\\', '/Volumes/music_ssd/');

    expect($mapper->toLocal('M:\\Music\\David Bowie\\01 Song.flac'))->toBe('/Volumes/music_ssd/Music/David Bowie/01 Song.flac');
});

it('matches the remote prefix case insensitively', function () {
    $mapper = new PlexPathMapper('M:\\', '/Volumes/music_ssd');

    expect($mapper->toLocal('m:\\Music\\a.mp3'))->toBe('/Volumes/music_ssd/Music/a.mp3');
});

it('returns null for a path outside the mapped prefix', function () {
    expect((new PlexPathMapper('M:\\', '/Volumes/music_ssd/'))->toLocal('D:\\Other\\a.mp3'))->toBeNull();
});

it('maps a local path back to the Plex host form', function () {
    $mapper = new PlexPathMapper('M:\\', '/Volumes/music_ssd/');

    expect($mapper->toRemote('/Volumes/music_ssd/Music/Various Artists/Road Trip'))->toBe('M:\\Music\\Various Artists\\Road Trip');
});

it('returns null mapping back a path outside the mount', function () {
    expect((new PlexPathMapper('M:\\', '/Volumes/music_ssd/'))->toRemote('/tmp/x'))->toBeNull();
});

it('is unconfigured when either prefix is empty', function () {
    $mapper = new PlexPathMapper('', '/Volumes/music_ssd/');

    expect($mapper->isConfigured())->toBeFalse()
        ->and($mapper->toLocal('M:\\a'))->toBeNull();
});

it('resolves from config through the container', function () {
    config()->set('services.plex.path_from', 'X:\\');
    config()->set('services.plex.path_to', '/mnt/x/');

    expect(app(PlexPathMapper::class)->toLocal('X:\\a\\b.mp3'))->toBe('/mnt/x/a/b.mp3')
        ->and(app(PlexPathMapper::class)->localRoot())->toBe('/mnt/x');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Unit/PlexPathMapperTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

`config/services.php`, inside `'plex' => [...]` add:

```php
        'path_from' => env('PLEX_PATH_FROM', ''),
        'path_to' => env('PLEX_PATH_TO', ''),
```

and a sibling top level entry:

```php
    'ffmpeg' => [
        'path' => env('FFMPEG_PATH', 'ffmpeg'),
    ],
```

`.env.example`, after `PLEX_BASE_URL=`:

```
PLEX_PATH_FROM=
PLEX_PATH_TO=
FFMPEG_PATH=ffmpeg
```

`app/Services/Albums/PlexPathMapper.php`:

```php
<?php

namespace App\Services\Albums;

class PlexPathMapper
{
    public function __construct(
        private readonly string $remotePrefix,
        private readonly string $localPrefix,
    ) {}

    public function isConfigured(): bool
    {
        return $this->remotePrefix !== '' && $this->localPrefix !== '';
    }

    public function localRoot(): string
    {
        return rtrim($this->localPrefix, '/');
    }

    public function toLocal(string $remotePath): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        if (! str_starts_with(mb_strtolower($remotePath), mb_strtolower($this->remotePrefix))) {
            return null;
        }

        $relative = str_replace('\\', '/', substr($remotePath, strlen($this->remotePrefix)));

        return $this->localRoot().'/'.ltrim($relative, '/');
    }

    public function toRemote(string $localPath): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $root = $this->localRoot().'/';

        if (! str_starts_with($localPath, $root)) {
            return null;
        }

        $relative = str_replace('/', '\\', substr($localPath, strlen($root)));

        return rtrim($this->remotePrefix, '\\').'\\'.$relative;
    }
}
```

`AppServiceProvider::register()` (add the `use App\Services\Albums\PlexPathMapper;` import):

```php
    public function register(): void
    {
        $this->app->bind(PlexPathMapper::class, fn () => new PlexPathMapper(
            (string) config('services.plex.path_from'),
            (string) config('services.plex.path_to'),
        ));
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact tests/Unit/PlexPathMapperTest.php`
Expected: PASS (7).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/services.php .env.example app/Providers/AppServiceProvider.php app/Services/Albums/PlexPathMapper.php tests/Unit/PlexPathMapperTest.php
git commit -m "feat(albums): map Plex host paths to the local music share"
```

---

### Task 4: `AlbumNaming` (folder and file names)

**Files:**
- Create: `app/Services/Albums/AlbumNaming.php`
- Test: `tests/Unit/AlbumNamingTest.php`

**Interfaces:**
- Produces: `AlbumNaming::sanitize(string $name, int $maxLength): string`, `AlbumNaming::folderName(string $playlistTitle, string $playlistId): string`, `AlbumNaming::fileName(int $position, int $total, string $artist, string $title): string`. All static, pure.

- [ ] **Step 1: Write the failing tests** (`tests/Unit/AlbumNamingTest.php`)

```php
<?php

use App\Services\Albums\AlbumNaming;

it('replaces Windows forbidden characters with underscores', function () {
    expect(AlbumNaming::sanitize('12" Dance: 80s / Groove? <*|>\\', 200))->toBe('12_ Dance_ 80s _ Groove_ _____');
});

it('strips control characters and trailing dots and spaces', function () {
    expect(AlbumNaming::sanitize("Road\tTrip... ", 200))->toBe('RoadTrip');
});

it('caps the length and re-trims after cutting', function () {
    expect(AlbumNaming::sanitize('abcde. fgh', 7))->toBe('abcde');
});

it('uses the sanitized playlist title as the folder name', function () {
    expect(AlbumNaming::folderName('Summer: 2026', '42'))->toBe('Summer_ 2026');
});

it('falls back to the playlist id when the title sanitizes to nothing', function () {
    expect(AlbumNaming::folderName('...', '42'))->toBe('Playlist 42');
});

it('caps the folder name at 100 characters', function () {
    expect(mb_strlen(AlbumNaming::folderName(str_repeat('a', 300), '42')))->toBe(100);
});

it('builds a two digit numbered file name', function () {
    expect(AlbumNaming::fileName(3, 12, 'AC/DC', 'Back In Black'))->toBe('03 - AC_DC - Back In Black.mp3');
});

it('pads to three digits for playlists over 99 tracks', function () {
    expect(AlbumNaming::fileName(7, 100, 'A', 'B'))->toBe('007 - A - B.mp3');
});

it('falls back for empty artist and title', function () {
    expect(AlbumNaming::fileName(1, 2, '', '?'))->toBe('01 - Unknown Artist - _.mp3')
        ->and(AlbumNaming::fileName(1, 2, 'A', '...'))->toBe('01 - A - Untitled.mp3');
});

it('caps artist at 60 and title at 90 characters', function () {
    $name = AlbumNaming::fileName(1, 2, str_repeat('a', 200), str_repeat('t', 200));

    expect($name)->toBe('01 - '.str_repeat('a', 60).' - '.str_repeat('t', 90).'.mp3');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Unit/AlbumNamingTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement** (`app/Services/Albums/AlbumNaming.php`)

```php
<?php

namespace App\Services\Albums;

class AlbumNaming
{
    public static function sanitize(string $name, int $maxLength): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $clean = preg_replace('/[:\/\\\\*?"<>|]/u', '_', $clean);
        $clean = rtrim(trim($clean), '. ');

        return rtrim(mb_substr($clean, 0, $maxLength), '. ');
    }

    public static function folderName(string $playlistTitle, string $playlistId): string
    {
        $name = self::sanitize($playlistTitle, 100);

        return $name === '' ? "Playlist {$playlistId}" : $name;
    }

    public static function fileName(int $position, int $total, string $artist, string $title): string
    {
        $number = str_pad((string) $position, $total > 99 ? 3 : 2, '0', STR_PAD_LEFT);
        $artist = self::sanitize($artist, 60) ?: 'Unknown Artist';
        $title = self::sanitize($title, 90) ?: 'Untitled';

        return "{$number} - {$artist} - {$title}.mp3";
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact tests/Unit/AlbumNamingTest.php`
Expected: PASS (10). If the forbidden-character expectation differs only in the count of trailing underscores, recount the input characters rather than changing the regex.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Albums/AlbumNaming.php tests/Unit/AlbumNamingTest.php
git commit -m "feat(albums): Windows-safe album folder and track file names"
```

---

### Task 5: `FfmpegCommand` builder plus a real ffmpeg integration test

**Files:**
- Create: `app/Services/Albums/FfmpegCommand.php`
- Test: `tests/Unit/FfmpegCommandTest.php`
- Test: `tests/Feature/FfmpegIntegrationTest.php`

**Interfaces:**
- Produces: `FfmpegCommand::build(string $binary, string $source, string $target, array $tags, bool $remux): array` returning `list<string>` suitable for `Process::run()`. `$tags` is `array<string, string>`.

- [ ] **Step 1: Write the failing unit tests** (`tests/Unit/FfmpegCommandTest.php`)

```php
<?php

use App\Services\Albums\FfmpegCommand;

it('transcodes to 320k MP3 with cleared source tags and kept art', function () {
    $command = FfmpegCommand::build('ffmpeg', '/in/a.flac', '/out/01.mp3', ['title' => 'Song', 'track' => '1/2'], remux: false);

    expect($command)->toBe([
        'ffmpeg', '-nostdin', '-y', '-v', 'error', '-i', '/in/a.flac',
        '-map', '0:a:0', '-map', '0:v?', '-c:v', 'copy', '-disposition:v', 'attached_pic',
        '-map_metadata', '-1',
        '-metadata', 'title=Song', '-metadata', 'track=1/2',
        '-id3v2_version', '3',
        '-c:a', 'libmp3lame', '-b:a', '320k',
        '/out/01.mp3',
    ]);
});

it('copies the audio stream when remuxing an MP3', function () {
    $command = FfmpegCommand::build('/opt/homebrew/bin/ffmpeg', '/in/a.mp3', '/out/01.mp3', [], remux: true);

    expect($command[0])->toBe('/opt/homebrew/bin/ffmpeg')
        ->and(array_slice($command, -3))->toBe(['-c:a', 'copy', '/out/01.mp3'])
        ->and($command)->not->toContain('libmp3lame');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Unit/FfmpegCommandTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement** (`app/Services/Albums/FfmpegCommand.php`)

```php
<?php

namespace App\Services\Albums;

class FfmpegCommand
{
    /**
     * @param  array<string, string>  $tags
     * @return list<string>
     */
    public static function build(string $binary, string $source, string $target, array $tags, bool $remux): array
    {
        $metadata = collect($tags)
            ->flatMap(fn (string $value, string $key) => ['-metadata', "{$key}={$value}"])
            ->values()
            ->all();

        $audio = $remux
            ? ['-c:a', 'copy']
            : ['-c:a', 'libmp3lame', '-b:a', '320k'];

        return [
            $binary, '-nostdin', '-y', '-v', 'error', '-i', $source,
            '-map', '0:a:0', '-map', '0:v?', '-c:v', 'copy', '-disposition:v', 'attached_pic',
            '-map_metadata', '-1',
            ...$metadata,
            '-id3v2_version', '3',
            ...$audio,
            $target,
        ];
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact tests/Unit/FfmpegCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Write the integration test** (`tests/Feature/FfmpegIntegrationTest.php`)

This runs the real binary on generated fixtures in a temp dir. It is skipped when `ffmpeg` is not installed. It never touches the share.

```php
<?php

use App\Services\Albums\FfmpegCommand;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/plexify-ffmpeg-'.Str::random(8);
    File::ensureDirectoryExists($this->dir);
});

afterEach(fn () => File::deleteDirectory($this->dir));

function ffprobeJson(string $file): array
{
    return json_decode(Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format_tags:stream=codec_name,bit_rate', '-of', 'json', $file])->output(), true);
}

it('transcodes a FLAC with embedded art to tagged 320k MP3 keeping the art', function () {
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=d=1', "{$this->dir}/plain.flac"])->throw();
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=red:s=64x64:d=1', '-frames:v', '1', "{$this->dir}/c.jpg"])->throw();
    Process::run(['ffmpeg', '-v', 'error', '-y', '-i', "{$this->dir}/plain.flac", '-i', "{$this->dir}/c.jpg", '-map', '0', '-map', '1', '-c', 'copy', '-disposition:v', 'attached_pic', '-metadata', 'genre=Rock', "{$this->dir}/src.flac"])->throw();

    $tags = ['title' => 'Song', 'artist' => 'Artist', 'album' => 'Road Trip', 'album_artist' => 'Various Artists', 'compilation' => '1', 'track' => '3/12', 'date' => '2026'];

    Process::run(FfmpegCommand::build('ffmpeg', "{$this->dir}/src.flac", "{$this->dir}/out.mp3", $tags, remux: false))->throw();

    $probe = ffprobeJson("{$this->dir}/out.mp3");

    expect(collect($probe['streams'])->pluck('codec_name')->all())->toBe(['mp3', 'mjpeg'])
        ->and($probe['streams'][0]['bit_rate'])->toBe('320000')
        ->and($probe['format']['tags'])->toMatchArray($tags)
        ->and($probe['format']['tags'])->not->toHaveKey('genre');
})->skip(fn () => Process::run(['which', 'ffmpeg'])->failed(), 'ffmpeg is not installed');

it('remuxes an MP3 without re-encoding', function () {
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=d=1', '-c:a', 'libmp3lame', '-b:a', '128k', "{$this->dir}/src.mp3"])->throw();

    Process::run(FfmpegCommand::build('ffmpeg', "{$this->dir}/src.mp3", "{$this->dir}/out.mp3", ['title' => 'X'], remux: true))->throw();

    $probe = ffprobeJson("{$this->dir}/out.mp3");

    expect($probe['streams'][0]['bit_rate'])->toBe('128000')
        ->and($probe['format']['tags']['title'])->toBe('X');
})->skip(fn () => Process::run(['which', 'ffmpeg'])->failed(), 'ffmpeg is not installed');
```

- [ ] **Step 6: Run the integration test**

Run: `php artisan test --compact tests/Feature/FfmpegIntegrationTest.php`
Expected: PASS (2). If the 128k remux bit_rate reads back slightly different (e.g. `128000` vs a VBR estimate), assert `toBe('128000')` still holds for a CBR source; if it does not, compare against the source's own probed bit_rate instead of a literal.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Albums/FfmpegCommand.php tests/Unit/FfmpegCommandTest.php tests/Feature/FfmpegIntegrationTest.php
git commit -m "feat(albums): ffmpeg command builder for tagging and MP3 transcoding"
```

---

### Task 6: `album_exports` table, status enum, model, factory

**Files:**
- Create: migration via `php artisan make:migration create_album_exports_table --no-interaction`
- Create: `app/Enums/AlbumExportStatus.php` (via `php artisan make:enum AlbumExportStatus --string --no-interaction`, adjust if flags differ; check `--help`)
- Create: `app/Models/AlbumExport.php` and `database/factories/AlbumExportFactory.php` (via `php artisan make:model AlbumExport --factory --no-interaction`)
- Test: `tests/Feature/AlbumExportTest.php`

**Interfaces:**
- Produces: `AlbumExportStatus` cases `Queued='queued'`, `Running='running'`, `Done='done'`, `Failed='failed'`; `isActive(): bool` (Queued or Running).
- Produces: `AlbumExport` model, fillable `plex_playlist_id, title, folder_path, status, progress, total, error, notified_at`; `status` cast to the enum, `notified_at` to datetime; `static latestPerPlaylist(): Collection<string, AlbumExport>` keyed by `plex_playlist_id`, newest row per playlist.
- Produces: `AlbumExportFactory` with states `running()`, `done(string $folderPath)`, `failed(string $error)`.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/AlbumExportTest.php`)

```php
<?php

use App\Enums\AlbumExportStatus;
use App\Models\AlbumExport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts status to the enum', function () {
    $export = AlbumExport::factory()->create();

    expect($export->fresh()->status)->toBe(AlbumExportStatus::Queued);
});

it('knows which statuses are active', function () {
    expect(AlbumExportStatus::Queued->isActive())->toBeTrue()
        ->and(AlbumExportStatus::Running->isActive())->toBeTrue()
        ->and(AlbumExportStatus::Done->isActive())->toBeFalse()
        ->and(AlbumExportStatus::Failed->isActive())->toBeFalse();
});

it('returns the newest export per playlist keyed by playlist id', function () {
    AlbumExport::factory()->done('/old')->create(['plex_playlist_id' => '1']);
    $newest = AlbumExport::factory()->running()->create(['plex_playlist_id' => '1']);
    $other = AlbumExport::factory()->create(['plex_playlist_id' => '2']);

    $latest = AlbumExport::latestPerPlaylist();

    expect($latest->keys()->sort()->values()->all())->toBe(['1', '2'])
        ->and($latest['1']->id)->toBe($newest->id)
        ->and($latest['2']->id)->toBe($other->id);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/AlbumExportTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

Migration `up()` only:

```php
    public function up(): void
    {
        Schema::create('album_exports', function (Blueprint $table) {
            $table->id();
            $table->string('plex_playlist_id')->index();
            $table->string('title');
            $table->string('folder_path', 1024)->nullable();
            $table->string('status');
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }
```

`app/Enums/AlbumExportStatus.php`:

```php
<?php

namespace App\Enums;

enum AlbumExportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
```

`app/Models/AlbumExport.php`:

```php
<?php

namespace App\Models;

use App\Enums\AlbumExportStatus;
use Database\Factories\AlbumExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AlbumExport extends Model
{
    /** @use HasFactory<AlbumExportFactory> */
    use HasFactory;

    protected $fillable = ['plex_playlist_id', 'title', 'folder_path', 'status', 'progress', 'total', 'error', 'notified_at'];

    protected $casts = [
        'status' => AlbumExportStatus::class,
        'progress' => 'integer',
        'total' => 'integer',
        'notified_at' => 'datetime',
    ];

    /** @return Collection<string, AlbumExport> */
    public static function latestPerPlaylist(): Collection
    {
        return static::query()
            ->orderByDesc('id')
            ->get()
            ->unique('plex_playlist_id')
            ->keyBy('plex_playlist_id');
    }
}
```

`database/factories/AlbumExportFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\AlbumExportStatus;
use App\Models\AlbumExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlbumExport> */
class AlbumExportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plex_playlist_id' => (string) fake()->unique()->numberBetween(1000, 9999),
            'title' => fake()->words(2, true),
            'status' => AlbumExportStatus::Queued,
            'progress' => 0,
            'total' => 3,
        ];
    }

    public function running(): static
    {
        return $this->state(['status' => AlbumExportStatus::Running, 'progress' => 1]);
    }

    public function done(string $folderPath): static
    {
        return $this->state(['status' => AlbumExportStatus::Done, 'progress' => 3, 'folder_path' => $folderPath]);
    }

    public function failed(string $error): static
    {
        return $this->state(['status' => AlbumExportStatus::Failed, 'error' => $error]);
    }
}
```

- [ ] **Step 4: Migrate and run**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/AlbumExportTest.php`
Expected: PASS (3).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/*create_album_exports_table.php app/Enums/AlbumExportStatus.php app/Models/AlbumExport.php database/factories/AlbumExportFactory.php tests/Feature/AlbumExportTest.php
git commit -m "feat(albums): album_exports table, status enum and model"
```

---

### Task 7: `AlbumPaths` (target folder and preflight checks)

**Files:**
- Create: `app/Services/Albums/AlbumExportException.php`
- Create: `app/Services/Albums/AlbumPaths.php`
- Test: `tests/Feature/AlbumPathsTest.php`

**Interfaces:**
- Consumes: `PlexClient::musicSectionId(): int`, `PlexClient::musicSections(): Collection<int, MusicSection>` (with `location`, Task 1), `PlexPathMapper` (Task 3), `AlbumNaming::folderName()` (Task 4), `Track::$filePath`.
- Produces: `AlbumExportException extends RuntimeException`. `AlbumPaths::__construct(PlexClient $plex, PlexPathMapper $mapper)`, `compilationsFolder(): string` (throws `AlbumExportException`), `targetFolder(string $playlistTitle, string $playlistId): string`, `sourceFile(Track $track): ?string`, `problemWith(Collection $tracks): ?string` (null when everything is fine, otherwise a sentence suitable for a toast).

- [ ] **Step 1: Write the failing tests** (`tests/Feature/AlbumPathsTest.php`)

```php
<?php

use App\Services\Albums\AlbumExportException;
use App\Services\Albums\AlbumPaths;
use App\Services\Plex\Dto\MusicSection;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->share = sys_get_temp_dir().'/plexify-share-'.Str::random(8);
    File::ensureDirectoryExists("{$this->share}/Music/Src");
    config()->set('services.plex.path_from', 'M:\\');
    config()->set('services.plex.path_to', "{$this->share}/");

    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('musicSectionId')->andReturn(6);
        $mock->shouldReceive('musicSections')->andReturn(collect([new MusicSection(6, 'Music', 'M:\\Music')]));
    });
});

afterEach(fn () => File::deleteDirectory($this->share));

function pathsTrack(?string $file, string $title = 'Song'): Track
{
    return new Track(id: '1', title: $title, artist: 'A', album: 'B', trackNumber: 1, durationMs: 1, partId: 1, container: 'flac', filePath: $file);
}

it('targets Various Artists inside the active library folder', function () {
    expect(app(AlbumPaths::class)->targetFolder('Road: Trip', '42'))->toBe("{$this->share}/Music/Various Artists/Road_ Trip");
});

it('throws when the library folder is outside the mapped prefix', function () {
    config()->set('services.plex.path_from', 'Z:\\');

    app(AlbumPaths::class)->compilationsFolder();
})->throws(AlbumExportException::class);

it('has no problem with mapped existing files', function () {
    touch("{$this->share}/Music/Src/a.flac");

    expect(app(AlbumPaths::class)->problemWith(collect([pathsTrack('M:\\Music\\Src\\a.flac')])))->toBeNull();
});

it('reports an unconfigured mapping', function () {
    config()->set('services.plex.path_to', '');

    expect(app(AlbumPaths::class)->problemWith(collect([pathsTrack('M:\\a')])))->toContain('PLEX_PATH_TO');
});

it('reports an unmounted share', function () {
    config()->set('services.plex.path_to', '/Volumes/definitely-not-mounted-'.Str::random(6).'/');

    expect(app(AlbumPaths::class)->problemWith(collect([pathsTrack('M:\\a')])))->toBe('The music share is not mounted.');
});

it('reports an empty playlist', function () {
    expect(app(AlbumPaths::class)->problemWith(collect()))->toBe('The playlist is empty.');
});

it('names a track whose path cannot be mapped', function () {
    expect(app(AlbumPaths::class)->problemWith(collect([pathsTrack(null, 'Lost Song')])))->toBe('Can\'t locate the file for "Lost Song".');
});

it('names a track whose file is missing on the share', function () {
    expect(app(AlbumPaths::class)->problemWith(collect([pathsTrack('M:\\Music\\Src\\gone.flac', 'Gone')])))->toBe('The file for "Gone" is missing on the share.');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/AlbumPathsTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

`app/Services/Albums/AlbumExportException.php`:

```php
<?php

namespace App\Services\Albums;

use RuntimeException;

class AlbumExportException extends RuntimeException {}
```

`app/Services/Albums/AlbumPaths.php`:

```php
<?php

namespace App\Services\Albums;

use App\Services\Plex\Dto\Track;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;

class AlbumPaths
{
    public function __construct(
        private readonly PlexClient $plex,
        private readonly PlexPathMapper $mapper,
    ) {}

    public function compilationsFolder(): string
    {
        $sectionId = $this->plex->musicSectionId();
        $location = $this->plex->musicSections()->firstWhere('id', $sectionId)?->location;
        $localLibrary = $location === null ? null : $this->mapper->toLocal($location);

        if ($localLibrary === null) {
            throw new AlbumExportException("The music library's folder isn't covered by PLEX_PATH_FROM.");
        }

        return "{$localLibrary}/Various Artists";
    }

    public function targetFolder(string $playlistTitle, string $playlistId): string
    {
        return $this->compilationsFolder().'/'.AlbumNaming::folderName($playlistTitle, $playlistId);
    }

    public function sourceFile(Track $track): ?string
    {
        return $track->filePath === null ? null : $this->mapper->toLocal($track->filePath);
    }

    /** @param  Collection<int, Track>  $tracks */
    public function problemWith(Collection $tracks): ?string
    {
        if (! $this->mapper->isConfigured()) {
            return 'Set PLEX_PATH_FROM and PLEX_PATH_TO in .env first.';
        }

        if (! is_dir($this->mapper->localRoot())) {
            return 'The music share is not mounted.';
        }

        if ($tracks->isEmpty()) {
            return 'The playlist is empty.';
        }

        foreach ($tracks as $track) {
            $source = $this->sourceFile($track);

            if ($source === null) {
                return "Can't locate the file for \"{$track->title}\".";
            }

            if (! is_file($source)) {
                return "The file for \"{$track->title}\" is missing on the share.";
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact tests/Feature/AlbumPathsTest.php`
Expected: PASS (8).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Albums/AlbumExportException.php app/Services/Albums/AlbumPaths.php tests/Feature/AlbumPathsTest.php
git commit -m "feat(albums): resolve album target folder and preflight source files"
```

---

### Task 8: `CreateAlbumFromPlaylist` job

**Files:**
- Create: `app/Jobs/CreateAlbumFromPlaylist.php` (via `php artisan make:job CreateAlbumFromPlaylist --no-interaction`, then replace the body)
- Test: `tests/Feature/CreateAlbumFromPlaylistTest.php`

**Interfaces:**
- Consumes: `AlbumExport` / `AlbumExportStatus` (Task 6), `AlbumPaths` (Task 7), `PlexPathMapper::toRemote()` (Task 3), `AlbumNaming::fileName()` (Task 4), `FfmpegCommand::build()` (Task 5), `PlexClient::playlistTracks()`, `PlexClient::musicSectionId()`, `PlexClient::refreshSectionPath()` (Task 2).
- Produces: `new CreateAlbumFromPlaylist(int $albumExportId)`, dispatchable via `CreateAlbumFromPlaylist::dispatch($id)`. On completion the row is `done` (optional `error` note) or `failed` (with `error`).

Behaviour:
1. Load tracks; re-run `problemWith()` (state may have changed since queueing).
2. Resolve target. Previous folder = `folder_path` of the newest earlier `done` export for the same playlist. If the target exists and is not that previous folder, fail: `A folder named "X" already exists in Various Artists.`
3. Mark `running`, set `folder_path` and `total`, `progress = 0`.
4. Per track: `ffmpeg` into `storage/app/album-builds/{id}/`, `increment('progress')`. A failed process fails the export with `ffmpeg failed on "Title": <last 3 stderr lines>`.
5. Publish: copy build dir to `<Various Artists>/.plexify-build-{id}` on the share, delete the previous folder (if any), move staging to target. On any publish error delete the staging dir and fail.
6. Refresh Plex for the remote form of the target. On `PlexException` still mark `done` with `error = 'Plex was not asked to rescan. Its next scheduled scan will pick the album up.'`.
7. Always delete the local build dir. `failed()` (timeouts, killed worker) marks the row `failed` with `The job timed out or the worker stopped.` and deletes the build dir.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/CreateAlbumFromPlaylistTest.php`)

```php
<?php

use App\Enums\AlbumExportStatus;
use App\Jobs\CreateAlbumFromPlaylist;
use App\Models\AlbumExport;
use App\Services\Plex\Dto\MusicSection;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->share = sys_get_temp_dir().'/plexify-share-'.Str::random(8);
    $this->va = "{$this->share}/Music/Various Artists";
    File::ensureDirectoryExists("{$this->share}/Music/Src");
    touch("{$this->share}/Music/Src/a.flac");
    touch("{$this->share}/Music/Src/b.mp3");
    config()->set('services.plex.path_from', 'M:\\');
    config()->set('services.plex.path_to', "{$this->share}/");
    config()->set('services.ffmpeg.path', 'ffmpeg');

    $this->tracks = collect([
        new Track(id: '1', title: 'Song A', artist: 'Artist A', album: 'X', trackNumber: 5, durationMs: 1, partId: 1, container: 'flac', filePath: 'M:\\Music\\Src\\a.flac'),
        new Track(id: '2', title: 'Song B', artist: 'Artist B', album: 'Y', trackNumber: 9, durationMs: 1, partId: 2, container: 'mp3', filePath: 'M:\\Music\\Src\\b.mp3'),
    ]);
});

afterEach(fn () => File::deleteDirectory($this->share));

function mockAlbumJobPlex(Collection $tracks, ?Closure $refresh = null): void
{
    test()->mock(PlexClient::class, function ($mock) use ($tracks, $refresh) {
        $mock->shouldReceive('playlistTracks')->andReturn($tracks);
        $mock->shouldReceive('musicSectionId')->andReturn(6);
        $mock->shouldReceive('musicSections')->andReturn(collect([new MusicSection(6, 'Music', 'M:\\Music')]));
        $refresh
            ? $refresh($mock)
            : $mock->shouldReceive('refreshSectionPath')->byDefault();
    });
}

function fakeFfmpegWritingOutputs(?string $failOnSource = null): void
{
    Process::fake(function (PendingProcess $process) use ($failOnSource) {
        if ($failOnSource !== null && in_array($failOnSource, $process->command, true)) {
            return Process::result(errorOutput: "line one\nline two\nInvalid data found\n", exitCode: 1);
        }

        touch(last($process->command));

        return Process::result();
    });
}

function runAlbumJob(AlbumExport $export): AlbumExport
{
    app()->call([new CreateAlbumFromPlaylist($export->id), 'handle']);

    return $export->fresh();
}

it('writes numbered tagged MP3s into Various Artists and marks the export done', function () {
    mockAlbumJobPlex($this->tracks, fn ($mock) => $mock->shouldReceive('refreshSectionPath')->once()->with(6, 'M:\\Music\\Various Artists\\Road Trip'));
    fakeFfmpegWritingOutputs();
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip', 'total' => 2]);

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Done)
        ->and($export->progress)->toBe(2)
        ->and($export->error)->toBeNull()
        ->and($export->folder_path)->toBe("{$this->va}/Road Trip")
        ->and(collect(File::files("{$this->va}/Road Trip"))->map->getFilename()->sort()->values()->all())
        ->toBe(['01 - Artist A - Song A.mp3', '02 - Artist B - Song B.mp3'])
        ->and(File::directories($this->va))->toBe(["{$this->va}/Road Trip"])
        ->and(glob("{$this->va}/.plexify-build-*"))->toBe([])
        ->and(File::isDirectory(storage_path("app/album-builds/{$export->id}")))->toBeFalse();

    Process::assertRan(fn (PendingProcess $p) => in_array("{$this->share}/Music/Src/a.flac", $p->command, true)
        && in_array('libmp3lame', $p->command, true)
        && in_array('album=Road Trip', $p->command, true)
        && in_array('album_artist=Various Artists', $p->command, true)
        && in_array('compilation=1', $p->command, true)
        && in_array('track=1/2', $p->command, true)
        && in_array('date='.now()->year, $p->command, true));

    Process::assertRan(fn (PendingProcess $p) => in_array("{$this->share}/Music/Src/b.mp3", $p->command, true)
        && in_array('track=2/2', $p->command, true)
        && ! in_array('libmp3lame', $p->command, true));
});

it('fails with the ffmpeg error and leaves the previous album untouched', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs(failOnSource: "{$this->share}/Music/Src/b.mp3");
    File::ensureDirectoryExists("{$this->va}/Road Trip");
    touch("{$this->va}/Road Trip/old.mp3");
    AlbumExport::factory()->done("{$this->va}/Road Trip")->create(['plex_playlist_id' => '42']);
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Failed)
        ->and($export->error)->toBe('ffmpeg failed on "Song B": line one line two Invalid data found')
        ->and(File::exists("{$this->va}/Road Trip/old.mp3"))->toBeTrue()
        ->and(File::directories($this->va))->toBe(["{$this->va}/Road Trip"])
        ->and(glob("{$this->va}/.plexify-build-*"))->toBe([])
        ->and(File::isDirectory(storage_path("app/album-builds/{$export->id}")))->toBeFalse();
});

it('replaces the previous album folder on recreate', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    File::ensureDirectoryExists("{$this->va}/Road Trip");
    touch("{$this->va}/Road Trip/old.mp3");
    AlbumExport::factory()->done("{$this->va}/Road Trip")->create(['plex_playlist_id' => '42']);
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    expect(runAlbumJob($export)->status)->toBe(AlbumExportStatus::Done)
        ->and(File::exists("{$this->va}/Road Trip/old.mp3"))->toBeFalse()
        ->and(File::exists("{$this->va}/Road Trip/01 - Artist A - Song A.mp3"))->toBeTrue();
});

it('moves the album when the playlist was renamed since the last export', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    File::ensureDirectoryExists("{$this->va}/Old Name");
    touch("{$this->va}/Old Name/old.mp3");
    AlbumExport::factory()->done("{$this->va}/Old Name")->create(['plex_playlist_id' => '42']);
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'New Name']);

    expect(runAlbumJob($export)->status)->toBe(AlbumExportStatus::Done)
        ->and(File::directories($this->va))->toBe(["{$this->va}/New Name"]);
});

it('refuses to overwrite a folder it did not create', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    File::ensureDirectoryExists("{$this->va}/Road Trip");
    touch("{$this->va}/Road Trip/hand-made.mp3");
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Failed)
        ->and($export->error)->toBe('A folder named "Road Trip" already exists in Various Artists.')
        ->and(File::exists("{$this->va}/Road Trip/hand-made.mp3"))->toBeTrue();
    Process::assertNothingRan();
});

it('still finishes when Plex cannot be asked to rescan', function () {
    mockAlbumJobPlex($this->tracks, fn ($mock) => $mock->shouldReceive('refreshSectionPath')->andThrow(new PlexUnreachableException('down')));
    fakeFfmpegWritingOutputs();
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Done)
        ->and($export->error)->toBe('Plex was not asked to rescan. Its next scheduled scan will pick the album up.');
});

it('fails up front when a source file has gone missing', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    unlink("{$this->share}/Music/Src/b.mp3");
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Failed)
        ->and($export->error)->toBe('The file for "Song B" is missing on the share.');
    Process::assertNothingRan();
});

it('marks the export failed when the worker gives up on the job', function () {
    $export = AlbumExport::factory()->running()->create();
    File::ensureDirectoryExists(storage_path("app/album-builds/{$export->id}"));

    (new CreateAlbumFromPlaylist($export->id))->failed(new RuntimeException('timed out'));

    expect($export->fresh()->status)->toBe(AlbumExportStatus::Failed)
        ->and($export->fresh()->error)->toBe('The job timed out or the worker stopped.')
        ->and(File::isDirectory(storage_path("app/album-builds/{$export->id}")))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/CreateAlbumFromPlaylistTest.php`
Expected: FAIL, class `App\Jobs\CreateAlbumFromPlaylist` not found.

- [ ] **Step 3: Implement** (`app/Jobs/CreateAlbumFromPlaylist.php`)

```php
<?php

namespace App\Jobs;

use App\Enums\AlbumExportStatus;
use App\Models\AlbumExport;
use App\Services\Albums\AlbumExportException;
use App\Services\Albums\AlbumNaming;
use App\Services\Albums\AlbumPaths;
use App\Services\Albums\FfmpegCommand;
use App\Services\Albums\PlexPathMapper;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

class CreateAlbumFromPlaylist implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $albumExportId) {}

    public function handle(PlexClient $plex, AlbumPaths $paths, PlexPathMapper $mapper): void
    {
        $export = AlbumExport::findOrFail($this->albumExportId);

        try {
            $this->build($export, $plex, $paths, $mapper);
        } catch (AlbumExportException $e) {
            $export->update(['status' => AlbumExportStatus::Failed, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);
            $export->update(['status' => AlbumExportStatus::Failed, 'error' => 'Unexpected error: '.$e->getMessage()]);
        } finally {
            File::deleteDirectory($this->buildDir());
        }
    }

    public function failed(?Throwable $exception): void
    {
        AlbumExport::find($this->albumExportId)?->update([
            'status' => AlbumExportStatus::Failed,
            'error' => 'The job timed out or the worker stopped.',
        ]);

        File::deleteDirectory($this->buildDir());
    }

    private function build(AlbumExport $export, PlexClient $plex, AlbumPaths $paths, PlexPathMapper $mapper): void
    {
        $tracks = $plex->playlistTracks($export->plex_playlist_id)->values();
        $problem = $paths->problemWith($tracks);

        if ($problem !== null) {
            throw new AlbumExportException($problem);
        }

        $target = $paths->targetFolder($export->title, $export->plex_playlist_id);
        $previous = $this->previousFolder($export);

        if (File::isDirectory($target) && $target !== $previous) {
            throw new AlbumExportException('A folder named "'.basename($target).'" already exists in Various Artists.');
        }

        $export->update([
            'status' => AlbumExportStatus::Running,
            'folder_path' => $target,
            'total' => $tracks->count(),
            'progress' => 0,
        ]);

        File::ensureDirectoryExists($this->buildDir());

        foreach ($tracks as $index => $track) {
            $this->encode($export, $paths, $track, $index + 1, $tracks->count());
            $export->increment('progress');
        }

        $this->publish($export, $target, $previous);

        $export->update([
            'status' => AlbumExportStatus::Done,
            'error' => $this->refreshPlex($plex, $mapper, $target),
        ]);
    }

    private function encode(AlbumExport $export, AlbumPaths $paths, Track $track, int $position, int $total): void
    {
        $output = $this->buildDir().'/'.AlbumNaming::fileName($position, $total, $track->artist, $track->title);

        $command = FfmpegCommand::build(
            (string) config('services.ffmpeg.path'),
            (string) $paths->sourceFile($track),
            $output,
            [
                'title' => $track->title,
                'artist' => $track->artist !== '' ? $track->artist : 'Unknown Artist',
                'album' => $export->title,
                'album_artist' => 'Various Artists',
                'compilation' => '1',
                'track' => "{$position}/{$total}",
                'date' => (string) now()->year,
            ],
            remux: strtolower($track->container) === 'mp3',
        );

        $result = Process::timeout(600)->run($command);

        if ($result->failed()) {
            $stderr = collect(explode("\n", trim($result->errorOutput())))->take(-3)->implode(' ');

            throw new AlbumExportException("ffmpeg failed on \"{$track->title}\": {$stderr}");
        }
    }

    private function publish(AlbumExport $export, string $target, ?string $previous): void
    {
        $staging = dirname($target)."/.plexify-build-{$export->id}";

        try {
            File::deleteDirectory($staging);

            if (! File::copyDirectory($this->buildDir(), $staging)) {
                throw new AlbumExportException('Copying the album to the music share failed.');
            }

            if ($previous !== null) {
                File::deleteDirectory($previous);
            }

            if (! File::moveDirectory($staging, $target)) {
                throw new AlbumExportException('Moving the album into place on the music share failed.');
            }
        } catch (Throwable $e) {
            File::deleteDirectory($staging);

            throw $e;
        }
    }

    private function refreshPlex(PlexClient $plex, PlexPathMapper $mapper, string $target): ?string
    {
        try {
            $plex->refreshSectionPath($plex->musicSectionId(), (string) $mapper->toRemote($target));
        } catch (PlexException) {
            return 'Plex was not asked to rescan. Its next scheduled scan will pick the album up.';
        }

        return null;
    }

    private function previousFolder(AlbumExport $export): ?string
    {
        return AlbumExport::query()
            ->where('plex_playlist_id', $export->plex_playlist_id)
            ->where('status', AlbumExportStatus::Done)
            ->where('id', '<', $export->id)
            ->latest('id')
            ->value('folder_path');
    }

    private function buildDir(): string
    {
        return storage_path("app/album-builds/{$this->albumExportId}");
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact tests/Feature/CreateAlbumFromPlaylistTest.php`
Expected: PASS (8). `File::directories()` skips dot directories, which is why the staging leak is checked separately with `glob(".plexify-build-*")`. A non-empty glob is a real leak: the staging dir must be gone after a successful or failed publish.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/CreateAlbumFromPlaylist.php tests/Feature/CreateAlbumFromPlaylistTest.php
git commit -m "feat(albums): queued job that builds a Various Artists album from a playlist"
```

---

### Task 9: Success style for toasts

**Files:**
- Modify: `resources/views/components/⚡toasts.blade.php`
- Test: `tests/Feature/ToastsTest.php`

**Interfaces:**
- Produces: `notify` with `type: 'success'` renders a `data-toast-type="success"` toast with an accent check icon; anything else keeps the red alert icon.

- [ ] **Step 1: Write the failing tests** (append to `tests/Feature/ToastsTest.php`)

```php
it('renders a success toast with the success style', function () {
    Livewire::test('toasts')
        ->dispatch('notify', type: 'success', message: 'Album created.')
        ->assertSee('Album created.')
        ->assertSeeHtml('data-toast-type="success"');
});

it('keeps the error style for error toasts', function () {
    Livewire::test('toasts')
        ->dispatch('notify', type: 'error', message: 'Nope.')
        ->assertSeeHtml('data-toast-type="error"');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/ToastsTest.php`
Expected: the two new tests FAIL.

- [ ] **Step 3: Implement**

In the toast `<div wire:key=...>` add `data-toast-type="{{ $toast['type'] === 'success' ? 'success' : 'error' }}"` and replace the single icon line with:

```blade
            @if ($toast['type'] === 'success')
                <x-lucide-circle-check class="w-4 h-4 text-accent flex-none mt-0.5" />
            @else
                <x-lucide-circle-alert class="w-4 h-4 text-red-400 flex-none mt-0.5" />
            @endif
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --compact tests/Feature/ToastsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add "resources/views/components/⚡toasts.blade.php" tests/Feature/ToastsTest.php
git commit -m "feat(toasts): success toast style"
```

---

### Task 10: Sidebar `createAlbum` action and completion toasts

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php` (PHP class only in this task)
- Test: `tests/Feature/SidebarAlbumExportTest.php` (new file, its own helpers; `SidebarTest.php` helpers are not loaded when this file runs alone)

**Interfaces:**
- Consumes: `AlbumExport::latestPerPlaylist()`, `AlbumExportStatus`, `AlbumPaths::problemWith()`, `CreateAlbumFromPlaylist::dispatch(int)`.
- Produces (used by Task 11's Blade): `#[Computed] albumExports(): Collection<string, AlbumExport>`, `#[Computed] hasActiveAlbumExport(): bool`, action `createAlbum(string $playlistId): bool`, action `announceFinishedAlbumExports(): void` (also called from `mount()`).

- [ ] **Step 1: Write the failing tests** (`tests/Feature/SidebarAlbumExportTest.php`)

```php
<?php

use App\Enums\AlbumExportStatus;
use App\Jobs\CreateAlbumFromPlaylist;
use App\Models\AlbumExport;
use App\Services\Plex\Dto\MusicSection;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->share = sys_get_temp_dir().'/plexify-share-'.Str::random(8);
    File::ensureDirectoryExists("{$this->share}/Music/Src");
    touch("{$this->share}/Music/Src/a.flac");
    config()->set('services.plex.path_from', 'M:\\');
    config()->set('services.plex.path_to', "{$this->share}/");
});

afterEach(fn () => File::deleteDirectory($this->share));

function mockAlbumSidebarPlex(?Closure $tracks = null): void
{
    test()->mock(PlexClient::class, function ($mock) use ($tracks) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([
            new Playlist(id: '42', title: 'Road Trip', trackCount: 1, durationMs: 0, thumb: null, playlistType: 'audio'),
        ]));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn(['name' => 'HOME', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'abc']);
        $mock->shouldReceive('musicSectionTitle')->andReturn('Music');
        $mock->shouldReceive('musicSectionId')->andReturn(6);
        $mock->shouldReceive('musicSections')->andReturn(collect([new MusicSection(6, 'Music', 'M:\\Music')]));

        $tracks
            ? $tracks($mock)
            : $mock->shouldReceive('playlistTracks')->andReturn(collect([
                new Track(id: '1', title: 'Song A', artist: 'A', album: 'X', trackNumber: 1, durationMs: 1, partId: 1, container: 'flac', filePath: 'M:\\Music\\Src\\a.flac'),
            ]));
    });
}

it('queues an album export for a playlist', function () {
    mockAlbumSidebarPlex();

    Livewire::test('sidebar')->call('createAlbum', '42')->assertReturned(true);

    $export = AlbumExport::sole();
    expect($export->plex_playlist_id)->toBe('42')
        ->and($export->title)->toBe('Road Trip')
        ->and($export->status)->toBe(AlbumExportStatus::Queued)
        ->and($export->total)->toBe(1);
    Queue::assertPushed(CreateAlbumFromPlaylist::class, fn ($job) => $job->albumExportId === $export->id);
});

it('refuses a second export while one is in flight', function () {
    mockAlbumSidebarPlex();
    AlbumExport::factory()->running()->create(['plex_playlist_id' => '42']);

    Livewire::test('sidebar')
        ->call('createAlbum', '42')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error', message: 'An album for this playlist is already being created.');

    Queue::assertNothingPushed();
});

it('refuses when a source file is missing and names the track', function () {
    mockAlbumSidebarPlex(fn ($mock) => $mock->shouldReceive('playlistTracks')->andReturn(collect([
        new Track(id: '1', title: 'Gone', artist: 'A', album: 'X', trackNumber: 1, durationMs: 1, partId: 1, container: 'flac', filePath: 'M:\\Music\\Src\\gone.flac'),
    ])));

    Livewire::test('sidebar')
        ->call('createAlbum', '42')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error', message: 'Couldn\'t create album: The file for "Gone" is missing on the share.');

    expect(AlbumExport::count())->toBe(0);
});

it('refuses when Plex is unreachable', function () {
    mockAlbumSidebarPlex(fn ($mock) => $mock->shouldReceive('playlistTracks')->andThrow(new PlexUnreachableException('down')));

    Livewire::test('sidebar')
        ->call('createAlbum', '42')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error', message: 'Couldn\'t load the playlist. Plex may be unreachable.');
});

it('refuses an unknown playlist', function () {
    mockAlbumSidebarPlex();

    Livewire::test('sidebar')
        ->call('createAlbum', '999')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error', message: 'That playlist no longer exists.');
});

it('announces a finished export once', function () {
    mockAlbumSidebarPlex();
    $export = AlbumExport::factory()->done('/x')->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    Livewire::test('sidebar')->assertDispatched('notify', type: 'success', message: 'Album "Road Trip" created.');

    expect($export->fresh()->notified_at)->not->toBeNull();

    Livewire::test('sidebar')->assertNotDispatched('notify');
});

it('announces a failed export with its reason', function () {
    mockAlbumSidebarPlex();
    AlbumExport::factory()->failed('ffmpeg exploded')->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    Livewire::test('sidebar')->assertDispatched('notify', type: 'error', message: 'Couldn\'t create album "Road Trip": ffmpeg exploded');
});

it('does not announce an export that is still running', function () {
    mockAlbumSidebarPlex();
    AlbumExport::factory()->running()->create(['plex_playlist_id' => '42']);

    Livewire::test('sidebar')->assertNotDispatched('notify');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/SidebarAlbumExportTest.php`
Expected: FAIL, "Unable to call component method createAlbum" and missing dispatches.

- [ ] **Step 3: Implement** (sidebar PHP class)

Add imports: `App\Enums\AlbumExportStatus`, `App\Jobs\CreateAlbumFromPlaylist`, `App\Models\AlbumExport`, `App\Services\Albums\AlbumPaths`.

After `boot()`:

```php
    public function mount(): void
    {
        $this->announceFinishedAlbumExports();
    }
```

After the `rootPlacements()` computed:

```php
    /** @return Collection<string, AlbumExport> */
    #[Computed]
    public function albumExports(): Collection
    {
        return AlbumExport::latestPerPlaylist();
    }

    #[Computed]
    public function hasActiveAlbumExport(): bool
    {
        return $this->albumExports->contains(fn (AlbumExport $export) => $export->status->isActive());
    }
```

After `playPlaylist()`:

```php
    public function createAlbum(string $playlistId): bool
    {
        if ($this->albumExports->get($playlistId)?->status->isActive()) {
            $this->dispatch('notify', type: 'error', message: 'An album for this playlist is already being created.');

            return false;
        }

        $playlist = $this->playlists->firstWhere('id', $playlistId);

        if ($playlist === null) {
            $this->dispatch('notify', type: 'error', message: 'That playlist no longer exists.');

            return false;
        }

        try {
            $tracks = $this->plex->playlistTracks($playlistId)->values();
        } catch (PlexException $e) {
            Log::channel('plex')->warning('createAlbum failed', ['playlist' => $playlistId, 'error' => $e->getMessage()]);
            $this->dispatch('notify', type: 'error', message: 'Couldn\'t load the playlist. Plex may be unreachable.');

            return false;
        }

        $problem = app(AlbumPaths::class)->problemWith($tracks);

        if ($problem !== null) {
            $this->dispatch('notify', type: 'error', message: "Couldn't create album: {$problem}");

            return false;
        }

        $export = AlbumExport::create([
            'plex_playlist_id' => $playlistId,
            'title' => $playlist->title,
            'status' => AlbumExportStatus::Queued,
            'total' => $tracks->count(),
        ]);

        CreateAlbumFromPlaylist::dispatch($export->id);
        unset($this->albumExports);

        return true;
    }

    public function announceFinishedAlbumExports(): void
    {
        AlbumExport::query()
            ->whereIn('status', [AlbumExportStatus::Done, AlbumExportStatus::Failed])
            ->whereNull('notified_at')
            ->get()
            ->each(function (AlbumExport $export) {
                $export->status === AlbumExportStatus::Done
                    ? $this->dispatch('notify', type: 'success', message: "Album \"{$export->title}\" created.")
                    : $this->dispatch('notify', type: 'error', message: "Couldn't create album \"{$export->title}\": {$export->error}");

                $export->update(['notified_at' => now()]);
            });

        unset($this->albumExports);
    }
```

- [ ] **Step 4: Run to verify pass, plus the existing sidebar suite**

Run: `php artisan test --compact tests/Feature/SidebarAlbumExportTest.php tests/Feature/SidebarTest.php tests/Feature/RoutesTest.php`
Expected: PASS. `SidebarTest`/`RoutesTest` should be unaffected (no exports exist, `mount()` only queries the empty table).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/components/⚡sidebar.blade.php" tests/Feature/SidebarAlbumExportTest.php
git commit -m "feat(sidebar): queue album exports and announce when they finish"
```

---

### Task 11: Sidebar UI (menu entry, progress subtitle, polling)

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php` (Blade: `openMenu`, both `@include('partials.playlist-row', ...)` calls, playlist context menu, aside root)
- Modify: `resources/views/partials/playlist-row.blade.php`
- Test: `tests/Feature/SidebarAlbumExportTest.php`

**Interfaces:**
- Consumes: `albumExports`, `hasActiveAlbumExport`, `createAlbum()`, `announceFinishedAlbumExports()` from Task 10.
- Produces: playlist row `<a>` carries `data-album-done="1|0"`; row subtitle `Creating album… {progress}/{total}` with `data-album-progress`; menu button `data-action="create-album"`; hidden `<div data-album-poll wire:poll.2s="announceFinishedAlbumExports">` rendered only while an export is active.

Note the Alpine gotchas in CLAUDE.md: the menu label must not be a server literal inside an Alpine expression. The row carries a plain `data-album-done` attribute (Livewire morphs it), and `openMenu` reads it at event time into the reactive `menu` object. `wire:poll` lives on its own element rendered by `@if`, so it is inserted and removed as a whole element instead of an attribute toggled on an existing node.

- [ ] **Step 1: Write the failing tests** (append to `tests/Feature/SidebarAlbumExportTest.php`)

```php
it('offers Create album in the playlist menu', function () {
    mockAlbumSidebarPlex();

    Livewire::test('sidebar')
        ->assertSeeHtml('data-action="create-album"')
        ->assertSeeHtml('@click="$wire.createAlbum(menu.id); menu = null"')
        ->assertSeeHtml('data-album-done="0"');
});

it('flags a playlist whose album exists so the menu reads Recreate album', function () {
    mockAlbumSidebarPlex();
    AlbumExport::factory()->done('/x')->create(['plex_playlist_id' => '42', 'notified_at' => now()]);

    Livewire::test('sidebar')
        ->assertSeeHtml('data-album-done="1"')
        ->assertSeeHtml("menu?.albumDone ? 'Recreate album' : 'Create album'");
});

it('shows progress on the row and polls while an export runs', function () {
    mockAlbumSidebarPlex();
    AlbumExport::factory()->running()->create(['plex_playlist_id' => '42', 'progress' => 1, 'total' => 3]);

    Livewire::test('sidebar')
        ->assertSee('Creating album… 1/3')
        ->assertDontSee('Playlist · 1 songs')
        ->assertSeeHtml('wire:poll.2s="announceFinishedAlbumExports"');
});

it('does not poll when nothing is running', function () {
    mockAlbumSidebarPlex();

    Livewire::test('sidebar')
        ->assertSee('Playlist · 1 songs')
        ->assertDontSeeHtml('data-album-poll');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/SidebarAlbumExportTest.php`
Expected: the 4 new tests FAIL.

- [ ] **Step 3: Implement**

`openMenu` in the playlists card `x-data` becomes:

```js
            openMenu(e, kind, id) { e.preventDefault(); e.stopPropagation(); this.menu = { x: e.clientX, y: e.clientY, kind, id, albumDone: e.currentTarget?.dataset?.albumDone === '1' }; },
```

Both `@include('partials.playlist-row', [...])` calls gain `'albumExport' => $this->albumExports->get($p->id)`.

In the playlist context menu, directly after the `Play` button:

```blade
                <button type="button" data-action="create-album"
                        class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10"
                        x-text="menu?.albumDone ? 'Recreate album' : 'Create album'"
                        @click="$wire.createAlbum(menu.id); menu = null">Create album</button>
```

Directly inside the `<aside ...>` root, as its first child:

```blade
    @if ($this->hasActiveAlbumExport)
        <div data-album-poll wire:poll.2s="announceFinishedAlbumExports" class="hidden"></div>
    @endif
```

`resources/views/partials/playlist-row.blade.php`:
- Update the header comment's "Expects:" line to add `$albumExport (?App\Models\AlbumExport, the playlist's latest export)`.
- On the `<a href=...>` add `data-album-done="{{ $albumExport?->status === \App\Enums\AlbumExportStatus::Done ? '1' : '0' }}"`.
- Replace the subtitle line with:

```blade
                @if ($albumExport?->status->isActive())
                    <div data-album-progress class="text-[12px] text-text-3 truncate">Creating album… {{ $albumExport->progress }}/{{ $albumExport->total }}</div>
                @else
                    <div class="text-[12px] text-text-3 truncate">Playlist · {{ $p->trackCount }} songs</div>
                @endif
```

- [ ] **Step 4: Run to verify pass, plus neighbours**

Run: `php artisan test --compact tests/Feature/SidebarAlbumExportTest.php tests/Feature/SidebarTest.php tests/Feature/RoutesTest.php`
Expected: PASS.

- [ ] **Step 5: Build assets and commit**

Run: `npm run build`

```bash
git add "resources/views/components/⚡sidebar.blade.php" resources/views/partials/playlist-row.blade.php tests/Feature/SidebarAlbumExportTest.php
git commit -m "feat(sidebar): Create/Recreate album menu entry with live progress"
```

---

### Task 12: Worker, local config, docs, full suite

**Files:**
- Modify: `solo.yml`
- Modify: `.env` (local only, gitignored)
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add the queue worker to `solo.yml`** (same shape as the existing entries)

```yaml
  Queue:
    command: php artisan queue:work --timeout=1800 --tries=1
    working_dir: null
    auto_start: true
    auto_restart: true
    restart_when_changed: []
    env: {}
```

- [ ] **Step 2: Configure the local `.env`** (after `PLEX_BASE_URL=`)

```
PLEX_PATH_FROM=M:\
PLEX_PATH_TO=/Volumes/music_ssd/
FFMPEG_PATH=/opt/homebrew/bin/ffmpeg
```

Verify: `php artisan config:show services.plex` shows `path_from` as `M:\` (single backslash) and `path_to` as `/Volumes/music_ssd/`. Then `php artisan cache:clear` (Task 1 changed cached DTOs).

- [ ] **Step 3: Document in `CLAUDE.md`**

Add a "Playlist to album" paragraph after the library picker paragraph (no em or en dashes), covering: the spec and plan paths; the sidebar menu entry; `album_exports` and the `CreateAlbumFromPlaylist` job; `PLEX_PATH_FROM`/`PLEX_PATH_TO`/`FFMPEG_PATH`; the build locally, stage as hidden `.plexify-build-{id}` on the share, then swap flow; that only a previous `done` export's folder is ever deleted and a foreign folder with the same name is refused; the `Queue` solo process (without it exports sit at `queued`); the new tests. Add a Gotchas bullet: "`Track::$filePath` and `MusicSection::$location` are Plex host (Windows) paths, never local ones. Always go through `PlexPathMapper`." Add `tests/Unit/PlexPathMapperTest.php`, `AlbumNamingTest.php`, `FfmpegCommandTest.php` and the new Feature tests to the Test layout section.

- [ ] **Step 4: Full suite**

Run: `vendor/bin/pint --dirty --format agent && php artisan test --compact`
Expected: all green (browser tests need the live Plex server). Report any failure verbatim; do not mark this task done with failures.

- [ ] **Step 5: Commit**

```bash
git add solo.yml CLAUDE.md
git commit -m "chore(albums): queue worker process and project docs"
```

- [ ] **Step 6: Hand off for manual verification**

Ask the user to start the `Queue` process in Solo, right click a small playlist, choose "Create album", and check the result in Plex (album under Various Artists, track order, MP3 320k). Do not run an export against the real share yourself.
