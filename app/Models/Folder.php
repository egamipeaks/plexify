<?php

namespace App\Models;

use Database\Factories\FolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folder extends Model
{
    /** @use HasFactory<FolderFactory> */
    use HasFactory;

    protected $fillable = ['name', 'position', 'expanded'];

    protected $casts = [
        'position' => 'integer',
        'expanded' => 'boolean',
    ];

    /** @return HasMany<FolderPlaylist, $this> */
    public function folderPlaylists(): HasMany
    {
        return $this->hasMany(FolderPlaylist::class)->orderBy('position')->orderBy('id');
    }
}
