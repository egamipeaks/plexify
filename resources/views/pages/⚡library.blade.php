<?php

use App\Livewire\Concerns\TogglesFavorite;
use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    use TogglesFavorite;
    #[Url(as: 'artist', except: null)]
    public ?string $selectedArtistId = null;

    #[Url(as: 'album', except: null)]
    public ?string $selectedAlbumId = null;

    public ?string $errorMessage = null;

    public bool $headerCollapsed = false;

    public bool $artistsCompact = false;

    public bool $albumsCompact = false;

    public bool $tracksCompact = false;

    public function mount(PlexClient $plex): void
    {
        $this->headerCollapsed = \App\Support\AppSetting::albumHeaderCollapsed();
        $this->artistsCompact = \App\Support\AppSetting::artistsCompact();
        $this->albumsCompact = \App\Support\AppSetting::albumsCompact();
        $this->tracksCompact = \App\Support\AppSetting::libraryTracksCompact();
        $this->loadArtists($plex);
    }

    public function updatedHeaderCollapsed(bool $value): void
    {
        \App\Support\AppSetting::setAlbumHeaderCollapsed($value);
    }

    public function updatedArtistsCompact(bool $value): void
    {
        \App\Support\AppSetting::setArtistsCompact($value);
    }

    public function updatedAlbumsCompact(bool $value): void
    {
        \App\Support\AppSetting::setAlbumsCompact($value);
    }

    public function updatedTracksCompact(bool $value): void
    {
        \App\Support\AppSetting::setLibraryTracksCompact($value);
    }

    public function selectArtist(string $id): void
    {
        $this->selectedArtistId = $id;
        $this->selectedAlbumId = null;
    }

    public function selectAlbum(string $id): void
    {
        $this->selectedAlbumId = $id;
    }

    public function closeAlbum(): void
    {
        $this->selectedAlbumId = null;
    }

    public function playTrack(PlexClient $plex, string $trackId): void
    {
        $tracks = $this->tracks->values();
        $i = $tracks->search(fn ($t) => $t->id === $trackId);

        if ($i === false) {
            return;
        }

        $this->dispatch('play-track', queue: $this->albumQueue($plex), index: $i, contextType: 'album', contextId: $this->selectedAlbumId);
    }

    public function playAlbum(PlexClient $plex): void
    {
        if ($this->tracks->isEmpty()) {
            return;
        }

        $this->dispatch('play-track', queue: $this->albumQueue($plex), index: 0, contextType: 'album', contextId: $this->selectedAlbumId);
    }

    public function shuffleAlbum(PlexClient $plex): void
    {
        if ($this->tracks->isEmpty()) {
            return;
        }

        $this->dispatch('play-track',
            queue: $this->albumQueue($plex),
            index: random_int(0, $this->tracks->count() - 1),
            shuffle: true,
            contextType: 'album',
            contextId: $this->selectedAlbumId,
        );
    }

    /** @return list<array{id: string, url: string, title: string, artist: string, artwork: ?string, albumId: ?string, artistId: ?string}> */
    protected function albumQueue(PlexClient $plex): array
    {
        $artwork = $this->thumbFor($this->selectedAlbum?->thumb);

        return $this->tracks->values()->map(fn ($t) => $plex->queueItem($t, $artwork))->all();
    }

    protected function thumbFor(?string $thumb): ?string
    {
        return app(PlexClient::class)->thumbUrl($thumb);
    }

    public function retry(PlexClient $plex): void
    {
        $this->errorMessage = null;
        $this->loadArtists($plex);
    }

    private function loadArtists(PlexClient $plex): void
    {
        try {
            $plex->artists();
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. " . $e->getMessage();
        }
    }

    /** @return Collection<int, Artist> */
    #[Computed]
    public function artists(): Collection
    {
        if ($this->errorMessage) {
            return collect();
        }
        try {
            return app(PlexClient::class)->artists();
        } catch (PlexException $e) {
            $this->errorMessage = "Can't reach your Plex server. " . $e->getMessage();

            return collect();
        }
    }

    #[Computed]
    public function albums(): Collection
    {
        if (! $this->selectedArtistId) {
            return collect();
        }
        try {
            return app(PlexClient::class)->albumsForArtist($this->selectedArtistId);
        } catch (PlexException) {
            return collect();
        }
    }

    #[Computed]
    public function tracks(): Collection
    {
        if (! $this->selectedAlbumId) {
            return collect();
        }
        try {
            return app(PlexClient::class)->tracksForAlbum($this->selectedAlbumId);
        } catch (PlexException) {
            return collect();
        }
    }

    #[Computed]
    public function selectedAlbum(): ?Album
    {
        return $this->albums->firstWhere('id', $this->selectedAlbumId);
    }
};
?>

