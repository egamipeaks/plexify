<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('album_exports', function (Blueprint $table) {
            $table->id();
            $table->string('plex_playlist_id')->index();
            $table->string('title');
            $table->string('folder_path', 1024)->nullable();
            $table->string('status');
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }
};
