<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Smoke tests for the /settings page.
 * Hits the live Plex server (ping() runs on page load via the serverInfo computed).
 * Requires Plex to be reachable from this machine — same dependency as the other Browser tests.
 */

it('density toggle persists the compact value to the database', function () {
    $page = visit('/settings');

    $page->assertSee('Settings')
        ->assertSee('Plex Server')
        ->assertSee('Display')
        ->assertSee('About');

    // The "Compact" button text also appears in the explanatory copy, so drive the click
    // via script() to target the button element precisely, avoiding the strict-locator error.
    $page->script(<<<'JS'
        Array.from(document.querySelectorAll('button')).find(b => b.textContent.trim() === 'Compact').click()
    JS);

    // Wait for the Livewire round-trip to complete (the active button gains bg-surface-3).
    $ready = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 6000;
            while (Date.now() < deadline) {
                const active = document.querySelector('button.bg-surface-3');
                if (active && active.textContent.trim() === 'Compact') return true;
                await sleep(100);
            }
            return false;
        })()
    JS);

    expect($ready)->toBeTrue('Expected the Livewire round-trip to mark "Compact" active within 6 seconds.');
    expect(Setting::get('density'))->toBe('compact');
});

it('resync metadata button shows a green confirmation message', function () {
    $page = visit('/settings');

    $page->script("document.querySelector('button[wire\\\\:click=\"resyncMetadata\"]').click()");

    $appeared = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 10000;
            while (Date.now() < deadline) {
                const spans = document.querySelectorAll('span.text-accent');
                for (const s of spans) {
                    if (s.textContent.includes('Cleared at')) return true;
                }
                await sleep(150);
            }
            return false;
        })()
    JS);

    expect($appeared)->toBeTrue('Expected a "Cleared at …" confirmation to appear after resyncing metadata.');
    $page->assertSee('Cleared at');
});
