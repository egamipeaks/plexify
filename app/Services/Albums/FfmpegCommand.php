<?php

namespace App\Services\Albums;

class FfmpegCommand
{
    /**
     * @param  array<string, string>  $tags
     * @return list<string>
     */
    public static function build(string $binary, string $source, string $target, array $tags, bool $remux): array
    {
        $metadata = collect($tags)
            ->flatMap(fn (string $value, string $key) => ['-metadata', "{$key}={$value}"])
            ->values()
            ->all();

        $audio = $remux
            ? ['-c:a', 'copy']
            : ['-c:a', 'libmp3lame', '-b:a', '320k'];

        return [
            $binary, '-nostdin', '-y', '-v', 'error', '-i', $source,
            '-map', '0:a:0', '-map', '0:v?', '-c:v', 'copy', '-disposition:v', 'attached_pic',
            '-map_metadata', '-1',
            ...$metadata,
            '-id3v2_version', '3',
            ...$audio,
            $target,
        ];
    }
}
