# Plextune Milestone 1: App Shell + Player Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a Laravel 13 + Livewire 4 + Alpine + Tailwind project with a three-region layout (topbar, sidebar+main, player) and prove the load-bearing architectural bet: a hardcoded audio track plays in the player, and audio survives navigation between stub views thanks to `@persist` + Livewire 4's island-by-default behavior.

**Architecture:** A plain Blade layout holds three persistent Livewire components (topbar, sidebar, player). The main pane is rendered by routed Livewire single-file components (one per top-level view). Sidebar nav links use `wire:navigate` for SPA-fast transitions; the `<audio>` element inside the player is wrapped in `@persist('audio')` so it survives DOM morphs.

**Tech Stack:** Laravel 13, Livewire 4 (single-file components), Alpine.js, Tailwind CSS, Pest 4 (with Playwright browser plugin), MySQL, Native HTML5 `<audio>`.

**Spec reference:** `docs/superpowers/specs/2026-05-09-plextune-design.md`

---

## File Structure

This milestone creates the following files:

**Configuration & infra:**
- `.env` (DB credentials, `PLEX_TOKEN`, `PLEX_BASE_URL` placeholders)
- `config/services.php` (add `plex` config block)
- `tailwind.config.js` (design tokens from spec)
- `resources/css/app.css` (Tailwind + custom utilities)

**Database:**
- `database/migrations/xxxx_create_settings_table.php`
- `app/Models/Setting.php`

**Layout & views:**
- `resources/views/components/layouts/app.blade.php` (three-region grid)
- `resources/views/pages/⚡library.blade.php` (stub: "Library")
- `resources/views/pages/⚡search.blade.php` (stub: "Search")
- `resources/views/pages/⚡playlist-detail.blade.php` (stub: "Playlist {id}")
- `resources/views/pages/⚡settings.blade.php` (stub: "Settings")

**Persistent Livewire components** (paths confirmed by `php artisan make:livewire`):
- `resources/views/livewire/⚡topbar.blade.php`
- `resources/views/livewire/⚡sidebar.blade.php`
- `resources/views/livewire/⚡player.blade.php`

**Routes:**
- `routes/web.php` (four `Route::livewire()` declarations)

**Public assets:**
- `public/test-track.mp3` (placeholder audio file engineer drops in for testing)

**Tests:**
- `tests/Feature/SettingTest.php`
- `tests/Feature/RoutesTest.php`
- `tests/Feature/PlayerComponentTest.php`
- `tests/Browser/AudioPersistenceTest.php`

---

## Task 1: Initialize Laravel 13 project and Herd HTTPS

**Files:**
- Create: project root via Laravel installer
- Modify: `.env`

- [ ] **Step 1: Create Laravel 13 project in current directory**

The directory `/Users/akrzy/Dev/Herd/Plexify/` already contains the `design/` folder and `docs/` folder. Install Laravel 13 alongside them.

```bash
cd /Users/akrzy/Dev/Herd/Plexify
composer create-project laravel/laravel:^13.0 tmp-laravel
# Move Laravel files up, preserving design/ and docs/
shopt -s dotglob && mv tmp-laravel/* . && rmdir tmp-laravel
```

Expected: Laravel 13 directory structure exists (`app/`, `routes/`, `resources/`, `composer.json`). `design/` and `docs/` are untouched.

- [ ] **Step 2: Verify Laravel version**

```bash
php artisan --version
```

Expected: `Laravel Framework 13.x.x` (any 13.x).

- [ ] **Step 3: Configure MySQL and link Herd site**

Edit `.env`:

```env
APP_NAME=Plextune
APP_URL=https://plexify.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=plexify
DB_USERNAME=root
DB_PASSWORD=

PLEX_TOKEN=
PLEX_BASE_URL=
```

Then create the database (MySQL is provided by DBngin, running locally on 127.0.0.1:3306 with root user / no password) and link the site to Herd:

```bash
mysql -u root -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS plexify;"
herd link
herd secure
```

Note: Herd is used only for serving the PHP site over HTTPS at `https://plexify.test`. MySQL is managed separately by DBngin.

Expected: `https://plexify.test` resolves and shows the default Laravel welcome page.

- [ ] **Step 4: Initialize git and make first commit**

```bash
git init
git add .
git commit -m "chore: initialize Laravel 13 project"
```

---

## Task 2: Install Livewire 4, Alpine, Tailwind, and Pest 4

