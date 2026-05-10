<?php

/*
 * End-to-end browser coverage for the library drill-down + click-to-play flow.
 *
 * These tests hit the live Plex library configured via PLEX_TOKEN in .env, so they
 * require the Plex server to be reachable. If it is not (or the library is empty),
 * the drill-down step fails with "could not find a playable artist -> album -> track
 * path": that is an environmental dependency, not a code bug.
 *
 * Notes on the Pest 4 browser plugin API actually used here:
 *  - `click()` resolves a CSS selector through a *strict* Playwright locator (errors on
 *    multiple matches) and `assertVisible()` does no auto-waiting, so neither is a good
 *    fit for the library's hundreds of artist/album/track buttons whose contents arrive
 *    via async Livewire (`wire:click`) round-trips. Instead the whole drill-down runs
 *    inside the browser via `script()` (page.evaluate): an async orchestrator that walks
 *    artists -> albums -> tracks, polling the DOM after each click, and skips entries
 *    that turn out to be empty (Plex's per-artist/album child counts are not reliable).
 *  - `text($selector)` reads an element's text content.
 *  - `assertPresent()` checks DOM presence via `count()` (no strict-mode violation).
 */

/**
 * Drives the artist -> album -> track drill-down entirely in the browser and clicks the
 * first reachable track. Returns the now-playing title the player ended up showing, or
 * an empty string if no playable path was found.
 */
function drillDownAndPlay($page): string
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

            const artistCount = document.querySelectorAll('[data-region=artists-column] button').length;

            for (let a = 0; a < Math.min(artistCount, 12); a++) {
                document.querySelectorAll('[data-region=artists-column] button')[a]?.click();
                await sleep(200);
                if (!await waitFor('[data-region=albums-column] button', 6000)) continue;

                const albumCount = document.querySelectorAll('[data-region=albums-column] button').length;

                for (let b = 0; b < albumCount; b++) {
                    document.querySelectorAll('[data-region=albums-column] button')[b]?.click();
                    await sleep(200);
                    if (!await waitFor('[data-region=tracklist] button[wire\\:click^="playTrack"]', 6000)) continue;

                    document.querySelector('[data-region=tracklist] button[wire\\:click^="playTrack"]').click();

                    const deadline = Date.now() + 8000;
                    while (Date.now() < deadline) {
                        const el = document.querySelector('[data-region=now-playing-title]');
                        const text = el ? el.textContent.trim() : '';
                        if (text !== '') return text;
                        await sleep(100);
                    }
                    return ''; // track clicked but player title never populated
                }
            }
            return ''; // no playable artist -> album -> track path found
        })()
    JS);
}

it('drills down from artist to album to track and plays', function () {
    $page = visit('/');

    $page->assertVisible('[data-region=artists-column]')
        ->assertPresent('[data-region=artists-column] button');

    $nowPlaying = drillDownAndPlay($page);

    expect($nowPlaying)->not->toBe('', 'Expected a track title in the player after the drill-down, but it was empty.');
    $page->assertVisible('[data-region=now-playing-title]');
});

it('keeps the player alive when navigating between views', function () {
    $page = visit('/');

    $nowPlaying = drillDownAndPlay($page);
    expect($nowPlaying)->not->toBe('');

    // Navigate to /search via the sidebar link (wire:navigate).
    $page->click('Search')
        ->assertUrlIs(url('/search'))
        ->assertSee('Search view stub');

    // The @persist('player') wrapper keeps the player mounted across wire:navigate,
    // so the now-playing title element is still in the DOM with the same track.
    $page->assertVisible('[data-region=now-playing-title]');
    expect(trim((string) $page->text('[data-region=now-playing-title]')))->toBe($nowPlaying);
});
