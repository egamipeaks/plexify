# Plextune — Persist Player Volume + Wire Mute Toggle — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the player remember its volume across full page reloads (via `localStorage`) and turn the inert speaker button into a mute toggle.

**Architecture:** Pure client-side. Volume lives in the Alpine `audioPlayer()` scope and is mirrored to `localStorage['plextune.volume']` on change, then re-read in `init()`. Mute uses the native `<audio>.muted` property so the stored `volume` is always the real (unmuted) level. No PHP, no Livewire, no `AppSetting`. The only production file touched is `resources/views/components/⚡player.blade.php`.

**Tech Stack:** Livewire 4 SFC + Alpine.js (the player), Pest 4 browser plugin (Playwright) for tests.

**Spec:** `docs/superpowers/specs/2026-05-12-plextune-player-volume-persistence-design.md`

## Background the implementer needs

- The persistent player is a single-file Livewire component at `resources/views/components/⚡player.blade.php`. Its Alpine scope is the `audioPlayer(...)` factory inside the `@script` block at the bottom of that file. The component's root element is `<div data-region="player" x-data="audioPlayer(...)">` around line 28.
- `audioPlayer()` already has `volume: 1` (around line 142), an `init()` method (around line 159) whose body includes `this.$refs.audio.volume = this.volume;` (around line 187), and a `setVolume(value)` method (around line 379). `init()` can run more than once (Livewire re-runs `@script` on component updates) — re-reading `localStorage` is idempotent so this is fine; do **not** gate it behind the existing `firstInit` flag.
- The volume `<input type="range">` is around line 112. Note there is a **second** range input around line 92 (the seek bar), so tests must target the player audio element / Alpine state, not "the range input".
- `x-cloak` is already wired in this project's CSS (`[x-cloak]{display:none}`), used by the sidebar and collapsed headers — safe to use.
- **Gotcha (this codebase):** an uncaught exception thrown inside an Alpine event handler kills Alpine for the rest of the page session. `localStorage` can throw (private mode, quota). So every `localStorage` access in this plan is wrapped in `try { ... } catch (e) {}`.
- Browser tests here hit the live Plex server for the sidebar's `server-chip` ping, but the page still renders if Plex is unreachable, and nothing in these tests depends on Plex data. New browser test files need `uses(RefreshDatabase::class)` (it is not global in `tests/Pest.php`) because rendering the layout runs the sidebar's `folders()` query.
- `php artisan test` should be green before and after (it was 240 tests). These changes add 2 browser tests.

---

### Task 1: Persist the volume to localStorage

**Files:**
- Create: `tests/Browser/PlayerVolumeTest.php`
- Modify: `resources/views/components/⚡player.blade.php` (the `init()` method around line 187, and `setVolume()` around line 379)

- [ ] **Step 1: Write the failing test**

Create `tests/Browser/PlayerVolumeTest.php` with exactly:

```php
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
                if (el && window.Alpine && Alpine.$data(el) && typeof Alpine.$data(el).setVolume === 'function') break;
                await sleep(100);
            }
        })()
    JS);
};

it('persists the volume to localStorage and restores it on reload', function () use ($waitForPlayer) {
    $page = visit('/');
    $waitForPlayer($page);

    $page->script("Alpine.\$data(document.querySelector('[data-region=\"player\"]')).setVolume(0.5)");

    $stored = (string) $page->script("localStorage.getItem('plextune.volume')");
    expect($stored)->toBe('0.5');

    // Re-visit in the same browser context (localStorage survives) and confirm
    // the restored value reached both the Alpine state and the <audio> element.
    $page = visit('/');
    $waitForPlayer($page);

    $vol = (string) $page->script("String(Alpine.\$data(document.querySelector('[data-region=\"player\"]')).volume)");
    expect($vol)->toBe('0.5');

    $audioVol = (string) $page->script("String(document.querySelector('[data-region=\"player\"] audio').volume)");
    expect($audioVol)->toBe('0.5');
});
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `php artisan test --compact --filter='persists the volume to localStorage'`
Expected: FAIL — `localStorage.getItem('plextune.volume')` is `null` (the current `setVolume()` never writes it), so `expect(null)->toBe('0.5')` fails. (If Plex is unreachable the page still renders; the failure should be the assertion, not a page error. If you see a connection error, that is environmental — note it and continue, the assertion logic is what matters.)

- [ ] **Step 3: Implement — write on change, read on init**

In `resources/views/components/⚡player.blade.php`:

(a) Replace the `setVolume` method (currently):

```js
            setVolume(value) {
                this.volume = parseFloat(value);
                this.$refs.audio.volume = this.volume;
            },