**Files:**
- Modify: `composer.json`, `package.json` (via installer commands)
- Create: `tailwind.config.js`, `resources/css/app.css` updates

- [ ] **Step 1: Install Livewire 4**

```bash
composer require livewire/livewire:^4.0
```

Expected: composer adds `livewire/livewire` at v4.x. No errors.

- [ ] **Step 2: Install Pest 4 + Laravel + browser plugins**

```bash
composer require pestphp/pest:^4.0 pestphp/pest-plugin-laravel:^4.0 pestphp/pest-plugin-browser:^4.0 --dev
php artisan pest:install
```

Expected: `tests/Pest.php` exists. `php artisan test` runs the default Pest tests successfully.

- [ ] **Step 3: Install Playwright (for browser tests)**

```bash
npm install playwright@latest --save-dev
npx playwright install chromium
```

Expected: Playwright binary installed, `node_modules/playwright` exists.

- [ ] **Step 4: Add `tests/Browser/Screenshots` to `.gitignore`**

Append to `.gitignore`:

```
tests/Browser/Screenshots
```

- [ ] **Step 5: Install Tailwind CSS 4**

Laravel 13 ships with Vite. Install Tailwind:

```bash
npm install -D tailwindcss@latest @tailwindcss/vite@latest
```

Update `vite.config.js`:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
```

- [ ] **Step 6: Replace `resources/css/app.css` with design tokens**

```css
@import "tailwindcss";

@theme {
    --color-bg: #000000;
    --color-surface: #121212;
    --color-surface-1: #1a1a1a;
    --color-surface-2: #1f1f1f;
    --color-surface-3: #2a2a2a;
    --color-surface-4: #3a3a3a;
    --color-text-1: #FFFFFF;
    --color-text-2: #A7A7A7;
    --color-text-3: #6A6A6A;
    --color-accent: #1DB954;
    --color-accent-hover: #1ED760;
    --color-plex-orange-from: #E5A00D;
    --color-plex-orange-to: #CC7700;
}

html, body {
    background: var(--color-bg);
    color: var(--color-text-1);
    font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
    height: 100%;
}
```

- [ ] **Step 7: Confirm Alpine ships with Livewire 4**

Livewire 4 bundles Alpine; no separate install needed. Verify `resources/js/app.js` does not import Alpine separately. If it does, remove that import (Livewire's bundled copy will be used).

- [ ] **Step 8: Run dev build and verify**

```bash
npm run build
```

Expected: Vite builds without errors. `public/build/` contains compiled assets.

- [ ] **Step 9: Commit**

```bash
git add .
git commit -m "chore: install Livewire 4, Tailwind 4, Pest 4 with browser plugin"
```

---

## Task 3: Settings model and migration (DB persistence proof)

**Files:**
- Create: `database/migrations/xxxx_create_settings_table.php`
- Create: `app/Models/Setting.php`
- Create: `tests/Feature/SettingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SettingTest.php`:

```php
<?php

use App\Models\Setting;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('stores and retrieves a setting value', function () {
    Setting::set('theme', 'dark');

    expect(Setting::get('theme'))->toBe('dark');
});

it('returns default when setting is missing', function () {
    expect(Setting::get('missing_key', 'fallback'))->toBe('fallback');
});

it('overwrites an existing setting', function () {
    Setting::set('density', 'comfortable');
    Setting::set('density', 'compact');

    expect(Setting::get('density'))->toBe('compact');
});

