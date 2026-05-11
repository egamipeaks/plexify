# M6 Settings Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `/settings` page with three sections (Plex Server with a working cross-request cache flush, Display density toggle persist-only, and About) replacing the current 12-line stub.

**Architecture:** New `App\Support\AppSetting` typed accessor wraps the existing `Setting` Eloquent model. `PlexCache` gains a cross-request key index (`plex:_index`) so `flushAll()` actually works from a button click. A single Livewire 4 single-file page component (`pages::settings`) renders all three sections with a centred max-w-3xl column of `bg-surface-1` cards.

**Tech Stack:** Laravel 13, Livewire 4, Pest 4 (Unit, Feature, Browser), Tailwind 4, existing `App\Models\Setting`, `App\Services\Plex\PlexClient`, `App\Services\Plex\PlexCache`.

**Spec:** `docs/superpowers/specs/2026-05-11-plextune-milestone-6-settings-design.md`.

---

## File map

Created:

- `app/Support/AppSetting.php` — typed accessor over `Setting`. Static methods, no constructor.
- `tests/Unit/AppSettingTest.php` — unit tests for the accessor.
- `tests/Feature/SettingsViewTest.php` — Livewire component tests for the page.
- `tests/Browser/SettingsTest.php` — Playwright smoke test.

Modified:

- `app/Services/Plex/PlexCache.php` — replace in-memory `$keys` array with a cross-request `plex:_index` cache entry; update `remember`, `forget`, `flushAll`.
- `tests/Unit/PlexCacheTest.php` — extend with cross-request index assertions.
- `config/app.php` — add `'version' => env('APP_VERSION', 'dev')`.
- `.env.example` — add `APP_VERSION=dev`.
- `resources/views/pages/⚡settings.blade.php` — replace 12-line stub with the real component.

No new migrations. No new models. The route `/settings` and the topbar gear icon are already wired.

---

## Project conventions reminder

- Livewire 4 SFCs live at `resources/views/components/⚡<name>.blade.php` and pages at `resources/views/pages/⚡<name>.blade.php`. The `⚡` is a literal U+26A1 emoji prefix. Component tags self-close: `<livewire:name />`.
- Single stable root `<div>` for every Livewire component (no top-level `@if/@else` without a wrapping element).
- `#[Computed]` methods cannot autowire DI — resolve services via `app(PlexClient::class)`. Action methods (`updatedX`, `wire:click` handlers) DO autowire, so prefer constructor-style param injection there.
- Run `vendor/bin/pint --dirty --format agent` after touching PHP.
- Tests that hit `Setting` (database) require `uses(RefreshDatabase::class)`. It is NOT global in `tests/Pest.php`.
- `Livewire::test('pages::settings')` will also render the sidebar (via the page layout). The sidebar in turn renders `<livewire:server-chip />` which calls `PlexClient::ping()`. When stubbing `PlexClient`, stub `ping()` too.
- Browser tests hit the live Plex server; skip or guard tests that depend on a specific Plex response if needed. The resync browser test in this plan is designed to work either way (success path).

---

## Task 1: `PlexCache` cross-request key index

**Files:**
- Modify: `app/Services/Plex/PlexCache.php`
- Test: `tests/Unit/PlexCacheTest.php`

**Context:** Today `flushAll()` iterates an in-memory `$keys` array populated only during the current request. From a button click it does nothing useful. Replace it with an index stored under `plex:_index`.

- [ ] **Step 1: Write the failing tests**

Open `tests/Unit/PlexCacheTest.php`. Add the following tests at the end of the file (before the closing line):

