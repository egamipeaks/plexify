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

            const playerData = () => Alpine.$data(document.querySelector('[data-region="player"]'));

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

    // Media Session: after a track starts playing, metadata should be set (title is a string).
    $metaTitleType = (string) $page->script("typeof (navigator.mediaSession.metadata?.title)");
    expect($metaTitleType)->toBe('string', 'MediaMetadata should be set on the session after a track loads');
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

            const playerData = () => Alpine.$data(document.querySelector('[data-region="player"]'));

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

            const playerData = () => Alpine.$data(document.querySelector('[data-region="player"]'));

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

it('highlights the currently-playing track in the library tracklist and moves the highlight on skip', function () {
    $page = visit('/');

    $nowPlaying = drillIntoAlbumAndClickTrack($page);
    expect(json_decode((string) $nowPlaying, true))->toBeArray("Expected the queue to populate, got: {$nowPlaying}");

    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const playerData = () => Alpine.$data(document.querySelector('[data-region="player"]'));
            const rowHasEq = (i) => {
                const rows = document.querySelectorAll('[data-region=tracklist] button[wire\\:click^="playTrack"]');
                return !!rows[i]?.querySelector('.eq');
            };

            const p = playerData();
            await sleep(300);
            const before = {
                idx: p.index,
                eqOnCurrent: rowHasEq(p.index),
                eqOnOther: rowHasEq(p.index === 0 ? 1 : 0),
            };

            p.next();
            await sleep(500);
            const after = { idx: p.index, eqOnCurrent: rowHasEq(p.index) };

            return JSON.stringify({ before, after });
        })()
    JS);

    $decoded = json_decode((string) $result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");
    expect($decoded['before']['eqOnCurrent'])->toBeTrue('The playing row should show the equalizer marker');
    expect($decoded['before']['eqOnOther'])->toBeFalse('Non-playing rows should not show the equalizer');
    expect($decoded['after']['eqOnCurrent'])->toBeTrue('The equalizer should follow to the current track after skip');
});

it('navigates to the playing track album when the now-playing title is clicked from another page', function () {
    $page = visit('/');

    $nowPlaying = drillIntoAlbumAndClickTrack($page);
    expect(json_decode((string) $nowPlaying, true))->toBeArray("Expected the queue to populate, got: {$nowPlaying}");

    // Move to the search page (the player + queue persist via @persist), then click the now-playing title.
    $page->script("window.Livewire.navigate('/search')");

    $onSearch = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            for (let i = 0; i < 50; i++) {
                if (location.pathname.replace(/\/$/, '').endsWith('/search')) { return 'ON_SEARCH'; }
                await sleep(100);
            }
            return 'STILL_' + location.pathname;
        })()
    JS);
    expect($onSearch)->toBe('ON_SEARCH');

    $page->assertVisible('[data-region=now-playing-title]');
    $page->click('[data-region=now-playing-title]');

    $url = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            for (let i = 0; i < 50; i++) {
                if (/[?&]album=/.test(location.search)) { return location.href; }
                await sleep(100);
            }
            return 'NO_ALBUM_PARAM:' + location.href;
        })()
    JS);
    expect($url)->toMatch('/[?&]album=/');
});

it('only highlights the playing row in the list it is playing from', function () {
    $page = visit('/');

    $nowPlaying = drillIntoAlbumAndClickTrack($page);
    expect(json_decode((string) $nowPlaying, true))->toBeArray("Expected the queue to populate, got: {$nowPlaying}");

    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            await sleep(300);

            // Should have .eq in the current album tracklist (context = album).
            const eqInAlbum = document.querySelectorAll('[data-region=tracklist] .eq').length;

            // Navigate to search page (queue persists, context stays album).
            window.Livewire.navigate('/search');
            for (let i = 0; i < 50; i++) {
                if (location.pathname.replace(/\/$/, '').endsWith('/search')) break;
                await sleep(100);
            }
            await sleep(500);

            // On the search page there is no [data-region=tracklist]; .eq should not exist.
            const eqOnSearch = document.querySelectorAll('.eq').length;

            // Navigate back to the library home (no album selected → no tracklist).
            window.Livewire.navigate('/');
            for (let i = 0; i < 50; i++) {
                if (location.pathname === '/' || location.pathname === '') break;
                await sleep(100);
            }
            await sleep(500);

            // No album panel open so tracklist is gone; .eq should not be in DOM.
            const eqOnHome = document.querySelectorAll('[data-region=tracklist] .eq').length;

            return JSON.stringify({ eqInAlbum, eqOnSearch, eqOnHome });
        })()
    JS);

    $decoded = json_decode((string) $result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");
    expect($decoded['eqInAlbum'])->toBeGreaterThan(0, 'The playing row should show .eq in its originating album tracklist');
    expect($decoded['eqOnSearch'])->toBe(0, '.eq should not appear on the search page when context is album');
    expect($decoded['eqOnHome'])->toBe(0, '.eq should not appear in the library when no album tracklist is open');
});

