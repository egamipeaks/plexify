<?php

use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    /** @return Collection<int, Playlist> */
    #[Computed]
    public function playlists(): Collection
    {
        try {
            return $this->plex->playlists();
        } catch (PlexException) {
            return collect();
        }
    }

    public function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }
};
?>

<aside class="flex flex-col gap-2 h-full min-h-0">
    {{-- Top nav card --}}
    <div class="bg-surface rounded-lg p-2 flex flex-col gap-1">
        <a href="{{ route('library') }}" wire:navigate
           @class([
               'w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors',
               'text-white bg-surface-2' => request()->routeIs('library'),
               'text-text-2 hover:text-white' => ! request()->routeIs('library'),
           ])>
            <x-lucide-house class="w-5 h-5" />
            <span class="flex-1 text-left">Home</span>
        </a>

        <a href="{{ route('search') }}" wire:navigate
           @class([
               'w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors',
               'text-white bg-surface-2' => request()->routeIs('search'),
               'text-text-2 hover:text-white' => ! request()->routeIs('search'),
           ])>
            <x-lucide-search class="w-5 h-5" />
            <span class="flex-1 text-left">Search</span>
        </a>

        <a href="{{ route('library') }}" wire:navigate
           class="w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors text-text-2 hover:text-white">
            <x-lucide-library class="w-5 h-5" />
            <span class="flex-1 text-left">Your Library</span>
        </a>

        <a href="{{ route('library') }}" wire:navigate
           class="w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors text-text-2 hover:text-white">
            <x-lucide-clock class="w-5 h-5" />
            <span class="flex-1 text-left">Recently Added</span>
        </a>
    </div>

    {{-- Playlists card --}}
    <div class="bg-surface rounded-lg flex-1 min-h-0 flex flex-col">
        <div class="flex items-center justify-between px-4 pt-3 pb-2">
            <div class="flex items-center gap-2 text-text-2 hover:text-white cursor-pointer">
                <x-lucide-library class="w-[18px] h-[18px]" />
                <span class="text-[14px] font-bold">Your Playlists</span>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" title="New folder"
                        class="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-surface-3">
                    <x-lucide-folder class="w-[14px] h-[14px]" />
                </button>
                <button type="button" title="Create playlist"
                        class="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-surface-3">
                    <x-lucide-plus class="w-4 h-4" />
                </button>
            </div>
        </div>

        <div class="px-3 pb-2 flex items-center justify-between">
            <div class="flex items-center gap-1 text-text-2 hover:text-white cursor-pointer">
                <x-lucide-search class="w-[14px] h-[14px]" />
            </div>
            <button type="button"
                    class="flex items-center gap-1 text-[12px] text-text-2 hover:text-white font-semibold">
                Recents
                <x-lucide-list-music class="w-3 h-3" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto scroll px-2 pb-2 flex flex-col gap-0.5">
            @forelse ($this->playlists as $playlist)
                <a href="{{ route('playlist', $playlist->id) }}" wire:navigate wire:key="sidebar-pl-{{ $playlist->id }}"
                   @class([
                       'w-full flex items-center gap-3 px-2 py-1.5 rounded-md transition-colors',
                       'text-white bg-surface-2' => request()->routeIs('playlist') && (string) request()->route('playlist') === $playlist->id,
                       'text-text-2 hover:text-white hover:bg-surface-2' => ! (request()->routeIs('playlist') && (string) request()->route('playlist') === $playlist->id),
                   ])>
                    @if ($playlist->thumb)
                        <img src="{{ $this->thumbFor($playlist->thumb) }}" alt="{{ $playlist->title }}"
                             class="rounded-sm flex-none bg-surface-2 object-cover" style="width: 32px; height: 32px;" loading="lazy">
                    @else
                        <div class="rounded-sm flex-none bg-surface-2 grid place-items-center" style="width: 32px; height: 32px;">
                            <x-lucide-list-music class="w-3.5 h-3.5 text-text-3" />
                        </div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="text-[14px] truncate">{{ $playlist->title }}</div>
                        <div class="text-[12px] text-text-3 truncate">Playlist · {{ $playlist->trackCount }} songs</div>
                    </div>
                </a>
            @empty
                <div class="px-3 py-6 text-[12px] text-text-3 text-center">No playlists yet</div>
            @endforelse
        </div>
    </div>

    {{-- Server chip card --}}
    <livewire:server-chip />
</aside>
