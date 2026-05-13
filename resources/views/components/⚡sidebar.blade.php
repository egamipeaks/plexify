<?php

use App\Models\Folder;
use App\Models\FolderPlaylist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $renamingFolderId = null;

    public ?string $renamingPlaylistId = null;

    public string $filter = '';

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

    /** @return Collection<int, FolderPlaylist> */
    #[Computed]
    public function rootPlacements(): Collection
    {
        return FolderPlaylist::whereNull('folder_id')->orderBy('position')->orderBy('id')->get();
    }

    /**
     * The ordered plex playlist ids for the root container: placed playlists by saved
     * position, then unplaced playlists in Plex order. $allPlexIds is the full ordered
     * list of plex ids from PlexClient::playlists(); $filedIds are ids that live in a folder.
     *
     * @param  list<string>  $allPlexIds
     * @param  list<string>  $filedIds
     * @return list<string>
     */
    protected function rootOrderedPlexIds(array $allPlexIds, array $filedIds): array
    {
        $rootIds = array_values(array_diff($allPlexIds, $filedIds));

        $placedOrder = $this->rootPlacements
            ->pluck('plex_playlist_id')
            ->filter(fn ($id) => in_array($id, $rootIds, true))
            ->values()
            ->all();

        $unplaced = array_values(array_diff($rootIds, $placedOrder));

        return [...$placedOrder, ...$unplaced];
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

        unset($this->folders);
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
        unset($this->folders);
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

        unset($this->folders);
    }

    public function addTrackToPlaylist(string $playlistId, string $trackId): bool
    {
        try {
            $this->plex->addTrackToPlaylist($playlistId, $trackId);
        } catch (PlexException $e) {
            Log::channel('plex')->warning('addTrackToPlaylist failed', ['playlist' => $playlistId, 'track' => $trackId, 'error' => $e->getMessage()]);
            $this->dispatch('notify', type: 'error', message: 'Couldn\'t add that song to the playlist. Plex may be unreachable.');

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
            $this->dispatch('notify', type: 'error', message: 'Couldn\'t add that album to the playlist. Plex may be unreachable.');

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
            $this->dispatch('notify', type: 'error', message: 'Couldn\'t create the playlist. Plex may be unreachable.');

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
                $this->dispatch('notify', type: 'error', message: 'Couldn\'t rename the playlist. Plex may be unreachable.');
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
            $this->dispatch('notify', type: 'error', message: 'Couldn\'t delete the playlist. Plex may be unreachable.');

            return;
        }

        FolderPlaylist::where('plex_playlist_id', $playlistId)->delete();
        unset($this->playlists, $this->folders);
    }

    public function playPlaylist(string $playlistId): void
    {
        try {
            $tracks = $this->plex->playlistTracks($playlistId)->values();
        } catch (PlexException $e) {
            Log::channel('plex')->warning('playPlaylist failed', ['playlist' => $playlistId, 'error' => $e->getMessage()]);
            $this->dispatch('notify', type: 'error', message: 'Couldn\'t start that playlist. Plex may be unreachable.');

            return;
        }

        if ($tracks->isEmpty()) {
            return;
        }

        $this->dispatch('play-track',
            queue: $tracks->map(fn ($t) => $this->plex->queueItem($t))->values()->all(),
            index: 0,
            contextType: 'playlist',
            contextId: $playlistId,
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
            <x-lucide-library class="w-5 h-5" />
            <span class="flex-1 text-left">Your Library</span>
        </a>

        <a href="{{ route('recentlyAdded') }}" wire:navigate
           @class([
               'w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors',
               'text-white bg-surface-2' => request()->routeIs('recentlyAdded'),
               'text-text-2 hover:text-white' => ! request()->routeIs('recentlyAdded'),
           ])>
            <x-lucide-clock class="w-5 h-5" />
            <span class="flex-1 text-left">Recently Added</span>
        </a>

        <a href="{{ route('recentlyPlayed') }}" wire:navigate
           @class([
               'w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors',
               'text-white bg-surface-2' => request()->routeIs('recentlyPlayed'),
               'text-text-2 hover:text-white' => ! request()->routeIs('recentlyPlayed'),
           ])>
            <x-lucide-history class="w-5 h-5" />
            <span class="flex-1 text-left">Recently Played</span>
        </a>
    </div>

    {{-- Playlists card --}}
    <div class="bg-surface rounded-lg flex-1 min-h-0 flex flex-col"
         x-data="{
            draggingTrack: false,
            menu: null,
            dropTarget: null,
            flash: {},
            init() {
                this._ds = (e) => { try { if ([...(e.dataTransfer?.types ?? [])].includes('plextune/track')) this.draggingTrack = true; } catch (_) {} };
                this._de = () => { this.draggingTrack = false; this.dropTarget = null; };
                window.addEventListener('dragstart', this._ds);
                window.addEventListener('dragend', this._de);
            },
            destroy() {
                window.removeEventListener('dragstart', this._ds);
                window.removeEventListener('dragend', this._de);
            },
            openMenu(e, kind, id) { e.preventDefault(); e.stopPropagation(); this.menu = { x: e.clientX, y: e.clientY, kind, id }; },
            flashRow(key, ok) { this.flash[key] = ok ? 'ok' : 'err'; setTimeout(() => { this.flash[key] = null; }, 700); },
            async dropTrackOn(key, playlistId, e) {
                e.preventDefault(); this.dropTarget = null;
                const trackId = e.dataTransfer.getData('plextune/track');
                const albumId = e.dataTransfer.getData('plextune/album');
                if (!trackId && !albumId) return;
                try {
                    const ok = trackId
                        ? await $wire.addTrackToPlaylist(playlistId, trackId)
                        : await $wire.addAlbumToPlaylist(playlistId, albumId);
                    this.flashRow(key, ok);
                } catch (_) { this.flashRow(key, false); }
            },
            async dropTrackOnNew(e) {
                e.preventDefault(); this.dropTarget = null;
                const trackId = e.dataTransfer.getData('plextune/track');
                if (!trackId) return;
                try { const ok = await $wire.createPlaylistFromTrack(trackId); this.flashRow('__new', ok); }
                catch (_) { this.flashRow('__new', false); }
            },
            dropPlaylistOn(folderId, e) {
                e.preventDefault(); this.dropTarget = null;
                const playlistId = e.dataTransfer.getData('plextune/playlist');
                if (playlistId) $wire.movePlaylistToFolder(playlistId, folderId);
            },
         }"
         @keydown.escape.window="menu = null">

        {{-- Header --}}
        <div class="flex items-center justify-between px-4 pt-3 pb-2">
            <div class="flex items-center gap-2 text-text-2 hover:text-white cursor-pointer">
                <x-lucide-library class="w-[18px] h-[18px]" />
                <span class="text-[14px] font-bold">Your Playlists</span>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" title="New folder" wire:click="createFolder"
                        class="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-surface-3">
                    <x-lucide-folder class="w-[14px] h-[14px]" />
                </button>
                <button type="button" title="Drag a song here to create a playlist"
                        class="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-surface-3">
                    <x-lucide-plus class="w-4 h-4" />
                </button>
            </div>
        </div>

        {{-- Filter bar --}}
        <div class="px-3 pb-2 flex items-center justify-between gap-2">
            <div class="flex items-center gap-1.5 flex-1 min-w-0 bg-surface-2 rounded-md h-7 px-2 text-text-2">
                <x-lucide-search class="w-[14px] h-[14px] flex-none" />
                <input type="text" wire:model.live.debounce.200ms="filter" placeholder="Filter playlists"
                       class="bg-transparent outline-none text-[12px] flex-1 min-w-0 text-white placeholder:text-text-3">
            </div>
            <button type="button"
                    class="flex items-center gap-1 text-[12px] text-text-2 hover:text-white font-semibold flex-none">
                Recents
                <x-lucide-list-music class="w-3 h-3" />
            </button>
        </div>

        {{-- List --}}
        <div class="flex-1 overflow-y-auto scroll px-2 pb-2 flex flex-col gap-0.5">
            @php
                $allPlaylists = $this->playlists;
                $byId = $allPlaylists->keyBy('id');
                $filter = trim($this->filter);
                $matches = fn ($title) => $filter === '' || str_contains(mb_strtolower((string) $title), mb_strtolower($filter));
                $filed = $this->folders->flatMap(fn ($f) => $f->folderPlaylists->pluck('plex_playlist_id'))->all();
                $rootPlaylists = collect($this->rootOrderedPlexIds($allPlaylists->pluck('id')->all(), $filed))
                    ->map(fn ($id) => $byId->get($id))->filter()->values();
                $visibleRoot = $rootPlaylists->filter(fn ($p) => $matches($p->title))->values();
            @endphp

            {{-- Folders --}}
            @foreach ($this->folders as $folder)
                @php
                    $items = $folder->folderPlaylists
                        ->map(fn ($fp) => $byId->get($fp->plex_playlist_id))
                        ->filter()->values();
                    $visibleItems = $items->filter(fn ($p) => $matches($p->title))->values();
                    $bodyOpen = $folder->expanded || $filter !== '';
                @endphp
                @if ($filter === '' || $visibleItems->isNotEmpty())
                    <div wire:key="folder-{{ $folder->id }}" class="flex flex-col">
                        <div wire:click="toggleFolder({{ $folder->id }})"
                             @contextmenu="openMenu($event, 'folder', {{ $folder->id }})"
                             @dragover.prevent="dropTarget = 'folder-{{ $folder->id }}'"
                             @dragleave="dropTarget = null"
                             @drop="dropPlaylistOn({{ $folder->id }}, $event)"
                             :class="dropTarget === 'folder-{{ $folder->id }}' ? 'bg-accent/15 ring-1 ring-accent/40' : 'hover:bg-surface-2'"
                             class="group w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-left transition-colors cursor-pointer">
                            <x-lucide-chevron-right @class(['w-3 h-3 flex-none text-text-2 transition-transform', 'rotate-90' => $bodyOpen]) />
                            <x-lucide-folder class="w-4 h-4 text-text-2 flex-none" />
                            @if ($renamingFolderId === $folder->id)
                                <input type="text" value="{{ $folder->name }}"
                                       x-init="$el.focus(); $el.select()"
                                       @click.stop
                                       @keydown.enter="$el.blur()"
                                       @keydown.escape="$wire.set('renamingFolderId', null)"
                                       @blur="$wire.renameFolder({{ $folder->id }}, $event.target.value)"
                                       class="flex-1 min-w-0 bg-white/10 ring-1 ring-white/30 rounded px-1.5 py-0.5 text-[13px] font-bold text-white outline-none">
                            @else
                                <span @click.stop
                                      @dblclick="$wire.set('renamingFolderId', {{ $folder->id }})"
                                      class="text-[13px] font-bold text-white flex-1 truncate">{{ $folder->name }}</span>
                            @endif
                            <span class="text-[11px] text-text-3 tabular-nums flex-none">{{ $filter === '' ? $items->count() : $visibleItems->count() }}</span>
                        </div>
                        @if ($bodyOpen)
                            <div class="ml-3 pl-2 border-l border-white/10 flex flex-col gap-0.5 py-0.5">
                                @forelse ($filter === '' ? $items : $visibleItems as $p)
                                    @include('partials.playlist-row', ['p' => $p, 'renaming' => $renamingPlaylistId === $p->id, 'thumbUrl' => $this->thumbFor($p->thumb)])
                                @empty
                                    <div class="px-2 py-2 text-[11px] text-text-3 italic">Empty. Drop a playlist here.</div>
                                @endforelse
                            </div>
                        @endif
                    </div>
                @endif
            @endforeach

            {{-- "Other" header (only when folders exist and there are unfiled playlists visible) --}}
            @if ($this->folders->isNotEmpty() && $visibleRoot->isNotEmpty())
                <div @dragover.prevent="dropTarget = '__root'"
                     @dragleave="dropTarget = null"
                     @drop="dropPlaylistOn(null, $event)"
                     :class="dropTarget === '__root' ? 'bg-accent/10 ring-1 ring-accent/30 rounded' : ''"
                     class="px-2 pt-2 pb-1 text-[10px] uppercase tracking-wider text-text-3 font-bold">Other</div>
            @endif

            {{-- Root playlists --}}
            @forelse ($visibleRoot as $p)
                @include('partials.playlist-row', ['p' => $p, 'renaming' => $renamingPlaylistId === $p->id, 'thumbUrl' => $this->thumbFor($p->thumb)])
            @empty
                @if ($this->folders->isEmpty() && $allPlaylists->isEmpty())
                    <div class="px-3 py-6 text-[12px] text-text-3 text-center">No playlists yet</div>
                @elseif ($this->folders->isEmpty() && $filter !== '')
                    <div class="px-3 py-6 text-[12px] text-text-3 text-center">No matches.</div>
                @endif
            @endforelse

            {{-- "New playlist" drop zone — only while dragging a track --}}
            <div x-show="draggingTrack" x-cloak data-region="new-playlist-zone"
                 @dragover.prevent="dropTarget = '__new'"
                 @dragleave="dropTarget = null"
                 @drop="dropTrackOnNew($event)"
                 :class="{
                     'bg-accent/15 ring-1 ring-accent/40': dropTarget === '__new',
                     'bg-emerald-400/10 ring-1 ring-emerald-400': flash['__new'] === 'ok',
                     'bg-red-400/10 ring-1 ring-red-400': flash['__new'] === 'err',
                 }"
                 class="mt-1 flex items-center gap-2 px-2 py-2 rounded-md border border-dashed border-white/20 text-text-2 text-[12px] font-semibold">
                <x-lucide-plus class="w-3.5 h-3.5 flex-none" />
                New playlist
            </div>
        </div>

        {{-- Folder context menu --}}
        <div x-show="menu && menu.kind === 'folder'" x-cloak
             class="fixed z-[1000] min-w-[200px] py-1 rounded-md bg-surface-3 ring-1 ring-white/10 shadow-2xl text-[13px]"
             :style="menu ? { left: menu.x + 'px', top: menu.y + 'px' } : {}"
             @click.outside="menu = null">
            <button type="button" class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10"
                    @click="$wire.set('renamingFolderId', menu.id); menu = null">Rename folder</button>
            <button type="button" class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10"
                    @click="$wire.toggleFolder(menu.id); menu = null">Expand / Collapse</button>
            <div class="my-1 border-t border-white/10"></div>
            <button type="button" class="w-full text-left px-3 py-1.5 text-red-400 hover:bg-red-500/10"
                    @click="$wire.deleteFolder(menu.id); menu = null">Delete folder</button>
        </div>

        {{-- Playlist context menu --}}
        <div x-show="menu && menu.kind === 'playlist'" x-cloak
             class="fixed z-[1000] min-w-[210px] py-1 rounded-md bg-surface-3 ring-1 ring-white/10 shadow-2xl text-[13px]"
             :style="menu ? { left: menu.x + 'px', top: menu.y + 'px' } : {}"
             @click.outside="menu = null">
                <button type="button" class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10"
                        @click="$wire.playPlaylist(menu.id); menu = null">Play</button>
                <div class="my-1 border-t border-white/10"></div>
                @foreach ($this->folders as $folder)
                    <button type="button"
                            class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10 flex items-center gap-2"
                            @click="$wire.movePlaylistToFolder(menu.id, {{ $folder->id }}); menu = null">
                        <x-lucide-folder class="w-3 h-3 text-text-2 flex-none" />
                        <span class="flex-1 truncate">Move to {{ $folder->name }}</span>
                    </button>
                @endforeach
                <button type="button" class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10"
                        @click="$wire.movePlaylistToFolder(menu.id, null); menu = null">Remove from folder</button>
                <button type="button" class="w-full text-left px-3 py-1.5 text-white hover:bg-white/10"
                        @click="$wire.set('renamingPlaylistId', menu.id); menu = null">Rename playlist</button>
                <div class="my-1 border-t border-white/10"></div>
                <button type="button" class="w-full text-left px-3 py-1.5 text-red-400 hover:bg-red-500/10"
                        @click="$wire.deletePlaylist(menu.id); menu = null">Delete playlist</button>
        </div>
    </div>

    {{-- Server chip card --}}
    <livewire:server-chip />
</aside>
