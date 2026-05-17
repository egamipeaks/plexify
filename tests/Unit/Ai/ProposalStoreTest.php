<?php

use App\Ai\Support\ProposalStore;
use App\Models\AiPlaylistProposal;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores and retrieves the latest proposal for a conversation', function () {
    $store = new ProposalStore;
    $store->put('conv-42', [
        'name' => 'New Wave Faves',
        'description' => 'Twenty essentials',
        'tracks' => [['ratingKey' => '1', 'title' => 'T', 'artist' => 'A', 'reason' => 'classic']],
    ]);

    $proposal = $store->latest('conv-42');

    expect($proposal)->not->toBeNull()
        ->and($proposal->name)->toBe('New Wave Faves')
        ->and($proposal->payload['tracks'])->toHaveCount(1)
        ->and($proposal->status)->toBe('pending');
});

it('returns null when no proposal exists', function () {
    expect((new ProposalStore)->latest('nope'))->toBeNull();
});

it('returns the most recent proposal when multiple exist', function () {
    $store = new ProposalStore;
    $store->put('conv-1', ['name' => 'First', 'description' => null, 'tracks' => []]);
    $store->put('conv-1', ['name' => 'Second', 'description' => null, 'tracks' => []]);

    expect($store->latest('conv-1')->name)->toBe('Second');
});

it('marks a proposal accepted with a plex playlist id', function () {
    $store = new ProposalStore;
    $store->put('conv-7', ['name' => 'X', 'description' => null, 'tracks' => []]);
    $store->markAccepted('conv-7', 'abc123');

    $proposal = AiPlaylistProposal::where('conversation_id', 'conv-7')->first();
    expect($proposal->status)->toBe('accepted')
        ->and($proposal->plex_playlist_id)->toBe('abc123');
});

it('marks a proposal discarded', function () {
    $store = new ProposalStore;
    $store->put('conv-8', ['name' => 'X', 'description' => null, 'tracks' => []]);
    $store->markDiscarded('conv-8');

    expect(AiPlaylistProposal::where('conversation_id', 'conv-8')->first()->status)->toBe('discarded');
});

it('markAccepted/markDiscarded are no-ops when no proposal exists', function () {
    $store = new ProposalStore;
    $store->markAccepted('missing', 'x'); // should not throw
    $store->markDiscarded('missing');     // should not throw

    expect(AiPlaylistProposal::count())->toBe(0);
});
