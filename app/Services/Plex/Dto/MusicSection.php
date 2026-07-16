<?php

namespace App\Services\Plex\Dto;

readonly class MusicSection
{
    public function __construct(
        public int $id,
        public string $title,
    ) {}

    public static function fromPlex(array $row): self
    {
        return new self(
            id: (int) $row['key'],
            title: (string) $row['title'],
        );
    }
}
