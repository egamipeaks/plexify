<?php

namespace App\Services\Plex\Dto;

readonly class Album
{
    public function __construct(
        public string $id,
        public string $title,
        public string $artist,
        public ?int $year,
        public ?string $thumb,
        public int $trackCount,
        public int $durationMs,
    ) {}

    public static function fromPlex(array $row): self
    {
        return new self(
            id: (string) $row['ratingKey'],
            title: $row['title'],
            artist: $row['parentTitle'] ?? '',
            year: $row['year'] ?? null,
            thumb: $row['thumb'] ?? null,
            trackCount: $row['leafCount'] ?? 0,
            durationMs: $row['duration'] ?? 0,
        );
    }
}
