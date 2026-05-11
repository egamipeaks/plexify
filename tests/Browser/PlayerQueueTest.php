<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Exercises the playback queue, auto-advance, transport buttons, shuffle and repeat.
 * Like the other Browser tests this needs the Plex server reachable with at least one
 * album that has multiple tracks. Drives the player's Alpine state directly via
 * Alpine.$data() so it does not have to wait for a real track to finish.
 *
 * Selectors mirror LibraryDrillDownTest.php:
 *   [data-region=artists-column] button  — artist rows
 *   [data-region=albums-column] button   — album rows
 *   [data-region=tracklist] button[wire\:click^="playTrack"] — track play buttons
 *   [data-region=now-playing-title]      — player title region
 */

/**
 * Shared preamble: drills into the first reachable artist → album that has multiple tracks
 * and clicks the second track row (falls back to the first if only one exists). Returns a
 * JSON-encoded object with { len, index, title } once the Alpine queue is populated, or an
 * error string on failure.
 */
function drillIntoAlbumAndClickTrack($page): string
{
    return (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            const waitFor = async (selector, timeoutMs) => {
                const deadline = Date.now() + timeoutMs;
                while (Date.now() < deadline) {
                    if (document.querySelectorAll(selector).length > 0) return true;
                    await sleep(100);
                }
                return false;
            };

            const playerData = () => Alpine.$data(document.querySelector('[x-data="audioPlayer()"]'));

            if (!await waitFor('[data-region=artists-column] button', 8000)) return 'NO_ARTIST';

            const artistButtons = document.querySelectorAll('[data-region=artists-column] button');

            for (let a = 0; a < Math.min(artistButtons.length, 12); a++) {
                document.querySelectorAll('[data-region=artists-column] button')[a]?.click();
                await sleep(200);
                if (!await waitFor('[data-region=albums-column] button', 6000)) continue;

                const albumButtons = document.querySelectorAll('[data-region=albums-column] button');

                for (let b = 0; b < albumButtons.length; b++) {
                    document.querySelectorAll('[data-region=albums-column] button')[b]?.click();
                    await sleep(200);
                    if (!await waitFor('[data-region=tracklist] button[wire\\:click^="playTrack"]', 6000)) continue;

                    const rows = document.querySelectorAll('[data-region=tracklist] button[wire\\:click^="playTrack"]');
                    if (rows.length < 2) continue; // keep searching for an album with multiple tracks

                    rows[1].click(); // click the second track

                    // Wait until the player queue is populated.
                    const deadline = Date.now() + 8000;
                    while (Date.now() < deadline) {
                        const d = playerData();
                        if (d && d.queue.length > 0) {
                            return JSON.stringify({ len: d.queue.length, index: d.index, title: d.current ? d.current.title : '' });
                        }
                        await sleep(100);
                    }
                    return 'NO_QUEUE';
                }
            }

            return 'NO_PLAYABLE_ALBUM';
        })()
    JS);
}

it('builds a queue from the album tracklist and plays the clicked track', function () {
    $page = visit('/');

    $nowPlaying = drillIntoAlbumAndClickTrack($page);

    $decoded = json_decode((string) $nowPlaying, true);
    expect($decoded)->toBeArray("Expected the player queue to populate after clicking a track, got: {$nowPlaying}");
    expect($decoded['len'])->toBeGreaterThan(1);
    expect($decoded['index'])->toBe(1, 'Clicking the second track row should set the queue index to 1');
    expect($decoded['title'])->not->toBe('');

    $page->assertVisible('[data-region=now-playing-title]');
    expect(trim((string) $page->text('[data-region=now-playing-title]')))->toBe($decoded['title']);
});

