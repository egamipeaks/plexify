<?php

use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** all | playlists | albums | artists | tracks */
    public string $filter = 'all';

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    #[On('plextune-search')]
    public function applyTerm(string $term): void
    {
        $term = trim($term);

        if ($term === $this->q) {
            return;
        }

        $this->q = $term;
        $this->filter = 'all';
        unset($this->results);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function showSection(string $key): bool
    {
        return $this->filter === 'all' || $this->filter === $key;
    }

    public function playTrack(string $trackId): void
    {
        $tracks = ($this->results?->tracks ?? collect())->values();
        $i = $tracks->search(fn ($t) => $t->id === $trackId);

        if ($i === false) {
            return;
        }

        $this->dispatch('play-track',
            queue: $tracks->map(fn ($t) => $this->plex->queueItem($t))->values()->all(),
            index: $i,
            contextType: 'search',
            contextId: null,
        );
    }

    public function goToArtist(string $artistId): void
    {
        $this->redirectRoute('library', ['artist' => $artistId], navigate: true);
    }

    public function goToAlbum(?string $artistId, string $albumId): void
    {
        $this->redirectRoute('library', array_filter([
            'artist' => $artistId,
            'album' => $albumId,
        ]), navigate: true);
    }

    #[Computed]
    public function results(): ?SearchResults
    {
        if (trim($this->q) === '') {
            return null;
        }

        try {
            return $this->plex->searchAll($this->q);
        } catch (PlexException $e) {
            $this->dispatch('notify', type: 'error', message: 'Search failed. '.$e->getMessage());

            return null;
        }
    }

    protected function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }
};
?>

