# Plextune — persist player volume + wire mute toggle

Status: shipped 2026-05-12. One of the post-M7 polish follow-ups.

## Goal

Two things, both confined to the persistent player:

1. **Persist the player volume across full page reloads.** Today `audioPlayer().volume` resets to `1` on every reload (it survives `wire:navigate` already, via `@persist('player')`, but not a real reload).
2. **Wire the speaker button as a mute toggle.** It currently renders but does nothing.

Non-goals: no `AppSetting` / DB / Livewire involvement, no persistence of the mute state across reloads, no changes to any other view, no audio-quality / normalization settings.

## Affected files

- `resources/views/components/⚡player.blade.php` — the only production file touched.
- `tests/Browser/PlayerVolumeTest.php` — new Pest 4 browser test.

## Design

### Volume persistence (localStorage, client-only)

Volume is a client-only concern, like the play queue, so it lives entirely in the Alpine `audioPlayer()` scope and is mirrored to `localStorage`. No server round-trip.

- Storage key: `plextune.volume`. Value: a number in `[0, 1]` stringified by `localStorage`.
- In `audioPlayer().init()`, immediately before the existing `this.$refs.audio.volume = this.volume;` line: read `localStorage.getItem('plextune.volume')`, `parseFloat` it, and if the result is a finite number in `[0, 1]` assign it to `this.volume`. A missing key, a non-numeric value, or an out-of-range value leaves the `volume: 1` default in place. `init()` may run more than once (Livewire re-runs `@script` on component updates); re-reading `localStorage` each time is idempotent and harmless, so this needs no `firstInit` guard.
- In `setVolume(value)`: after `this.volume = parseFloat(value)` and `this.$refs.audio.volume = this.volume`, write `localStorage.setItem('plextune.volume', this.volume)`. `localStorage` writes are synchronous and cheap, so no debounce is needed even during a slider drag.

### Mute toggle

- New Alpine state on `audioPlayer()`: `muted: false`.
- New method `toggleMute()`: flips the native `this.$refs.audio.muted` and mirrors it into `this.muted`. Using the `<audio>` element's own `.muted` property means `this.volume` is never altered, so unmuting restores the prior level for free and the persisted volume is always the real (unmuted) level.
- `setVolume(value)` also clears the mute state (`this.muted = false; this.$refs.audio.muted = false;`) before applying the new level, so dragging the slider unmutes. This is standard player behavior.
- The speaker `<button>` (currently inert, around `⚡player.blade.php:109`) gets `@click="toggleMute()"`. The icon reflects the state:
  - `<x-lucide-volume-2 x-show="!muted" x-cloak class="w-4 h-4" />`
  - `<x-lucide-volume-x x-show="muted" x-cloak class="w-4 h-4" />`
  - `volume-x` is a standard Lucide icon; the codebase already uses `volume-1` and `volume-2`. Both icons are `x-cloak`'d so neither flashes before Alpine evaluates `muted`.
- The mute state survives `wire:navigate` (it is part of the persisted Alpine scope) but is intentionally not written to `localStorage`; a full reload starts unmuted.

## Testing

All changes are JavaScript inside a Blade file, so there is nothing to unit/feature test in PHP. A new Pest 4 browser test, `tests/Browser/PlayerVolumeTest.php`:

1. **Volume persists across reload.** Visit `/`. Drive the slider via `script()` (evaluate the player scope's `setVolume(0.3)` — or set the range input's value and dispatch `input`). Assert `localStorage['plextune.volume']` is `0.3`. Revisit `/`. Assert the volume `<input type="range">` `value` is `0.3` and the `<audio>` element's `volume` is `0.3`.
2. **Mute toggle.** Visit `/`. Click the speaker button. Assert the `<audio>` element's `muted` is `true` and the `volume-x` icon is visible (`volume-2` hidden). Click again. Assert `muted` is `false` and `volume-2` is visible again.

Browser tests in this project hit the live Plex server for the sidebar's `server-chip` ping, but the page still renders if Plex is unreachable, and none of this test's assertions depend on Plex data.