it('auto-advances to the next track when next() is called and stops at the end with repeat off', function () {
    $page = visit('/');

    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            const waitFor = async (selector, timeoutMs) => {
                const deadline = Date.now() + timeoutMs;
                while (Date.now() < deadline) {
                    if (document.querySelectorAll(selector).length > 0) return true;
                    await sleep(100);
                }
                return false;
            };

            const playerData = () => Alpine.$data(document.querySelector('[x-data="audioPlayer()"]'));

            if (!await waitFor('[data-region=artists-column] button', 8000)) return 'NO_ARTIST';

            // Find an album with multiple tracks.
            const artistButtons = document.querySelectorAll('[data-region=artists-column] button');
            let populated = false;

            for (let a = 0; a < Math.min(artistButtons.length, 12) && !populated; a++) {
                document.querySelectorAll('[data-region=artists-column] button')[a]?.click();
                await sleep(200);
                if (!await waitFor('[data-region=albums-column] button', 6000)) continue;

                const albumButtons = document.querySelectorAll('[data-region=albums-column] button');
                for (let b = 0; b < albumButtons.length && !populated; b++) {
                    document.querySelectorAll('[data-region=albums-column] button')[b]?.click();
                    await sleep(200);
                    if (!await waitFor('[data-region=tracklist] button[wire\\:click^="playTrack"]', 6000)) continue;

                    const rows = document.querySelectorAll('[data-region=tracklist] button[wire\\:click^="playTrack"]');
                    if (rows.length < 2) continue;

                    rows[0].click();
                    const deadline = Date.now() + 8000;
                    while (Date.now() < deadline) {
                        const d = playerData();
                        if (d && d.queue.length >= 2) { populated = true; break; }
                        await sleep(100);
                    }
                }
            }

            if (!populated) return 'QUEUE_TOO_SHORT';

            const p = playerData();
            p.repeat = 'off';
            const startIndex = p.index;
            const titleAtStart = p.current.title;

            // Simulate moving to the next track.
            p.next();
            await sleep(300);
            const advanced = { index: p.index, title: p.current ? p.current.title : '' };

            // Jump to the last track and call next() — should stop playback, queue stays loaded.
            p.jumpTo(p.queue.length - 1);
            await sleep(300);
            p.next();
            await sleep(300);
            const atEnd = { isPlaying: p.isPlaying, queueLen: p.queue.length, index: p.index };

            return JSON.stringify({ startIndex, titleAtStart, advanced, atEnd });
        })()
    JS);

    $decoded = json_decode((string) $result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");
    expect($decoded['advanced']['index'])->toBe($decoded['startIndex'] + 1);
    expect($decoded['advanced']['title'])->not->toBe($decoded['titleAtStart']);
    expect($decoded['atEnd']['isPlaying'])->toBeFalse();
    expect($decoded['atEnd']['queueLen'])->toBeGreaterThan(0);
});

