<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Exercises global keyboard shortcuts:
 *   "/" or Cmd+K / Ctrl+K  → focus the topbar search input
 *   Space                   → toggle play/pause
 *   ArrowRight / ArrowLeft  → next / previous track
 *
 * The shortcuts are registered as a window keydown listener inside
 * audioPlayer().init(). Events are dispatched via script() since the
 * Pest 4 browser plugin has no native key-press helper.
 */

it('focuses the search input when "/" is pressed outside a typing target', function () {
    $page = visit('/');

    // Wait for the page to load (player mounts and registers the keydown listener).
    $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            // Ensure the player Alpine component is ready.
            const deadline = Date.now() + 5000;
            while (Date.now() < deadline) {
                const el = document.querySelector('[data-region="player"]');
                if (el && window.Alpine && Alpine.$data(el)) break;
                await sleep(100);
            }
        })()
    JS);

    // Blur any existing focus so body is the active element.
    $page->script('document.activeElement && document.activeElement.blur()');

    // Dispatch "/" keydown on window (the listener checks !isTypingTarget(e.target)).
    $page->script("window.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }))");

    $activeId = (string) $page->script("document.activeElement ? document.activeElement.id : ''");

    expect($activeId)->toBe('topbar-search');
});

it('focuses the search input on Cmd+K', function () {
    $page = visit('/');

    $page->script('document.activeElement && document.activeElement.blur()');

    $page->script("window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true }))");

    $activeId = (string) $page->script("document.activeElement ? document.activeElement.id : ''");

    expect($activeId)->toBe('topbar-search');
});

it('toggles play/pause on Space when not typing', function () {
    $page = visit('/');

    // Wait for the player to be ready (Alpine initialized).
    $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 5000;
            while (Date.now() < deadline) {
                const el = document.querySelector('[data-region="player"]');
                if (el && window.Alpine && Alpine.$data(el) && Alpine.$data(el).togglePlay) break;
                await sleep(100);
            }
        })()
    JS);

    // Monkey-patch togglePlay on the Alpine player instance to spy on calls,
    // then dispatch Space and verify the spy was called.
    $result = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const playerData = () => Alpine.$data(document.querySelector('[data-region="player"]'));

            const p = playerData();
            if (!p) return 'NO_PLAYER';

            // Patch togglePlay to count calls without actually touching audio.
            let toggleCallCount = 0;
            const originalToggle = p.togglePlay.bind(p);
            p.togglePlay = () => { toggleCallCount++; };

            // Ensure a non-button element has focus.
            document.activeElement && document.activeElement.blur();
            await sleep(50);

            // Dispatch Space — should invoke our patched togglePlay.
            document.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));

            await sleep(200);

            // Restore original.
            p.togglePlay = originalToggle;

            return JSON.stringify({ toggleCallCount });
        })()
    JS);

    $decoded = json_decode($result, true);
    expect($decoded)->toBeArray("Expected result object, got: {$result}");
    expect($decoded['toggleCallCount'])->toBe(1, 'Space should call togglePlay exactly once');
});

it('ignores Space and does not call togglePlay when focus is in the search input', function () {
    $page = visit('/');

    // Wait for the player Alpine component and its togglePlay method to be ready.
    $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 5000;
            while (Date.now() < deadline) {
                const el = document.querySelector('[data-region="player"]');
                if (el && window.Alpine && Alpine.$data(el) && Alpine.$data(el).togglePlay) break;
                await sleep(100);
            }
        })()
    JS);

    $result = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const p = Alpine.$data(document.querySelector('[data-region="player"]'));
            if (!p) return 'NO_PLAYER';

            // Spy on togglePlay — it must NOT be called while an input is focused.
            let toggleCallCount = 0;
            p.togglePlay = () => { toggleCallCount++; };

            // Focus the topbar search input so e.target is a typing target.
            const input = document.getElementById('topbar-search');
            if (!input) return 'NO_INPUT';
            input.focus();
            await sleep(50);

            // Dispatch Space from the focused input so e.target === input.
            input.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
            await sleep(200);

            const activeId = document.activeElement ? document.activeElement.id : '';
            return JSON.stringify({ toggleCallCount, activeId });
        })()
    JS);

    $decoded = json_decode($result, true);
    expect($decoded)->toBeArray("Expected result object, got: {$result}");
    expect($decoded['toggleCallCount'])->toBe(0, 'Space must not call togglePlay while typing in the search input');
    expect($decoded['activeId'])->toBe('topbar-search', 'Focus must remain on the search input');
});

it('calls next() on ArrowRight and previous() on ArrowLeft when not typing', function () {
    $page = visit('/');

    // Wait for the player to be ready (Alpine initialized).
    $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 5000;
            while (Date.now() < deadline) {
                const el = document.querySelector('[data-region="player"]');
                if (el && window.Alpine && Alpine.$data(el) && Alpine.$data(el).next) break;
                await sleep(100);
            }
        })()
    JS);

    // Monkey-patch next/previous to spy on calls, then dispatch keys and verify.
    $result = (string) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const playerData = () => Alpine.$data(document.querySelector('[data-region="player"]'));

            const p = playerData();
            if (!p) return 'NO_PLAYER';

            let nextCallCount = 0;
            let prevCallCount = 0;
            p.next = () => { nextCallCount++; };
            p.previous = () => { prevCallCount++; };

            document.activeElement && document.activeElement.blur();
            await sleep(50);

            // ArrowRight should call next().
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
            await sleep(200);

            // ArrowLeft should call previous().
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
            await sleep(200);

            return JSON.stringify({ nextCallCount, prevCallCount });
        })()
    JS);

    $decoded = json_decode($result, true);
    expect($decoded)->toBeArray("Expected result object, got: {$result}");
    expect($decoded['nextCallCount'])->toBe(1, 'ArrowRight should call next() exactly once');
    expect($decoded['prevCallCount'])->toBe(1, 'ArrowLeft should call previous() exactly once');
});
