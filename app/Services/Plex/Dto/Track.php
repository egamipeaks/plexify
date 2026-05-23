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
        public ?string $thumb = null,
        public ?string $albumId = null,
        public ?string $artistId = null,
        public ?string $playlistItemId = null,
        public int $userRating = 0,
        public ?string $lastRatedAt = null,
    ) {}

    public static function fromPlex(array $row): self
    {
        $part = $row['Media'][0]['Part'][0] ?? [];

        return new self(
            id: (string) $row['ratingKey'],
            title: $row['title'],
            artist: $row['originalTitle'] ?? $row['grandparentTitle'] ?? '',
            album: $row['parentTitle'] ?? '',
            trackNumber: $row['index'] ?? 0,
            durationMs: $row['duration'] ?? 0,
            partId: (int) ($part['id'] ?? 0),
            container: $part['container'] ?? 'mp3',
            thumb: $row['thumb'] ?? $row['parentThumb'] ?? null,
            albumId: isset($row['parentRatingKey']) ? (string) $row['parentRatingKey'] : null,
            artistId: isset($row['grandparentRatingKey']) ? (string) $row['grandparentRatingKey'] : null,
            playlistItemId: isset($row['playlistItemID']) ? (string) $row['playlistItemID'] : null,
            userRating: (int) ($row['userRating'] ?? 0),
            lastRatedAt: isset($row['lastRatedAt']) ? (string) $row['lastRatedAt'] : null,
        );
    }
}
