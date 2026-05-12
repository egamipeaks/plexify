<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * End-to-end coverage for the Alpine-owned topbar search input → search overlay flow.
 * The topbar input now dispatches a 'plextune-search' browser event (debounced 300 ms)
 * which the <livewire:search> component listens for. No navigation occurs; the overlay
 * renders in-place as an absolute element over the main pane.
 *
 * Hits the live Plex library configured via PLEX_TOKEN in .env; if Plex is unreachable
 * the result-dependent assertions (overlay present) may fail — environmental, not a code bug.
 *
 * As with LibraryDrillDownTest, list interactions are driven via script() (page.evaluate)
 * because the Pest browser plugin's click()/assertVisible() are strict and non-waiting.
 */

it('shows search results as an overlay without navigating away, keeping focus', function () {
    $page = visit('/');

    // Focus the input and fire a debounced input event.
    $page->script("document.getElementById('topbar-search').focus()");
    $page->script("const i = document.getElementById('topbar-search'); i.value = 'the'; i.dispatchEvent(new Event('input', { bubbles: true }));");

    // Wait for the overlay to appear (Livewire round-trip after 300 ms debounce).
    $overlayAppeared = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                if (document.querySelectorAll('[data-region="search-overlay"]').length > 0) return true;
                await sleep(150);
            }
            return false;
        })()
    JS);

    expect($overlayAppeared)->toBeTrue('Expected the search overlay to appear without a navigation.');

    // Active element stays in the topbar input.
    $page->assertScript('document.activeElement.id', 'topbar-search');

    // The input value is preserved.
    $page->assertScript("document.getElementById('topbar-search').value", 'the');

    // We are still on the home page (no navigation happened).
    $page->assertScript('location.pathname', '/');
});

it('keeps both characters when typing a second char after a pause', function () {
    $page = visit('/');

    $page->script("document.getElementById('topbar-search').focus()");

    // Type 'a', triggering the debounce.
    $page->script("const i = document.getElementById('topbar-search'); i.value = 'a'; i.dispatchEvent(new Event('input', { bubbles: true }));");

    // Wait past the debounce + one Livewire round-trip (~700 ms total).
    $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            await sleep(700);
        })()
    JS);

    // Now type 'ab' (simulating a second character added after the first round-trip).
    $page->script("const i = document.getElementById('topbar-search'); i.value = 'ab'; i.dispatchEvent(new Event('input', { bubbles: true }));");

    // Wait for Livewire to settle.
    $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            await sleep(700);
        })()
    JS);

    $page->assertScript("document.getElementById('topbar-search').value", 'ab');
    $page->assertScript('document.activeElement.id', 'topbar-search');
});

it('clears the overlay with the clear button', function () {
    $page = visit('/');

    // Seed the input and fire the event so the overlay appears.
    $page->script("const i = document.getElementById('topbar-search'); i.value = 'the'; i.dispatchEvent(new Event('input', { bubbles: true }));");

    // Wait for the overlay.
    $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                if (document.querySelectorAll('[data-region="search-overlay"]').length > 0) return true;
                await sleep(150);
            }
            return false;
        })()
    JS);

    $page->assertPresent('[data-region="search-overlay"]');

    // Click the clear button (x-show makes it visible when term !== '').
    $page->script("document.querySelector('[data-region=\"search-clear\"]').click()");

    // Wait for the overlay to disappear.
    $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 5000;
            while (Date.now() < deadline) {
                if (document.querySelectorAll('[data-region="search-overlay"]').length === 0) return true;
                await sleep(150);
            }
            return false;
        })()
    JS);

    $page->assertScript("document.querySelectorAll('[data-region=\"search-overlay\"]').length", 0);
    $page->assertScript("document.getElementById('topbar-search').value", '');
});

it('opens the overlay on a direct visit to /?q=', function () {
    $page = visit('/?q=the');

    // The topbar seeds term from ?q=, then on page-load the search component re-hydrates
    // its own #[Url] prop and renders the overlay.
    $overlayAppeared = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                if (document.querySelectorAll('[data-region="search-overlay"]').length > 0) return true;
                await sleep(150);
            }
            return false;
        })()
    JS);

    expect($overlayAppeared)->toBeTrue('Expected overlay on direct /?q=the visit.');
});

it('plays a track from the search results', function () {
    $page = visit('/');

    // Trigger the search overlay.
    $page->script("const i = document.getElementById('topbar-search'); i.value = 'the'; i.dispatchEvent(new Event('input', { bubbles: true }));");

    $nowPlaying = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            // Wait for the overlay to appear.
            const deadline0 = Date.now() + 8000;
            while (Date.now() < deadline0) {
                if (document.querySelectorAll('[data-region="search-overlay"]').length > 0) break;
                await sleep(150);
            }

            // Switch to the Tracks filter so only track rows are present.
            const deadline1 = Date.now() + 8000;
            let pill = null;
            while (Date.now() < deadline1) {
                pill = [...document.querySelectorAll('button[wire\\:click="setFilter(\'tracks\')"]')][0];
                if (pill) break;
                await sleep(150);
            }
            if (!pill) return '';
            pill.click();

            // Wait for a track row and click it.
            const deadline2 = Date.now() + 8000;
            let row = null;
            while (Date.now() < deadline2) {
                row = document.querySelector('button[wire\\:click^="playTrack"]');
                if (row) break;
                await sleep(150);
            }
            if (!row) return '';
            row.click();

            const deadline3 = Date.now() + 8000;
            while (Date.now() < deadline3) {
                const el = document.querySelector('[data-region=now-playing-title]');
                const text = el ? el.textContent.trim() : '';
                if (text !== '') return text;
                await sleep(100);
            }
            return '';
        })()
    JS);

    expect($nowPlaying)->not->toBe('', 'Expected the player to show a track title after clicking a search result.');
    $page->assertVisible('[data-region=now-playing-title]');
});