it('skip-back restarts or goes to previous track; shuffle and repeat toggles work correctly', function () {
    $page = visit('/');

    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            const waitFor = async (selector, timeoutMs) => {
                const deadline = Date.now() + timeoutMs;
                while (Date.now() < deadline) {
                    if (document.querySelectorAll(selector).length > 0) return true;
                    await sleep(100);
                }
                return false;
            };

            const playerData = () => Alpine.$data(document.querySelector('[x-data="audioPlayer()"]'));

            if (!await waitFor('[data-region=artists-column] button', 8000)) return 'NO_ARTIST';

            // Find an album with multiple tracks and load the queue.
            const artistButtons = document.querySelectorAll('[data-region=artists-column] button');
            let populated = false;

            for (let a = 0; a < Math.min(artistButtons.length, 12) && !populated; a++) {
                document.querySelectorAll('[data-region=artists-column] button')[a]?.click();
                await sleep(200);
                if (!await waitFor('[data-region=albums-column] button', 6000)) continue;

                const albumButtons = document.querySelectorAll('[data-region=albums-column] button');
                for (let b = 0; b < albumButtons.length && !populated; b++) {
                    document.querySelectorAll('[data-region=albums-column] button')[b]?.click();
                    await sleep(200);
                    if (!await waitFor('[data-region=tracklist] button[wire\\:click^="playTrack"]', 6000)) continue;

                    const rows = document.querySelectorAll('[data-region=tracklist] button[wire\\:click^="playTrack"]');
                    if (rows.length < 2) continue;

                    rows[1].click(); // start on the second track so previous() can go back
                    const deadline = Date.now() + 8000;
                    while (Date.now() < deadline) {
                        const d = playerData();
                        if (d && d.queue.length >= 2 && d.index >= 1) { populated = true; break; }
                        await sleep(100);
                    }
                }
            }

            if (!populated) return 'QUEUE_TOO_SHORT';

            const p = playerData();

            // --- skip-back past 3s: should restart the same track (set currentTime > 3) ---
            // We force the audio element's currentTime; if the media hasn't loaded enough
            // for the seek to stick, currentTime may stay at 0 and previous() will go back
            // instead. We guard by checking if currentTime actually advanced.
            p.$refs.audio.currentTime = 10;
            await sleep(100);
            const idxBefore = p.index;
            const timeBeforeRestart = p.$refs.audio.currentTime;
            p.previous();
            await sleep(200);
            // If currentTime was >3 before the call, previous() resets to 0 without changing index.
            // If currentTime stayed at 0 (media not buffered), previous() would have gone back.
            // We record both outcomes so assertions can adapt.
            const restarted = {
                index: p.index,
                time: p.$refs.audio.currentTime,
                hadTimeBeforeCall: timeBeforeRestart,
            };

            // --- skip-back within 3s: go to previous track ---
            // Ensure we are on a track with a predecessor (index >= 1).
            if (p.index === 0) {
                p.jumpTo(1);
                await sleep(200);
            }
            const idxBeforePrev = p.index;
            p.$refs.audio.currentTime = 0; // force currentTime to be <= 3
            p.previous();
            await sleep(200);
            const wentBack = { from: idxBeforePrev, to: p.index };

            // --- shuffle on: current track stays at head, queue order changes ---
            p.jumpTo(0);
            await sleep(100);
            const orderBefore = p.queue.map(t => t.id).join(',');
            const headId = p.current.id;
            p.toggleShuffle();
            await sleep(100);
            const shuffleOn = {
                shuffle: p.shuffle,
                index: p.index,
                headStillCurrent: p.current.id === headId,
                // For a queue of 2 the shuffled order may happen to be the same; allow it.
                orderChangedOrTooShort: p.queue.map(t => t.id).join(',') !== orderBefore || p.queue.length <= 2,
            };

            // --- shuffle off: restores original order and keeps current track ---
            p.toggleShuffle();
            await sleep(100);
            const shuffleOff = {
                shuffle: p.shuffle,
                order: p.queue.map(t => t.id).join(','),
                originalOrder: p.originalQueue.map(t => t.id).join(','),
                stillCurrent: p.current.id === headId,
            };

            // --- repeat cycles off -> all -> one -> off ---
            const r0 = p.repeat;
            p.cycleRepeat(); const r1 = p.repeat;
            p.cycleRepeat(); const r2 = p.repeat;
            p.cycleRepeat(); const r3 = p.repeat;

            return JSON.stringify({ idxBefore, restarted, wentBack, shuffleOn, shuffleOff, repeat: [r0, r1, r2, r3] });
        })()
    JS);

    $decoded = json_decode((string) $result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");

    // Skip-back when currentTime > 3: same track index, audio reset to 0.
    // When media hasn't buffered far enough the seek may no-op and previous() goes back
    // instead; in that case index changes by -1. Accept both outcomes, but verify
    // consistency: if index didn't change, currentTime must be near 0.
    if ($decoded['restarted']['index'] === $decoded['idxBefore']) {
        // Stayed on same track — currentTime should have been reset.
        expect($decoded['restarted']['time'])->toBeLessThan(1.0, 'previous() with currentTime>3 should reset audio to 0');
    } else {
        // Went to previous track — only valid if media was not buffered so currentTime stayed 0.
        expect($decoded['restarted']['hadTimeBeforeCall'])->toBeLessThan(1.0, 'Went back a track but currentTime was >3 before the call — unexpected');
        expect($decoded['restarted']['index'])->toBe($decoded['idxBefore'] - 1, 'Fallback: went back a track, so index should be idxBefore - 1');
    }

    // Skip-back within 3s goes to the previous track.
    expect($decoded['wentBack']['to'])->toBe($decoded['wentBack']['from'] - 1, 'previous() with currentTime<=3 should go to index - 1');

    // Shuffle on keeps current track at head (index 0) and (for queues > 2) shuffles order.
    expect($decoded['shuffleOn']['shuffle'])->toBeTrue();
    expect($decoded['shuffleOn']['index'])->toBe(0);
    expect($decoded['shuffleOn']['headStillCurrent'])->toBeTrue();
    expect($decoded['shuffleOn']['orderChangedOrTooShort'])->toBeTrue();

    // Shuffle off restores the original order and keeps the same current track.
    expect($decoded['shuffleOff']['shuffle'])->toBeFalse();
    expect($decoded['shuffleOff']['order'])->toBe($decoded['shuffleOff']['originalOrder']);
    expect($decoded['shuffleOff']['stillCurrent'])->toBeTrue();

    // Repeat cycles: off -> all -> one -> off.
    expect($decoded['repeat'])->toBe(['off', 'all', 'one', 'off']);
});
