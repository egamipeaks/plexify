<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Dto\Artist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?string $selectedArtistId = null;
    public ?string $selectedAlbumId = null;
    public ?string $errorMessage = null;

    public function mount(PlexClient $plex): void
    {
        $this->loadArtists($plex);
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

    public function playTrack(string $trackId): void
    {
        $track = $this->tracks->firstWhere('id', $trackId);

        if (! $track) {
            return;
        }

        $this->dispatch('play-track',
            url: app(PlexClient::class)->streamUrl($track),
            title: $track->title,
            artist: $track->artist,
        );
    }

    public function formatMs(int $ms): string
    {
        $seconds = (int) round($ms / 1000);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return sprintf('%d:%02d', $m, $s);
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

@if ($errorMessage)
    <div class="h-full grid place-items-center p-12 text-center">
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
    <div class="h-full flex flex-col">
        {{-- Miller columns: Artists + Albums --}}
        <div class="grid grid-cols-2 gap-2 px-2 pt-2 flex-none" style="height: 220px;">
            {{-- Artists column --}}
            <div class="flex flex-col min-h-0 bg-surface rounded-lg overflow-hidden">
                <div class="px-4 pt-3 pb-2 flex items-center justify-between gap-2 flex-none">
                    <div class="text-[13px] font-bold uppercase tracking-wider text-text-2 whitespace-nowrap">Artists</div>
                    <div class="text-[11px] text-text-3">{{ $this->artists->count() }}</div>
                </div>
                <div class="overflow-y-auto flex-1" data-region="artists-column">
                    @foreach ($this->artists as $artist)
                        <button wire:click="selectArtist('{{ $artist->id }}')"
                                @class([
                                    'w-full flex items-center gap-3 px-3 py-2 text-left transition-colors',
                                    'bg-surface-3 text-white' => $selectedArtistId === $artist->id,
                                    'text-text-2 hover:text-white hover:bg-surface-2' => $selectedArtistId !== $artist->id,
                                ])>
                            <div class="w-9 h-9 rounded-full bg-surface-2 flex-none grid place-items-center">
                                <x-lucide-user class="w-4 h-4 text-text-3" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-[14px] font-semibold truncate">{{ $artist->name }}</div>
                                <div class="text-[11px] text-text-2 truncate">{{ $artist->albumCount }} albums</div>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Albums column --}}
            <div class="flex flex-col min-h-0 bg-surface rounded-lg overflow-hidden">
                <div class="px-4 pt-3 pb-2 flex items-center justify-between gap-2 flex-none">
                    <div class="text-[13px] font-bold uppercase tracking-wider text-text-2 whitespace-nowrap">Albums</div>
                    @if ($selectedArtistId)
                        <div class="text-[11px] text-text-3">{{ $this->albums->count() }}</div>
                    @endif
                </div>
                <div class="overflow-y-auto flex-1" data-region="albums-column">
                    @if (! $selectedArtistId)
                        <div class="grid place-items-center h-full text-text-3 text-[12px]">Select an artist</div>
                    @elseif ($this->albums->isEmpty())
                        <div class="grid place-items-center h-full text-text-3 text-[12px]">No albums</div>
                    @else
                        @foreach ($this->albums as $album)
                            <button wire:click="selectAlbum('{{ $album->id }}')"
                                    @class([
                                        'w-full flex items-center gap-3 px-3 py-2 text-left transition-colors',
                                        'bg-surface-3 text-white' => $selectedAlbumId === $album->id,
                                        'text-text-2 hover:text-white hover:bg-surface-2' => $selectedAlbumId !== $album->id,
                                    ])>
                                <div class="w-9 h-9 rounded bg-surface-2 flex-none grid place-items-center">
                                    <x-lucide-disc class="w-4 h-4 text-text-3" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-[14px] font-semibold truncate">{{ $album->title }}</div>
                                    <div class="text-[11px] text-text-2 truncate">
                                        {{ $album->year ?: '' }}{{ $album->year ? ' · ' : '' }}{{ $album->trackCount }} tracks
                                    </div>
                                </div>
                            </button>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>

        {{-- Album header --}}
        @if ($this->selectedAlbum)
            <div class="flex-none p-2">
                <div class="bg-gradient-to-b from-surface-3 to-surface rounded-lg p-6 flex items-end gap-6">
                    <div class="w-[120px] h-[120px] rounded shadow-2xl bg-surface-2 grid place-items-center flex-none">
                        <x-lucide-disc class="w-10 h-10 text-text-3" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-text-2">Album</div>
                        <h1 class="font-black truncate" style="font-size: clamp(22px, 3.4vw, 40px);">{{ $this->selectedAlbum->title }}</h1>
                        <div class="text-[13px] text-text-2 mt-2">
                            {{ $this->selectedAlbum->artist }}
                            @if ($this->selectedAlbum->year)
                                · {{ $this->selectedAlbum->year }}
                            @endif
                            · {{ $this->selectedAlbum->trackCount }} songs · {{ $this->formatMs($this->selectedAlbum->durationMs) }}
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Tracklist --}}
        @if ($selectedAlbumId)
            <div class="flex-1 p-2 overflow-auto" data-region="tracklist">
                <div class="bg-surface rounded-lg">
                    <div class="grid gap-2 px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-text-2 border-b border-surface-2 sticky top-0 bg-surface"
                         style="grid-template-columns: 36px 1fr 60px;">
                        <div class="text-right">#</div>
                        <div>Title</div>
                        <div class="text-right">Time</div>
                    </div>
                    @foreach ($this->tracks as $track)
                        <button wire:click="playTrack('{{ $track->id }}')"
                                class="w-full grid gap-2 px-4 py-2 hover:bg-surface-2 transition-colors text-left text-[13px] tabular-nums"
                                style="grid-template-columns: 36px 1fr 60px;">
                            <div class="text-text-2 text-right">{{ $track->trackNumber }}</div>
                            <div class="min-w-0">
                                <div class="font-semibold truncate text-white">{{ $track->title }}</div>
                                <div class="text-[11px] text-text-2 truncate">{{ $track->artist }}</div>
                            </div>
                            <div class="text-text-2 text-right">{{ $this->formatMs($track->durationMs) }}</div>
                        </button>
                    @endforeach
                </div>
            </div>
        @else
            <div class="flex-1 p-2 overflow-auto" data-region="tracklist"></div>
        @endif
    </div>
@endif