```php
test('remember appends the namespaced key to plex:_index', function () {
    $cache = new PlexCache;

    $cache->remember('foo', 60, fn () => 'value-foo');

    expect(Cache::get('plex:_index'))->toBe(['plex:foo']);
});

test('remember does not duplicate an existing index entry on a cache hit', function () {
    $cache = new PlexCache;

    $cache->remember('foo', 60, fn () => 'value-foo');
    $cache->remember('foo', 60, fn () => 'value-foo-again');

    expect(Cache::get('plex:_index'))->toBe(['plex:foo']);
});

test('remember appends each distinct key once across calls', function () {
    $cache = new PlexCache;

    $cache->remember('foo', 60, fn () => 'a');
    $cache->remember('bar', 60, fn () => 'b');
    $cache->remember('foo', 60, fn () => 'a-again');

    expect(Cache::get('plex:_index'))->toEqualCanonicalizing(['plex:foo', 'plex:bar']);
});

test('forget removes the entry from plex:_index', function () {
    $cache = new PlexCache;

    $cache->remember('foo', 60, fn () => 'a');
    $cache->remember('bar', 60, fn () => 'b');

    $cache->forget('foo');

    expect(Cache::get('plex:_index'))->toBe(['plex:bar']);
    expect(Cache::get('plex:foo'))->toBeNull();
});

test('flushAll forgets every tracked key and clears the index', function () {
    $cache = new PlexCache;

    $cache->remember('foo', 60, fn () => 'a');
    $cache->remember('bar', 60, fn () => 'b');

    $cache->flushAll();

    expect(Cache::get('plex:foo'))->toBeNull();
    expect(Cache::get('plex:bar'))->toBeNull();
    expect(Cache::get('plex:_index'))->toBeNull();
});

test('flushAll is a no-op when the index is empty', function () {
    $cache = new PlexCache;

    $cache->flushAll();

    expect(Cache::get('plex:_index'))->toBeNull();
});

test('flushAll works across instances (cross-request)', function () {
    $writer = new PlexCache;
    $writer->remember('foo', 60, fn () => 'a');
    $writer->remember('bar', 60, fn () => 'b');

    $clearer = new PlexCache;
    $clearer->flushAll();

    expect(Cache::get('plex:foo'))->toBeNull();
    expect(Cache::get('plex:bar'))->toBeNull();
});
```

Make sure the file's `use` statements at the top include `Illuminate\Support\Facades\Cache;` (it likely already does — confirm and add if missing). Also confirm the file already includes `use App\Services\Plex\PlexCache;`.

- [ ] **Step 2: Run the tests and confirm they fail**

```bash
php artisan test --compact --filter=PlexCacheTest
```

Expected: the new tests fail (most likely because `Cache::get('plex:_index')` returns `null` after `remember`, since the current code never writes the index). Existing PlexCacheTest assertions still pass.

- [ ] **Step 3: Replace `PlexCache` implementation**

Overwrite `app/Services/Plex/PlexCache.php` with:

```php
<?php

namespace App\Services\Plex;

use Closure;
use Illuminate\Support\Facades\Cache;

class PlexCache
{
    public const TTL_RESOURCES = 3600;          // 1 hour

    public const TTL_SECTIONS = 86400;          // 24 hours

    public const TTL_ARTISTS = 86400;           // 24 hours

    public const TTL_ALBUMS = 86400;            // 24 hours

    public const TTL_TRACKS = 604800;           // 7 days

    public const TTL_PLAYLISTS = 300;           // 5 minutes

    private const PREFIX = 'plex:';

    private const INDEX_KEY = 'plex:_index';

    private const INDEX_TTL = self::TTL_TRACKS;

    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $namespaced = self::PREFIX.$key;

        $value = Cache::remember($namespaced, $ttl, $callback);

        $this->trackKey($namespaced);

        return $value;
    }

    public function forget(string $key): void
    {
        $namespaced = self::PREFIX.$key;
        Cache::forget($namespaced);
        $this->untrackKey($namespaced);
    }

    public function flushAll(): void
    {
        $index = Cache::get(self::INDEX_KEY, []);

        foreach ($index as $namespaced) {
            Cache::forget($namespaced);
        }

        Cache::forget(self::INDEX_KEY);
    }

    private function trackKey(string $namespaced): void
    {
        $index = Cache::get(self::INDEX_KEY, []);

        if (in_array($namespaced, $index, true)) {
            return;
        }

        $index[] = $namespaced;
        Cache::put(self::INDEX_KEY, $index, self::INDEX_TTL);
    }

    private function untrackKey(string $namespaced): void
    {
        $index = Cache::get(self::INDEX_KEY, []);

        $filtered = array_values(array_filter($index, fn ($k) => $k !== $namespaced));

        if (empty($filtered)) {
            Cache::forget(self::INDEX_KEY);

            return;
        }

        Cache::put(self::INDEX_KEY, $filtered, self::INDEX_TTL);
    }
}
```

