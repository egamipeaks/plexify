<?php

namespace App\Livewire\Concerns;

use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Facades\Log;

trait TogglesFavorite
{
    public function toggleHeart(string $ratingKey, int $newRating): bool
    {
        if (! in_array($newRating, [0, 10], true)) {
            return false;
        }

        try {
            app(PlexClient::class)->rateTrack($ratingKey, $newRating);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('toggleHeart failed', [
                'ratingKey' => $ratingKey,
                'newRating' => $newRating,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'Couldn\'t update favorite. Plex may be unreachable.');

            return false;
        }

        return true;
    }
}
