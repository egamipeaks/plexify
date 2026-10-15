<?php

use App\Services\Albums\FfmpegCommand;
use App\Services\Albums\LoudnessMeasurement;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/plexify-ffmpeg-'.Str::random(8);
    File::ensureDirectoryExists($this->dir);
});

afterEach(fn () => File::deleteDirectory($this->dir));

function ffprobeJson(string $file): array
{
    return json_decode(Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format_tags:stream=codec_name,bit_rate', '-of', 'json', $file])->output(), true);
}

it('transcodes a FLAC with embedded art to tagged 320k MP3 keeping the art', function () {
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=d=1', "{$this->dir}/plain.flac"])->throw();
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=red:s=64x64:d=1', '-frames:v', '1', "{$this->dir}/c.jpg"])->throw();
    Process::run(['ffmpeg', '-v', 'error', '-y', '-i', "{$this->dir}/plain.flac", '-i', "{$this->dir}/c.jpg", '-map', '0', '-map', '1', '-c', 'copy', '-disposition:v', 'attached_pic', '-metadata', 'genre=Rock', "{$this->dir}/src.flac"])->throw();

    $tags = ['title' => 'Song', 'artist' => 'Artist', 'album' => 'Road Trip', 'album_artist' => 'Various Artists', 'compilation' => '1', 'track' => '3/12', 'date' => '2026'];

    Process::run(FfmpegCommand::build('ffmpeg', "{$this->dir}/src.flac", "{$this->dir}/out.mp3", $tags, -14.0, null))->throw();

    $probe = ffprobeJson("{$this->dir}/out.mp3");

    expect(collect($probe['streams'])->pluck('codec_name')->all())->toBe(['mp3', 'mjpeg'])
        ->and($probe['streams'][0]['bit_rate'])->toBe('320000')
        ->and($probe['format']['tags'])->toMatchArray($tags)
        ->and($probe['format']['tags'])->not->toHaveKey('genre');
})->skip(fn () => Process::run(['which', 'ffmpeg'])->failed(), 'ffmpeg is not installed');

it('normalizes a quiet MP3 to the loudness target at 44.1 kHz', function () {
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=f=440:d=5,volume=0.05', '-ar', '48000', '-c:a', 'libmp3lame', '-b:a', '128k', "{$this->dir}/src.mp3"])->throw();

    $measure = fn (string $file) => LoudnessMeasurement::fromFfmpegOutput(
        Process::run(FfmpegCommand::measure('ffmpeg', $file, -14.0))->throw()->errorOutput(),
    );

    Process::run(FfmpegCommand::build('ffmpeg', "{$this->dir}/src.mp3", "{$this->dir}/out.mp3", ['title' => 'X'], -14.0, $measure("{$this->dir}/src.mp3")))->throw();

    $probe = json_decode(Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=sample_rate,bit_rate:format_tags', '-of', 'json', "{$this->dir}/out.mp3"])->output(), true);

    expect($measure("{$this->dir}/out.mp3")->inputI)->toBeGreaterThan(-15.0)->toBeLessThan(-13.0)
        ->and($probe['streams'][0]['sample_rate'])->toBe('44100')
        ->and($probe['streams'][0]['bit_rate'])->toBe('320000')
        ->and($probe['format']['tags']['title'])->toBe('X');
})->skip(fn () => Process::run(['which', 'ffmpeg'])->failed(), 'ffmpeg is not installed');
