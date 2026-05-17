<?php

namespace App\Ai\Tools;

use App\Services\Plex\PlexClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchArtists implements Tool
{
    public function __construct(private PlexClient $plex) {}

    public function description(): Stringable|string
    {
        return 'Search for artists by name. Returns up to "limit" matches (default 10) with ratingKey, name, and albumCount. Use when the user names a specific artist.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
            'limit' => $schema->integer()->min(1)->max(50)->nullable()->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = $request['limit'] ?? 10;

        $artists = $this->plex->searchAll($request['query'])->artists
            ->take($limit)
            ->map(fn ($a) => ['ratingKey' => $a->id, 'name' => $a->name, 'albumCount' => $a->albumCount])
            ->values()
            ->all();

        return json_encode($artists, JSON_THROW_ON_ERROR);
    }
}
