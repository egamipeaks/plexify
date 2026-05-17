<?php

namespace App\Ai\Tools;

use App\Services\Plex\PlexClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListTaxonomy implements Tool
{
    public function __construct(private PlexClient $plex) {}

    public function description(): Stringable|string
    {
        return 'List the available genres, styles, or moods in the user\'s Plex music library. Returns a JSON array of {id, name}. Use the returned ids with findTracks.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(['genre', 'style', 'mood'])->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $kind = $request['kind'] ?? null;

        $rows = match ($kind) {
            'genre' => $this->plex->genres(),
            'style' => $this->plex->styles(),
            'mood' => $this->plex->moods(),
            default => throw new InvalidArgumentException('Unknown taxonomy kind: '.var_export($kind, true)),
        };

        return json_encode($rows, JSON_THROW_ON_ERROR);
    }
}
