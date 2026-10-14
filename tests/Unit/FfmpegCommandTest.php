<?php

use App\Services\Albums\FfmpegCommand;

it('transcodes to 320k MP3 with cleared source tags and kept art', function () {
    $command = FfmpegCommand::build('ffmpeg', '/in/a.flac', '/out/01.mp3', ['title' => 'Song', 'track' => '1/2'], remux: false);

    expect($command)->toBe([
        'ffmpeg', '-nostdin', '-y', '-v', 'error', '-i', '/in/a.flac',
        '-map', '0:a:0', '-map', '0:v?', '-c:v', 'copy', '-disposition:v', 'attached_pic',
        '-map_metadata', '-1',
        '-metadata', 'title=Song', '-metadata', 'track=1/2',
        '-id3v2_version', '3',
        '-c:a', 'libmp3lame', '-b:a', '320k',
        '/out/01.mp3',
    ]);
});

it('copies the audio stream when remuxing an MP3', function () {
    $command = FfmpegCommand::build('/opt/homebrew/bin/ffmpeg', '/in/a.mp3', '/out/01.mp3', [], remux: true);

    expect($command[0])->toBe('/opt/homebrew/bin/ffmpeg')
        ->and(array_slice($command, -3))->toBe(['-c:a', 'copy', '/out/01.mp3'])
        ->and($command)->not->toContain('libmp3lame');
});
