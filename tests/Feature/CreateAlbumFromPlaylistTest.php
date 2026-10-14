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