Note: `serializable_classes` in `config/cache.php` does NOT need a new entry — we're storing a plain array of strings under `plex:_index`, not a DTO.

- [ ] **Step 4: Run all PlexCacheTest tests**

```bash
php artisan test --compact --filter=PlexCacheTest
```

Expected: all tests pass (new ones plus the original ones).

- [ ] **Step 5: Run the full suite to confirm nothing else regressed**

```bash
php artisan test --compact
```

Expected: green.

- [ ] **Step 6: Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Services/Plex/PlexCache.php tests/Unit/PlexCacheTest.php
git commit -m "feat(plex-cache): cross-request key index so flushAll works from a button click" \
  -m "Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `App\Support\AppSetting` typed accessor

**Files:**
- Create: `app/Support/AppSetting.php`
- Test: `tests/Unit/AppSettingTest.php`

**Context:** Wraps `Setting::get`/`Setting::set` with typed methods and a validated allowed-values list. Single source of truth for default values and allowed values.

- [ ] **Step 1: Create the failing unit test**

Write `tests/Unit/AppSettingTest.php`:

```php
<?php

use App\Models\Setting;
use App\Support\AppSetting;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('density returns the default value when no setting is stored', function () {
    expect(AppSetting::density())->toBe('comfortable');
});

test('density returns the stored value', function () {
    Setting::set('density', 'compact');

    expect(AppSetting::density())->toBe('compact');
});

test('setDensity persists the value', function () {
    AppSetting::setDensity('compact');

    expect(Setting::get('density'))->toBe('compact');
    expect(AppSetting::density())->toBe('compact');
});

test('setDensity rejects an invalid value', function () {
    AppSetting::setDensity('massive');
})->throws(InvalidArgumentException::class);

test('setDensity overwrites an existing value', function () {
    AppSetting::setDensity('compact');
    AppSetting::setDensity('comfortable');

    expect(AppSetting::density())->toBe('comfortable');
});
```

- [ ] **Step 2: Run and confirm the tests fail**

```bash
php artisan test --compact --filter=AppSettingTest
```

Expected: failure because `App\Support\AppSetting` does not exist.

- [ ] **Step 3: Create the class**

Write `app/Support/AppSetting.php`:

```php
<?php

namespace App\Support;

use App\Models\Setting;
use InvalidArgumentException;

class AppSetting
{
    public const DENSITY_COMFORTABLE = 'comfortable';

    public const DENSITY_COMPACT = 'compact';

    private const DENSITY_DEFAULT = self::DENSITY_COMFORTABLE;

    private const DENSITY_ALLOWED = [
        self::DENSITY_COMFORTABLE,
        self::DENSITY_COMPACT,
    ];

    public static function density(): string
    {
        $value = Setting::get('density', self::DENSITY_DEFAULT);

        if (! in_array($value, self::DENSITY_ALLOWED, true)) {
            return self::DENSITY_DEFAULT;
        }

        return $value;
    }

    public static function setDensity(string $value): void
    {
        if (! in_array($value, self::DENSITY_ALLOWED, true)) {
            throw new InvalidArgumentException("Invalid density value: {$value}");
        }

        Setting::set('density', $value);
    }
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

```bash
php artisan test --compact --filter=AppSettingTest
```

Expected: green.

- [ ] **Step 5: Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Support/AppSetting.php tests/Unit/AppSettingTest.php
git commit -m "feat(settings): AppSetting typed accessor wrapping the Setting model" \
  -m "Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: `config/app.php` `version` key and `.env.example`

**Files:**
- Modify: `config/app.php`
- Modify: `.env.example`

**Context:** Cheap addition consumed by the About section.

- [ ] **Step 1: Add the version key to `config/app.php`**

Open `config/app.php`. Find the `'name' => env('APP_NAME', 'Laravel'),` line. Immediately after it add:

```php
    'version' => env('APP_VERSION', 'dev'),
