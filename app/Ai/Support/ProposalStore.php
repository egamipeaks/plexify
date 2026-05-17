<?php

namespace App\Ai\Support;

use App\Models\AiPlaylistProposal;

class ProposalStore
{
    /**
     * @param  array{name: string, description?: ?string, tracks: array}  $proposal
     */
    public function put(string $conversationId, array $proposal): AiPlaylistProposal
    {
        return AiPlaylistProposal::create([
            'conversation_id' => $conversationId,
            'name' => $proposal['name'],
            'description' => $proposal['description'] ?? null,
            'payload' => ['tracks' => $proposal['tracks'] ?? []],
            'status' => 'pending',
        ]);
    }

    public function latest(string $conversationId): ?AiPlaylistProposal
    {
        return AiPlaylistProposal::where('conversation_id', $conversationId)
            ->latest('id')
            ->first();
    }

    public function markAccepted(string $conversationId, string $plexPlaylistId): void
    {
        $this->latest($conversationId)?->update([
            'status' => 'accepted',
            'plex_playlist_id' => $plexPlaylistId,
        ]);
    }

    public function markDiscarded(string $conversationId): void
    {
        $this->latest($conversationId)?->update(['status' => 'discarded']);
    }
}
