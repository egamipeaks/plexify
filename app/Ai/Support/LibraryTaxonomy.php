<?php

namespace App\Ai\Support;

use App\Services\Plex\PlexClient;

class LibraryTaxonomy
{
    public function __construct(private PlexClient $plex) {}

    /**
     * @return array{genres: list<string>, styles: list<string>, moods: list<string>}
     */
    public function snapshot(): array
    {
        return [
            'genres' => array_column($this->plex->genres(), 'name'),
            'styles' => array_column($this->plex->styles(), 'name'),
            'moods' => array_column($this->plex->moods(), 'name'),
        ];
    }
}
