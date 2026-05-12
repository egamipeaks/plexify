<?php

use App\Models\Setting;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexCache;
use App\Services\Plex\PlexClient;
use App\Support\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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

it('renders the page title and three section headings', function () {
    Livewire::test('pages::settings')
        ->assertSee('Settings')
        ->assertSee('Plex Server')
        ->assertSee('Display')
        ->assertSee('About')
        ->assertSee('Playback');
});

it('renders the density label as "Density default"', function () {
    Livewire::test('pages::settings')
        ->assertSee('Density default');
});

it('renders the server name from ping', function () {
    Livewire::test('pages::settings')
        ->assertSee('My Plex');
});

it('defaults density to comfortable', function () {
    Livewire::test('pages::settings')
        ->assertSet('density', 'comfortable');
});

it('hydrates density from a stored value', function () {
    Setting::set('density', 'compact');

    Livewire::test('pages::settings')
        ->assertSet('density', 'compact');
});

it('persists the density change', function () {
    Livewire::test('pages::settings')
        ->set('density', 'compact');

    expect(Setting::get('density'))->toBe('compact');
});

it('flushes the plex cache and pings when resyncing', function () {
    $cache = Mockery::mock(PlexCache::class);
    $cache->shouldReceive('flushAll')->once();
    $this->app->instance(PlexCache::class, $cache);

    Livewire::test('pages::settings')
        ->call('resyncMetadata')
        ->assertSet('resyncError', null)
        ->assertSet('resyncedAt', fn ($v) => is_string($v) && $v !== '');
});

it('records an error when ping fails after flushing', function () {
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
        ->assertSet('resyncError', 'Cache cleared, but Plex is unreachable.');
});

it('shows the no-token status when PLEX_TOKEN is empty', function () {
    config()->set('services.plex.token', '');

    $unreachable = Mockery::mock(PlexClient::class);
    $unreachable->shouldReceive('ping')
        ->andThrow(new PlexUnreachableException('no token'));
    $this->app->instance(PlexClient::class, $unreachable);

    Livewire::test('pages::settings')
        ->assertSee('No token configured');
});

it('shows app version from config', function () {
    config()->set('app.version', '1.2.3');

    Livewire::test('pages::settings')
        ->assertSee('1.2.3');
});

it('renders the scrobble toggle reflecting the saved value', function () {
    AppSetting::setScrobbleEnabled(false);

    Livewire::test('pages::settings')
        ->assertSet('scrobbleEnabled', false)
        ->assertSee('Scrobble plays to Plex');
});

it('persists the scrobble toggle', function () {
    Livewire::test('pages::settings')
        ->set('scrobbleEnabled', false);

    expect(AppSetting::scrobbleEnabled())->toBeFalse();
});
