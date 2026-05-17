<?php

namespace App\Ai\Tools;

use App\Services\Plex\PlexClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FindTracks implements Tool
{
    public function __construct(private PlexClient $plex) {}

    public function description(): Stringable|string
    {
        return 'Find tracks in the user\'s library by tag ids and/or year range. Returns a JSON array of up to "limit" tracks (default 50, max 200). Combine styleIds/moodIds/genreIds for AND semantics. Always use ids returned by listTaxonomy, never bare names.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'styleIds' => $schema->array()->items($schema->string()),
            'moodIds' => $schema->array()->items($schema->string()),
            'genreIds' => $schema->array()->items($schema->string()),
            'artistIds' => $schema->array()->items($schema->string()),
            'yearFrom' => $schema->integer(),
            'yearTo' => $schema->integer(),
            'limit' => $schema->integer()->min(1)->max(200),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $filters = [];

        foreach (['styleIds', 'moodIds', 'genreIds', 'artistIds'] as $listKey) {
            $value = $request[$listKey] ?? null;

            if (! empty($value)) {
                $filters[$listKey] = $value;
            }
        }

        foreach (['yearFrom', 'yearTo'] as $intKey) {
            $value = $request[$intKey] ?? null;

            if ($value !== null) {
                $filters[$intKey] = $value;
            }
        }

        $limit = $request['limit'] ?? null;
        $filters['limit'] = min($limit ?? 50, 200);

        $tracks = $this->plex->findTracks($filters)
            ->map(fn ($t) => [
                'ratingKey' => $t->id,
                'title' => $t->title,
                'artist' => $t->artist,
                'album' => $t->album,
                'durationMs' => $t->durationMs,
            ])
            ->values()
            ->all();

        return json_encode($tracks, JSON_THROW_ON_ERROR);
    }
}
