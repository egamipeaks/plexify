<?php

use App\Services\Albums\AlbumExportException;
use App\Services\Albums\LoudnessMeasurement;

function loudnormOutput(string $inputI = '-20.50', string $inputTp = '-3.25'): string
{
    return <<<OUT
    Input #0, flac, from 'a.flac':
      Duration: 00:00:05.00
    [Parsed_loudnorm_0 @ 0x600003738000]
    {
    	"input_i" : "{$inputI}",
    	"input_tp" : "{$inputTp}",
    	"input_lra" : "6.10",
    	"input_thresh" : "-30.70",
    	"output_i" : "-13.95",
    	"output_tp" : "-1.00",
    	"output_lra" : "5.00",
    	"output_thresh" : "-23.95",
    	"normalization_type" : "dynamic",
    	"target_offset" : "0.20"
    }
    [out#0/null @ 0x60000373c240] video:0KiB audio:1875KiB
    OUT;
}

it('parses the loudnorm JSON block from ffmpeg output', function () {
    expect(LoudnessMeasurement::fromFfmpegOutput(loudnormOutput()))->toEqual(
        new LoudnessMeasurement(inputI: -20.5, inputTp: -3.25, inputLra: 6.1, inputThresh: -30.7, targetOffset: 0.2),
    );
});

it('returns null for a silent track whose loudness is not finite', function () {
    expect(LoudnessMeasurement::fromFfmpegOutput(loudnormOutput(inputI: '-inf', inputTp: '-inf')))->toBeNull();
});

it('throws when the output has no loudnorm block', function () {
    LoudnessMeasurement::fromFfmpegOutput("Input #0\nsomething went wrong\n");
})->throws(AlbumExportException::class, 'Could not read the loudness measurement.');
