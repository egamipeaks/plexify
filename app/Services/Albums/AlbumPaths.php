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
