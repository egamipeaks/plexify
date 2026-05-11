<?php

namespace App\Support;

use App\Models\Setting;
use InvalidArgumentException;

class AppSetting
{
    public const DENSITY_COMFORTABLE = 'comfortable';

    public const DENSITY_COMPACT = 'compact';

    private const DENSITY_DEFAULT = self::DENSITY_COMFORTABLE;

    private const DENSITY_ALLOWED = [
        self::DENSITY_COMFORTABLE,
        self::DENSITY_COMPACT,
    ];

    public static function density(): string
    {
        $value = Setting::get('density', self::DENSITY_DEFAULT);

        if (! in_array($value, self::DENSITY_ALLOWED, true)) {
            return self::DENSITY_DEFAULT;
        }

        return $value;
    }

    public static function setDensity(string $value): void
    {
        if (! in_array($value, self::DENSITY_ALLOWED, true)) {
            throw new InvalidArgumentException("Invalid density value: {$value}");
        }

        Setting::set('density', $value);
    }
}
