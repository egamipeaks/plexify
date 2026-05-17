<?php

use App\Ai\Agents\PlaylistGeneratorAgent;
use App\Models\AiPlaylistProposal;
use App\Models\User;
use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Exceptions\PlexUnreachableException;
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

it('renders the latest pending proposal as a card after a send', function () {
    putenv('OPENAI_API_KEY=sk-test');

    app()->bind(PlaylistGeneratorAgent::class, function () {
        return new class
        {
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
                // Simulate the ProposePlaylist tool firing during the prompt by
                // inserting a row directly. The page reads the latest pending row
                // via ProposalStore.
                AiPlaylistProposal::create([
                    'conversation_id' => $this->convId,
                    'name' => 'New Wave Essentials',
                    'description' => 'Twenty stone-cold classics',
                    'payload' => ['tracks' => [
                        ['ratingKey' => '1', 'title' => 'Just Like Heaven', 'artist' => 'The Cure', 'album' => 'Kiss Me', 'durationMs' => 220000, 'reason' => 'classic'],
                        ['ratingKey' => '2', 'title' => 'Bizarre Love Triangle', 'artist' => 'New Order', 'album' => 'Substance', 'durationMs' => 280000, 'reason' => null],
                    ]],
                    'status' => 'pending',
                ]);

                return (object) ['text' => 'Here is your playlist.', 'conversationId' => $this->convId];
            }
        };
    });

    Livewire::test('pages::generate')
        ->set('input', 'go')
        ->call('send')
        ->assertSee('New Wave Essentials')
        ->assertSee('Twenty stone-cold classics')
        ->assertSee('Just Like Heaven')
        ->assertSee('Bizarre Love Triangle')
        ->assertSee('classic')                                  // per-track reason
        ->assertSeeHtml('wire:click="acceptProposal"')
        ->assertSeeHtml('wire:click="discardProposal"');
});

it('does not render a card when the latest proposal is accepted or discarded', function () {
    putenv('OPENAI_API_KEY=sk-test');

    AiPlaylistProposal::create([
        'conversation_id' => 'manual-conv',
        'name' => 'Old proposal',
        'payload' => ['tracks' => []],
        'status' => 'accepted',
    ]);

    Livewire::test('pages::generate')
        ->set('conversationId', 'manual-conv')
        ->assertDontSee('Old proposal');
});

it('creates a Plex playlist on accept and marks proposal accepted', function () {
    putenv('OPENAI_API_KEY=sk-test');

    AiPlaylistProposal::create([
        'conversation_id' => 'conv-acc-1',
        'name' => 'NW Essentials',
        'description' => null,
        'payload' => ['tracks' => [
            ['ratingKey' => '1', 'title' => 'A', 'artist' => 'X', 'album' => 'Q', 'durationMs' => 200000, 'reason' => null],
            ['ratingKey' => '2', 'title' => 'B', 'artist' => 'Y', 'album' => 'R', 'durationMs' => 220000, 'reason' => null],
            ['ratingKey' => '3', 'title' => 'C', 'artist' => 'Z', 'album' => 'S', 'durationMs' => 240000, 'reason' => null],
        ]],
        'status' => 'pending',
    ]);

    // Override the layout's PlexClient mock (set in beforeEach) with one that
    // also expects createPlaylist/addTrackToPlaylist calls.
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => true, 'connection' => '', 'machineIdentifier' => 'x']);
    $plex->shouldReceive('playlists')->andReturn(collect());
    $plex->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ?? '');
    $plex->shouldReceive('searchAll')->andReturn(SearchResults::empty());
    $plex->shouldReceive('scrobbleUrl')->andReturn('');
    $plex->shouldReceive('createPlaylist')->once()->with('NW Essentials', '1')->andReturn('new-pl-id');
    $plex->shouldReceive('addTrackToPlaylist')->once()->with('new-pl-id', '2');
    $plex->shouldReceive('addTrackToPlaylist')->once()->with('new-pl-id', '3');
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::generate')
        ->set('conversationId', 'conv-acc-1')
        ->call('acceptProposal')
        ->assertRedirect(route('playlist', ['playlist' => 'new-pl-id']));

    $row = AiPlaylistProposal::where('conversation_id', 'conv-acc-1')->first();
    expect($row->status)->toBe('accepted')
        ->and($row->plex_playlist_id)->toBe('new-pl-id');
});

it('dispatches a notify toast and leaves proposal pending when Plex errors during accept', function () {
    putenv('OPENAI_API_KEY=sk-test');

    AiPlaylistProposal::create([
        'conversation_id' => 'conv-acc-2',
        'name' => 'X',
        'payload' => ['tracks' => [['ratingKey' => '1', 'title' => 'A', 'artist' => '', 'album' => '', 'durationMs' => 0, 'reason' => null]]],
        'status' => 'pending',
    ]);

    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => true, 'connection' => '', 'machineIdentifier' => 'x']);
    $plex->shouldReceive('playlists')->andReturn(collect());
    $plex->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ?? '');
    $plex->shouldReceive('searchAll')->andReturn(SearchResults::empty());
    $plex->shouldReceive('scrobbleUrl')->andReturn('');
    $plex->shouldReceive('createPlaylist')->andThrow(new PlexUnreachableException('Plex 503'));
    app()->instance(PlexClient::class, $plex);

    Livewire::test('pages::generate')
        ->set('conversationId', 'conv-acc-2')
        ->call('acceptProposal')
        ->assertDispatched('notify');

    expect(AiPlaylistProposal::where('conversation_id', 'conv-acc-2')->first()->status)->toBe('pending');
});

it('dispatches a notify toast when the proposal has no tracks', function () {
    putenv('OPENAI_API_KEY=sk-test');

    AiPlaylistProposal::create([
        'conversation_id' => 'conv-acc-3',
        'name' => 'Empty',
        'payload' => ['tracks' => []],
        'status' => 'pending',
    ]);

    Livewire::test('pages::generate')
        ->set('conversationId', 'conv-acc-3')
        ->call('acceptProposal')
        ->assertDispatched('notify');

    expect(AiPlaylistProposal::where('conversation_id', 'conv-acc-3')->first()->status)->toBe('pending');
});

it('discards the latest proposal', function () {
    putenv('OPENAI_API_KEY=sk-test');

    AiPlaylistProposal::create([
        'conversation_id' => 'conv-d1',
        'name' => 'X',
        'payload' => ['tracks' => []],
        'status' => 'pending',
    ]);

    Livewire::test('pages::generate')
        ->set('conversationId', 'conv-d1')
        ->call('discardProposal');

    expect(AiPlaylistProposal::where('conversation_id', 'conv-d1')->first()->status)->toBe('discarded');
});

it('resets conversation state on startNew', function () {
    putenv('OPENAI_API_KEY=sk-test');

    Livewire::test('pages::generate')
        ->set('conversationId', 'conv-n1')
        ->set('messages', [['role' => 'user', 'content' => 'hi']])
        ->set('input', 'partial')
        ->call('startNew')
        ->assertSet('conversationId', null)
        ->assertSet('messages', [])
        ->assertSet('input', '');
});

it('renders a New conversation button only when messages exist', function () {
    putenv('OPENAI_API_KEY=sk-test');

    $component = Livewire::test('pages::generate');
    $component->assertDontSee('New conversation');

    $component->set('messages', [['role' => 'user', 'content' => 'hello']])
        ->assertSee('New conversation')
        ->assertSeeHtml('wire:click="startNew"');
});
