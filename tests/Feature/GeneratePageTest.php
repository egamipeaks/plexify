<?php

use App\Ai\Agents\PlaylistGeneratorAgent;
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
        ->assertDontSee('Chat UI lands in Task 19');
});

it('sends a prompt and renders both the user and assistant messages', function () {
    putenv('OPENAI_API_KEY=sk-test');

    $captured = ['lastPrompt' => null, 'conversationId' => null];

    app()->bind(PlaylistGeneratorAgent::class, function () use (&$captured) {
        return new class($captured)
        {
            public function __construct(public array &$captured) {}

            public string $convId = '';

            public function forUser($user): self
            {
                return $this;
            }

            public function continue(string $conversationId, object $as): self
            {
                $this->convId = $conversationId;

                return $this;
            }

            public function prompt(string $text): object
            {
                $this->captured['lastPrompt'] = $text;
                $this->captured['conversationId'] = $this->convId;

                return (object) [
                    'text' => 'Got it. Want jangle pop or synth-driven?',
                    'conversationId' => $this->convId,
                ];
            }
        };
    });

    Livewire::test('pages::generate')
        ->set('input', 'Make me a new wave playlist')
        ->call('send')
        ->assertSee('Make me a new wave playlist')
        ->assertSee('Want jangle pop or synth-driven')
        ->assertSet('input', '');

    expect($captured['lastPrompt'])->toBe('Make me a new wave playlist')
        ->and($captured['conversationId'])->not->toBeEmpty();
});

it('reuses the same conversation id across multiple sends', function () {
    putenv('OPENAI_API_KEY=sk-test');

    $ids = [];

    app()->bind(PlaylistGeneratorAgent::class, function () use (&$ids) {
        return new class($ids)
        {
            public function __construct(public array &$ids) {}

            public string $convId = '';

            public function forUser($user): self
            {
                return $this;
            }

            public function continue(string $conversationId, object $as): self
            {
                $this->convId = $conversationId;
                $this->ids[] = $conversationId;

                return $this;
            }

            public function prompt(string $text): object
            {
                return (object) ['text' => 'ok', 'conversationId' => $this->convId];
            }
        };
    });

    $component = Livewire::test('pages::generate');
    $component->set('input', 'first')->call('send');
    $component->set('input', 'second')->call('send');

    expect($ids)->toHaveCount(2)->and($ids[0])->toBe($ids[1]); // same id reused
});

it('ignores empty/whitespace sends', function () {
    putenv('OPENAI_API_KEY=sk-test');

    Livewire::test('pages::generate')
        ->set('input', '   ')
        ->call('send')
        ->assertSet('messages', []);
});
