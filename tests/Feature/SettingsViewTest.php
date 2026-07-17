<?php

use App\Models\Setting;
use App\Services\Plex\Dto\MusicSection;
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
    $this->plex->shouldReceive('musicSectionId')->andReturn(1);
    $this->plex->shouldReceive('musicSections')->andReturn(collect([new MusicSection(1, 'Music')]));
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
    $failingPlex->shouldReceive('musicSectionId')->andThrow(new PlexUnreachableException('down'));
    $failingPlex->shouldReceive('musicSections')->andThrow(new PlexUnreachableException('down'));
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
    $unreachable->shouldReceive('musicSectionId')->andThrow(new PlexUnreachableException('no token'));
    $unreachable->shouldReceive('musicSections')->andThrow(new PlexUnreachableException('no token'));
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

it('seeds the select with the effective library when nothing was ever chosen', function () {
    expect(AppSetting::musicSectionId())->toBeNull();

    Livewire::test('pages::settings')->assertSet('musicSectionId', 1);
});

it('lists every artist section in the music library select', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(['name' => 'HomeServer', 'reachable' => true, 'connection' => 'direct']);
    $plex->shouldReceive('musicSectionTitle')->andReturn('Music');
    $plex->shouldReceive('musicSectionId')->andReturn(6);
    $plex->shouldReceive('musicSections')->andReturn(collect([
        new MusicSection(6, 'Music'),
        new MusicSection(13, 'Spoken'),
        new MusicSection(14, 'Classical'),
    ]));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::settings')
        ->assertSee('Music library')
        ->assertSee('Which Plex library to read from.')
        ->assertSee('Classical')
        ->assertSee('Spoken');
});

it('persists the chosen music library', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(['name' => 'HomeServer', 'reachable' => true, 'connection' => 'direct']);
    $plex->shouldReceive('musicSectionTitle')->andReturn('Music');
    $plex->shouldReceive('musicSectionId')->andReturn(6);
    $plex->shouldReceive('musicSections')->andReturn(collect([
        new MusicSection(6, 'Music'),
        new MusicSection(14, 'Classical'),
    ]));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::settings')->set('musicSectionId', 14);

    expect(AppSetting::musicSectionId())->toBe(14);
});

it('dispatches library-changed so the server chip can refresh itself', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(['name' => 'HomeServer', 'reachable' => true, 'connection' => 'direct']);
    $plex->shouldReceive('musicSectionTitle')->andReturn('Music');
    $plex->shouldReceive('musicSectionId')->andReturn(6);
    $plex->shouldReceive('musicSections')->andReturn(collect([
        new MusicSection(6, 'Music'),
        new MusicSection(14, 'Classical'),
    ]));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::settings')
        ->set('musicSectionId', 14)
        ->assertDispatched('library-changed');
});

it('does not dispatch library-changed when the chosen id is rejected', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(['name' => 'HomeServer', 'reachable' => true, 'connection' => 'direct']);
    $plex->shouldReceive('musicSectionTitle')->andReturn('Music');
    $plex->shouldReceive('musicSectionId')->andReturn(6);
    $plex->shouldReceive('musicSections')->andReturn(collect([new MusicSection(6, 'Music')]));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::settings')
        ->set('musicSectionId', 999)
        ->assertNotDispatched('library-changed');
});

it('ignores a music library id that is not a real section', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(['name' => 'HomeServer', 'reachable' => true, 'connection' => 'direct']);
    $plex->shouldReceive('musicSectionTitle')->andReturn('Music');
    $plex->shouldReceive('musicSectionId')->andReturn(6);
    $plex->shouldReceive('musicSections')->andReturn(collect([new MusicSection(6, 'Music')]));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::settings')->set('musicSectionId', 999);

    expect(AppSetting::musicSectionId())->toBeNull();
});

it('still renders the settings page when Plex is unreachable', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andThrow(new PlexUnreachableException('down'));
    $plex->shouldReceive('musicSectionTitle')->andThrow(new PlexUnreachableException('down'));
    $plex->shouldReceive('musicSectionId')->andThrow(new PlexUnreachableException('down'));
    $plex->shouldReceive('musicSections')->andThrow(new PlexUnreachableException('down'));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::settings')
        ->assertOk()
        ->assertSee('Display')
        ->assertSee('About')
        ->assertDontSee('Music library');
});