<div class="h-full flex flex-col" data-selected-artist="{{ $selectedArtistId }}" data-selected-album="{{ $selectedAlbumId }}">
@if ($errorMessage)
    <div class="flex-1 grid place-items-center p-12 text-center">
        <div class="max-w-md">
            <x-lucide-server-off class="w-12 h-12 mx-auto text-text-3 mb-4" />
            <h2 class="text-xl font-bold mb-2">Can't reach your Plex server</h2>
            <p class="text-text-2 text-sm mb-6">{{ $errorMessage }}</p>
            <button type="button" wire:click="retry"
                    class="px-5 py-2 bg-white text-black rounded-full font-bold hover:scale-105 transition-transform">
                Retry
            </button>
        </div>
    </div>
@else
        {{-- Miller columns: Artists + Albums (fills the page until an album is selected) --}}
        <div @class([
                'grid grid-cols-2 gap-2 px-2 pt-2 pb-2',
                'flex-none' => $this->selectedAlbum,
                'flex-1 min-h-0' => ! $this->selectedAlbum,
             ])
             @style(['height: 220px' => $this->selectedAlbum])>
            {{-- Artists column --}}
            <div class="flex flex-col min-h-0 bg-surface rounded-lg overflow-hidden">
                <div class="px-4 pt-3 pb-2 flex items-center justify-between gap-2 flex-none">
                    <div class="text-[13px] font-bold uppercase tracking-wider text-text-2 whitespace-nowrap">Artists</div>
                    <div class="flex items-center gap-2 flex-none">
                        <button type="button" class="text-[11px] text-text-2 hover:text-white font-semibold flex items-center gap-1 whitespace-nowrap">
                            <x-lucide-arrow-up-down class="w-[11px] h-[11px]" /> A&ndash;Z
                        </button>
                        <span class="text-[11px] text-text-3 tabular-nums">{{ $this->artists->count() }}</span>
                        <div class="flex items-center bg-surface-2 rounded p-0.5">
                            <button type="button" title="Comfortable" wire:click="$set('artistsCompact', false)"
                                    @class(['w-6 h-6 grid place-items-center rounded', 'bg-surface-4 text-white' => ! $artistsCompact, 'text-text-2 hover:text-white' => $artistsCompact])>
                                <x-lucide-grid-2x2 class="w-[11px] h-[11px]" />
                            </button>
                            <button type="button" title="Compact list" wire:click="$set('artistsCompact', true)"
                                    @class(['w-6 h-6 grid place-items-center rounded', 'bg-surface-4 text-white' => $artistsCompact, 'text-text-2 hover:text-white' => ! $artistsCompact])>
                                <x-lucide-menu class="w-[11px] h-[11px]" />
                            </button>
                        </div>
                    </div>
                </div>
                <div class="overflow-y-auto scroll flex-1" data-region="artists-column">
                    @forelse ($this->artists as $artist)
                        @if ($artistsCompact)
                            <button type="button" wire:key="artist-{{ $artist->id }}" wire:click="selectArtist('{{ $artist->id }}')"
                                    @class([
                                        'w-full flex items-center gap-3 px-3 py-[3px] text-[13px] text-left transition-colors',
                                        'bg-accent/15 text-accent' => $selectedArtistId === $artist->id,
                                        'text-text-2 hover:text-white hover:bg-surface-2' => $selectedArtistId !== $artist->id,
                                    ])>
                                <span class="flex-1 min-w-0 truncate">{{ $artist->name }}</span>
                            </button>
                        @else
                            <button type="button" wire:key="artist-{{ $artist->id }}" wire:click="selectArtist('{{ $artist->id }}')"
                                    @class([
                                        'w-full flex items-center gap-3 px-3 py-1.5 text-left transition-colors',
                                        'bg-surface-3 text-white' => $selectedArtistId === $artist->id,
                                        'text-text-2 hover:text-white hover:bg-surface-2' => $selectedArtistId !== $artist->id,
                                    ])>
                                @if ($artist->thumb)
                                    <img src="{{ $this->thumbFor($artist->thumb) }}" alt="{{ $artist->name }}"
                                         class="rounded-full flex-none bg-surface-2 object-cover" style="width: 36px; height: 36px;" loading="lazy">
                                @else
                                    <div class="rounded-full relative overflow-hidden flex-none bg-surface-2 grid place-items-center" style="width: 36px; height: 36px;">
                                        <x-lucide-user class="w-4 h-4 text-text-3" />
                                    </div>
                                @endif
                                <div class="flex-1 min-w-0">
                                    <div class="text-[14px] font-semibold truncate">{{ $artist->name }}</div>
                                </div>
                            </button>
                        @endif
                    @empty
                        <div class="grid place-items-center h-full text-text-3 text-[12px] px-4 text-center">No music found in your Plex library.</div>
                    @endforelse
                </div>
            </div>

            {{-- Albums column --}}
            <div class="flex flex-col min-h-0 bg-surface rounded-lg overflow-hidden">
                <div class="px-4 pt-3 pb-2 flex items-center justify-between gap-2 flex-none">
                    <div class="text-[13px] font-bold uppercase tracking-wider text-text-2 whitespace-nowrap">Albums</div>
                    <div class="flex items-center gap-2 flex-none">
                        <button type="button" class="text-[11px] text-text-2 hover:text-white font-semibold flex items-center gap-1 whitespace-nowrap">
                            <x-lucide-arrow-up-down class="w-[11px] h-[11px]" /> A&ndash;Z
                        </button>
                        <span class="text-[11px] text-text-3 tabular-nums">{{ $this->albums->count() }}</span>
                        <div class="flex items-center bg-surface-2 rounded p-0.5">
                            <button type="button" title="Comfortable" wire:click="$set('albumsCompact', false)"
                                    @class(['w-6 h-6 grid place-items-center rounded', 'bg-surface-4 text-white' => ! $albumsCompact, 'text-text-2 hover:text-white' => $albumsCompact])>
                                <x-lucide-grid-2x2 class="w-[11px] h-[11px]" />
                            </button>
                            <button type="button" title="Compact list" wire:click="$set('albumsCompact', true)"
                                    @class(['w-6 h-6 grid place-items-center rounded', 'bg-surface-4 text-white' => $albumsCompact, 'text-text-2 hover:text-white' => ! $albumsCompact])>
                                <x-lucide-menu class="w-[11px] h-[11px]" />
                            </button>
                        </div>
                    </div>
                </div>
                <div class="overflow-y-auto scroll flex-1" data-region="albums-column">
                    @if (! $selectedArtistId)
                        <div class="grid place-items-center h-full text-text-3 text-[12px]">Select an artist</div>
                    @elseif ($this->albums->isEmpty())
                        <div class="grid place-items-center h-full text-text-3 text-[12px]">No albums</div>
                    @else
                        @foreach ($this->albums as $album)
                            @if ($albumsCompact)
                                <button type="button" wire:key="album-{{ $album->id }}" wire:click="selectAlbum('{{ $album->id }}')"
                                        @class([
                                            'w-full flex items-center gap-3 px-3 py-[3px] text-[13px] text-left transition-colors',
                                            'bg-accent/15 text-accent' => $selectedAlbumId === $album->id,
                                            'text-text-2 hover:text-white hover:bg-surface-2' => $selectedAlbumId !== $album->id,
                                        ])>
                                    <span class="flex-1 min-w-0 truncate">{{ $album->title }}</span>
                                </button>
                            @else
                                <button type="button" wire:key="album-{{ $album->id }}" wire:click="selectAlbum('{{ $album->id }}')"
                                        @class([
                                            'w-full flex items-center gap-3 px-3 py-1.5 text-left transition-colors',
                                            'bg-surface-3 text-white' => $selectedAlbumId === $album->id,
                                            'text-text-2 hover:text-white hover:bg-surface-2' => $selectedAlbumId !== $album->id,
                                        ])>
                                    @if ($album->thumb)
                                        <img src="{{ $this->thumbFor($album->thumb) }}" alt="{{ $album->title }}"
                                             class="rounded flex-none bg-surface-2 object-cover" style="width: 36px; height: 36px;" loading="lazy">
                                    @else
                                        <div class="rounded relative overflow-hidden flex-none bg-surface-2 grid place-items-center" style="width: 36px; height: 36px;">
                                            <x-lucide-disc class="w-4 h-4 text-text-3" />
                                        </div>
                                    @endif
                                    <div class="flex-1 min-w-0">
                                        <div class="text-[14px] font-semibold truncate">{{ $album->title }}</div>
                                        <div class="text-[11px] text-text-2 truncate">
                                            {{ $album->year ?? '' }}
                                        </div>
                                    </div>
                                </button>
                            @endif
                        @endforeach
                    @endif
                </div>
            </div>
        </div>

        {{-- Album header --}}
        @if ($this->selectedAlbum)
            <div class="px-2 pb-2 flex-none" x-data="{ collapsed: @entangle('headerCollapsed') }" data-album-header>
                {{-- Collapsed 56px row --}}
                <div x-show="collapsed" x-cloak data-album-header-collapsed
                     class="relative flex items-center gap-3 h-14 px-4 rounded-lg" style="background: linear-gradient(180deg, rgba(42, 42, 42, 0.55) 0%, var(--color-surface) 100%);">
                    @if ($this->selectedAlbum->thumb)
                        <img src="{{ $this->thumbFor($this->selectedAlbum->thumb) }}" alt="{{ $this->selectedAlbum->title }}"
                             class="w-10 h-10 rounded flex-none bg-surface-2 object-cover">
                    @else
                        <div class="w-10 h-10 rounded flex-none bg-surface-2 grid place-items-center">
                            <x-lucide-disc class="w-5 h-5 text-text-3" />
                        </div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-[14px] font-bold text-white">{{ $this->selectedAlbum->title }}</div>
                        <div class="truncate text-[11px] text-text-2">{{ collect(['Album', $this->selectedAlbum->artist, $this->selectedAlbum->year])->filter()->implode(' · ') }}</div>
                    </div>
                    <template x-if="$store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}'">
                        <span data-source-indicator class="flex-none">
                            <x-lucide-volume-1 class="w-4 h-4 text-accent" />
                        </span>
                    </template>
                    <button type="button" wire:click="playAlbum" class="w-8 h-8 rounded-full bg-accent hover:bg-accent-hover grid place-items-center text-black flex-none">
                        <x-lucide-play class="w-4 h-4" style="fill: currentColor;" />
                    </button>
                    <button type="button" wire:click="shuffleAlbum" class="w-8 h-8 rounded-full grid place-items-center text-text-2 hover:text-white flex-none">
                        <x-lucide-shuffle class="w-4 h-4" />
                    </button>
                    <button type="button" @click="collapsed = false" title="Expand" class="w-8 h-8 rounded-full grid place-items-center text-text-2 hover:text-white flex-none">
                        <x-lucide-chevron-down class="w-4 h-4" />
                    </button>
                    <button type="button" wire:click="closeAlbum" title="Close album" class="w-8 h-8 rounded-full grid place-items-center text-text-2 hover:text-white flex-none">
                        <x-lucide-x class="w-4 h-4" />
                    </button>
                </div>

                {{-- Expanded header --}}
                <div x-show="!collapsed" x-cloak class="relative overflow-hidden rounded-lg" style="background: linear-gradient(180deg, rgba(42, 42, 42, 0.55) 0%, var(--color-surface) 100%);">
                    <div class="absolute top-3 right-3 z-10 flex items-center gap-1">
                        <button type="button" @click="collapsed = true" title="Collapse"
                                class="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-black/30 transition-colors">
                            <x-lucide-chevron-up class="w-4 h-4" />
                        </button>
                        <button type="button" wire:click="closeAlbum" title="Close album"
                                class="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-black/30 transition-colors">
                            <x-lucide-x class="w-4 h-4" />
                        </button>
                    </div>
                    <div class="px-6 py-5 flex items-center gap-5">
                        @if ($this->selectedAlbum->thumb)
                            <img src="{{ $this->thumbFor($this->selectedAlbum->thumb) }}" alt="{{ $this->selectedAlbum->title }}"
                                 draggable="true" title="Drag onto a playlist to add the whole album"
                                 ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/album', '{{ $this->selectedAlbum->id }}')"
                                 class="rounded-md flex-none shadow-2xl bg-surface-2 object-cover cursor-grab active:cursor-grabbing" style="width: 120px; height: 120px;">
                        @else
                            <div draggable="true" title="Drag onto a playlist to add the whole album"
                                 ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/album', '{{ $this->selectedAlbum->id }}')"
                                 class="rounded-md relative overflow-hidden flex-none shadow-2xl bg-surface-2 grid place-items-center cursor-grab active:cursor-grabbing" style="width: 120px; height: 120px;">
                                <x-lucide-disc class="w-10 h-10 text-text-3" />
                            </div>
                        @endif
                        <div class="flex-1 min-w-0">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-white/80">Album</div>
                            <h1 class="text-[clamp(22px,3.4vw,40px)] font-black tracking-tight leading-[1.05] truncate">{{ $this->selectedAlbum->title }}</h1>
                            <template x-if="$store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}'">
                                <span data-source-indicator class="mt-2 block">
                                    <x-lucide-volume-1 class="w-4 h-4 text-accent" />
                                </span>
                            </template>
                            <div class="mt-2 flex items-center gap-2 text-[13px] text-text-2 flex-wrap">
                                <span class="text-white font-semibold">{{ $this->selectedAlbum->artist }}</span>
                                @if ($this->selectedAlbum->year)
                                    <span>·</span>
                                    <span>{{ $this->selectedAlbum->year }}</span>
                                @endif
                                <span>·</span>
                                <span class="whitespace-nowrap tabular-nums">{{ $this->tracks->count() }} songs, {{ \App\Support\Duration::format($this->tracks->sum('durationMs')) }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="px-6 pb-4 flex items-center gap-4">
                        <button type="button" wire:click="playAlbum" class="w-14 h-14 rounded-full bg-accent hover:bg-accent-hover grid place-items-center text-black shadow-xl hover:scale-105 active:scale-100 transition-transform">
                            <x-lucide-play class="w-[22px] h-[22px]" style="fill: currentColor;" />
                        </button>
                        <button type="button" wire:click="shuffleAlbum" class="w-10 h-10 rounded-full grid place-items-center text-text-2 hover:text-white hover:scale-105 transition-all">
                            <x-lucide-shuffle class="w-[22px] h-[22px]" />
                        </button>
                        <button type="button" class="w-10 h-10 rounded-full grid place-items-center text-text-2 hover:text-white hover:scale-105 transition-all">
                            <x-lucide-download class="w-5 h-5" />
                        </button>
                        <div class="flex-1"></div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Tracklist --}}
        @if ($this->selectedAlbum)
            <div class="flex-1 min-h-0 flex flex-col px-2 pb-2 overflow-hidden" data-region="tracklist">
                <div class="flex-1 min-h-0 flex flex-col relative bg-surface rounded-lg overflow-hidden">
                    <div class="absolute right-2 top-1.5 z-20 flex items-center bg-surface-2 rounded p-0.5">
                        <button type="button" title="Comfortable" wire:click="$set('tracksCompact', false)"
                                @class(['w-6 h-6 grid place-items-center rounded', 'bg-surface-4 text-white' => ! $tracksCompact, 'text-text-2 hover:text-white' => $tracksCompact])>
                            <x-lucide-grid-2x2 class="w-[11px] h-[11px]" />
                        </button>
                        <button type="button" title="Compact list" wire:click="$set('tracksCompact', true)"
                                @class(['w-6 h-6 grid place-items-center rounded', 'bg-surface-4 text-white' => $tracksCompact, 'text-text-2 hover:text-white' => ! $tracksCompact])>
                            <x-lucide-menu class="w-[11px] h-[11px]" />
                        </button>
                    </div>
                    @if ($tracksCompact)
                        <div class="grid items-center px-4 py-1.5 text-[11px] uppercase tracking-wider text-text-2 border-b border-white/10 sticky top-0 bg-surface z-10 pr-20"
                             style="grid-template-columns: 20px 1.4fr 1fr 50px;">
                            <span class="text-right pr-1">#</span>
                            <span>Title</span>
                            <span>Artist</span>
                            <span class="text-right"><x-lucide-clock class="w-[14px] h-[14px] inline" /></span>
                        </div>
                    @else
                        <div class="grid items-center px-4 py-2 text-[11px] uppercase tracking-wider text-text-2 border-b border-white/10 sticky top-0 bg-surface z-10 pr-20"
                             style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">
                            <span></span>
                            <span class="text-right">#</span>
                            <span>Title</span>
                            <span>Album</span>
                            <span></span>
                            <span class="text-right"><x-lucide-clock class="w-[14px] h-[14px] inline" /></span>
                        </div>
                    @endif
                    <div class="overflow-y-auto scroll flex-1 py-1" x-data="{}">
                        @foreach ($this->tracks as $track)
                            @if ($tracksCompact)
                                <button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')"
                                        draggable="true"
                                        ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"
                                        class="row group w-full grid items-center px-4 py-[3px] rounded text-[13px] text-left hover:bg-white/[0.07] transition-colors"
                                        style="grid-template-columns: 20px 1.4fr 1fr 50px;">
                                    <span class="tabular-nums text-text-2 text-right pr-1"
                                          x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}'; } }">
                                        <template x-if="playing">
                                            <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                                        </template>
                                        <template x-if="!playing">
                                            <span>{{ $track->trackNumber }}</span>
                                        </template>
                                    </span>
                                    <div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
                                    <div class="text-text-2 group-hover:text-white truncate">{{ $track->artist }}</div>
                                    <div class="text-text-2 tabular-nums text-right text-[12px]">{{ \App\Support\Duration::format($track->durationMs) }}</div>
                                </button>
                            @else
                                <button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')"
                                        draggable="true"
                                        ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"
                                        class="row group w-full grid items-center px-4 py-2 rounded text-[14px] text-left hover:bg-white/[0.07] transition-colors"
                                        style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">
                                    <span class="text-text-3 group-hover:text-white grid place-items-center">
                                        <x-lucide-grip-vertical class="w-[14px] h-[14px]" />
                                    </span>
                                    <span class="tabular-nums text-text-2 text-right"
                                          x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}'; } }">
                                        <template x-if="playing">
                                            <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                                        </template>
                                        <template x-if="!playing">
                                            <span>{{ $track->trackNumber }}</span>
                                        </template>
                                    </span>
                                    <div class="min-w-0 flex items-center gap-3">
                                        @if ($this->selectedAlbum->thumb)
                                            <img src="{{ $this->thumbFor($this->selectedAlbum->thumb) }}" alt="{{ $this->selectedAlbum->title }}"
                                                 class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 36px; height: 36px;" loading="lazy">
                                        @else
                                            <div class="rounded-sm relative overflow-hidden flex-none bg-surface-2 grid place-items-center" style="width: 36px; height: 36px;">
                                                <x-lucide-disc class="w-3.5 h-3.5 text-text-3" />
                                            </div>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'album' && $store.player?.contextId === '{{ $this->selectedAlbumId }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
                                            <div class="truncate text-[12px] text-text-2 group-hover:text-white">{{ $track->artist }}</div>
                                        </div>
                                    </div>
                                    <div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>
                                    <x-heart-button :rating-key="$track->id" :rating="$track->userRating" size="sm" />
                                    <div class="text-text-2 tabular-nums text-right">{{ \App\Support\Duration::format($track->durationMs) }}</div>
                                </button>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
