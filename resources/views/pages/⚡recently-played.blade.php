<?php

use App\Services\Plex\Dto\Track;
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
        unset($this->tracks);
    }

    public function playTrack(string $trackId): void
    {
        $tracks = $this->tracks->values();
        $i = $tracks->search(fn ($t) => $t->id === $trackId);

        if ($i === false) {
            return;
        }

        $this->dispatch('play-track',
            queue: $tracks->map(fn ($t) => $this->plex->queueItem($t))->values()->all(),
            index: $i,
            contextType: 'recently-played',
            contextId: null,
        );
    }

    /** @return Collection<int, Track> */
    #[Computed]
    public function tracks(): Collection
    {
        if ($this->errorMessage) {
            return collect();
        }

        try {
            return $this->plex->recentlyPlayedTracks();
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
    @php($this->tracks)
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
    @elseif ($this->tracks->isEmpty())
        <div class="flex-1 grid place-items-center p-12 text-center">
            <div class="max-w-md">
                <x-lucide-history class="w-12 h-12 mx-auto text-text-3 mb-4" />
                <h2 class="text-xl font-bold mb-2">Recently Played</h2>
                <p class="text-text-2 text-sm">Nothing played yet. Play history from other Plex clients shows up here too.</p>
            </div>
        </div>
    @else
        <div class="px-6 pt-6 pb-2 flex-none">
            <h1 class="text-[clamp(22px,3.4vw,40px)] font-black tracking-tight text-white">Recently Played</h1>
            <p class="text-text-2 text-sm">The tracks most recently played across your Plex clients.</p>
        </div>

        <div class="flex-1 min-h-0 flex flex-col px-2 pb-2 overflow-hidden" data-region="tracklist">
            <div class="flex-1 min-h-0 flex flex-col relative bg-surface rounded-lg overflow-hidden">
                <div class="grid items-center px-4 py-2 text-[11px] uppercase tracking-wider text-text-2 border-b border-white/10 sticky top-0 bg-surface z-10"
                     style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">
                    <span></span>
                    <span class="text-right">#</span>
                    <span>Title</span>
                    <span>Album</span>
                    <span></span>
                    <span class="text-right"><x-lucide-clock class="w-[14px] h-[14px] inline" /></span>
                </div>
                <div class="overflow-y-auto scroll flex-1 py-1">
                    @foreach ($this->tracks as $i => $track)
                        <button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')"
                                draggable="true"
                                ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"
                                class="row group w-full grid items-center px-4 py-2 rounded text-[14px] text-left hover:bg-white/[0.07] transition-colors"
                                style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">
                            <span class="text-text-3 group-hover:text-white grid place-items-center">
                                <x-lucide-grip-vertical class="w-[14px] h-[14px]" />
                            </span>
                            <span class="tabular-nums text-text-2 text-right">{{ $i + 1 }}</span>
                            <div class="min-w-0 flex items-center gap-3">
                                <div class="relative rounded-sm flex-none bg-surface-2 grid place-items-center overflow-hidden" style="width: 36px; height: 36px;">
                                    <x-lucide-disc class="w-3.5 h-3.5 text-text-3" />
                                    @if ($track->thumb && $this->thumbFor($track->thumb))
                                        <img src="{{ $this->thumbFor($track->thumb) }}" alt="{{ $track->title }}" loading="lazy"
                                             onerror="this.remove()"
                                             class="absolute inset-0 w-full h-full object-cover">
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <div class="truncate font-medium text-white">{{ $track->title }}</div>
                                    <div class="truncate text-[12px] text-text-2 group-hover:text-white">{{ $track->artist }}</div>
                                </div>
                            </div>
                            <div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>
                            <span class="grid place-items-center text-text-2 hover:text-white">
                                <x-lucide-heart class="w-3.5 h-3.5" />
                            </span>
                            <div class="text-text-2 tabular-nums text-right">{{ \App\Support\Duration::format($track->durationMs) }}</div>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