```

with:

```js
            setVolume(value) {
                this.volume = parseFloat(value);
                this.muted = false;
                this.$refs.audio.muted = false;
                this.$refs.audio.volume = this.volume;
                try { localStorage.setItem('plextune.volume', this.volume); } catch (e) {}
            },
```

(Yes, this also clears mute — that is intentional and is exercised in Task 2. `this.muted` is added as a property in Task 2; referencing it here before that task is fine because Alpine object properties are plain JS and `setVolume` is only invoked at runtime, but to keep tasks independently runnable, also add the property now: in the object literal, change the line `volume: 1,` to:

```js
            volume: 1,
            muted: false,
```

)

(b) In `init()`, find the line `this.$refs.audio.volume = this.volume;` and replace it with:

```js
                let storedVolume = null;
                try { storedVolume = localStorage.getItem('plextune.volume'); } catch (e) {}
                const parsedVolume = parseFloat(storedVolume);
                if (Number.isFinite(parsedVolume) && parsedVolume >= 0 && parsedVolume <= 1) {
                    this.volume = parsedVolume;
                }
                this.$refs.audio.volume = this.volume;
```

- [ ] **Step 4: Build assets and run the test**

Run: `npm run build`
Then: `php artisan test --compact --filter='persists the volume to localStorage'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/⚡player.blade.php tests/Browser/PlayerVolumeTest.php
git commit -m "feat(player): persist volume to localStorage across reloads"
```

---

### Task 2: Wire the speaker button as a mute toggle

**Files:**
- Modify: `resources/views/components/⚡player.blade.php` (the speaker `<button>` around line 109; add a `toggleMute()` method near `setVolume()` around line 379; the `muted: false` property was already added in Task 1)
- Modify: `tests/Browser/PlayerVolumeTest.php` (append a second test)

- [ ] **Step 1: Write the failing test**

Append to `tests/Browser/PlayerVolumeTest.php` (after the first `it(...)` block, still inside the file, still able to see the `$waitForPlayer` closure defined at the top):

```php
it('toggles mute when the speaker button is clicked', function () use ($waitForPlayer) {
    $page = visit('/');
    $waitForPlayer($page);

    $page->click('[data-control="mute"]');

    $muted = (string) $page->script("document.querySelector('[data-region=\"player\"] audio').muted ? '1' : '0'");
    expect($muted)->toBe('1');

    $iconState = (string) $page->script(<<<'JS'
        (() => {
            const region = document.querySelector('[data-region="player"]');
            const mutedIcon = region.querySelector('[data-icon="muted"]');
            const unmutedIcon = region.querySelector('[data-icon="unmuted"]');
            const shown = el => el && getComputedStyle(el).display !== 'none';
            return (shown(mutedIcon) && !shown(unmutedIcon)) ? '1' : '0';
        })()
    JS);
    expect($iconState)->toBe('1');

    $page->click('[data-control="mute"]');

    $muted = (string) $page->script("document.querySelector('[data-region=\"player\"] audio').muted ? '1' : '0'");
    expect($muted)->toBe('0');
});
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `php artisan test --compact --filter='toggles mute when the speaker button'`
Expected: FAIL — there is no element matching `[data-control="mute"]` yet, so `click()` throws / times out. (Playwright will report the selector was not found.)

- [ ] **Step 3: Implement — mute state, handler, button markup, icon swap**

In `resources/views/components/⚡player.blade.php`:

(a) Replace the speaker button (currently):

```blade
        <div class="flex items-center gap-2 group">
            <button type="button" class="text-text-2 hover:text-white">
                <x-lucide-volume-2 class="w-4 h-4" />
            </button>
            <input type="range" min="0" max="1" step="0.01"
                   :value="volume"
                   @input="setVolume($event.target.value)"
                   class="w-24 accent-white">
        </div>
```

with:

```blade
        <div class="flex items-center gap-2 group">
            <button type="button" data-control="mute" @click="toggleMute()" class="text-text-2 hover:text-white">
                <x-lucide-volume-2 x-show="!muted" x-cloak data-icon="unmuted" class="w-4 h-4" />
                <x-lucide-volume-x x-show="muted" x-cloak data-icon="muted" class="w-4 h-4" />
            </button>
            <input type="range" min="0" max="1" step="0.01"
                   :value="volume"
                   @input="setVolume($event.target.value)"
                   class="w-24 accent-white">
        </div>
```