it('stores complex JSON-castable values', function () {
    Setting::set('compact_toggles', ['artists' => true, 'albums' => false]);

    expect(Setting::get('compact_toggles'))->toBe([
        'artists' => true,
        'albums' => false,
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=SettingTest
```

Expected: FAIL with "Class App\Models\Setting not found" or similar.

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration create_settings_table
```

Replace the generated migration's `up()` body (per coding standards: no `down()` method):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }
};
```

- [ ] **Step 4: Create the Setting model**

Create `app/Models/Setting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['name', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function get(string $name, mixed $default = null): mixed
    {
        $row = static::query()->where('name', $name)->first();

        if (! $row) {
            return $default;
        }

        return $row->value;
    }

    public static function set(string $name, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['name' => $name],
            ['value' => $value],
        );
    }
}
```

Note: the `value` column is JSON-cast, so scalars round-trip as their native type.

- [ ] **Step 5: Run migrations and tests**

```bash
php artisan migrate
php artisan test --filter=SettingTest
```

Expected: All four tests PASS.

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "feat: add Setting model and table for app preferences"
```

---

## Task 4: Three-region layout shell

**Files:**
- Create: `resources/views/components/layouts/app.blade.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create the layout file**

Create `resources/views/components/layouts/app.blade.php`:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Plextune' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-bg text-text-1">
    <div class="flex flex-col gap-2 p-2 h-screen w-screen">
        {{-- Topbar region --}}
        <div class="flex-none">
            <div class="bg-surface rounded-lg h-16 flex items-center px-6">
                <span class="text-text-2 text-sm">[topbar placeholder]</span>
            </div>
        </div>

        {{-- Middle region: sidebar + main --}}
        <div class="flex gap-2 flex-1 min-h-0">
            <aside class="w-[280px] flex-none">
                <div class="bg-surface rounded-lg h-full p-4">
                    <span class="text-text-2 text-sm">[sidebar placeholder]</span>
                </div>
            </aside>
            <main class="flex-1 min-w-0">
                <div class="bg-surface rounded-lg h-full overflow-auto">
                    {{ $slot }}
                </div>
            </main>
        </div>

        {{-- Player region --}}
        <div class="flex-none">
            <div class="bg-surface rounded-lg h-[72px] flex items-center px-4">
                <span class="text-text-2 text-sm">[player placeholder]</span>
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
```

- [ ] **Step 2: Add a temporary smoke route to verify layout renders**

Replace `routes/web.php` with:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('layouts.app-test');
});
```

Create `resources/views/layouts/app-test.blade.php`:

```blade
<x-layouts.app>
    <div class="p-6">
        <h1 class="text-2xl font-bold">Layout smoke test</h1>
        <p class="text-text-2 mt-2">If you see three rounded panels with this content in the middle, the layout works.</p>
    </div>
</x-layouts.app>
```

- [ ] **Step 3: Run dev server and verify**

```bash
npm run dev
```

Visit `https://plexify.test`. Expected: black background with three rounded dark-grey panels (topbar, sidebar+main, player) and the smoke heading visible inside the main panel.

- [ ] **Step 4: Commit**

```bash
git add .
git commit -m "feat: add three-region layout shell with design tokens"
```

---

## Task 5: Stub routed view components

**Files:**
- Create: `resources/views/pages/⚡library.blade.php`
- Create: `resources/views/pages/⚡search.blade.php`
- Create: `resources/views/pages/⚡playlist-detail.blade.php`
- Create: `resources/views/pages/⚡settings.blade.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/RoutesTest.php`

- [ ] **Step 1: Write the failing routes test**

Create `tests/Feature/RoutesTest.php`:

```php
<?php

it('renders the library route', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Library');
});

it('renders the search route', function () {
    $this->get('/search')
        ->assertOk()
        ->assertSee('Search');
});

it('renders the playlist detail route', function () {
    $this->get('/playlist/abc123')
        ->assertOk()
        ->assertSee('Playlist abc123');
});

it('renders the settings route', function () {
    $this->get('/settings')
        ->assertOk()
        ->assertSee('Settings');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=RoutesTest
```

Expected: FAIL — no routes match.

- [ ] **Step 3: Create the four SFC pages**

Create `resources/views/pages/⚡library.blade.php`:

```blade
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {};
?>

<div class="p-6">
    <h1 class="text-3xl font-black">Library</h1>
    <p class="text-text-2 mt-2">Library view stub.</p>
</div>
```

Create `resources/views/pages/⚡search.blade.php`:

```blade
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {};
?>

<div class="p-6">
    <h1 class="text-3xl font-black">Search</h1>
    <p class="text-text-2 mt-2">Search view stub.</p>
</div>
```

Create `resources/views/pages/⚡playlist-detail.blade.php`:

```blade
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public string $playlist = '';

    public function mount(string $playlist): void
    {
        $this->playlist = $playlist;
    }
};
?>

<div class="p-6">
    <h1 class="text-3xl font-black">Playlist {{ $playlist }}</h1>
    <p class="text-text-2 mt-2">Playlist detail view stub.</p>
</div>
```

Create `resources/views/pages/⚡settings.blade.php`:

```blade
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {};
?>

<div class="p-6">
    <h1 class="text-3xl font-black">Settings</h1>
    <p class="text-text-2 mt-2">Settings view stub.</p>
</div>
```

