<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['name', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function get(string $name, mixed $default = null): mixed
    {
        $row = static::query()->where('name', $name)->first();

        if (! $row) {
            return $default;
        }

        return $row->value;
    }

    public static function set(string $name, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['name' => $name],
            ['value' => $value],
        );
    }
}
