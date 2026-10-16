<?php

namespace App\Models;

use App\Enums\AlbumExportStatus;
use Database\Factories\AlbumExportFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    public function isAwaitingAnnouncement(): bool
    {
        return ! $this->status->isActive() && $this->notified_at === null;
    }

    /** @param  Builder<AlbumExport>  $query */
    #[Scope]
    protected function awaitingAnnouncement(Builder $query): void
    {
        $query->whereIn('status', [AlbumExportStatus::Done, AlbumExportStatus::Failed])->whereNull('notified_at');
    }

    /** @return Collection<string, AlbumExport> */
    public static function latestPerPlaylist(): Collection
    {
        return static::query()
            ->whereIn('id', static::query()->selectRaw('max(id)')->groupBy('plex_playlist_id'))
            ->get()
            ->keyBy('plex_playlist_id');
    }
}
