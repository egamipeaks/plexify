<?php

namespace App\Models;

use App\Enums\AlbumExportStatus;
use Database\Factories\AlbumExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AlbumExport extends Model
{
    /** @use HasFactory<AlbumExportFactory> */
    use HasFactory;

    protected $fillable = ['plex_playlist_id', 'title', 'folder_path', 'status', 'progress', 'total', 'error', 'notified_at'];

    protected $casts = [
        'status' => AlbumExportStatus::class,
        'progress' => 'integer',
        'total' => 'integer',
        'notified_at' => 'datetime',
    ];

    /** @return Collection<string, AlbumExport> */
    public static function latestPerPlaylist(): Collection
    {
        return static::query()
            ->orderByDesc('id')
            ->get()
            ->unique('plex_playlist_id')
            ->keyBy('plex_playlist_id');
    }
}
