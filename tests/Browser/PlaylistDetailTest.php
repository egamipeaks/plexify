<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * End-to-end browser coverage for the sidebar playlist list → playlist detail → play flow.
 *
 * These tests hit the live Plex library configured via PLEX_TOKEN in .env, so they require
 * the Plex server to be reachable and to have at least one audio playlist with tracks. If
 * it is not, the assertions fail with a descriptive message — that is an environmental
 * dependency, not a code bug (same as LibraryDrillDownTest and SearchTest).
 *
 * List interactions are driven via script() (page.evaluate) for the same reason as the other
 * browser tests: the Pest browser plugin's click() uses a strict Playwright locator that
 * errors on multiple matches, and assertVisible() does no auto-waiting.
 */

it('navigates from the sidebar into a playlist and plays a track', function () {
    $page = visit('/');

    // Find a sidebar playlist link whose text content indicates at least 1 song (trackCount > 0).
    // We inspect the full textContent of each link element to avoid selector issues with SVG icons
    // inside the thumbnail placeholder (which also carry the text-text-3 class).
    $targetHref = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                // Each playlist row is a <div wire:key="sidebar-pl-..."> containing an <a href="/playlist/...">.
                const rows = [...document.querySelectorAll('[wire\\:key^="sidebar-pl-"]')];
                if (rows.length > 0) {
                    // The textContent of each row contains "Playlist · N songs"; skip "0 songs".
                    for (const el of rows) {
                        if (!el.textContent.includes('0 songs')) {
                            const link = el.querySelector('a[href]') ?? el;
                            return link.getAttribute('href');
                        }
                    }
                    // Fall back to the first row's link if all show 0 songs.
                    const fallback = rows[0].querySelector('a[href]') ?? rows[0];
                    return fallback.getAttribute('href');
                }
                await sleep(150);
            }
            return null;
        })()
    JS);

    expect($targetHref)->not->toBeNull('Expected at least one playlist in the sidebar (is the Plex server reachable and does it have an audio playlist?)');

    // Click the target playlist link (by href) and wait for the tracklist to render.
    // Large playlists (thousands of tracks) can take several seconds to transfer and parse,
    // so we use a generous 30-second deadline.
    $tracksLoaded = (bool) $page->script('
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const href = '.json_encode($targetHref).';

            const rows = [...document.querySelectorAll(\'[wire\\\\:key^="sidebar-pl-"]\')];
            const link = rows.map(el => el.querySelector(\'a[href]\') ?? el).find(el => el.getAttribute(\'href\') === href);
            if (!link) return false;
            link.click();

            const deadline = Date.now() + 30000;
            while (Date.now() < deadline) {
                if (location.pathname.startsWith(\'/playlist/\')) {
                    if (document.querySelector(\'[wire\\\\:click="retry"]\')) return false;
                    const region = document.querySelector(\'[data-region="tracklist"]\');
                    if (region && region.querySelectorAll(\'button\').length > 0) return true;
                }
                await sleep(300);
            }
            return false;
        })()
    ');

    expect($tracksLoaded)->toBeTrue('Expected to land on /playlist/... and see track rows rendered.');

    // The playlist detail header shows the "PLAYLIST" eyebrow.
    $page->assertSee('PLAYLIST');

    // At least one track row is present.
    $page->assertPresent('[wire\:key^="track-"]');

    // Click the first track row and wait for the persistent player to start playing.
    $nowPlaying = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            const row = document.querySelector('[wire\\:key^="track-"]');
            if (!row) return '';
            row.click();

            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                const el = document.querySelector('[data-region=now-playing-title]');
                const text = el ? el.textContent.trim() : '';
                if (text !== '') return text;
                await sleep(100);
            }
            return '';
        })()
    JS);

    expect($nowPlaying)->not->toBe('', 'Expected the player to show a track title after clicking the first playlist track row.');
    $page->assertVisible('[data-region=now-playing-title]');
});

it('links the artist and album in a playlist track row to the library', function () {
    $page = visit('/');

    // Scrape a sidebar playlist link whose row text indicates at least one track.
    $targetHref = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                const rows = [...document.querySelectorAll('[wire\\:key^="sidebar-pl-"]')];
                if (rows.length > 0) {
                    for (const el of rows) {
                        if (!el.textContent.includes('0 songs')) {
                            const link = el.querySelector('a[href]') ?? el;
                            return link.getAttribute('href');
                        }
                    }
                }
                await sleep(150);
            }
            return null;
        })()
    JS);

    expect($targetHref)->not->toBeNull('Expected at least one non-empty audio playlist in the sidebar (is the Plex server reachable?).');

    // Reused: wait until the comfortable tracklist has rendered with link spans.
    $waitForLinkRows = '
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 30000;
            while (Date.now() < deadline) {
                if (document.querySelector(\'[wire\\\\:click="retry"]\')) return false;
                const row = document.querySelector(\'[wire\\\\:key^="track-"]\');
                if (row && row.querySelectorAll(\'span.cursor-pointer\').length >= 2) return true;
                await sleep(300);
            }
            return false;
        })()
    ';

    // --- Album link: span index 1 in the first row -> /?artist=...&album=...
    $page = visit($targetHref);
    expect((bool) $page->script($waitForLinkRows))->toBeTrue('Expected comfortable track rows with artist+album link spans on the playlist page.');

    $albumNav = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const row = document.querySelector('[wire\\:key^="track-"]');
            const links = [...row.querySelectorAll('span.cursor-pointer')];
            links[1].click();
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                if (location.pathname === '/' && location.search.startsWith('?artist=')) return location.search;
                await sleep(100);
            }
            return location.pathname + location.search;
        })()
    JS);

    expect($albumNav)->toContain('?artist=');
    expect($albumNav)->toContain('&album=');

    // --- Artist link: span index 0 -> /?artist=... with no &album=
    $page = visit($targetHref);
    expect((bool) $page->script($waitForLinkRows))->toBeTrue('Expected comfortable track rows with artist+album link spans on the playlist page (second visit).');

    $artistNav = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const row = document.querySelector('[wire\\:key^="track-"]');
            const links = [...row.querySelectorAll('span.cursor-pointer')];
            links[0].click();
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                if (location.pathname === '/' && location.search.startsWith('?artist=')) return location.search;
                await sleep(100);
            }
            return location.pathname + location.search;
        })()
    JS);

    expect($artistNav)->toContain('?artist=');
    expect($artistNav)->not->toContain('&album=');
});

