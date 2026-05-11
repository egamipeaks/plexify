<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_playlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained()->cascadeOnDelete();
            $table->string('plex_playlist_id');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique('plex_playlist_id');
        });
    }
};
