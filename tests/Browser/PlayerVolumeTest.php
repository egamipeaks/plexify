<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The player volume is persisted to localStorage under 'plextune.volume' and
 * restored in audioPlayer().init(); the speaker button toggles mute via the
 * native <audio>.muted property. Both are pure client-side JS, so they're
 * exercised through the browser. Tests hit live Plex for the sidebar ping but
 * none of the assertions depend on Plex data.
 */

$waitForPlayer = function ($page): void {
    $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 5000;
            while (Date.now() < deadline) {
                const el = document.querySelector('[data-region="player"]');
                if (el && window.Alpine && Alpine.$data(el) && typeof Alpine.$data(el).setVolume === 'function') return true;
                await sleep(100);
            }
            return false;
        })()
    JS);
};

it('persists the volume to localStorage and restores it on reload', function () use ($waitForPlayer) {
    $page = visit('/');
    $waitForPlayer($page);

    $page->script("Alpine.\$data(document.querySelector('[data-region=\"player\"]')).setVolume(0.5)");

    $stored = (string) $page->script("localStorage.getItem('plextune.volume')");
    expect($stored)->toBe('0.5');

    // Navigate to the same page in the same browser context (localStorage survives)
    // and confirm the restored value reached both the Alpine state and the <audio> element.
    $page->navigate('/');
    $waitForPlayer($page);

    $vol = (string) $page->script("String(Alpine.\$data(document.querySelector('[data-region=\"player\"]')).volume)");
    expect($vol)->toBe('0.5');

    $audioVol = (string) $page->script("String(document.querySelector('[data-region=\"player\"] audio').volume)");
    expect($audioVol)->toBe('0.5');
});

it('toggles mute when the speaker button is clicked', function () use ($waitForPlayer) {
    $page = visit('/');
    $waitForPlayer($page);

    $page->click('[data-control="mute"]');

    $muted = (string) $page->script("document.querySelector('[data-region=\"player\"] audio').muted ? '1' : '0'");
    expect($muted)->toBe('1');

    // x-show is applied via an Alpine reactive effect that flushes on a
    // microtask, so poll briefly rather than reading the display state in the
    // same tick as the click.
    $iconState = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const region = document.querySelector('[data-region="player"]');
            const shown = el => el && getComputedStyle(el).display !== 'none';
            const deadline = Date.now() + 2000;
            while (Date.now() < deadline) {
                if (shown(region.querySelector('[data-icon="muted"]')) && !shown(region.querySelector('[data-icon="unmuted"]'))) return '1';
                await sleep(50);
            }
            return '0';
        })()
    JS);
    expect($iconState)->toBe('1');

    $page->click('[data-control="mute"]');

    $muted = (string) $page->script("document.querySelector('[data-region=\"player\"] audio').muted ? '1' : '0'");
    expect($muted)->toBe('0');

    $iconState = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const region = document.querySelector('[data-region="player"]');
            const shown = el => el && getComputedStyle(el).display !== 'none';
            const deadline = Date.now() + 2000;
            while (Date.now() < deadline) {
                if (shown(region.querySelector('[data-icon="unmuted"]')) && !shown(region.querySelector('[data-icon="muted"]'))) return '1';
                await sleep(50);
            }
            return '0';
        })()
    JS);
    expect($iconState)->toBe('1');
});
