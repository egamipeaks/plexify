<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folder_playlists', function (Blueprint $table) {
            $table->dropForeign(['folder_id']);
        });

        Schema::table('folder_playlists', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->change();
            $table->foreign('folder_id')->references('id')->on('folders')->nullOnDelete();
        });
    }
};
