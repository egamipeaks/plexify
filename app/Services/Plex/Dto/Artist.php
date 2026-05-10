<?php

namespace App\Services\Plex\Dto;

readonly class Artist
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $thumb,
        public int $albumCount,
    ) {}

    public static function fromPlex(array $row): self
    {
        return new self(
            id: (string) $row['ratingKey'],
            name: $row['title'],
            thumb: $row['thumb'] ?? null,
            albumCount: $row['childCount'] ?? 0,
        );
    }
}