(`x-lucide-volume-x` is a standard Lucide icon; this codebase already uses `x-lucide-volume-1` and `x-lucide-volume-2`. `data-icon` is forwarded to the rendered `<svg>` by blade-icons and is only there for the test.)

(b) Add a `toggleMute()` method immediately after `setVolume()`:

```js
            toggleMute() {
                this.muted = !this.muted;
                this.$refs.audio.muted = this.muted;
            },
```

- [ ] **Step 4: Build assets and run the test**

Run: `npm run build`
Then: `php artisan test --compact --filter='toggles mute when the speaker button'`
Expected: PASS.

- [ ] **Step 5: Run the whole browser file + sanity check**

Run: `php artisan test --compact tests/Browser/PlayerVolumeTest.php`
Expected: 2 passing tests.

- [ ] **Step 6: Commit**

```bash
git add resources/views/components/⚡player.blade.php tests/Browser/PlayerVolumeTest.php
git commit -m "feat(player): wire the speaker button as a mute toggle"
```

---

### Task 3: Update the project guide

**Files:**
- Modify: `CLAUDE.md` (the post-M7 follow-ups area / wherever volume + mute would be recorded — there is no single milestone bullet for these follow-ups; add a short note in the same style as the existing post-M7 entries, or extend the most relevant existing line)
- Modify: `docs/superpowers/specs/2026-05-12-plextune-player-volume-persistence-design.md` — change the status line from `approved` to `shipped 2026-05-12`

- [ ] **Step 1: Edit `CLAUDE.md`**

Add a sentence near the M7 follow-up paragraph (or as its own short paragraph after it) recording: player volume now persists to `localStorage['plextune.volume']` (restored in `audioPlayer().init()`), and the player's speaker button is a mute toggle (`toggleMute()` flips the native `<audio>.muted`; icons swap via `x-show="muted"`); tests in `tests/Browser/PlayerVolumeTest.php` (2). Keep the no-em-dash rule for docs.

- [ ] **Step 2: Edit the spec status line**

In `docs/superpowers/specs/2026-05-12-plextune-player-volume-persistence-design.md`, change:

```
Status: approved (2026-05-12). One of the post-M7 polish follow-ups.
```

to:

```
Status: shipped 2026-05-12. One of the post-M7 polish follow-ups.
```

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md docs/superpowers/specs/2026-05-12-plextune-player-volume-persistence-design.md
git commit -m "docs(claude): record player volume persistence + mute toggle"
```

---

### Task 4: Full test run

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test --compact`
Expected: all green (was 240; now 242 with the 2 new browser tests). Browser tests require the live Plex server to be reachable for the unrelated sidebar ping; if it is down, the new `PlayerVolumeTest` assertions still hold but other browser tests may fail for that reason — verify any failures are pre-existing / environmental, not caused by these changes.

- [ ] **Step 2: Manual smoke (optional, user does this)**

At `https://plexify.test`: play a track, drag the volume slider down, reload the page — volume stays where you left it. Click the speaker icon — audio mutes and the icon changes to the muted glyph; click again — unmutes. Drag the slider while muted — it unmutes.

---

## Self-review notes

- **Spec coverage:** localStorage key `plextune.volume` (Task 1), read in `init()` with `[0,1]` validation and no `firstInit` guard (Task 1 Step 3b), written in `setVolume` without debounce (Task 1 Step 3a), `try/catch` around all `localStorage` access (Task 1) — covered. Mute: `muted` state (Task 1 Step 3a so both tasks run standalone), `toggleMute()` flipping native `.muted` (Task 2), `setVolume` clears mute (Task 1 Step 3a), speaker `<button>` `@click` + icon swap with `x-cloak` (Task 2), mute not persisted to `localStorage` (nothing writes it — covered by omission) — covered. Tests: volume-persists-across-reload + mute-toggle in `tests/Browser/PlayerVolumeTest.php` (Tasks 1 & 2) — covered. No PHP/unit changes, matching the spec.
- **No placeholders:** every code change shows the before/after text and the exact file location; every command has expected output.
- **Type/name consistency:** `muted` (property), `toggleMute()` (method), `setVolume()` (existing method, extended), `plextune.volume` (storage key), `data-control="mute"`, `data-icon="muted"` / `data-icon="unmuted"`, `[data-region="player"]` — used identically in the implementation and the tests throughout.
- **Ordering note:** the `muted: false` property and the mute-clearing lines in `setVolume` are added in Task 1 (not Task 2) so each task's file state compiles on its own; the button markup and `toggleMute()` arrive in Task 2. The Task 1 test does not touch mute, so this ordering is safe.
