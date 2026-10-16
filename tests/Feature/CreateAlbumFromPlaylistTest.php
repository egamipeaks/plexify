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

function fakeFfmpegWritingOutputs(?string $failOnSource = null, string $measuredI = '-20.50', string $targetOffset = '0.20', ?string $measureOutput = null): void
{
    Process::fake(function (PendingProcess $process) use ($failOnSource, $measuredI, $targetOffset, $measureOutput) {
        if ($failOnSource !== null && in_array($failOnSource, $process->command, true)) {
            return Process::result(errorOutput: "line one\nline two\nInvalid data found\n", exitCode: 1);
        }

        if (last($process->command) === '-' && $measureOutput !== null) {
            return Process::result(errorOutput: $measureOutput);
        }

        if (last($process->command) === '-') {
            return Process::result(errorOutput: "[Parsed_loudnorm_0 @ 0x1]\n{\n\t\"input_i\" : \"{$measuredI}\",\n\t\"input_tp\" : \"-3.25\",\n\t\"input_lra\" : \"6.10\",\n\t\"input_thresh\" : \"-30.70\",\n\t\"target_offset\" : \"{$targetOffset}\"\n}\n");
        }

        touch(last($process->command));

        return Process::result();
    });
}

function markAsPlexifyAlbum(string $folder, string $playlistId = '42', int $exportId = 1): void
{
    file_put_contents("{$folder}/.plexify-album", json_encode(['plex_playlist_id' => $playlistId, 'album_export_id' => $exportId]));
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
        && in_array('libmp3lame', $p->command, true)
        && in_array('loudnorm=I=-14:TP=-1:LRA=20:measured_I=-20.5:measured_TP=-3.25:measured_LRA=6.1:measured_thresh=-30.7:offset=0.2:linear=true', $p->command, true));

    Process::assertRanTimes(fn (PendingProcess $p) => last($p->command) === '-', 2);
});

it('encodes a silent track without the loudness filter', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs(measuredI: '-inf', targetOffset: 'inf');
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Done);

    Process::assertDidntRun(fn (PendingProcess $p) => last($p->command) !== '-' && in_array('-af', $p->command, true));
});

it('fails naming the track when the loudness measurement is unreadable', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs(measureOutput: "Input #0\nno loudnorm output\n");
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Failed)
        ->and($export->error)->toBe('Could not read the loudness of "Song A".')
        ->and(File::isDirectory("{$this->va}/Road Trip"))->toBeFalse();
});

it('uses the configured loudness target', function () {
    config()->set('services.ffmpeg.loudness_target', -16);
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    runAlbumJob($export);

    Process::assertRan(fn (PendingProcess $p) => last($p->command) === '-'
        && in_array('loudnorm=I=-16:TP=-1:LRA=20:print_format=json', $p->command, true));
});

it('fails with the ffmpeg error and leaves the previous album untouched', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs(failOnSource: "{$this->share}/Music/Src/b.mp3");
    File::ensureDirectoryExists("{$this->va}/Road Trip");
    touch("{$this->va}/Road Trip/old.mp3");
    markAsPlexifyAlbum("{$this->va}/Road Trip");
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
    markAsPlexifyAlbum("{$this->va}/Road Trip");
    AlbumExport::factory()->done("{$this->va}/Road Trip")->create(['plex_playlist_id' => '42']);
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    expect(runAlbumJob($export)->status)->toBe(AlbumExportStatus::Done)
        ->and(File::exists("{$this->va}/Road Trip/old.mp3"))->toBeFalse()
        ->and(File::exists("{$this->va}/Road Trip/01 - Artist A - Song A.mp3"))->toBeTrue()
        ->and(glob("{$this->va}/.plexify-*"))->toBe([]);
});

it('finishes done with a note when the old album copy cannot be removed', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    File::ensureDirectoryExists("{$this->va}/Road Trip/locked");
    touch("{$this->va}/Road Trip/locked/old.mp3");
    chmod("{$this->va}/Road Trip/locked", 0555);
    markAsPlexifyAlbum("{$this->va}/Road Trip");
    AlbumExport::factory()->done("{$this->va}/Road Trip")->create(['plex_playlist_id' => '42']);
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    $export = runAlbumJob($export);
    chmod("{$this->va}/.plexify-old-{$export->id}/locked", 0755);

    expect($export->status)->toBe(AlbumExportStatus::Done)
        ->and($export->error)->toBe("The old album copy could not be fully removed: {$this->va}/.plexify-old-{$export->id}")
        ->and(File::exists("{$this->va}/Road Trip/01 - Artist A - Song A.mp3"))->toBeTrue();
});

it('moves the album when the playlist was renamed since the last export', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    File::ensureDirectoryExists("{$this->va}/Old Name");
    touch("{$this->va}/Old Name/old.mp3");
    markAsPlexifyAlbum("{$this->va}/Old Name");
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

it('restores the previous album when moving the new one into place fails', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    File::ensureDirectoryExists("{$this->va}/Old Name");
    touch("{$this->va}/Old Name/old.mp3");
    markAsPlexifyAlbum("{$this->va}/Old Name");
    touch("{$this->va}/New Name");
    AlbumExport::factory()->done("{$this->va}/Old Name")->create(['plex_playlist_id' => '42']);
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'New Name']);

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Failed)
        ->and(File::exists("{$this->va}/Old Name/old.mp3"))->toBeTrue()
        ->and(glob("{$this->va}/.plexify-*"))->toBe([]);
});