```

If `config/app.php` already exposes a `version` key (unlikely in a fresh Laravel app — Laravel 13's default config does not have one), skip this step.

- [ ] **Step 2: Add the env var to `.env.example`**

Open `.env.example`. Find the `APP_NAME=` line. Immediately after it add:

```
APP_VERSION=dev
```

- [ ] **Step 3: Verify the config loads**

```bash
php artisan config:show app.version
```

Expected output: `dev` (or whatever you have in your local `.env`, if you also added it there).

- [ ] **Step 4: Commit**

```bash
git add config/app.php .env.example
git commit -m "feat(config): expose app.version for the About section" \
  -m "Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Settings page (failing feature tests first)

**Files:**
- Test: `tests/Feature/SettingsViewTest.php`

**Context:** Drive the page implementation by writing Livewire feature tests first. The implementation lands in Task 5.

- [ ] **Step 1: Look at an existing feature test for the test shape**

Read `tests/Feature/SearchViewTest.php` quickly to confirm the pattern (mocking `PlexClient`, using `Livewire::test`, `assertSee`, etc.). We'll mirror that style.

- [ ] **Step 2: Write the failing feature tests**

Write `tests/Feature/SettingsViewTest.php`:

```php
<?php

use App\Models\Setting;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexCache;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->plex = Mockery::mock(PlexClient::class);
    $this->plex->shouldReceive('ping')
        ->andReturn([
            'name' => 'My Plex',
            'reachable' => true,
            'connection' => 'direct',
            'machineIdentifier' => 'abc123',
        ]);
    $this->app->instance(PlexClient::class, $this->plex);
});

test('renders the page title and three section headings', function () {
    Livewire::test('pages::settings')
        ->assertSee('Settings')
        ->assertSee('Plex Server')
        ->assertSee('Display')
        ->assertSee('About');
});

test('renders the server name from ping', function () {
    Livewire::test('pages::settings')
        ->assertSee('My Plex');
});

test('density defaults to comfortable', function () {
    Livewire::test('pages::settings')
        ->assertSet('density', 'comfortable');
});

test('density hydrates from a stored value', function () {
    Setting::set('density', 'compact');

    Livewire::test('pages::settings')
        ->assertSet('density', 'compact');
});

test('updating density persists the new value', function () {
    Livewire::test('pages::settings')
        ->set('density', 'compact');

    expect(Setting::get('density'))->toBe('compact');
});

test('resyncMetadata flushes the plex cache and pings', function () {
    $cache = Mockery::mock(PlexCache::class);
    $cache->shouldReceive('flushAll')->once();
    $this->app->instance(PlexCache::class, $cache);

    $this->plex->shouldReceive('ping')->once(); // additional call from the action

    Livewire::test('pages::settings')
        ->call('resyncMetadata')
        ->assertSet('resyncError', null)
        ->assertNotSet('resyncedAt', null);
});

test('resyncMetadata records an error when ping fails after flushing', function () {
    $cache = Mockery::mock(PlexCache::class);
    $cache->shouldReceive('flushAll')->once();
    $this->app->instance(PlexCache::class, $cache);

    $failingPlex = Mockery::mock(PlexClient::class);
    $failingPlex->shouldReceive('ping')
        ->andThrow(new PlexUnreachableException('down'));
    $this->app->instance(PlexClient::class, $failingPlex);

    Livewire::test('pages::settings')
        ->call('resyncMetadata')
        ->assertSet('resyncedAt', null)
        ->assertNotSet('resyncError', null);
});

test('shows app version from config', function () {
    config()->set('app.version', '1.2.3');

    Livewire::test('pages::settings')
        ->assertSee('1.2.3');
});
```

Note on the resync-error test: we replace the `PlexClient` instance entirely with a failing mock so that the `serverInfo()` computed (which also calls `ping()`) and the `resyncMetadata` action both see the exception path. The `flushAll()` mock still asserts a single call.

- [ ] **Step 3: Run the tests and confirm they fail**

```bash
php artisan test --compact --filter=SettingsViewTest
```

Expected: failures (page is still the 12-line stub; component has none of the public props or methods these tests assert on).

