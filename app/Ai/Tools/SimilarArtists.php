<?php

namespace App\Ai\Tools;

use App\Services\Plex\PlexClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SimilarArtists implements Tool
{
    public function __construct(private PlexClient $plex) {}

    public function description(): Stringable|string
    {
        return 'Get artists similar to a given artist, per Plex metadata. Names only (these come from Plex\'s metadata provider and may not have ratingKeys of their own). To use a result as a filter, pass the name through searchArtists first to resolve to an artistId.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'artistId' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $similar = $this->plex->similarArtists($request['artistId'])->values()->all();

        return json_encode($similar, JSON_THROW_ON_ERROR);
    }
}
