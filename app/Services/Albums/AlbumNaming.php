<?php

namespace App\Services\Albums;

class AlbumNaming
{
    public static function sanitize(string $name, int $maxLength): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $clean = preg_replace('/[:\/\\\\*?"<>|]/u', '_', $clean);
        $clean = rtrim(trim($clean), '. ');

        return rtrim(mb_substr($clean, 0, $maxLength), '. ');
    }

    public static function folderName(string $playlistTitle, string $playlistId): string
    {
        $name = self::sanitize($playlistTitle, 100);

        return $name === '' ? "Playlist {$playlistId}" : $name;
    }

    public static function fileName(int $position, int $total, string $artist, string $title): string
    {
        $number = str_pad((string) $position, $total > 99 ? 3 : 2, '0', STR_PAD_LEFT);
        $artist = self::sanitize($artist, 60) ?: 'Unknown Artist';
        $title = self::sanitize($title, 90) ?: 'Untitled';

        return "{$number} - {$artist} - {$title}.mp3";
    }
}
