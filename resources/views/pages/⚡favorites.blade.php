<?php

use App\Livewire\Concerns\TogglesFavorite;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use App\Support\Duration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    use TogglesFavorite;

    public bool $loadFailed = false;

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    /** @return Collection<int, Track> */
    #[Computed]
    public function tracks(): Collection
    {
        if ($this->loadFailed) {
            return collect();
        }

        try {
            return $this->plex->favoriteTracks();
        } catch (PlexException $e) {
            Log::channel('plex')->warning('favoriteTracks failed', ['error' => $e->getMessage()]);
            $this->loadFailed = true;
            $this->dispatch('notify', type: 'error', message: 'Couldn\'t load favorites. Plex may be unreachable.');

            return collect();
        }
    }

    public function playTrack(string $trackId): void
    {
        $tracks = $this->tracks->values();
        $index = $tracks->search(fn ($t) => $t->id === $trackId);

        if ($index === false) {
            return;
        }

        $this->dispatch('play-track',
            queue: $tracks->map(fn ($t) => $this->plex->queueItem($t))->values()->all(),
            index: $index,
            contextType: 'favorites',
            contextId: null,
        );
    }

    protected function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }
};
?>

<div class="h-full flex flex-col">
    @php($this->tracks)

    @if ($this->tracks->isEmpty())
        <div class="flex-1 grid place-items-center p-12 text-center">
            <div class="max-w-md">
                <x-lucide-heart class="w-12 h-12 mx-auto text-text-3 mb-4" />
                <h2 class="text-xl font-bold mb-2">Favorites</h2>
                <p class="text-text-2 text-sm">No favorites yet. Heart a track and it'll show up here.</p>
            </div>
        </div>
    @else
        <div class="px-6 pt-6 pb-2 flex-none">
            <h1 class="text-[clamp(22px,3.4vw,40px)] font-black tracking-tight text-white">Favorites</h1>
            <p class="text-text-2 text-sm">{{ $this->tracks->count() }} {{ \Illuminate\Support\Str::plural('song', $this->tracks->count()) }}, sorted by most recently hearted.</p>
        </div>

        <div class="flex-1 min-h-0 flex flex-col px-2 pb-2 overflow-hidden" data-region="favorites-tracklist">
            <div class="flex-1 min-h-0 flex flex-col relative bg-surface rounded-lg overflow-hidden">
                <div class="grid items-center px-4 py-2 text-[11px] uppercase tracking-wider text-text-2 border-b border-white/10 sticky top-0 bg-surface z-10"
                     style="grid-template-columns: 40px 36px 1.6fr 1fr 1fr 16px 60px;">
                    <span></span>
                    <span class="text-right">#</span>
                    <span>Title</span>
                    <span>Album</span>
                    <span>Added</span>
                    <span></span>
                    <span class="text-right"><x-lucide-clock class="w-[14px] h-[14px] inline" /></span>
                </div>
                <div class="overflow-y-auto scroll flex-1 py-1" x-data="{}">
                    @foreach ($this->tracks as $i => $track)
                        <button type="button" wire:key="fav-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')"
                                draggable="true"
                                ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"
                                class="row group w-full grid items-center px-4 py-2 rounded text-[14px] text-left hover:bg-white/[0.07] transition-colors"
                                style="grid-template-columns: 40px 36px 1.6fr 1fr 1fr 16px 60px;">
                            <span class="text-text-3 group-hover:text-white grid place-items-center">
                                <x-lucide-grip-vertical class="w-[14px] h-[14px]" />
                            </span>
                            <span class="tabular-nums text-text-2 text-right"
                                  x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'favorites'; } }">
                                <template x-if="playing">
                                    <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                                </template>
                                <template x-if="!playing">
                                    <span>{{ $i + 1 }}</span>
                                </template>
                            </span>
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
                                    <div class="truncate font-medium"
                                         :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'favorites') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
                                    @if ($track->artistId)
                                        <span class="block truncate text-[12px] text-text-2 group-hover:text-white hover:underline cursor-pointer"
                                              @click.stop="Livewire.navigate('{{ route('library', ['artist' => $track->artistId]) }}')">{{ $track->artist }}</span>
                                    @else
                                        <div class="truncate text-[12px] text-text-2 group-hover:text-white">{{ $track->artist }}</div>
                                    @endif
                                </div>
                            </div>
                            @if ($track->artistId && $track->albumId)
                                <span class="text-text-2 group-hover:text-white truncate hover:underline cursor-pointer"
                                      @click.stop="Livewire.navigate('{{ route('library', ['artist' => $track->artistId, 'album' => $track->albumId]) }}')">{{ $track->album }}</span>
                            @else
                                <div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>
                            @endif
                            <div class="text-text-2 text-[12px] tabular-nums truncate">
                                @if ($track->lastRatedAt)
                                    {{ \Carbon\Carbon::createFromTimestamp((int) $track->lastRatedAt)->diffForHumans() }}
                                @endif
                            </div>
                            <x-heart-button :rating-key="$track->id" :rating="$track->userRating" size="sm" :always-visible="true" />
                            <div class="text-text-2 tabular-nums text-right">{{ Duration::format($track->durationMs) }}</div>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
