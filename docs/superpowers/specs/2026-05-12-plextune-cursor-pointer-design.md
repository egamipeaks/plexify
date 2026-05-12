# Plextune — cursor-pointer for interactive elements

Status: approved (2026-05-12). A small UI polish item (not part of the M7 follow-up list; raised separately).

## Goal

Make the mouse cursor change to a pointer (hand) when hovering anything clickable, the way Spotify does. Today most clickable things in the app show the default arrow because Tailwind CSS v4's Preflight (unlike v3) does not set `cursor: pointer` on `<button>`. The visible offenders the user called out: track rows (which are `<button>`s), the library artist/album miller rows (`<button>`s), and the player transport controls (play/pause, prev, next, shuffle, repeat, mute — all `<button>`s). The fix should also cover the handful of clickable plain `<div>`/`<img>` elements that use an Alpine `@click` handler (the now-playing cover, track title, and artist in the player), and be self-maintaining so future clickable elements pick it up automatically.

`<a href>` links already get a pointer cursor from the browser's UA stylesheet, so anchors do not need handling.

## Approach

A single `@layer base` block added to `resources/css/app.css`. No edits to any Blade file. The existing explicit `cursor-pointer` utility classes on the playlist-detail tracklist artist/album spans become redundant but stay (harmless).

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

### Why these selectors

- `button` — the dominant case. Tailwind v4 removed `cursor: pointer` from `<button>` in Preflight, so every `<button>` in the app currently shows the arrow. This rule restores it.
- `[role="button"]`, `summary`, `label[for]`, `select` — the other elements that are interactive but do not always get a pointer from the UA stylesheet. None exist in the app today besides `<button>`, but including them is cheap and correct.
- `[wire\:click]`, `[\@click]`, `[x-on\:click]` — clickable plain elements that carry a Livewire or Alpine click handler rather than being a `<button>` (the now-playing cover/title/artist in `resources/views/components/⚡player.blade.php` use `@click="goToAlbum()"` / `@click="goToArtist()"`). Alpine keeps `@click` as the literal attribute name in the rendered DOM, so the attribute selector matches. CSS attribute selectors cannot wildcard the attribute name, so the `.prevent` / `.stop` modifier variants that actually appear in this codebase (`@click.prevent` on the sidebar playlist anchor, `@click.stop` on the playlist-tracklist spans) are listed explicitly. (`wire:click.prevent` / `wire:click.stop` are listed for symmetry even though no current element uses them.) This is the "self-maintaining" part: a new `<div wire:click="...">` or `<span @click="...">` picks up the pointer with no extra work.
- `<a href>` is intentionally absent — browsers already give it a pointer, and a bare `<a>` with no `href` and no handler should not get one.

### Why `@layer base`

Unlayered CSS in Tailwind v4 has higher priority than `@layer` rules and than utilities. Putting these rules in `@layer base` means a `cursor-pointer` / `cursor-default` (or `cursor-grab`, etc.) utility class on a specific element still wins. The existing `cursor-pointer` classes on the playlist-tracklist spans therefore keep working (redundantly), and anyone who needs a different cursor on a clickable element later can override with a utility.

### Disabled handling

Disabled controls should keep the default arrow, not show a pointer. The player's previous/next buttons set the `disabled` attribute via `:disabled="queue.length === 0"`, and various buttons may get `disabled` from `wire:loading.attr`. The `:disabled` rule comes after the pointer rule within `@layer base`, so it overrides for those elements (`:disabled` specificity 0,1,0 beats `button` 0,0,1; `[aria-disabled="true"]` 0,1,0 ties `[role="button"]` and wins on source order). `[aria-disabled="true"]` is included defensively (no element uses it today). No `not-allowed` cursor — just `default`.

## Out of scope

- No `not-allowed` cursor on disabled elements (plain `default`).
- No `cursor-grab` / `cursor-grabbing` on the drag-and-drop sources or drop targets (could be a separate polish item).
- No `cursor-text` adjustments or any other cursor types.
- No changes to any Blade/PHP file. The existing `cursor-pointer` utility classes on the playlist-tracklist artist/album spans are left as-is.

## Affected files

- `resources/css/app.css` — add the `@layer base` block (the only production change).
- `tests/Browser/CursorTest.php` — new Pest browser test (or, equivalently, append the cases to an existing browser test file; a dedicated file is cleaner).

## Testing

A CSS-only change is verified via the browser. New Pest browser test (Playwright), in the style of the other `tests/Browser/` tests. Run `npm run build` first so the compiled CSS includes the new rules (browser tests run against built assets).

`it('shows a pointer cursor on clickable elements', ...)`:

- `visit('/')`.
- Via `script()`/`getComputedStyle`, assert a `<button>` reports `cursor: pointer`. Good target: the player play/pause button `[data-control]`... actually it has no stable hook — use a selector that is reliably present, e.g. the persistent player's transport buttons. The mute button has `data-control="mute"` — assert `getComputedStyle(document.querySelector('[data-control="mute"]')).cursor === 'pointer'`.
- Assert an `@click` non-`<button>` element reports `cursor: pointer`: the now-playing title `[data-region="now-playing-title"]` carries `@click="goToAlbum()"`. Assert its computed `cursor` is `pointer`.
- Assert a `:disabled` `<button>` reports `cursor: default` (or whatever the UA default resolves to — check for `default`, not `pointer`): on a fresh load the queue is empty so the player's previous button is `:disabled`. Select it (it has no `data-*` hook; it's the transport button with `:disabled="queue.length === 0"` and an `x-lucide-skip-back` icon — drive via `script()` to find `button[disabled]` inside the player region, or add a `data-control="previous"` hook to the button as part of this work — adding the hook is acceptable and keeps the test robust). Assert its computed `cursor` is not `pointer` (expected `default`).

Note: browser tests hit the live Plex server elsewhere in the suite, but this test only needs the app shell to render, so it does not depend on Plex being reachable (the player and topbar render regardless). Keep the assertions tolerant of minor cursor-value differences (assert `=== 'pointer'` for the clickable cases; for the disabled case assert `!== 'pointer'`).

Also: `vendor/bin/pint --dirty --format agent` (no PHP changed, so a no-op) and `php artisan test --compact` green at the end.

## Self-review notes

- Placeholder scan: none.
- Internal consistency: the approach, the selector list, and the testing section all reference the same selectors and rationale.
- Scope: single small change, one CSS file + one test file. Fine for one plan.
- Ambiguity: the test's disabled-button target is the one slightly fiddly bit — the spec allows adding a `data-control="previous"` hook to that button to make the selector robust, which the plan should decide on.
