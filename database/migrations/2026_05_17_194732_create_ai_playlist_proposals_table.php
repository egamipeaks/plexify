<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_playlist_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->string('plex_playlist_id')->nullable();
            $table->timestamps();
        });
    }
};
