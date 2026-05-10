<?php

namespace App\Services\Plex\Dto;

use Illuminate\Support\Collection;

readonly class SearchResults
{
    /**
     * @param Collection<int, Track> $tracks
     * @param Collection<int, Artist> $artists
     * @param Collection<int, Album> $albums
     * @param Collection<int, Playlist> $playlists
     */
    public function __construct(
        public Collection $tracks,
        public Collection $artists,
        public Collection $albums,
        public Collection $playlists,
    ) {}

    public static function empty(): self
    {
        return new self(collect(), collect(), collect(), collect());
    }

    public function total(): int
    {
        return $this->tracks->count()
            + $this->artists->count()
            + $this->albums->count()
            + $this->playlists->count();
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }
}
