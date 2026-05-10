<?php

use App\Services\Plex\Dto\SearchResults;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $q = '';

    /** all | playlists | albums | artists | tracks */
    public string $filter = 'all';

    public ?string $errorMessage = null;

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    public function mount(?string $q = null): void
    {
        $this->q = (string) $q;
    }

    public function updatedQ(): void
    {
        $this->filter = 'all';
        $this->errorMessage = null;
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
        $track = $this->results?->tracks->firstWhere('id', $trackId);

        if (! $track) {
            return;
        }

        $this->dispatch('play-track',
            url: $this->plex->streamUrl($track),
            title: $track->title,
            artist: $track->artist,
            artwork: $this->thumbFor($track->thumb),
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

    public function retry(): void
    {
        $this->errorMessage = null;
        unset($this->results);
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
            $this->errorMessage = "Can't reach your Plex server. " . $e->getMessage();

            return null;
        }
    }

    public function thumbFor(?string $thumb): ?string
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
@php($this->results)
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
@elseif (trim($q) === '')
    <div class="flex-1 grid place-items-center text-center px-6">
        <div>
            <div class="text-[20px] font-bold text-white mb-1">Search your library</div>
            <div class="text-text-2 text-[14px]">Find any song, artist, or album from your Plex server.</div>
        </div>
    </div>
@else
    <div class="flex-1 overflow-y-auto scroll px-6 pb-6">
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

        @if ($this->results->isEmpty())
            <div class="pt-16 text-center">
                <div class="text-[20px] font-bold text-white mb-2">No results found for &quot;{{ $q }}&quot;</div>
                <div class="text-text-2 text-[14px]">Please make sure your words are spelled correctly, or use fewer or different keywords.</div>
            </div>
        @else
            <div class="flex flex-col">
                {{-- Playlists (display-only in M3) --}}
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
                                    <span class="text-[12px] text-text-3 opacity-0 group-hover:opacity-100">&#8617;</span>
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
                        <div class="flex flex-col">
                            @foreach ($this->results->tracks as $track)
                                <button type="button" wire:key="search-tr-{{ $track->id }}"
                                        wire:click="playTrack('{{ $track->id }}')"
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
                                    <div class="min-w-0">
                                        <div class="truncate text-[14px] font-medium text-white">{{ $track->title }}</div>
                                        <div class="truncate text-[12px] text-text-2">{{ collect([$track->artist, $track->album])->filter()->implode(' · ') }}</div>
                                    </div>
                                    <span class="text-[12px] tabular-nums text-text-3">{{ $this->formatMs($track->durationMs) }}</span>
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>
        @endif
    </div>
@endif
</div>
