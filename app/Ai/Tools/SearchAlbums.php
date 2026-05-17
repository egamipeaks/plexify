<?php

namespace App\Ai\Tools;

use App\Services\Plex\PlexClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchAlbums implements Tool
{
    public function __construct(private PlexClient $plex) {}

    public function description(): Stringable|string
    {
        return 'Search for albums by title. Returns up to "limit" matches (default 10) with ratingKey, title, artist, year, and trackCount. Use when the user names a specific album.';
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

        $albums = $this->plex->searchAll($request['query'])->albums
            ->take($limit)
            ->map(fn ($a) => [
                'ratingKey' => $a->id,
                'title' => $a->title,
                'artist' => $a->artist,
                'year' => $a->year,
                'trackCount' => $a->trackCount,
            ])
            ->values()
            ->all();

        return json_encode($albums, JSON_THROW_ON_ERROR);
    }
}