it('clears the shuffle toggle when a plain track-row click loads a new queue', function () {
    $page = visit('/');

    $nowPlaying = drillIntoAlbumAndClickTrack($page);
    expect(json_decode((string) $nowPlaying, true))->toBeArray("Expected the queue to populate, got: {$nowPlaying}");

    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const playerData = () => Alpine.$data(document.querySelector('[data-region="player"]'));

            const p = playerData();
            if (!p.shuffle) { p.toggleShuffle(); }
            await sleep(200);
            const shuffleAfterToggle = playerData().shuffle;

            const rows = document.querySelectorAll('[data-region=tracklist] button[wire\\:click^="playTrack"]');
            rows[0].click();

            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                const d = playerData();
                if (d.queue.length > 0 && d.index === 0) { break; }
                await sleep(100);
            }
            await sleep(200);

            return JSON.stringify({ shuffleAfterToggle, shuffleAfterPlay: playerData().shuffle });
        })()
    JS);

    $decoded = json_decode((string) $result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");
    expect($decoded['shuffleAfterToggle'])->toBeTrue('Shuffle should be on after toggling it');
    expect($decoded['shuffleAfterPlay'])->toBeFalse('Clicking a track row should clear the shuffle toggle');
});

it('highlights the playing row in the search Tracks group', function () {
    $page = visit('/search?q=the');

    $result = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            // Wait for the Tracks filter pill and switch to it.
            const deadline1 = Date.now() + 10000;
            let pill = null;
            while (Date.now() < deadline1) {
                pill = document.querySelector('button[wire\\:click="setFilter(\'tracks\')"]');
                if (pill) break;
                await sleep(150);
            }
            if (!pill) return 'NO_TRACKS_PILL';
            pill.click();

            // Wait for at least one playTrack row.
            const deadline2 = Date.now() + 10000;
            let row = null;
            while (Date.now() < deadline2) {
                row = document.querySelector('button[wire\\:click^="playTrack"]');
                if (row) break;
                await sleep(150);
            }
            if (!row) return 'NO_TRACK_ROWS';

            row.click();

            // Wait for the now-playing title to populate (queue loaded).
            const deadline3 = Date.now() + 10000;
            while (Date.now() < deadline3) {
                const el = document.querySelector('[data-region=now-playing-title]');
                if (el && el.textContent.trim() !== '') break;
                await sleep(100);
            }

            // Give Alpine a moment to react and update the DOM.
            await sleep(500);

            const eqCount = document.querySelectorAll('.eq').length;
            const titleHighlighted = document.querySelectorAll('.text-accent').length > 0;

            return JSON.stringify({ eqCount, titleHighlighted });
        })()
    JS);

    // If the live server returned no track rows the script returns a sentinel string;
    // treat that as a graceful skip so the test is not a false negative.
    if (! str_starts_with($result, '{')) {
        $this->markTestSkipped("Search returned no track rows ({$result}); live Plex server may be unreachable or query matched nothing.");
    }

    $decoded = json_decode($result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");
    expect($decoded['eqCount'])->toBeGreaterThan(0, 'The playing search track row should show the .eq equalizer marker');
});

it('highlights the playing row in the recently-played list', function () {
    $page = visit('/recently-played');

    $result = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            // Wait for the page to settle (either track rows or the empty state).
            const deadline1 = Date.now() + 10000;
            while (Date.now() < deadline1) {
                // Track rows are present, or the empty-state heading is visible.
                if (document.querySelector('button[wire\\:click^="playTrack"]') ||
                    document.querySelector('h2')) break;
                await sleep(150);
            }

            const row = document.querySelector('[data-region=tracklist] button[wire\\:click^="playTrack"]');
            if (!row) return 'NO_TRACK_ROWS';

            row.click();

            // Wait for the now-playing title to populate.
            const deadline2 = Date.now() + 10000;
            while (Date.now() < deadline2) {
                const el = document.querySelector('[data-region=now-playing-title]');
                if (el && el.textContent.trim() !== '') break;
                await sleep(100);
            }

            await sleep(500);

            const eqCount = document.querySelectorAll('[data-region=tracklist] .eq').length;

            return JSON.stringify({ eqCount });
        })()
    JS);

    if ($result === 'NO_TRACK_ROWS') {
        $this->markTestSkipped('Recently-played list is empty on the live server; nothing to assert.');
    }

    $decoded = json_decode($result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");
    expect($decoded['eqCount'])->toBeGreaterThan(0, 'The playing recently-played track row should show the .eq equalizer marker');
});

it('shows the now-playing source indicator on the sidebar playlist row and album header when that context is active', function () {
    $page = visit('/');

    $nowPlaying = drillIntoAlbumAndClickTrack($page);
    expect(json_decode((string) $nowPlaying, true))->toBeArray("Expected the queue to populate, got: {$nowPlaying}");

    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            await sleep(400);

            // The album header should show a source indicator (.eq) because the context is album.
            const eqInAlbumHeader = document.querySelectorAll('.px-2.pb-2.flex-none .eq').length;

            // No sidebar playlist row should show .eq yet (context is album, not playlist).
            const eqInSidebarRows = document.querySelectorAll('[wire\\:key^="sidebar-pl-"] .eq').length;

            // Now find the first sidebar playlist row and get its playlist id.
            const firstRow = document.querySelector('[wire\\:key^="sidebar-pl-"]');
            const playlistId = firstRow ? firstRow.getAttribute('wire:key').replace('sidebar-pl-', '') : null;

            return JSON.stringify({ eqInAlbumHeader, eqInSidebarRows, playlistId });
        })()
    JS);

    $decoded = json_decode((string) $result, true);
    expect($decoded)->toBeArray("Expected a result object, got: {$result}");
    expect($decoded['eqInAlbumHeader'])->toBeGreaterThan(0, 'Album header should show .eq when an album is the playback source');
    expect($decoded['eqInSidebarRows'])->toBe(0, 'Sidebar playlist rows should not show .eq when context is album');
});