- [ ] **Step 4: Wire up routes**

Replace `routes/web.php` with:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::library')->name('library');
Route::livewire('/search', 'pages::search')->name('search');
Route::livewire('/playlist/{playlist}', 'pages::playlist-detail')->name('playlist');
Route::livewire('/settings', 'pages::settings')->name('settings');
```

Delete the temporary `resources/views/layouts/app-test.blade.php` and remove its reference. (It is no longer used.)

```bash
rm resources/views/layouts/app-test.blade.php
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter=RoutesTest
```

Expected: All four tests PASS.

- [ ] **Step 6: Manual verification**

Visit `https://plexify.test/`, `/search`, `/playlist/abc123`, `/settings`. Each should render with the layout shell and the corresponding heading.

- [ ] **Step 7: Commit**

```bash
git add .
git commit -m "feat: add stub routed view components for library, search, playlist, settings"
```

---

## Task 6: Sidebar Livewire component with wire:navigate

**Files:**
- Create: `resources/views/livewire/⚡sidebar.blade.php` (via `make:livewire`)
- Modify: `resources/views/components/layouts/app.blade.php` (replace placeholder)

- [ ] **Step 1: Generate the sidebar component**

```bash
php artisan make:livewire sidebar
```

This creates a single-file component. Confirm the path with:

```bash
find resources/views -name "*sidebar*"
```

Expected: a file at `resources/views/livewire/⚡sidebar.blade.php` (or close — note the exact path output by the command and use it for subsequent steps).

- [ ] **Step 2: Replace the sidebar contents**

Open the generated sidebar file and replace its contents with:

```blade
<?php

use Livewire\Component;

new class extends Component {};
?>

<div class="bg-surface rounded-lg h-full p-2 flex flex-col gap-1">
    <a href="{{ route('library') }}" wire:navigate
       @class([
           'flex items-center gap-3 px-3 py-2 rounded text-sm font-bold transition-colors',
           'bg-surface-2 text-white' => request()->routeIs('library'),
           'text-text-2 hover:text-white' => ! request()->routeIs('library'),
       ])>
        <span>Home</span>
    </a>

    <a href="{{ route('search') }}" wire:navigate
       @class([
           'flex items-center gap-3 px-3 py-2 rounded text-sm font-bold transition-colors',
           'bg-surface-2 text-white' => request()->routeIs('search'),
           'text-text-2 hover:text-white' => ! request()->routeIs('search'),
       ])>
        <span>Search</span>
    </a>

    <a href="{{ route('library') }}" wire:navigate
       @class([
           'flex items-center gap-3 px-3 py-2 rounded text-sm font-bold transition-colors',
           'text-text-2 hover:text-white',
       ])>
        <span>Your Library</span>
    </a>

    <a href="{{ route('settings') }}" wire:navigate
       @class([
           'flex items-center gap-3 px-3 py-2 rounded text-sm font-bold transition-colors',
           'bg-surface-2 text-white' => request()->routeIs('settings'),
           'text-text-2 hover:text-white' => ! request()->routeIs('settings'),
       ])>
        <span>Settings</span>
    </a>
</div>
```

- [ ] **Step 3: Replace the sidebar placeholder in the layout**

Edit `resources/views/components/layouts/app.blade.php`. Find:

```blade
<aside class="w-[280px] flex-none">
    <div class="bg-surface rounded-lg h-full p-4">
        <span class="text-text-2 text-sm">[sidebar placeholder]</span>
    </div>
</aside>
```

Replace with:

```blade
<aside class="w-[280px] flex-none">
    <livewire:sidebar />
</aside>
```

- [ ] **Step 4: Manual verification**