it('never deletes a previous export folder outside Various Artists', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    File::ensureDirectoryExists("{$this->share}/Music/Other/Road Trip");
    touch("{$this->share}/Music/Other/Road Trip/keep.mp3");
    File::ensureDirectoryExists("{$this->va}/Road Trip");
    touch("{$this->va}/Road Trip/hand-made.mp3");
    AlbumExport::factory()->done("{$this->share}/Music/Other/Road Trip")->create(['plex_playlist_id' => '42']);
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Failed)
        ->and(File::exists("{$this->share}/Music/Other/Road Trip/keep.mp3"))->toBeTrue()
        ->and(File::exists("{$this->va}/Road Trip/hand-made.mp3"))->toBeTrue();
});

it('does not retire a previous folder without an ownership marker', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    File::ensureDirectoryExists("{$this->va}/Road Trip");
    touch("{$this->va}/Road Trip/hand-made.mp3");
    AlbumExport::factory()->done("{$this->va}/Road Trip")->create(['plex_playlist_id' => '42']);
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Failed)
        ->and($export->error)->toBe('A folder named "Road Trip" already exists in Various Artists.')
        ->and(File::exists("{$this->va}/Road Trip/hand-made.mp3"))->toBeTrue();
});

it('leaves an unmarked folder at the previous path alone when the playlist was renamed', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    File::ensureDirectoryExists("{$this->va}/Old Name");
    touch("{$this->va}/Old Name/hand-made.mp3");
    AlbumExport::factory()->done("{$this->va}/Old Name")->create(['plex_playlist_id' => '42']);
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'New Name']);

    expect(runAlbumJob($export)->status)->toBe(AlbumExportStatus::Done)
        ->and(File::exists("{$this->va}/Old Name/hand-made.mp3"))->toBeTrue()
        ->and(File::isDirectory("{$this->va}/New Name"))->toBeTrue();
});

it('leaves a previous folder alone when its marker names another playlist', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    File::ensureDirectoryExists("{$this->va}/Road Trip");
    touch("{$this->va}/Road Trip/theirs.mp3");
    markAsPlexifyAlbum("{$this->va}/Road Trip", '99');
    AlbumExport::factory()->done("{$this->va}/Road Trip")->create(['plex_playlist_id' => '42']);
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Failed)
        ->and($export->error)->toBe('A folder named "Road Trip" already exists in Various Artists.')
        ->and(File::exists("{$this->va}/Road Trip/theirs.mp3"))->toBeTrue();
});

it('writes an ownership marker into the finished album folder', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    runAlbumJob($export);

    expect(json_decode(file_get_contents("{$this->va}/Road Trip/.plexify-album"), true))
        ->toBe(['plex_playlist_id' => '42', 'album_export_id' => $export->id]);
});

it('says where the previous album was left when restoring it fails', function () {
    mockAlbumJobPlex($this->tracks);
    fakeFfmpegWritingOutputs();
    File::ensureDirectoryExists("{$this->va}/Old Name");
    touch("{$this->va}/Old Name/old.mp3");
    markAsPlexifyAlbum("{$this->va}/Old Name");
    touch("{$this->va}/New Name");
    AlbumExport::factory()->done("{$this->va}/Old Name")->create(['plex_playlist_id' => '42']);
    $export = AlbumExport::factory()->create(['plex_playlist_id' => '42', 'title' => 'New Name']);
    File::partialMock()->shouldReceive('moveDirectory')->andReturnUsing(
        fn (string $from, string $to) => ! str_contains($from, '.plexify-old-') && @rename($from, $to)
    );

    $export = runAlbumJob($export);

    expect($export->status)->toBe(AlbumExportStatus::Failed)
        ->and($export->error)->toBe("Moving the album into place on the music share failed. The previous album was left at {$this->va}/.plexify-old-{$export->id}.");
});

it('says where the previous album was left when the worker gives up mid publish', function () {
    $export = AlbumExport::factory()->running()->create(['folder_path' => "{$this->va}/Road Trip"]);
    File::ensureDirectoryExists("{$this->va}/.plexify-old-{$export->id}");

    (new CreateAlbumFromPlaylist($export->id))->failed(new RuntimeException('timed out'));

    expect($export->fresh()->error)->toBe("The job timed out or the worker stopped. The previous album was left at {$this->va}/.plexify-old-{$export->id}.");
});

it('removes its staging folder from the share when the worker gives up', function () {
    $export = AlbumExport::factory()->running()->create(['folder_path' => "{$this->va}/Road Trip"]);
    File::ensureDirectoryExists("{$this->va}/.plexify-build-{$export->id}");
    File::ensureDirectoryExists("{$this->va}/.plexify-build-999");

    (new CreateAlbumFromPlaylist($export->id))->failed(new RuntimeException('timed out'));

    expect(File::isDirectory("{$this->va}/.plexify-build-{$export->id}"))->toBeFalse()
        ->and(File::isDirectory("{$this->va}/.plexify-build-999"))->toBeTrue();
});

it('keeps the queue retry_after longer than the job timeout', function () {
    expect(config('queue.connections.database.retry_after'))
        ->toBeGreaterThan((new CreateAlbumFromPlaylist(1))->timeout);
});