- [ ] **Step 4: Do not commit yet**

The component implementation in Task 5 will make these pass; we'll commit together.

---

## Task 5: Settings page component implementation

**Files:**
- Modify: `resources/views/pages/⚡settings.blade.php`

**Context:** Single-file Livewire 4 page component. One stable root `<div>`. Server-info computed uses `app(PlexClient::class)` because `#[Computed]` cannot autowire. The action methods do autowire DI.

- [ ] **Step 1: Replace the stub with the real component**

Overwrite `resources/views/pages/⚡settings.blade.php`:

```php
<?php

use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexCache;
use App\Services\Plex\PlexClient;
use App\Support\AppSetting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $density = AppSetting::DENSITY_COMFORTABLE;

    public ?string $resyncedAt = null;

    public ?string $resyncError = null;

    public function mount(): void
    {
        $this->density = AppSetting::density();
    }

    public function updatedDensity(string $value): void
    {
        AppSetting::setDensity($value);
    }

    public function resyncMetadata(PlexCache $cache, PlexClient $plex): void
    {
        $cache->flushAll();

        try {
            $plex->ping();
            $this->resyncedAt = now()->format('H:i:s');
            $this->resyncError = null;
        } catch (PlexException $e) {
            $this->resyncedAt = null;
            $this->resyncError = 'Cache cleared, but Plex is unreachable.';
        }
    }

    #[Computed]
    public function serverInfo(): array
    {
        $tokenSet = ! empty(config('services.plex.token'));

        try {
            $status = app(PlexClient::class)->ping();

            return [
                'name' => $status['name'] ?? null,
                'reachable' => (bool) ($status['reachable'] ?? false),
                'connection' => $status['connection'] ?? 'down',
                'tokenSet' => $tokenSet,
            ];
        } catch (PlexException) {
            return [
                'name' => null,
                'reachable' => false,
                'connection' => 'down',
                'tokenSet' => $tokenSet,
            ];
        }
    }
}; ?>

<div class="p-6">
    <div class="max-w-3xl mx-auto space-y-6">
        <header>
            <h1 class="text-3xl font-black">Settings</h1>
            <p class="text-text-2 mt-2">Configure Plexify.</p>
        </header>

        {{-- Plex Server --}}
        <section class="bg-surface-1 rounded-xl p-6">
            <h2 class="text-xl font-bold mb-4">Plex Server</h2>

            @php($info = $this->serverInfo)

            <div class="space-y-3 text-sm">
                <div class="flex items-center gap-2">
                    @if ($info['reachable'])
                        <span class="w-2.5 h-2.5 rounded-full bg-accent"></span>
                        <span class="text-text-1">Connected</span>
                    @elseif ($info['tokenSet'])
                        <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
                        <span class="text-text-1">Token set, server unreachable</span>
                    @else
                        <span class="w-2.5 h-2.5 rounded-full bg-surface-3"></span>
                        <span class="text-text-2">No token configured</span>
                    @endif
                </div>

                @if ($info['name'])
                    <div class="flex justify-between">
                        <span class="text-text-2">Server</span>
                        <span class="text-text-1 font-semibold">{{ $info['name'] }}</span>
                    </div>
                @endif

                @if ($info['reachable'])
                    <div class="flex justify-between">
                        <span class="text-text-2">Connection</span>
                        <span class="text-text-1 font-semibold capitalize">{{ $info['connection'] }}</span>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex items-center gap-4">
                <button type="button"
                        wire:click="resyncMetadata"
                        wire:loading.attr="disabled"
                        class="bg-accent text-black font-bold px-4 py-2 rounded-full hover:bg-accent-hover transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="resyncMetadata">Resync metadata</span>
                    <span wire:loading wire:target="resyncMetadata">Resyncing…</span>
                </button>

                @if ($resyncedAt)
                    <span class="text-sm text-accent">Cleared at {{ $resyncedAt }}</span>
                @elseif ($resyncError)
                    <span class="text-sm text-red-400">{{ $resyncError }}</span>
                @else
                    <span class="text-sm text-text-3">Clears cached artists, albums, tracks, and playlists.</span>
                @endif
            </div>
        </section>

        {{-- Display --}}
        <section class="bg-surface-1 rounded-xl p-6">
            <h2 class="text-xl font-bold mb-4">Display</h2>

            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-semibold">Density</div>
                    <div class="text-xs text-text-2 mt-1">Comfortable spaces rows out. Compact fits more on screen.</div>
                </div>

                <div class="inline-flex rounded-full bg-surface-2 p-1">
                    <button type="button"
                            wire:click="$set('density', 'comfortable')"
                            @class([
                                'px-4 py-1.5 rounded-full text-sm font-semibold transition-colors',
                                'bg-surface-3 text-text-1' => $density === 'comfortable',
                                'text-text-2 hover:text-text-1' => $density !== 'comfortable',
                            ])>
                        Comfortable
                    </button>
                    <button type="button"
                            wire:click="$set('density', 'compact')"
                            @class([
                                'px-4 py-1.5 rounded-full text-sm font-semibold transition-colors',
                                'bg-surface-3 text-text-1' => $density === 'compact',
                                'text-text-2 hover:text-text-1' => $density !== 'compact',
                            ])>
                        Compact
                    </button>
                </div>
            </div>
        </section>

        {{-- About --}}
        <section class="bg-surface-1 rounded-xl p-6">
            <h2 class="text-xl font-bold mb-4">About</h2>

            <div class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-text-2">Plexify</span>
                    <span class="text-text-1 font-semibold">{{ config('app.version') }}</span>
                </div>
                <p class="text-text-2 leading-relaxed">
                    Built with Laravel, Livewire, Tailwind, and Lucide icons. Streams from Plex Media Server.
                </p>
            </div>
        </section>
    </div>
</div>
```