Visit `https://plexify.test/`. Click "Search" — URL changes to `/search`, the active highlight moves to Search, and there is no full page reload (browser doesn't show its loading bar). Click "Home" — back to library.

- [ ] **Step 5: Commit**

```bash
git add .
git commit -m "feat: add sidebar component with wire:navigate links"
```

---

## Task 7: Topbar Livewire component

**Files:**
- Create: `resources/views/livewire/⚡topbar.blade.php` (via `make:livewire`)
- Modify: `resources/views/components/layouts/app.blade.php` (replace placeholder)

- [ ] **Step 1: Generate the topbar component**

```bash
php artisan make:livewire topbar
```

- [ ] **Step 2: Replace the topbar contents**

```blade
<?php

use Livewire\Component;

new class extends Component {
    public string $query = '';
};
?>

<div class="bg-surface rounded-lg h-16 flex items-center gap-4 px-6">
    <div class="flex gap-2">
        <button type="button" onclick="history.back()"
                class="w-8 h-8 rounded-full bg-black/60 grid place-items-center text-text-2 hover:text-white">
            ‹
        </button>
        <button type="button" onclick="history.forward()"
                class="w-8 h-8 rounded-full bg-black/60 grid place-items-center text-text-2 hover:text-white">
            ›
        </button>
    </div>

    <div class="flex-1 max-w-[760px]">
        <input type="text"
               wire:model.live.debounce.300ms="query"
               placeholder="Search your library"
               class="w-full bg-surface-1 rounded-full h-11 px-4 text-sm text-white placeholder:text-text-3 focus:outline-none focus:ring-1 focus:ring-text-2">
    </div>

    <a href="{{ route('settings') }}" wire:navigate
       class="w-8 h-8 rounded-full bg-surface-2 grid place-items-center text-text-2 hover:text-white">
        ⚙
    </a>
</div>
```

Note: actual search-redirect behavior is built in Milestone 3. For now the input is wired to a property but does nothing on change.

- [ ] **Step 3: Replace the topbar placeholder in the layout**

Edit `resources/views/components/layouts/app.blade.php`. Find the topbar `<div class="flex-none">` block and replace with:

```blade
<div class="flex-none">
    <livewire:topbar />
</div>
```

- [ ] **Step 4: Manual verification**

Visit `https://plexify.test/`. Confirm the topbar renders with two arrow buttons, a search input, and a settings cog. Click the cog → navigates to `/settings`.

- [ ] **Step 5: Commit**

```bash
git add .
git commit -m "feat: add topbar component with nav arrows, search input, settings link"
```

---

## Task 8: Player Livewire component (server-side track loading)

**Files:**
- Create: `resources/views/livewire/⚡player.blade.php` (via `make:livewire`)
- Create: `tests/Feature/PlayerComponentTest.php`

- [ ] **Step 1: Generate the player component**

```bash
php artisan make:livewire player
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/PlayerComponentTest.php`:

```php
<?php

use Livewire\Livewire;

it('dispatches a browser event when a track is loaded', function () {
    Livewire::test('player')
        ->call('loadTrack', 'https://example.com/track.mp3', 'Test Track', 'Test Artist')
        ->assertDispatched('audio-load', url: 'https://example.com/track.mp3');
});

it('updates now-playing state when a track is loaded', function () {
    Livewire::test('player')
        ->call('loadTrack', 'https://example.com/track.mp3', 'Test Track', 'Test Artist')
        ->assertSet('trackTitle', 'Test Track')
        ->assertSet('trackArtist', 'Test Artist')
        ->assertSet('trackUrl', 'https://example.com/track.mp3');
});

it('listens for play-track events and loads the track', function () {
    Livewire::test('player')
        ->dispatch('play-track', url: 'https://example.com/track.mp3', title: 'Evt Track', artist: 'Evt Artist')
        ->assertSet('trackTitle', 'Evt Track')
        ->assertDispatched('audio-load', url: 'https://example.com/track.mp3');
});
```

- [ ] **Step 3: Run test to verify it fails**

```bash
php artisan test --filter=PlayerComponentTest
```

Expected: FAIL — `loadTrack` method does not exist.

- [ ] **Step 4: Implement the player component**

Replace the contents of the generated player file with:

```blade
<?php

use Livewire\Component;
use Livewire\Attributes\On;

new class extends Component {
    public string $trackUrl = '';
    public string $trackTitle = '';
    public string $trackArtist = '';

    public function loadTrack(string $url, string $title = '', string $artist = ''): void
    {
        $this->trackUrl = $url;
        $this->trackTitle = $title;
        $this->trackArtist = $artist;

        $this->dispatch('audio-load', url: $url);
    }

    #[On('play-track')]
    public function onPlayTrack(string $url, string $title = '', string $artist = ''): void
    {
        $this->loadTrack($url, $title, $artist);
    }
};
?>

<div class="bg-surface rounded-lg h-[72px] flex items-center gap-4 px-4"
     x-data="audioPlayer()"
     x-init="init()">

    {{-- Now-playing --}}
    <div class="flex items-center gap-3 w-1/3 min-w-0">
        <div class="w-14 h-14 rounded bg-surface-2 flex-none"></div>
        <div class="min-w-0">
            <div class="text-sm font-bold truncate">{{ $trackTitle ?: '—' }}</div>
            <div class="text-xs text-text-2 truncate">{{ $trackArtist ?: '' }}</div>
        </div>
    </div>

    {{-- Transport --}}
    <div class="flex flex-col items-center gap-1 flex-1 min-w-0">
        <div class="flex items-center gap-4">
            <button type="button" @click="togglePlay"
                    class="w-8 h-8 rounded-full bg-white grid place-items-center text-black hover:scale-105 transition-transform">
                <span x-show="!isPlaying">▶</span>
                <span x-show="isPlaying" x-cloak>❚❚</span>
            </button>
        </div>
        <div class="w-full flex items-center gap-2 text-xs text-text-2 tabular-nums">
            <span x-text="formatTime(currentTime)">0:00</span>
            <input type="range" min="0" :max="duration || 0" step="0.1"
                   :value="currentTime"
                   @input="seek($event.target.value)"
                   class="flex-1 accent-white">
            <span x-text="formatTime(duration)">0:00</span>
        </div>
    </div>

    {{-- Volume --}}
    <div class="flex items-center gap-2 w-32">
        <span class="text-text-2 text-sm">🔊</span>
        <input type="range" min="0" max="1" step="0.01"
               :value="volume"
               @input="setVolume($event.target.value)"
               class="flex-1 accent-white">
    </div>

    {{-- The persistent audio element --}}
    @persist('audio')
        <audio x-ref="audio"
               @timeupdate="currentTime = $event.target.currentTime"
               @loadedmetadata="duration = $event.target.duration"
               @play="isPlaying = true"
               @pause="isPlaying = false"
               @ended="isPlaying = false"></audio>
    @endpersist
</div>

@script
<script>
    window.audioPlayer = function () {
        return {
            isPlaying: false,
            currentTime: 0,
            duration: 0,
            volume: 1,

            init() {
                // Livewire $dispatch surfaces as a CustomEvent on window with the event name as-is.
                // The dispatched payload is in event.detail.
                window.addEventListener('audio-load', (e) => {
                    this.$refs.audio.src = e.detail.url;
                    this.$refs.audio.play().catch(() => {});
                });
                this.$refs.audio.volume = this.volume;
            },

            togglePlay() {
                if (this.$refs.audio.paused) {
                    this.$refs.audio.play();
                } else {
                    this.$refs.audio.pause();
                }
            },

            seek(value) {
                this.$refs.audio.currentTime = parseFloat(value);
            },

            setVolume(value) {
                this.volume = parseFloat(value);
                this.$refs.audio.volume = this.volume;
            },

            formatTime(seconds) {
                if (!seconds || isNaN(seconds)) return '0:00';
                const m = Math.floor(seconds / 60);
                const s = Math.floor(seconds % 60).toString().padStart(2, '0');
                return `${m}:${s}`;
            },
        };
    };
</script>
@endscript
```

Note: `@persist('audio')` keeps the audio element across `wire:navigate` DOM swaps. Livewire's `$this->dispatch('audio-load', ...)` surfaces as a native `CustomEvent` on `window` with the payload available at `event.detail`. (Event names use kebab-case for Livewire/Alpine compatibility.)

- [ ] **Step 5: Replace the player placeholder in the layout**

Edit `resources/views/components/layouts/app.blade.php`. Find the player `<div class="flex-none">` block at the bottom and replace with:

```blade
<div class="flex-none">
    <livewire:player />
</div>
```

- [ ] **Step 6: Run tests**

```bash
php artisan test --filter=PlayerComponentTest
```

Expected: All three tests PASS.

- [ ] **Step 7: Commit**

```bash
git add .
git commit -m "feat: add player component with persistent audio and Alpine transport"
```

---

## Task 9: Manual end-to-end smoke test

**Files:** None (manual checklist).

- [ ] **Step 1: Add a placeholder track for testing**

Drop any small audio file (MP3, OGG, FLAC) into `public/test-track.mp3`. (Any audio file you have on hand works. The file does not need to be committed — add it to `.gitignore` if it is large.)

Add to `.gitignore`:

```
/public/test-track.mp3
```

- [ ] **Step 2: Add a temporary "play test track" button to the library stub**

Edit `resources/views/pages/⚡library.blade.php`:

```blade
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public function playTestTrack(): void
    {
        $this->dispatch('play-track',
            url: '/test-track.mp3',
            title: 'Test Track',
            artist: 'Local File',
        );
    }
};
?>

<div class="p-6">
    <h1 class="text-3xl font-black">Library</h1>
    <p class="text-text-2 mt-2">Library view stub.</p>

    <button type="button" wire:click="playTestTrack"
            class="mt-6 px-4 py-2 bg-accent text-black rounded-full font-bold hover:bg-accent-hover">
        Play test track
    </button>
</div>
```

- [ ] **Step 3: Smoke-test the player end-to-end**

Run through this checklist manually:

1. `npm run dev` (in one terminal).
2. Visit `https://plexify.test/`.
3. Click **Play test track**. Audio starts. The play button becomes pause; the scrubber moves; "Test Track" / "Local File" appears in the now-playing area.
4. Click the pause button. Audio stops. Click play again — audio resumes from where it stopped.
5. Drag the scrubber. Audio jumps to the new position.
6. Drag the volume slider. Volume changes audibly.
7. **The critical test:** while audio is playing, click **Search** in the sidebar. URL becomes `/search`. Page content swaps to the Search stub. **Audio keeps playing without interruption.** The player UI at the bottom still shows "Test Track" / "Local File" and the scrubber keeps moving.
8. Click **Home** in the sidebar. Same: audio uninterrupted, player state preserved.
9. Click the settings cog. Same: audio uninterrupted.

If all nine checks pass, the architecture is sound and Milestone 1 is complete.

- [ ] **Step 4: Commit the smoke-test button**

```bash
git add .
git commit -m "chore: add temporary play-test-track button for smoke testing"
```

(The button will be removed in Milestone 2 when real Plex tracks become available.)

---

## Task 10: Browser test for audio persistence across navigation

**Files:**
- Create: `tests/Browser/AudioPersistenceTest.php`

- [ ] **Step 1: Write the browser test**

Create `tests/Browser/AudioPersistenceTest.php`:

```php
<?php

it('keeps the now-playing state visible when navigating between views', function () {
    $page = visit('/');

    // Confirm initial Library view loads.
    $page->assertSee('Library view stub')
        ->click('Play test track');

    // Once playback starts, the player shows the track title.
    $page->assertSee('Test Track')
        ->assertSee('Local File');

    // Navigate to /search via the sidebar link (wire:navigate).
    $page->click('Search')
        ->assertUrlIs(url('/search'))
        ->assertSee('Search view stub');

    // The player region survived navigation: now-playing title is still visible.
    // This proves the persistent player + @persist('audio') architecture works,
    // because the player component would otherwise have been re-mounted with empty state.
    $page->assertSee('Test Track')
        ->assertSee('Local File');

    // Navigate back to Library — still alive.
    $page->click('Home')
        ->assertUrlIs(url('/'))
        ->assertSee('Test Track');
});
```

Note: `visit()`, `click()`, `assertSee()`, `assertUrlIs()`, and `script()` are Pest 4 browser-plugin primitives. The `script()` method evaluates JavaScript in the page context and returns the result.

- [ ] **Step 2: Run the browser test**

```bash
php artisan test --filter=AudioPersistenceTest
```

Expected: PASS. (First run may be slow as Playwright launches Chromium.)

- [ ] **Step 3: Commit**

```bash
git add .
git commit -m "test: add browser test for audio persistence across navigation"
```

---

## Done Criteria

Milestone 1 is complete when:

- All four routes (`/`, `/search`, `/playlist/{id}`, `/settings`) render with the three-region layout.
- The sidebar uses `wire:navigate` and the active link is highlighted.
- Clicking the **Play test track** button on `/` plays a local audio file.
- Audio continues to play when navigating between views.
- All Pest tests pass: `php artisan test`.
- The browser test for audio persistence passes.

## What This Milestone Deliberately Does Not Do

- No Plex API integration (Milestone 2).
- No real artist/album/track data (Milestone 2).
- No search functionality (Milestone 3).
- No playlists, folders, or DnD (Milestones 4–5).
- No settings UI beyond the layout slot (Milestone 6).
- No keyboard shortcuts (Milestone 7).
- No queue management — only single-track playback for testing.