<div>
    @if (trim($q) !== '')
        <div class="absolute inset-0 z-30 bg-base overflow-y-auto" data-region="search-overlay">
            @php($this->results)
            @if ($this->results === null)
                <div class="pt-16 text-center px-6">
                    <div class="text-[20px] font-bold text-white mb-2">Couldn't search right now</div>
                    <div class="text-text-2 text-[14px]">Your Plex server may be unreachable.</div>
                </div>
            @elseif ($this->results->isEmpty())
                <div class="pt-16 text-center px-6">
                    <div class="text-[20px] font-bold text-white mb-2">No results found for &quot;{{ $q }}&quot;</div>
                    <div class="text-text-2 text-[14px]">Please make sure your words are spelled correctly, or use fewer or different keywords.</div>
                </div>
            @else
                <div class="px-6 pb-6">
                    {{-- Filter tab pills (sticky) --}}
                    <div class="sticky top-0 z-10 bg-base/95 backdrop-blur-sm pt-4 pb-3 -mx-6 px-6 flex items-center gap-2 flex-wrap">
                        @php($pills = ['all' => 'All', 'playlists' => 'Playlists', 'albums' => 'Albums', 'artists' => 'Artists', 'tracks' => 'Tracks'])
                        @foreach ($pills as $key => $label)
                            @php($count = match ($key) {
                                'all' => $this->results->total(),
                                'playlists' => $this->results->playlists->count(),
                                'albums' => $this->results->albums->count(),
                                'artists' => $this->results->artists->count(),
                                'tracks' => $this->results->tracks->count(),
                            })
                            <button type="button" wire:click="setFilter('{{ $key }}')"
                                    @class([
                                        'px-3.5 py-1.5 rounded-full text-[13px] font-semibold transition-colors whitespace-nowrap',
                                        'bg-white text-black' => $filter === $key,
                                        'bg-surface-2 hover:bg-surface-3 text-white' => $filter !== $key,
                                    ])>
                                {{ $label }}<span @class(['ml-1.5', 'text-black/50' => $filter === $key, 'text-text-2' => $filter !== $key])>{{ $count }}</span>
                            </button>
                        @endforeach
                    </div>

                    <div class="flex flex-col">
                        {{-- Playlists --}}
                        @if ($this->showSection('playlists') && $this->results->playlists->isNotEmpty())
                            <section>
                                @if ($filter === 'all')
                                    <div class="flex items-center gap-2 px-3 pt-5 pb-2">
                                        <h2 class="text-[15px] font-bold uppercase tracking-wider text-text-2">Playlists</h2>
                                        <span class="text-[12px] text-text-3">{{ $this->results->playlists->count() }}</span>
                                    </div>
                                @endif
                                <div class="flex flex-col">
                                    @foreach ($this->results->playlists as $playlist)
                                        <div wire:key="search-pl-{{ $playlist->id }}"
                                             class="group w-full grid items-center gap-3 px-3 py-2 rounded text-left"
                                             style="grid-template-columns: 44px 1fr auto;">
                                            @if ($playlist->thumb)
                                                <img src="{{ $this->thumbFor($playlist->thumb) }}" alt="{{ $playlist->title }}"
                                                     class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 44px; height: 44px;" loading="lazy">
                                            @else
                                                <div class="rounded-sm flex-none bg-surface-2 grid place-items-center" style="width: 44px; height: 44px;">
                                                    <x-lucide-list-music class="w-4 h-4 text-text-3" />
                                                </div>
                                            @endif
                                            <div class="min-w-0">
                                                <div class="truncate text-[14px] font-medium text-white">{{ $playlist->title }}</div>
                                                <div class="truncate text-[12px] text-text-2">Playlist · {{ $playlist->trackCount }} songs</div>
                                            </div>
                                            <span></span>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        {{-- Albums --}}
                        @if ($this->showSection('albums') && $this->results->albums->isNotEmpty())
                            <section>
                                @if ($filter === 'all')
                                    <div class="flex items-center gap-2 px-3 pt-5 pb-2">
                                        <h2 class="text-[15px] font-bold uppercase tracking-wider text-text-2">Albums</h2>
                                        <span class="text-[12px] text-text-3">{{ $this->results->albums->count() }}</span>
                                    </div>
                                @endif
                                <div class="flex flex-col">
                                    @foreach ($this->results->albums as $album)
                                        <button type="button" wire:key="search-al-{{ $album->id }}"
                                                wire:click="goToAlbum('{{ $album->artistId }}', '{{ $album->id }}')"
                                                class="group w-full grid items-center gap-3 px-3 py-2 rounded hover:bg-white/[0.06] transition-colors text-left"
                                                style="grid-template-columns: 44px 1fr auto;">
                                            @if ($album->thumb)
                                                <img src="{{ $this->thumbFor($album->thumb) }}" alt="{{ $album->title }}"
                                                     class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 44px; height: 44px;" loading="lazy">
                                            @else
                                                <div class="rounded-sm flex-none bg-surface-2 grid place-items-center" style="width: 44px; height: 44px;">
                                                    <x-lucide-disc class="w-4 h-4 text-text-3" />
                                                </div>
                                            @endif
                                            <div class="min-w-0">
                                                <div class="truncate text-[14px] font-medium text-white">{{ $album->title }}</div>
                                                <div class="truncate text-[12px] text-text-2">
                                                    {{ collect(['Album', $album->year, $album->artist])->filter()->implode(' · ') }}
                                                </div>
                                            </div>
                                            <span></span>
                                        </button>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        {{-- Artists --}}
                        @if ($this->showSection('artists') && $this->results->artists->isNotEmpty())
                            <section>
                                @if ($filter === 'all')
                                    <div class="flex items-center gap-2 px-3 pt-5 pb-2">
                                        <h2 class="text-[15px] font-bold uppercase tracking-wider text-text-2">Artists</h2>
                                        <span class="text-[12px] text-text-3">{{ $this->results->artists->count() }}</span>
                                    </div>
                                @endif
                                <div class="flex flex-col">
                                    @foreach ($this->results->artists as $artist)
                                        <button type="button" wire:key="search-ar-{{ $artist->id }}"
                                                wire:click="goToArtist('{{ $artist->id }}')"
                                                class="group w-full grid items-center gap-3 px-3 py-2 rounded hover:bg-white/[0.06] transition-colors text-left"
                                                style="grid-template-columns: 44px 1fr auto;">
                                            @if ($artist->thumb)
                                                <img src="{{ $this->thumbFor($artist->thumb) }}" alt="{{ $artist->name }}"
                                                     class="rounded-full flex-none bg-surface-2 object-cover" style="width: 44px; height: 44px;" loading="lazy">
                                            @else
                                                <div class="rounded-full flex-none bg-surface-2 grid place-items-center" style="width: 44px; height: 44px;">
                                                    <x-lucide-user class="w-4 h-4 text-text-3" />
                                                </div>
                                            @endif
                                            <div class="min-w-0">
                                                <div class="truncate text-[14px] font-medium text-white">{{ $artist->name }}</div>
                                                <div class="truncate text-[12px] text-text-2">
                                                    {{ collect(['Artist', $artist->albumCount ? $artist->albumCount . ' albums' : null])->filter()->implode(' · ') }}
                                                </div>
                                            </div>
                                            <span class="text-[12px] text-text-3 opacity-0 group-hover:opacity-100"><x-lucide-corner-down-left class="w-3.5 h-3.5" /></span>
                                        </button>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        {{-- Tracks --}}
                        @if ($this->showSection('tracks') && $this->results->tracks->isNotEmpty())
                            <section>
                                @if ($filter === 'all')
                                    <div class="flex items-center gap-2 px-3 pt-5 pb-2">
                                        <h2 class="text-[15px] font-bold uppercase tracking-wider text-text-2">Tracks</h2>
                                        <span class="text-[12px] text-text-3">{{ $this->results->tracks->count() }}</span>
                                    </div>
                                @endif
                                <div class="flex flex-col" x-data="{}">
                                    @foreach ($this->results->tracks as $track)
                                        <button type="button" wire:key="search-tr-{{ $track->id }}"
                                                wire:click="playTrack('{{ $track->id }}')"
                                                draggable="true"
                                                ondragstart="event.dataTransfer.effectAllowed='copy'; event.dataTransfer.setData('plextune/track', '{{ $track->id }}')"
                                                class="group w-full grid items-center gap-3 px-3 py-2 rounded hover:bg-white/[0.06] transition-colors text-left"
                                                style="grid-template-columns: 44px 1fr auto;">
                                            @if ($track->thumb)
                                                <img src="{{ $this->thumbFor($track->thumb) }}" alt="{{ $track->title }}"
                                                     class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 44px; height: 44px;" loading="lazy">
                                            @else
                                                <div class="rounded-sm flex-none bg-surface-2 grid place-items-center" style="width: 44px; height: 44px;">
                                                    <x-lucide-disc class="w-4 h-4 text-text-3" />
                                                </div>
                                            @endif
                                            <div class="min-w-0" x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'search'; } }">
                                                <div class="truncate text-[14px] font-medium flex items-center gap-2" :class="playing ? 'text-accent' : 'text-white'">
                                                    <span class="truncate">{{ $track->title }}</span>
                                                    <template x-if="playing">
                                                        <span class="eq flex-none" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                                                    </template>
                                                </div>
                                                <div class="truncate text-[12px] text-text-2">{{ collect([$track->artist, $track->album])->filter()->implode(' · ') }}</div>
                                            </div>
                                            <span class="text-[12px] tabular-nums text-text-3">{{ \App\Support\Duration::format($track->durationMs) }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </section>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
