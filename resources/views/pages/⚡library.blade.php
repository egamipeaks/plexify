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

        {{-- Album header (Task 13) --}}
        <div class="flex-none p-2"></div>

        {{-- Tracklist (Task 13) --}}
        <div class="flex-1 p-2 overflow-auto" data-region="tracklist"></div>
    </div>
@endif
