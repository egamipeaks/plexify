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

it('displays the "Density default" label in the Display section', function () {
    $page = visit('/settings');

    $page->assertSee('Density default');

    // Verify data-density is NOT on the body (global wiring was retired in M7).
    $bodyAttr = $page->script("document.body.getAttribute('data-density')");
    expect($bodyAttr)->toBeNull();
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

it('switches the music library and persists the choice', function () {
    $page = visit('/settings');

    $page->assertSee('Music library');

    // Pick whichever option is not currently selected, so the test does not
    // hardcode this particular server's section ids.
    $target = $page->script(<<<'JS'
        (() => {
            const select = Array.from(document.querySelectorAll('select'))
                .find(s => s.getAttribute('wire:model.live') === 'musicSectionId');
            if (!select) return null;
            const other = Array.from(select.options).find(o => o.value !== select.value);
            return other ? {value: other.value} : null;
        })()
    JS);

    expect($target)->not->toBeNull('Expected the Plex server to expose more than one music library.');

    // Drive the change through the Livewire component's own $wire.set() rather than
    // dispatching a synthetic DOM "change" event on the <select>: wire:model.live is
    // implemented on top of an Alpine x-model binding, and forcing select.value plus a
    // synthetic event races Alpine's own hydration of that binding, making the DOM
    // "did it switch?" check pass before the server round-trip (and the AppSetting
    // write) has actually completed. $wire.set(...) returns a promise that resolves
    // only after the request finishes, so awaiting it is a reliable synchronization
    // point. This matches the window.Livewire.find(...) pattern already used in
    // PlaylistDetailTest and SidebarFoldersTest to drive Livewire actions directly.
    $result = $page->script(<<<JS
        (async () => {
            const select = Array.from(document.querySelectorAll('select'))
                .find(s => s.getAttribute('wire:model.live') === 'musicSectionId');
            const compEl = select ? select.closest('[wire\\\\:id]') : null;
            const compId = compEl ? compEl.getAttribute('wire:id') : null;
            const comp = compId ? window.Livewire.find(compId) : null;
            if (!comp) return {ok: false, reason: 'no settings Livewire component found'};

            await comp.set('musicSectionId', {$target['value']});

            return {ok: true, reason: ''};
        })()
    JS);

    expect($result['ok'])->toBeTrue($result['reason']);
    expect(AppSetting::musicSectionId())->toBe((int) $target['value']);
});

it('shows the effective library name on the sidebar server chip', function () {
    $page = visit('/settings');

    $library = $page->script(<<<'JS'
        (() => {
            const select = Array.from(document.querySelectorAll('select'))
                .find(s => s.getAttribute('wire:model.live') === 'musicSectionId');
            return select ? select.options[select.selectedIndex].text : null;
        })()
    JS);

    expect($library)->not->toBeNull('Expected a music library select on the settings page.');

    // Read the chip itself rather than using assertSee, which would match the
    // library name in the select and pass without the chip changing at all.
    $chipText = $page->script(<<<'JS'
        (() => {
            const chip = document.querySelector('[wire\\:click="refresh"]');
            return chip ? chip.innerText : null;
        })()
    JS);

    expect($chipText)->not->toBeNull('Expected the server chip to be present in the sidebar.')
        ->and($chipText)->toContain($library);
});
