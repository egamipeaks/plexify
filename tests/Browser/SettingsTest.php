<?php

use App\Models\Setting;
use App\Support\AppSetting;
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
    // Scope the check to the Display section to avoid matching the Playback On/Off buttons.
    $ready = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 6000;
            while (Date.now() < deadline) {
                const section = Array.from(document.querySelectorAll('section')).find(s => s.querySelector('h2') && s.querySelector('h2').textContent.trim() === 'Display');
                if (section) {
                    const active = section.querySelector('button.bg-surface-3');
                    if (active && active.textContent.trim() === 'Compact') return true;
                }
                await sleep(100);
            }
            return false;
        })()
    JS);

    expect($ready)->toBeTrue('Expected the Livewire round-trip to mark "Compact" active within 6 seconds.');
    expect(Setting::get('density'))->toBe('compact');
});

it('scrobble toggle persists the off value to the database', function () {
    $page = visit('/settings');

    $page->assertSee('Scrobble plays to Plex');

    // Click the "Off" button in the Playback section via script to avoid strict-locator ambiguity.
    $page->script(<<<'JS'
        Array.from(document.querySelectorAll('section')).find(s => s.querySelector('h2') && s.querySelector('h2').textContent.trim() === 'Playback')
            ?.querySelectorAll('button')
            [1]
            ?.click()
    JS);

    // Wait for the Livewire round-trip: the "Off" button gains bg-surface-3.
    $ready = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 6000;
            while (Date.now() < deadline) {
                const section = Array.from(document.querySelectorAll('section')).find(s => s.querySelector('h2') && s.querySelector('h2').textContent.trim() === 'Playback');
                if (section) {
                    const buttons = section.querySelectorAll('button');
                    if (buttons[1] && buttons[1].classList.contains('bg-surface-3')) return true;
                }
                await sleep(100);
            }
            return false;
        })()
    JS);

    expect($ready)->toBeTrue('Expected "Off" button to become active within 6 seconds.');
    expect(AppSetting::scrobbleEnabled())->toBeFalse();

    // Navigate away and back, assert the "Off" button is still active.
    visit('/');
    $page2 = visit('/settings');

    $offActive = (bool) $page2->script(<<<'JS'
        (() => {
            const section = Array.from(document.querySelectorAll('section')).find(s => s.querySelector('h2') && s.querySelector('h2').textContent.trim() === 'Playback');
            if (!section) return false;
            const buttons = section.querySelectorAll('button');
            return buttons[1] && buttons[1].classList.contains('bg-surface-3');
        })()
    JS);

    expect($offActive)->toBeTrue('Expected "Off" button to remain active after navigating away and back.');
});

it('sets data-density on the body after selecting Compact on settings', function () {
    // Start from a known state: comfortable.
    Setting::set('density', 'comfortable');

    $page = visit('/settings');

    // Click the "Compact" density button via script to avoid strict-locator issues.
    $page->script(<<<'JS'
        Array.from(document.querySelectorAll('button')).find(b => b.textContent.trim() === 'Compact').click()
    JS);

    // Wait for the Livewire round-trip to complete.
    $ready = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 6000;
            while (Date.now() < deadline) {
                const section = Array.from(document.querySelectorAll('section')).find(s => s.querySelector('h2') && s.querySelector('h2').textContent.trim() === 'Display');
                if (section) {
                    const active = section.querySelector('button.bg-surface-3');
                    if (active && active.textContent.trim() === 'Compact') return true;
                }
                await sleep(100);
            }
            return false;
        })()
    JS);

    expect($ready)->toBeTrue('Livewire round-trip did not complete within 6 seconds.');

    // Navigate to the library and assert data-density on the body.
    $libraryPage = visit('/');

    $density = $libraryPage->script("document.body.getAttribute('data-density')");

    expect($density)->toBe('compact');
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
