<?php

use App\Models\Folder;
use App\Models\FolderPlaylist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $renamingFolderId = null;

    public ?string $renamingPlaylistId = null;

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

    /** @return Collection<int, Folder> */
    #[Computed]
    public function folders(): Collection
    {
        return Folder::with('folderPlaylists')->orderBy('position')->orderBy('id')->get();
    }

    /** @return array<string, int> Plex playlist id => folder id */
    #[Computed]
    public function folderOf(): array
    {
        $map = [];

        foreach ($this->folders as $folder) {
            foreach ($folder->folderPlaylists as $fp) {
                $map[$fp->plex_playlist_id] = $folder->id;
            }
        }

        return $map;
    }

    public function thumbFor(?string $thumb): ?string
    {
        return $this->plex->thumbUrl($thumb);
    }

    public function createFolder(): void
    {
        $folder = Folder::create([
            'name' => 'New Folder',
            'position' => (int) Folder::max('position') + 1,
            'expanded' => true,
        ]);

        unset($this->folders, $this->folderOf);
        $this->renamingFolderId = $folder->id;
    }

    public function renameFolder(int $id, string $name): void
    {
        $name = trim($name);

        if ($name !== '') {
            Folder::whereKey($id)->update(['name' => $name]);
            unset($this->folders);
        }

        $this->renamingFolderId = null;
    }

    public function deleteFolder(int $id): void
    {
        Folder::whereKey($id)->delete();
        unset($this->folders, $this->folderOf);
    }

    public function toggleFolder(int $id): void
    {
        $folder = Folder::find($id);

        if (! $folder) {
            return;
        }

        $folder->update(['expanded' => ! $folder->expanded]);
        unset($this->folders);
    }

    public function movePlaylistToFolder(string $playlistId, ?int $folderId): void
    {
        FolderPlaylist::where('plex_playlist_id', $playlistId)->delete();

        if ($folderId !== null && Folder::whereKey($folderId)->exists()) {
            FolderPlaylist::create([
                'folder_id' => $folderId,
                'plex_playlist_id' => $playlistId,
                'position' => (int) FolderPlaylist::where('folder_id', $folderId)->max('position') + 1,
            ]);
        }

        unset($this->folders, $this->folderOf);
    }

    public function addTrackToPlaylist(string $playlistId, string $trackId): bool
    {
        try {
            $this->plex->addTrackToPlaylist($playlistId, $trackId);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('addTrackToPlaylist failed', ['playlist' => $playlistId, 'track' => $trackId, 'error' => $e->getMessage()]);

            return false;
        }

        unset($this->playlists);

        return true;
    }

    public function addAlbumToPlaylist(string $playlistId, string $albumId): bool
    {
        try {
            $this->plex->addAlbumToPlaylist($playlistId, $albumId);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('addAlbumToPlaylist failed', ['playlist' => $playlistId, 'album' => $albumId, 'error' => $e->getMessage()]);

            return false;
        }

        unset($this->playlists);

        return true;
    }

    public function createPlaylistFromTrack(string $trackId): bool
    {
        try {
            $newId = $this->plex->createPlaylist('New Playlist', $trackId);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('createPlaylistFromTrack failed', ['track' => $trackId, 'error' => $e->getMessage()]);

            return false;
        }

        unset($this->playlists);
        $this->renamingPlaylistId = $newId;

        return true;
    }

    public function renamePlaylist(string $playlistId, string $name): void
    {
        $name = trim($name);

        if ($name !== '') {
            try {
                $this->plex->renamePlaylist($playlistId, $name);
                unset($this->playlists);
            } catch (PlexException $e) {
                Log::channel('plex')->warning('renamePlaylist failed', ['playlist' => $playlistId, 'error' => $e->getMessage()]);
            }
        }

        $this->renamingPlaylistId = null;
    }

    public function deletePlaylist(string $playlistId): void
    {
        try {
            $this->plex->deletePlaylist($playlistId);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('deletePlaylist failed', ['playlist' => $playlistId, 'error' => $e->getMessage()]);

            return;
        }

        FolderPlaylist::where('plex_playlist_id', $playlistId)->delete();
        unset($this->playlists, $this->folders, $this->folderOf);
    }

    public function playPlaylist(string $playlistId): void
    {
        try {
            $tracks = $this->plex->playlistTracks($playlistId);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('playPlaylist failed', ['playlist' => $playlistId, 'error' => $e->getMessage()]);

            return;
        }

        $track = $tracks->first();

        if (! $track instanceof Track) {
            return;
        }

        $this->dispatch('play-track',
            url: $this->plex->streamUrl($track),
            title: $track->title,
            artist: $track->artist,
            artwork: $this->thumbFor($track->thumb),
        );
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
