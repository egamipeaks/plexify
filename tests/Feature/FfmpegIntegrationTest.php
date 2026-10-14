<?php

use App\Services\Albums\FfmpegCommand;
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

    Process::run(FfmpegCommand::build('ffmpeg', "{$this->dir}/src.flac", "{$this->dir}/out.mp3", $tags, remux: false))->throw();

    $probe = ffprobeJson("{$this->dir}/out.mp3");

    expect(collect($probe['streams'])->pluck('codec_name')->all())->toBe(['mp3', 'mjpeg'])
        ->and($probe['streams'][0]['bit_rate'])->toBe('320000')
        ->and($probe['format']['tags'])->toMatchArray($tags)
        ->and($probe['format']['tags'])->not->toHaveKey('genre');
})->skip(fn () => Process::run(['which', 'ffmpeg'])->failed(), 'ffmpeg is not installed');

it('remuxes an MP3 without re-encoding', function () {
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=d=1', '-c:a', 'libmp3lame', '-b:a', '128k', "{$this->dir}/src.mp3"])->throw();

    Process::run(FfmpegCommand::build('ffmpeg', "{$this->dir}/src.mp3", "{$this->dir}/out.mp3", ['title' => 'X'], remux: true))->throw();

    $probe = ffprobeJson("{$this->dir}/out.mp3");

    expect($probe['streams'][0]['bit_rate'])->toBe('128000')
        ->and($probe['format']['tags']['title'])->toBe('X');
})->skip(fn () => Process::run(['which', 'ffmpeg'])->failed(), 'ffmpeg is not installed');
