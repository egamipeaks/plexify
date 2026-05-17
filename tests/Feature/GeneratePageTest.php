<?php

use App\Models\User;
use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\PlexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    User::firstOrCreate(['email' => 'plexify@local'], ['name' => 'Plexify', 'password' => bcrypt('x')]);

    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => null]);
    $plex->shouldReceive('playlists')->andReturn(collect());
    $plex->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ?? '');
    $plex->shouldReceive('searchAll')->andReturn(SearchResults::empty());
    $plex->shouldReceive('scrobbleUrl')->andReturn('');
    app()->instance(PlexClient::class, $plex);

    putenv('OPENAI_API_KEY=');
});

it('renders the generate page when reached over HTTP', function () {
    $this->get('/generate')->assertOk()->assertSee('Generate a playlist');
});

it('shows the AI-not-configured panel when OPENAI_API_KEY is empty', function () {
    Livewire::test('pages::generate')->assertSee('AI generator not configured');
});

it('hides the not-configured panel when OPENAI_API_KEY is set', function () {
    putenv('OPENAI_API_KEY=sk-test');

    Livewire::test('pages::generate')
        ->assertDontSee('AI generator not configured')
        ->assertSee('Chat UI lands in Task 19');
});
