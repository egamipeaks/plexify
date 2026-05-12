<?php

namespace App\Support;

class Duration
{
    public static function format(int $ms): string
    {
        $seconds = max(0, (int) round($ms / 1000));

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
