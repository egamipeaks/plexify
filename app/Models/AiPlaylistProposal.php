<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiPlaylistProposal extends Model
{
    protected $fillable = ['conversation_id', 'name', 'description', 'payload', 'status', 'plex_playlist_id'];

    protected $casts = [
        'payload' => 'array',
    ];
}