it('reorders a track in a playlist and restores the original order', function () {
    $page = visit('/');

    $targetHref = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                const rows = [...document.querySelectorAll('[wire\\:key^="sidebar-pl-"]')];
                if (rows.length > 0) {
                    for (const el of rows) {
                        const t = el.textContent;
                        if (!t.includes('0 songs') && !t.includes('1 song')) {
                            const link = el.querySelector('a[href]') ?? el;
                            return link.getAttribute('href');
                        }
                    }
                }
                await sleep(150);
            }
            return null;
        })()
    JS);

    expect($targetHref)->not->toBeNull('Expected an audio playlist with at least 2 tracks in the sidebar (is the Plex server reachable?).');

    $page = visit($targetHref);

    expect((bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 30000;
            while (Date.now() < deadline) {
                if (document.querySelector('[wire\\:click="retry"]')) return false;
                const region = document.querySelector('[data-region="tracklist"]');
                if (region && region.querySelectorAll('[wire\\:key^="track-"]').length >= 2) return true;
                await sleep(300);
            }
            return false;
        })()
    JS))->toBeTrue('Expected at least 2 track rows on the playlist page.');

    // We reorder the playlist twice (move row 2 above row 1, then move it back) so the live Plex playlist is left unchanged. If the test is killed between the two moves the playlist stays reordered — same live-server caveat as the play tests above.
    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const keys = () => [...document.querySelectorAll('[data-region="tracklist"] [wire\\:key^="track-"]')].map(el => el.getAttribute('wire:key'));
            const rows = () => [...document.querySelectorAll('[data-region="tracklist"] [wire\\:key^="track-"]')];
            const itemId = el => {
                const a = el.getAttribute('x-on:dragstart') || el.getAttribute('@dragstart') || el.getAttribute('data-x-on-dragstart') || '';
                const m = a.match(/plextune\/playlist-item',\s*'([^']+)'/);
                return m ? m[1] : null;
            };

            const before = keys();
            let r = rows();
            const dragged = itemId(r[1]);
            const target = itemId(r[0]);
            if (!dragged || !target) return { ok: false, reason: 'could not read playlist-item ids from row attributes' };

            const compEl = document.querySelector('[data-region="tracklist"]').closest('[wire\\:id]');
            const compId = compEl ? compEl.getAttribute('wire:id') : null;
            const comp = compId ? window.Livewire.find(compId) : null;
            if (!comp || typeof comp.call !== 'function') return { ok: false, reason: 'no playlist-detail Livewire component found' };

            await comp.call('moveTrack', dragged, target, 'before');
            for (let i = 0; i < 60 && keys()[0] === before[0]; i++) await sleep(150);
            const swapped = keys();

            // restore: the dragged item is now first; move it back to after the row that follows it
            await comp.call('moveTrack', dragged, itemId(rows()[1]), 'after');
            for (let i = 0; i < 60 && keys()[0] !== before[0]; i++) await sleep(150);
            const restored = keys();

            return { ok: true, reason: '', before, swapped, restored };
        })()
    JS);

    expect($result['ok'])->toBeTrue($result['reason'] ?? 'reorder script failed');
    expect($result['swapped'][0])->toBe($result['before'][1], 'Row 2 should be first after moving it before row 1.');
    expect($result['restored'])->toBe($result['before'], 'Original track order should be restored after the inverse move.');
});