@endif
</div>

@script
<script>
    (() => {
        const saved = {};

        document.addEventListener('mousedown', () => {
            document.querySelectorAll('[data-region]').forEach(region => {
                saved[region.dataset.region] = region.scrollTop;
            });
        }, { capture: true });

        Livewire.hook('morphed', ({ el }) => {
            el.querySelectorAll('[data-region]').forEach(region => {
                if (saved[region.dataset.region] !== undefined) {
                    region.scrollTop = saved[region.dataset.region];
                }
            });
        });

        // After this page renders (initial load or wire:navigate from elsewhere with
        // ?artist=X&album=Y in the URL), scroll the miller columns so the selected
        // rows are visible. Skips if the user has already scrolled in this session.
        const scrollToSelection = () => {
            const root = document.querySelector('[data-selected-artist]');
            if (!root) return;
            const artistId = root.dataset.selectedArtist;
            const albumId = root.dataset.selectedAlbum;
            if (artistId && saved['artists-column'] === undefined) {
                document.querySelector(`[data-region="artists-column"] [wire\\:key="artist-${artistId}"]`)
                    ?.scrollIntoView({ block: 'center' });
            }
            if (albumId && saved['albums-column'] === undefined) {
                document.querySelector(`[data-region="albums-column"] [wire\\:key="album-${albumId}"]`)
                    ?.scrollIntoView({ block: 'center' });
            }
        };
        requestAnimationFrame(scrollToSelection);
    })();
</script>
@endscript
