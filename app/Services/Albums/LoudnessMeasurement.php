<?php

namespace App\Services\Albums;

readonly class LoudnessMeasurement
{
    public function __construct(
        public float $inputI,
        public float $inputTp,
        public float $inputLra,
        public float $inputThresh,
        public float $targetOffset,
    ) {}

    public static function fromFfmpegOutput(string $output): ?self
    {
        $start = strrpos($output, '{');
        $end = strrpos($output, '}');

        $json = $start !== false && $end !== false && $end > $start
            ? json_decode(substr($output, $start, $end - $start + 1), true)
            : null;

        $keys = ['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'];

        if (! is_array($json) || array_diff($keys, array_keys($json)) !== []) {
            throw new AlbumExportException('Could not read the loudness measurement.');
        }

        $values = array_map(fn (string $key) => is_numeric($json[$key]) ? (float) $json[$key] : null, $keys);

        if (in_array(null, $values, true)) {
            return null;
        }

        return new self(...$values);
    }
}
