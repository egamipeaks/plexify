<?php

namespace App\Ai\Tools;

use App\Services\Plex\PlexClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PopularTracksForArtist implements Tool
{
    public function __construct(private PlexClient $plex) {}

    public function description(): Stringable|string
    {
        return 'Get an artist\'s most popular tracks (the "fire icon" in Plex). Useful when the user names an artist and you want their bangers. Returns up to 20 rows; pass the artistId you got from searchArtists or findTracks.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'artistId' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $tracks = $this->plex->popularTracksForArtist($request['artistId'])
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
