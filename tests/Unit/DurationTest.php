<?php

use App\Support\Duration;

it('formats sub-minute durations', function () {
    expect(Duration::format(7_000))->toBe('0:07');
});

it('formats minute-and-seconds durations', function () {
    expect(Duration::format(187_000))->toBe('3:07');
});

it('rounds to the nearest second', function () {
    expect(Duration::format(187_600))->toBe('3:08');
});

it('formats exactly one hour as H:MM:SS', function () {
    expect(Duration::format(3_600_000))->toBe('1:00:00');
});

it('formats multi-hour durations as H:MM:SS', function () {
    // 2h 3m 4s
    expect(Duration::format((2 * 3600 + 3 * 60 + 4) * 1000))->toBe('2:03:04');
});

it('treats zero and negatives as 0:00', function () {
    expect(Duration::format(0))->toBe('0:00');
    expect(Duration::format(-5_000))->toBe('0:00');
});
