<?php

namespace App\Services\Plex\Dto;

readonly class Playlist
{
    public function __construct(
        public string $id,
        public string $title,
        public int $trackCount,
        public int $durationMs,
        public ?string $thumb,
        public string $playlistType,
        public ?string $summary = null,
    ) {}

    public static function fromPlex(array $row): self
    {
        return new self(
            id: (string) $row['ratingKey'],
            title: $row['title'],
            trackCount: $row['leafCount'] ?? 0,
            durationMs: $row['duration'] ?? 0,
            thumb: $row['composite'] ?? $row['thumb'] ?? null,
            playlistType: $row['playlistType'] ?? 'audio',
            summary: ($row['summary'] ?? '') !== '' ? $row['summary'] : null,
        );
    }
}
