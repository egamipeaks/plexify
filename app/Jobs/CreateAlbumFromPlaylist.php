<?php

namespace App\Jobs;

use App\Enums\AlbumExportStatus;
use App\Models\AlbumExport;
use App\Services\Albums\AlbumExportException;
use App\Services\Albums\AlbumNaming;
use App\Services\Albums\AlbumPaths;
use App\Services\Albums\FfmpegCommand;
use App\Services\Albums\LoudnessMeasurement;
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

    private const MARKER_FILE = '.plexify-album';

    public int $tries = 1;

    public int $timeout = 3600;

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
        $export = AlbumExport::find($this->albumExportId);
        $error = 'The job timed out or the worker stopped.';

        if ($export?->folder_path !== null) {
            $retired = dirname($export->folder_path)."/.plexify-old-{$export->id}";

            if (File::isDirectory($retired)) {
                $error .= " The previous album was left at {$retired}.";
            }
        }

        $export?->update(['status' => AlbumExportStatus::Failed, 'error' => $error]);

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
        $previous = $this->retirableFolder($export);

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
        File::put($this->buildDir().'/'.self::MARKER_FILE, json_encode([
            'plex_playlist_id' => $export->plex_playlist_id,
            'album_export_id' => $export->id,
        ]));

        foreach ($tracks as $index => $track) {
            $this->encode($export, $paths, $track, $index + 1, $tracks->count());
            $export->increment('progress');
        }

        $cleanupNote = $this->publish($export, $target, $previous);
        $plexNote = $this->refreshPlex($plex, $mapper, $target);

        $export->update([
            'status' => AlbumExportStatus::Done,
            'error' => collect([$cleanupNote, $plexNote])->filter()->implode(' ') ?: null,
        ]);
    }

    private function encode(AlbumExport $export, AlbumPaths $paths, Track $track, int $position, int $total): void
    {
        $output = $this->buildDir().'/'.AlbumNaming::fileName($position, $total, $track->artist, $track->title);

        $binary = (string) config('services.ffmpeg.path');
        $source = (string) $paths->sourceFile($track);
        $targetLufs = (float) config('services.ffmpeg.loudness_target');

        $measureOutput = $this->runFfmpeg(FfmpegCommand::measure($binary, $source, $targetLufs), $track);

        try {
            $measurement = LoudnessMeasurement::fromFfmpegOutput($measureOutput);
        } catch (AlbumExportException) {
            throw new AlbumExportException("Could not read the loudness of \"{$track->title}\".");
        }

        $this->runFfmpeg(FfmpegCommand::build(
            $binary,
            $source,
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
            $targetLufs,
            $measurement,
        ), $track);
    }

    /** @param  list<string>  $command */
    private function runFfmpeg(array $command, Track $track): string
    {
        $result = Process::timeout(600)->run($command);

        if ($result->failed()) {
            $stderr = collect(explode("\n", trim($result->errorOutput())))->take(-3)->implode(' ');

            throw new AlbumExportException("ffmpeg failed on \"{$track->title}\": {$stderr}");
        }

        return $result->errorOutput();
    }

    private function publish(AlbumExport $export, string $target, ?string $previous): ?string
    {
        $staging = dirname($target)."/.plexify-build-{$export->id}";
        $retired = $previous !== null ? dirname($previous)."/.plexify-old-{$export->id}" : null;
        $retiredPrevious = false;

        try {
            File::deleteDirectory($staging);

            if (! File::copyDirectory($this->buildDir(), $staging)) {
                throw new AlbumExportException('Copying the album to the music share failed.');
            }

            if ($previous !== null && File::isDirectory($previous)) {
                File::deleteDirectory($retired);

                if (! File::moveDirectory($previous, $retired)) {
                    throw new AlbumExportException('Setting the previous album aside on the music share failed.');
                }

                $retiredPrevious = true;
            }

            if (! File::moveDirectory($staging, $target)) {
                throw new AlbumExportException('Moving the album into place on the music share failed.');
            }
        } catch (Throwable $e) {
            File::deleteDirectory($staging);

            if ($retiredPrevious && ! File::moveDirectory($retired, $previous)) {
                throw new AlbumExportException("{$e->getMessage()} The previous album was left at {$retired}.", previous: $e);
            }

            throw $e;
        }

        if (! $retiredPrevious || File::deleteDirectory($retired)) {
            return null;
        }

        return "The old album copy could not be fully removed: {$retired}";
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

    private function retirableFolder(AlbumExport $export): ?string
    {
        $previous = $this->previousFolder($export);

        if ($previous === null || basename(dirname($previous)) !== 'Various Artists') {
            return null;
        }

        $marker = json_decode((string) @file_get_contents("{$previous}/".self::MARKER_FILE), true);

        if (! is_array($marker) || (string) ($marker['plex_playlist_id'] ?? '') !== $export->plex_playlist_id) {
            return null;
        }

        return $previous;
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
