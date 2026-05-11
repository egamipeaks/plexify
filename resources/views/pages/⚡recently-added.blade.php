<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?string $errorMessage = null;

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    public function retry(): void
    {
        $this->errorMessage = null;
        unset($this->albums);
    }

    /** @return Collection<int, Album> */
    #[Computed]
    public function albums(): Collection
    {
        if ($this->errorMessage) {
            return collect();
        }

        try {
            return $this->plex->recentlyAddedAlbums();
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. ".$e->getMessage();

            return collect();
        }
    }

    public function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }
};
?>

<div class="h-full flex flex-col">
    @php($this->albums)
    @if ($this->errorMessage)
        <div class="flex-1 grid place-items-center p-12 text-center">
            <div class="max-w-md">
                <x-lucide-server-off class="w-12 h-12 mx-auto text-text-3 mb-4" />
                <h2 class="text-xl font-bold mb-2">Can't reach your Plex server</h2>
                <p class="text-text-2 text-sm mb-6">{{ $this->errorMessage }}</p>
                <button type="button" wire:click="retry"
                        class="px-5 py-2 bg-white text-black rounded-full font-bold hover:scale-105 transition-transform">
                    Retry
                </button>
            </div>
        </div>
    @elseif ($this->albums->isEmpty())
        <div class="flex-1 grid place-items-center p-12 text-center">
            <div class="max-w-md">
                <x-lucide-clock class="w-12 h-12 mx-auto text-text-3 mb-4" />
                <h2 class="text-xl font-bold mb-2">Recently Added</h2>
                <p class="text-text-2 text-sm">No recent additions yet.</p>
            </div>
        </div>
    @else
        <div class="px-6 pt-6 pb-2 flex-none">
            <h1 class="text-[clamp(22px,3.4vw,40px)] font-black tracking-tight text-white">Recently Added</h1>
            <p class="text-text-2 text-sm">The latest albums imported into your library.</p>
        </div>
        <div class="flex-1 overflow-y-auto scroll px-6 pb-6">
            <div class="grid grid-cols-[repeat(auto-fill,minmax(180px,1fr))] gap-4">
                @foreach ($this->albums as $album)
                    @include('partials.album-card', ['album' => $album, 'thumbUrl' => $this->thumbFor($album->thumb)])
                @endforeach
            </div>
        </div>
    @endif
</div>
