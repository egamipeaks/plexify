<?php

use App\Services\Albums\FfmpegCommand;
use App\Services\Albums\LoudnessMeasurement;

it('measures loudness against the target without writing a file', function () {
    expect(FfmpegCommand::measure('ffmpeg', '/in/a.flac', -14.0))->toBe([
        'ffmpeg', '-nostdin', '-hide_banner', '-nostats', '-i', '/in/a.flac',
        '-map', '0:a:0', '-af', 'loudnorm=I=-14:TP=-1:LRA=20:print_format=json',
        '-f', 'null', '-',
    ]);
});

it('normalizes and encodes to 320k MP3 with cleared source tags and kept art', function () {
    $measurement = new LoudnessMeasurement(inputI: -20.5, inputTp: -3.25, inputLra: 6.1, inputThresh: -30.7, targetOffset: 0.2);

    $command = FfmpegCommand::build('ffmpeg', '/in/a.flac', '/out/01.mp3', ['title' => 'Song', 'track' => '1/2'], -14.0, $measurement);

    expect($command)->toBe([
        'ffmpeg', '-nostdin', '-y', '-v', 'error', '-i', '/in/a.flac',
        '-map', '0:a:0', '-map', '0:v?', '-c:v', 'copy', '-disposition:v', 'attached_pic',
        '-map_metadata', '-1',
        '-metadata', 'title=Song', '-metadata', 'track=1/2',
        '-id3v2_version', '3',
        '-af', 'loudnorm=I=-14:TP=-1:LRA=20:measured_I=-20.5:measured_TP=-3.25:measured_LRA=6.1:measured_thresh=-30.7:offset=0.2:linear=true',
        '-ar', '44100',
        '-c:a', 'libmp3lame', '-b:a', '320k',
        '/out/01.mp3',
    ]);
});

it('encodes without a loudness filter when there is no measurement', function () {
    $command = FfmpegCommand::build('/opt/homebrew/bin/ffmpeg', '/in/a.mp3', '/out/01.mp3', [], -14.0, null);

    expect($command[0])->toBe('/opt/homebrew/bin/ffmpeg')
        ->and($command)->not->toContain('-af')
        ->and(array_slice($command, -5))->toBe(['-c:a', 'libmp3lame', '-b:a', '320k', '/out/01.mp3']);
});
