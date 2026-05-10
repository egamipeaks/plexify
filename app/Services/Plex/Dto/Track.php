<?php

namespace App\Services\Plex\Dto;

readonly class Track
{
    public function __construct(
        public string $id,
        public string $title,
        public string $artist,
        public string $album,
        public int $trackNumber,
        public int $durationMs,
        public int $partId,
        public string $container,
    ) {}

    public static function fromPlex(array $row): self
    {
        $part = $row['Media'][0]['Part'][0] ?? [];

        return new self(
            id: (string) $row['ratingKey'],
            title: $row['title'],
            artist: $row['grandparentTitle'] ?? '',
            album: $row['parentTitle'] ?? '',
            trackNumber: $row['index'] ?? 0,
            durationMs: $row['duration'] ?? 0,
            partId: (int) ($part['id'] ?? 0),
            container: $part['container'] ?? 'mp3',
        );
    }
}
