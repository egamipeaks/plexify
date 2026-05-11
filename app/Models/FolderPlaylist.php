<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolderPlaylist extends Model
{
    protected $fillable = ['folder_id', 'plex_playlist_id', 'position'];

    protected $casts = [
        'folder_id' => 'integer',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }
}