Notes:

- The page has one root `<div class="p-6">` per the single-root rule.
- `$this->serverInfo` (computed) is read once into a local `$info` via `@php($info = $this->serverInfo)` so subsequent uses do not re-invoke the computed (and to keep templating clean).
- The repo link from the spec is intentionally omitted because the spec said "hardcoded for now" but never settled on a URL. If desired, add `<a href="..." target="_blank" rel="noreferrer">GitHub</a>` later; this plan does not introduce one.
- `bg-accent-hover` is already defined in `resources/css/app.css` per the project guide; no new token needed.

- [ ] **Step 2: Run the Settings feature tests**

```bash
php artisan test --compact --filter=SettingsViewTest
```

Expected: all eight tests pass.

- [ ] **Step 3: Build assets**

```bash
npm run build
```

Expected: builds successfully (no Vite errors).

- [ ] **Step 4: Run the full suite**

```bash
php artisan test --compact
```

Expected: green (167 tests + 8 new feature tests + 5 new unit AppSetting tests + 7 new unit PlexCache tests, ~187 total). If anything else regressed (e.g., another test that asserted on the old `Setting` table being empty), fix or update it.

- [ ] **Step 5: Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/⚡settings.blade.php tests/Feature/SettingsViewTest.php
git commit -m "feat(settings): three-section settings page (Plex Server, Display, About)" \
  -m "Plex Server shows server status and a Resync metadata button that calls PlexCache::flushAll. Display has a persist-only density toggle backed by AppSetting. About shows app version and credits." \
  -m "Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Browser smoke test

**Files:**
- Create: `tests/Browser/SettingsTest.php`

