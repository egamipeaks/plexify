<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * End-to-end coverage for the topbar -> /search?q= flow and the search results view.
 * Hits the live Plex library configured via PLEX_TOKEN in .env; if Plex is unreachable
 * (or the library has nothing matching "the"), the result-dependent assertions fail —
 * that is an environmental dependency, not a code bug.
 *
 * As with LibraryDrillDownTest, list interactions are driven via script() (page.evaluate)
 * because the Pest browser plugin's click()/assertVisible() are strict and non-waiting.
 */

it('navigates to /search when typing in the topbar and renders grouped results', function () {
    $page = visit('/');

    // Type into the persistent topbar search input; wire:model.live.debounce.300ms +
    // updatedQuery() then redirect(navigate: true) to /search?q=the.
    $page->fill('input[placeholder="What do you want to play?"]', 'the');

    $arrived = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                if (location.pathname === '/search' && new URLSearchParams(location.search).get('q') === 'the') {
                    // wait for at least one result row to render
                    if (document.querySelectorAll('section button, section > div > div > div').length > 0) return true;
                }
                await sleep(150);
            }
            return false;
        })()
    JS);

    expect($arrived)->toBeTrue('Expected to land on /search?q=the with results rendered.');

    // The filter pills are present.
    $page->assertSee('All')->assertSee('Artists')->assertSee('Tracks');
});

it('plays a track from the search results', function () {
    $page = visit('/search?q=the');

    $nowPlaying = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

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

            // Wait for a track row (button with wire:click^="playTrack") and click it.
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

it('keeps the typed query in the topbar input after the search re-runs', function () {
    // Regression: the topbar bound `query` to a plain prop, so the redirect(navigate: true)
    // that runs a fresh search landed on a topbar whose `query` was '' and Livewire then
    // cleared the input. Binding it with #[Url(as: 'q')] re-hydrates it from ?q= on arrival.
    $page = visit('/search?q=the');

    $page->fill('input[placeholder="What do you want to play?"]', 'weezer');

    $value = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const sel = 'input[placeholder="What do you want to play?"]';
            const deadline = Date.now() + 10000;
            while (Date.now() < deadline) {
                if (new URLSearchParams(location.search).get('q') === 'weezer') break;
                await sleep(150);
            }
            await sleep(800); // let the navigate/morph settle
            const el = document.querySelector(sel);
            return el ? el.value : '<<no input>>';
        })()
    JS);

    expect($value)->toBe('weezer');
});
