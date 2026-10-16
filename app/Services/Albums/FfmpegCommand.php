<?php

namespace App\Services\Albums;

class FfmpegCommand
{
    /** @return list<string> */
    public static function measure(string $binary, string $source, float $targetLufs): array
    {
        return [
            $binary, '-nostdin', '-hide_banner', '-nostats', '-i', $source,
            '-map', '0:a:0', '-af', self::loudnormFilter($targetLufs).':print_format=json',
            '-f', 'null', '-',
        ];
    }

    /**
     * @param  array<string, string>  $tags
     * @return list<string>
     */
    public static function build(string $binary, string $source, string $target, array $tags, float $targetLufs, ?LoudnessMeasurement $measurement): array
    {
        $metadata = collect($tags)
            ->flatMap(fn (string $value, string $key) => ['-metadata', "{$key}={$value}"])
            ->values()
            ->all();

        // loudnorm resamples to 192 kHz when it falls back to dynamic mode, so pin the output rate.
        $normalize = $measurement === null
            ? []
            : ['-af', self::loudnormFilter($targetLufs).":measured_I={$measurement->inputI}:measured_TP={$measurement->inputTp}:measured_LRA={$measurement->inputLra}:measured_thresh={$measurement->inputThresh}:offset={$measurement->targetOffset}:linear=true", '-ar', '44100'];

        return [
            $binary, '-nostdin', '-y', '-v', 'error', '-i', $source,
            '-map', '0:a:0', '-map', '0:v?', '-c:v', 'copy', '-disposition:v', 'attached_pic',
            '-map_metadata', '-1',
            ...$metadata,
            '-id3v2_version', '3',
            ...$normalize,
            '-c:a', 'libmp3lame', '-b:a', '320k',
            $target,
        ];
    }

    private static function loudnormFilter(float $targetLufs): string
    {
        return "loudnorm=I={$targetLufs}:TP=-1:LRA=20";
    }
}
