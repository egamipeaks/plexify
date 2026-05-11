<?php

use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $playlist = '';

    public ?string $errorMessage = null;

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    public function mount(string $playlist): void
    {
        $this->playlist = $playlist;
    }

    public function playTrack(string $trackId): void
    {
        $tracks = $this->tracks->values();
        $i = $tracks->search(fn ($t) => $t->id === $trackId);

        if ($i === false) {
            return;
        }

        $this->dispatch('play-track', queue: $this->queuePayload(), index: $i);
    }

    public function playAll(): void
    {
        if ($this->tracks->isEmpty()) {
            return;
        }

        $this->dispatch('play-track', queue: $this->queuePayload(), index: 0);
    }

    public function shuffle(): void
    {
        if ($this->tracks->isEmpty()) {
            return;
        }

        $this->dispatch('play-track',
            queue: $this->queuePayload(),
            index: random_int(0, $this->tracks->count() - 1),
            shuffle: true,
        );
    }

    public function retry(): void
    {
        $this->errorMessage = null;
        unset($this->playlistMeta, $this->tracks);
    }

    #[Computed]
    public function playlistMeta(): ?Playlist
    {
        try {
            return $this->plex->playlists()->firstWhere('id', $this->playlist);
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. " . $e->getMessage();

            return null;
        }
    }

    /** @return Collection<int, Track> */
    #[Computed]
    public function tracks(): Collection
    {
        if (! $this->playlistMeta) {
            return collect();
        }

        try {
            return $this->plex->playlistTracks($this->playlist);
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. " . $e->getMessage();

            return collect();
        }
    }

    /** @return list<array{id: string, url: string, title: string, artist: string, artwork: ?string, albumId: ?string, artistId: ?string}> */
    protected function queuePayload(): array
    {
        return $this->tracks->values()->map(fn ($t) => $this->plex->queueItem($t))->all();
    }

    protected function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }

    protected function formatMs(int $ms): string
    {
        $seconds = (int) round($ms / 1000);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
};
?>

<div class="h-full flex flex-col">
{{-- Force the computeds so a Plex failure populates $errorMessage before the branches below. --}}
@php($this->playlistMeta)
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
@elseif (! $this->playlistMeta)
    <div class="flex-1 grid place-items-center p-12 text-center">
        <div class="max-w-md">
            <x-lucide-list-music class="w-12 h-12 mx-auto text-text-3 mb-4" />
            <h2 class="text-xl font-bold mb-2">Playlist not found</h2>
            <p class="text-text-2 text-sm mb-6">This playlist doesn't exist on your Plex server.</p>
            <a href="{{ route('library') }}" wire:navigate
               class="px-5 py-2 bg-white text-black rounded-full font-bold hover:scale-105 transition-transform inline-block">
                Back to library
            </a>
        </div>
    </div>
@else
    @php($meta = $this->playlistMeta)
    {{-- Gradient header --}}
    <div class="px-2 pt-2 pb-2 flex-none">
        <div class="relative overflow-hidden rounded-lg" style="background: linear-gradient(180deg, #4a3b6b 0%, #2a2438 60%, var(--color-surface) 100%);">
            <div class="flex items-end gap-6 p-6">
                <div class="rounded relative overflow-hidden flex-none shadow-2xl bg-surface-2 grid place-items-center" style="width: 180px; height: 180px;">
                    <x-lucide-list-music class="w-12 h-12 text-text-3" />
                    @if ($meta->thumb && $this->thumbFor($meta->thumb))
                        <img src="{{ $this->thumbFor($meta->thumb) }}" alt="{{ $meta->title }}"
                             onerror="this.remove()"
                             class="absolute inset-0 w-full h-full object-cover">
                    @endif
                </div>
                <div class="min-w-0 flex-1 flex flex-col gap-2">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-white">PLAYLIST</div>
                    <h1 class="text-white font-extrabold tracking-tight leading-[1.05] truncate self-start" style="font-size: clamp(28px, 4.2vw, 56px);">{{ $meta->title }}</h1>
                    @if ($meta->summary)
                        <div class="text-[14px] text-text-2 max-w-prose">{{ $meta->summary }}</div>
                    @endif
                    <div class="flex items-center gap-1.5 text-[13px] text-text-2 flex-wrap">
                        <span class="whitespace-nowrap tabular-nums">{{ $this->tracks->count() }} songs, {{ $this->formatMs($this->tracks->sum('durationMs')) }}</span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-4 px-6 pb-4">
                <button type="button" wire:click="playAll"
                        class="w-14 h-14 rounded-full bg-accent hover:bg-accent-hover hover:scale-105 active:scale-100 transition-transform grid place-items-center text-black shadow-xl">
                    <x-lucide-play class="w-[22px] h-[22px]" style="fill: currentColor;" />
                </button>
                <button type="button" wire:click="shuffle"
                        class="w-10 h-10 rounded-full grid place-items-center text-text-2 hover:text-white hover:scale-105 transition-transform">
                    <x-lucide-shuffle class="w-[22px] h-[22px]" />
                </button>
                <button type="button"
                        class="px-4 py-1.5 rounded-full border border-white/30 text-white text-[13px] font-bold hover:border-white">
                    Edit playlist
                </button>
                <button type="button" class="text-text-2 hover:text-white">
                    <x-lucide-download class="w-5 h-5" />
                </button>
                <div class="flex-1"></div>
            </div>
        </div>
    </div>

    {{-- Tracklist --}}
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
                            @if ($track->thumb)
                                <img src="{{ $this->thumbFor($track->thumb) }}" alt="{{ $track->title }}"
                                     class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 36px; height: 36px;" loading="lazy">
                            @else
                                <div class="rounded-sm relative overflow-hidden flex-none bg-surface-2 grid place-items-center" style="width: 36px; height: 36px;">
                                    <x-lucide-disc class="w-3.5 h-3.5 text-text-3" />
                                </div>
                            @endif
                            <div class="min-w-0">
                                <div class="truncate font-medium text-white">{{ $track->title }}</div>
                                <div class="truncate text-[12px] text-text-2 group-hover:text-white">{{ $track->artist }}</div>
                            </div>
                        </div>
                        <div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>
                        <span class="grid place-items-center text-text-2 hover:text-white">
                            <x-lucide-heart class="w-3.5 h-3.5" />
                        </span>
                        <div class="text-text-2 tabular-nums text-right">{{ $this->formatMs($track->durationMs) }}</div>
                    </button>
                @endforeach
            </div>
        </div>
    </div>
@endif
</div>
