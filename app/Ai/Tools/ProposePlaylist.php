<?php

namespace App\Ai\Tools;

use App\Ai\Support\ProposalStore;
use App\Services\Plex\PlexClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProposePlaylist implements Tool
{
    public function __construct(
        private PlexClient $plex,
        private ProposalStore $store,
        private string $conversationId,
    ) {}

    public function description(): Stringable|string
    {
        return 'Render a playlist proposal to the user. Call this when you are ready to present a final list. The user will see it as a card with Accept / Refine / Discard buttons. trackRatingKeys MUST be ratingKeys returned by earlier tool calls in this conversation. Order matters: that is the playback order.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'description' => $schema->string(),
            'trackRatingKeys' => $schema->array()->items($schema->string())->required(),
            'rationalePerTrack' => $schema->object(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $ratingKeys = $request['trackRatingKeys'] ?? [];
        $rationale = $request['rationalePerTrack'] ?? [];

        $fetched = $this->plex->findTracks(['ratingKeys' => $ratingKeys])
            ->keyBy(fn ($t) => $t->id);

        $tracks = collect($ratingKeys)
            ->map(fn (string $key) => $fetched->get($key))
            ->filter()
            ->map(fn ($t) => [
                'ratingKey' => $t->id,
                'title' => $t->title,
                'artist' => $t->artist,
                'album' => $t->album,
                'durationMs' => $t->durationMs,
                'reason' => $rationale[$t->id] ?? null,
            ])
            ->values()
            ->all();

        $this->store->put($this->conversationId, [
            'name' => $request['name'],
            'description' => $request['description'] ?? null,
            'tracks' => $tracks,
        ]);

        return 'Proposal rendered, awaiting user feedback.';
    }
}
