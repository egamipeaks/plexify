<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::firstOrCreate(
            ['email' => 'plexify@local'],
            ['name' => 'Plexify', 'password' => bcrypt(str()->random(32))],
        );
    }
};
