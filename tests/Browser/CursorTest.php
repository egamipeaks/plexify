<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Verifies the base CSS rule that gives clickable elements a pointer cursor and
 * keeps disabled controls on the default cursor. Runs against built assets
 * (`npm run build`); only needs the app shell to render, so it does not depend
 * on the Plex server being reachable.
 */

it('shows a pointer cursor on clickable elements and the default cursor on disabled ones', function () {
    $page = visit('/');

    $cursors = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            // Alpine sets `disabled` on the empty-queue transport buttons shortly after load.
            const deadline = Date.now() + 6000;
            let prev = null;
            while (Date.now() < deadline) {
                prev = document.querySelector('[data-control="previous"]');
                if (prev && prev.disabled) break;
                await sleep(100);
            }
            const cursorOf = el => el ? getComputedStyle(el).cursor : null;
            return {
                muteButton: cursorOf(document.querySelector('[data-control="mute"]')),
                prevButton: cursorOf(prev),
                prevDisabled: !!(prev && prev.disabled),
            };
        })()
    JS);

    // A <button> with no cursor utility class -> the base `button { cursor: pointer }` rule applies.
    expect($cursors['muteButton'])->toBe('pointer');
    // Sanity: Alpine actually disabled the previous button on a fresh (empty-queue) load.
    expect($cursors['prevDisabled'])->toBeTrue();
    // A disabled <button> falls back to the default arrow (the `:disabled { cursor: default }` override).
    expect($cursors['prevButton'])->not->toBe('pointer');
});
