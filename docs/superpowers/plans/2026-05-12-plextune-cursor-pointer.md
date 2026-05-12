# Cursor-pointer for Interactive Elements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the mouse cursor change to a pointer (hand) over anything clickable — `<button>`, `[role=button]`, links, and elements with a `wire:click` / `@click` handler — while disabled controls keep the default arrow.

**Architecture:** One `@layer base` block in `resources/css/app.css`. Tailwind v4's Preflight (unlike v3) does not set `cursor: pointer` on `<button>`, which is why most clickable things in the app show the arrow today. Attribute selectors (`[wire\:click]`, `[\@click]`, …) make it self-maintaining. A later `:disabled, [aria-disabled="true"] { cursor: default }` rule overrides for disabled controls. One tiny Blade change adds a `data-control="previous"` test hook to the player's previous button. Verified by a new Pest browser test reading `getComputedStyle().cursor`.

**Tech Stack:** Tailwind CSS v4 (`resources/css/app.css`, built with Vite), Laravel 13 / Livewire 4 SFCs, Alpine.js, Pest 4 (+ pest-plugin-browser / Playwright).

---

## Spec

`docs/superpowers/specs/2026-05-12-plextune-cursor-pointer-design.md` (approved 2026-05-12).

## Background facts (read once)

- `resources/css/app.css` starts with `@import "tailwindcss";` followed by an `@theme { … }` block, then some unlayered rules (`html, body { … }`, `[x-cloak] { display: none !important }`, the `.eq` equalizer styles). `@import "tailwindcss"` declares the `theme, base, components, utilities` layers, so a `@layer base { … }` block added after the import correctly appends to Tailwind's base layer (lower priority than utilities — so a `cursor-pointer` / `cursor-default` utility class on an element still wins; that is intentional).
- Within `@layer base`, two rules with equal specificity are resolved by source order (later wins). `:disabled` and `[aria-disabled="true"]` have specificity (0,1,0); `button` has (0,0,1); `[role="button"]` / `[wire\:click]` / `[\@click]` have (0,1,0). So the `cursor: pointer` rules must come first and the `cursor: default` override second; `:disabled` then beats `button` on specificity and ties-then-wins against `[\@click]` on order.
- Alpine keeps `@click` as the literal attribute name in the rendered DOM (it's an alias for `x-on:click`, not a transform). Blade passes `@click` through verbatim. So `[\@click]` matches those elements. CSS attribute selectors cannot wildcard the attribute *name*, so the modifier variants that exist in this codebase (`@click.prevent` on the sidebar playlist anchor, `@click.stop` on the playlist-detail tracklist spans) are listed explicitly; `wire:click.prevent` / `wire:click.stop` are listed for symmetry.
- `<a href>` already gets a pointer from the browser UA stylesheet, so anchors are not in the rule.
- The persistent player (`resources/views/components/⚡player.blade.php`) is always rendered. Relevant elements: `[data-control="mute"]` is a `<button @click="toggleMute()">` with **no** `cursor-pointer` utility class (good "does the base `button` rule work" probe); `[data-region="now-playing-title"]` is a `<div @click="goToAlbum()" class="… cursor-pointer …">` (already has the utility, so it's a "clickable thing shows pointer" check, not an isolation test); the previous button is `<button type="button" @click="previous()" :disabled="queue.length === 0" class="text-text-2 …">` with an `x-lucide-skip-back` icon — on a fresh page load the queue is empty, so Alpine sets the `disabled` attribute on it shortly after load.
- Browser tests run against built assets (`npm run build`), not `npm run dev`. Every file under `tests/Browser/` needs `uses(RefreshDatabase::class);` (the sidebar's `folders()` query runs on every full-page render). This test only needs the app shell to render, so it does not depend on the Plex server being reachable. `phpunit.xml` registers the `Browser` testsuite and `tests/Pest.php` does `->in('Unit', 'Feature', 'Browser')`, so a new file in `tests/Browser/` is auto-discovered.

## File Structure

- **Modify:** `resources/views/components/⚡player.blade.php` — add `data-control="previous"` to the previous transport button (one attribute, mirrors the existing `data-control="mute"`).
- **Modify:** `resources/css/app.css` — add the `@layer base` block (the main change).
- **Create:** `tests/Browser/CursorTest.php` — new Pest browser test.
- **Modify (docs):** `CLAUDE.md` — one short project-log paragraph.

---

### Task 1: Add the cursor-pointer base styles, the test hook, and the browser test

**Files:**
- Modify: `resources/views/components/⚡player.blade.php` (the previous transport button)
- Create: `tests/Browser/CursorTest.php`
- Modify: `resources/css/app.css` (append a `@layer base` block)

- [ ] **Step 1: Add the `data-control="previous"` test hook to the player's previous button**

In `resources/views/components/⚡player.blade.php`, find the previous-track button (it currently looks like this):

```blade
            <button type="button" @click="previous()" :disabled="queue.length === 0"
                    class="text-text-2 hover:text-white disabled:opacity-40 disabled:hover:text-text-2">
                <x-lucide-skip-back class="w-[18px] h-[18px] fill-current" />
            </button>
```

Add `data-control="previous"` (mirroring the existing `data-control="mute"` on the mute button):

```blade
            <button type="button" data-control="previous" @click="previous()" :disabled="queue.length === 0"
                    class="text-text-2 hover:text-white disabled:opacity-40 disabled:hover:text-text-2">
                <x-lucide-skip-back class="w-[18px] h-[18px] fill-current" />
            </button>
```

Do not change anything else in this file.

- [ ] **Step 2: Write the browser test**

Create `tests/Browser/CursorTest.php`:

```php
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
                nowPlayingTitle: cursorOf(document.querySelector('[data-region="now-playing-title"]')),
                prevButton: cursorOf(prev),
                prevDisabled: !!(prev && prev.disabled),
            };
        })()
    JS);

    // A <button> with no cursor utility class -> the base `button { cursor: pointer }` rule applies.
    expect($cursors['muteButton'])->toBe('pointer');
    // A clickable @click element shows the pointer too.
    expect($cursors['nowPlayingTitle'])->toBe('pointer');
    // Sanity: Alpine actually disabled the previous button on a fresh (empty-queue) load.
    expect($cursors['prevDisabled'])->toBeTrue();
    // A disabled <button> falls back to the default arrow (the `:disabled { cursor: default }` override).
    expect($cursors['prevButton'])->not->toBe('pointer');
});
```

- [ ] **Step 3: Build the current assets and run the test — verify it FAILS**

Run: `npm run build`
Then: `php artisan test --compact --filter="shows a pointer cursor on clickable elements"`
Expected: FAIL. With the current `app.css` there is no `button { cursor: pointer }` rule, so `[data-control="mute"]` resolves to `auto`/`default` (Chromium's UA stylesheet does not set `cursor` on `<button>`), and the `expect($cursors['muteButton'])->toBe('pointer')` assertion fails. (The `nowPlayingTitle` and `prevDisabled` assertions may already pass — that's fine; the test as a whole fails.)

- [ ] **Step 4: Add the `@layer base` block to `resources/css/app.css`**

Append this block to the **end** of `resources/css/app.css` (after the existing `.eq.is-paused` rule):

```css
@layer base {
    button,
    [role="button"],
    summary,
    label[for],
    select,
    [wire\:click], [wire\:click\.prevent], [wire\:click\.stop],
    [x-on\:click],
    [\@click], [\@click\.prevent], [\@click\.stop] {
        cursor: pointer;
    }

    :disabled,
    [aria-disabled="true"] {
        cursor: default;
    }
}
```

- [ ] **Step 5: Rebuild assets and run the test — verify it PASSES**

Run: `npm run build`
Then: `php artisan test --compact --filter="shows a pointer cursor on clickable elements"`
Expected: PASS. `[data-control="mute"]` and `[data-region="now-playing-title"]` now report `pointer`; the disabled previous button reports `default` (the `:disabled` rule, later in the layer, beats `button` on specificity and `[\@click]` on source order).

- [ ] **Step 6: Format**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no changes (only CSS / Blade / a new PHP test file in the project's style) — but run it to be safe.

- [ ] **Step 7: Commit**

```bash
git add resources/css/app.css "resources/views/components/⚡player.blade.php" tests/Browser/CursorTest.php
git commit -m "feat(ui): pointer cursor on clickable elements"
```

(`public/build` is gitignored — do not add it. Confirm with `git status` after `npm run build`; if for some reason it is not ignored, do not stage it anyway.)

---

### Task 2: Record it in the project log and run the full suite

**Files:**
- Modify: `CLAUDE.md` (append a paragraph after the "Playlist tracklist artist/album links" paragraph)

- [ ] **Step 1: Append the project-log paragraph to `CLAUDE.md`**

Add this paragraph immediately after the existing `**Playlist tracklist artist/album links (shipped 2026-05-12, …)**` paragraph (and before the `Git log is the authoritative record…` line):

```markdown
**Cursor-pointer for interactive elements (shipped 2026-05-12, branch `feat/cursor-pointer`).** Spec: `docs/superpowers/specs/2026-05-12-plextune-cursor-pointer-design.md`. Plan: `docs/superpowers/plans/2026-05-12-plextune-cursor-pointer.md`. Tailwind v4's Preflight (unlike v3) does not set `cursor: pointer` on `<button>`, so most clickable things showed the arrow. A `@layer base` block in `resources/css/app.css` now sets `cursor: pointer` on `button`, `[role=button]`, `summary`, `label[for]`, `select`, and any element carrying a `wire:click` / `@click` handler (including the `.prevent` / `.stop` variants used in the codebase), with a later `:disabled, [aria-disabled="true"] { cursor: default }` override (the player's empty-queue prev/next buttons fall back to the arrow). `<a href>` already gets a pointer from the UA stylesheet, so anchors are not in the rule; the block is in `@layer base` so a `cursor-*` utility class on a specific element still wins. The player's previous transport button gained a `data-control="previous"` hook for the test. Test: `tests/Browser/CursorTest.php`.
```

(Do not introduce em dashes or en dashes — the repo rule for docs. Use periods, commas, parentheses.)

- [ ] **Step 2: Run the full test suite**

Run: `php artisan test --compact`
Expected: green. ~247 tests (was 246), ~21 Playwright browser tests. (Browser tests that hit live Plex need it reachable; an environmental failure there is not a code regression. The new `CursorTest` does not depend on Plex.)

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(claude): record the pointer-cursor change"
```

---

## Out of scope (per the spec — do not build)

- No `cursor: not-allowed` on disabled controls (plain `default`).
- No `cursor-grab` / `cursor-grabbing` on the drag-and-drop sources or drop targets.
- No other cursor types, no other Blade/PHP changes. Leave the existing `cursor-pointer` utility classes (e.g. on the playlist-detail tracklist artist/album spans and the now-playing strip) as-is — they become redundant but harmless.

## Self-review notes

- **Spec coverage:** the `@layer base` block (Task 1 step 4) matches the spec's CSS verbatim; the `data-control="previous"` hook and the browser test (Task 1 steps 1–2) match the spec's testing section; the project-log paragraph is Task 2. No spec section uncovered.
- **Placeholder scan:** none — every step has concrete code/commands.
- **Type/naming consistency:** `data-control="previous"`, `[data-control="mute"]`, `[data-region="now-playing-title"]`, the JS keys `muteButton` / `nowPlayingTitle` / `prevButton` / `prevDisabled`, and the test name are used consistently across Task 1's steps and Task 2's CLAUDE.md text.
- **Order dependency:** the `cursor: pointer` rule must precede the `cursor: default` rule inside `@layer base` (noted in the spec and step 4's code shows it correctly).