**Context:** End-to-end through Playwright. Hits the live Plex server (the page's `ping()` call). Uses `RefreshDatabase` because we assert on a `Setting` row.

- [ ] **Step 1: Look at an existing browser test for the shape**

Read `tests/Browser/SidebarFoldersTest.php` briefly to confirm the patterns (`visit`, `assertSee`, `script`, `RefreshDatabase`).

- [ ] **Step 2: Write the test**

Write `tests/Browser/SettingsTest.php`:

```php
<?php

use App\Models\Setting;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('settings page renders and density toggle persists', function () {
    $page = visit('/settings');

    $page->assertSee('Settings')
        ->assertSee('Plex Server')
        ->assertSee('Display')
        ->assertSee('About');

    $page->click('button:has-text("Compact")');

    // Allow the Livewire round-trip to land.
    $page->waitForLivewire();

    expect(Setting::get('density'))->toBe('compact');
});

test('resync metadata button shows confirmation', function () {
    $page = visit('/settings');

    $page->click('button:has-text("Resync metadata")');

    $page->waitForText('Cleared at')
        ->assertSee('Cleared at');
});
```

If `waitForLivewire()` is not available in the project's Pest browser helper, use `usleep(400_000)` (400ms) or poll with `script()` until `document.querySelector('[wire\\:loading]')` returns null. Check what other browser tests do (`PlayerQueueTest.php` is a recent reference).

If `click('button:has-text("Compact")')` is not supported by the strict locator used in `pest-plugin-browser`, drive the click via `script()`:

```php
$page->script("Array.from(document.querySelectorAll('button')).find(b => b.textContent.trim() === 'Compact').click()");
```

The fallback above is the safer choice given the gotcha noted in `CLAUDE.md` about `click()` being strict on multiple matches.

- [ ] **Step 3: Run the browser test**

```bash
php artisan test --compact --filter=SettingsTest
```

Expected: green. If "resync metadata button shows confirmation" fails because Plex is unreachable from your machine, document this as an expected-with-live-plex test and move on (the same applies to other browser tests in the project).

- [ ] **Step 4: Run the full suite**

```bash
php artisan test --compact
```

Expected: green.

- [ ] **Step 5: Commit**

```bash
git add tests/Browser/SettingsTest.php
git commit -m "test(browser): settings page smoke test (density persists, resync confirms)" \
  -m "Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Update `CLAUDE.md` milestone status

**Files:**
- Modify: `CLAUDE.md`

**Context:** Final housekeeping after the milestone ships. Keep the project guide accurate.

- [ ] **Step 1: Update the milestone table**

In `CLAUDE.md`, find the milestone status table:

```
| 6 | Settings page | Next |
| 7 | Polish ... | Not started |
```

Change `| 6 | Settings page | Next |` to `| 6 | Settings page (Plex Server status + Resync metadata; Display density toggle persist-only; About) | Done |`.

Change `| 7 | Polish ...` row's status from `Not started` to `Next`.

- [ ] **Step 2: Add a brief gotcha entry**

Under the "Gotchas" section, append a new bullet near the `PlexCache` lines:

```
- **`PlexCache::flushAll()` works across requests** now. `remember()` appends each namespaced key to a `plex:_index` cache entry (TTL 7 days); `flushAll()` iterates that index and forgets each entry, then clears the index. Single-user app, so the lost-write race on the index is acceptable. The Settings page's "Resync metadata" button is the production user of this.
```

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(claude): M6 settings shipped; record PlexCache index gotcha" \
  -m "Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Self-review notes

Coverage check against the spec:

- Plex Server section (server status, token state, Resync) → Task 5 (component) + Task 1 (cross-request flush). Covered.
- Display density toggle, persist-only → Task 5 (UI) + Task 2 (AppSetting) + Task 4 (feature tests). Covered.
- About section with `config('app.version')` → Task 3 (config) + Task 5 (UI). Covered.
- `PlexCache::flushAll()` cross-request behaviour → Task 1. Covered.
- `AppSetting` typed accessor → Task 2. Covered.
- Tests: unit (`AppSettingTest`, extended `PlexCacheTest`), feature (`SettingsViewTest`), browser (`SettingsTest`) → Tasks 1, 2, 4, 6. Covered.
- The spec mentions a GitHub repo link in About. Task 5 explicitly drops it because the spec said "hardcoded for now" without a URL. Flag for the user to provide the URL post-implementation.

Type/name consistency check:

- `AppSetting::density()` / `setDensity` consistent across Tasks 2, 4, 5.
- `PlexCache::flushAll` / `forget` / `remember` consistent across Tasks 1, 4, 5.
- `serverInfo()` computed shape (`name`, `reachable`, `connection`, `tokenSet`) matches between Tasks 4 (tests assert via `assertSee`) and 5 (template reads).
- `$resyncedAt`, `$resyncError`, `$density` property names consistent across Tasks 4 and 5.

No placeholders, no TBDs, no "similar to Task N" backreferences.
