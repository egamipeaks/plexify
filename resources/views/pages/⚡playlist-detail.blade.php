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

    public bool $headerCollapsed = false;

    public bool $tracksCompact = false;

    protected PlexClient $plex;

    public function boot(PlexClient $plex): void
    {
        $this->plex = $plex;
    }

    public function mount(string $playlist): void
    {
        $this->playlist = $playlist;
        $this->headerCollapsed = \App\Support\AppSetting::albumHeaderCollapsed();
        $this->tracksCompact = \App\Support\AppSetting::playlistTracksCompact();
    }

    public function updatedHeaderCollapsed(bool $value): void
    {
        \App\Support\AppSetting::setAlbumHeaderCollapsed($value);
    }

    public function updatedTracksCompact(bool $value): void
    {
        \App\Support\AppSetting::setPlaylistTracksCompact($value);
    }

    public function playTrack(string $trackId): void
    {
        $tracks = $this->tracks->values();
        $i = $tracks->search(fn ($t) => $t->id === $trackId);

        if ($i === false) {
            return;
        }

        $this->dispatch('play-track', queue: $this->queuePayload(), index: $i, contextType: 'playlist', contextId: $this->playlist);
    }

    public function playAll(): void
    {
        if ($this->tracks->isEmpty()) {
            return;
        }

        $this->dispatch('play-track', queue: $this->queuePayload(), index: 0, contextType: 'playlist', contextId: $this->playlist);
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
            contextType: 'playlist',
            contextId: $this->playlist,
        );
    }

    public function moveTrack(string $draggedItemId, string $targetItemId, string $position): bool
    {
        if ($draggedItemId === $targetItemId) {
            return true;
        }

        $tracks = $this->tracks->values();
        $draggedIndex = $tracks->search(fn ($t) => $t->playlistItemId === $draggedItemId);
        $targetIndex = $tracks->search(fn ($t) => $t->playlistItemId === $targetItemId);

        if ($draggedIndex === false || $targetIndex === false) {
            return false;
        }

        if ($position === 'before' && $draggedIndex === $targetIndex - 1) {
            return true;
        }

        if ($position === 'after' && $draggedIndex === $targetIndex + 1) {
            return true;
        }

        $afterId = $position === 'after'
            ? $targetItemId
            : ($targetIndex === 0 ? null : $tracks[$targetIndex - 1]->playlistItemId);

        if ($afterId === $draggedItemId) {
            return true;
        }

        try {
            $this->plex->moveTrack($this->playlist, $draggedItemId, $afterId);
        } catch (PlexException $e) {
            $this->dispatch('notify', type: 'error', message: "Couldn't reorder the playlist. ".$e->getMessage());

            return false;
        }

        unset($this->tracks);

        return true;
    }

    public function removeTrack(string $playlistItemId): bool
    {
        $exists = $this->tracks->contains(fn ($t) => $t->playlistItemId === $playlistItemId);

        if (! $exists) {
            return false;
        }

        try {
            $this->plex->removeTrackFromPlaylist($this->playlist, $playlistItemId);
        } catch (PlexException $e) {
            $this->dispatch('notify', type: 'error', message: "Couldn't remove the track. ".$e->getMessage());

            return false;
        }

        unset($this->tracks);

        return true;
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
    <div class="px-2 pt-2 pb-2 flex-none" x-data="{ collapsed: @entangle('headerCollapsed') }" data-playlist-header>
        {{-- Collapsed 56px row --}}
        <div x-show="collapsed" x-cloak data-playlist-header-collapsed
             class="relative flex items-center gap-3 h-14 px-4 rounded-lg" style="background: linear-gradient(180deg, #4a3b6b 0%, #2a2438 60%, var(--color-surface) 100%);">
            @php($collapsedThumb = $this->thumbFor($meta->thumb))
            <div class="w-10 h-10 rounded flex-none bg-surface-2 grid place-items-center overflow-hidden relative">
                <x-lucide-list-music class="w-5 h-5 text-text-3" />
                @if ($collapsedThumb)
                    <img src="{{ $collapsedThumb }}" alt="{{ $meta->title }}"
                         onerror="this.remove()"
                         class="absolute inset-0 w-full h-full object-cover">
                @endif
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-[14px] font-bold text-white">{{ $meta->title }}</div>
                <div class="truncate text-[11px] text-text-2">{{ $this->tracks->count() }} songs, {{ \App\Support\Duration::format($this->tracks->sum('durationMs')) }}</div>
            </div>
            <template x-if="$store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}'">
                <span data-source-indicator class="flex-none">
                    <x-lucide-volume-1 class="w-4 h-4 text-accent" />
                </span>
            </template>
            <button type="button" wire:click="playAll" class="w-8 h-8 rounded-full bg-accent hover:bg-accent-hover grid place-items-center text-black flex-none">
                <x-lucide-play class="w-4 h-4" style="fill: currentColor;" />
            </button>
            <button type="button" wire:click="shuffle" class="w-8 h-8 rounded-full grid place-items-center text-text-2 hover:text-white flex-none">
                <x-lucide-shuffle class="w-4 h-4" />
            </button>
            <button type="button" @click="collapsed = false" title="Expand" class="w-8 h-8 rounded-full grid place-items-center text-text-2 hover:text-white flex-none">
                <x-lucide-chevron-down class="w-4 h-4" />
            </button>
        </div>

        {{-- Expanded header --}}
        <div x-show="!collapsed" x-cloak class="relative overflow-hidden rounded-lg" style="background: linear-gradient(180deg, #4a3b6b 0%, #2a2438 60%, var(--color-surface) 100%);">
            <button type="button" @click="collapsed = true" title="Collapse"
                    class="absolute top-3 right-3 z-10 w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-black/30 transition-colors">
                <x-lucide-chevron-up class="w-4 h-4" />
            </button>
            <div class="flex items-end gap-6 p-6">
                @php($expandedThumb = $this->thumbFor($meta->thumb))
                <div class="rounded relative overflow-hidden flex-none shadow-2xl bg-surface-2 grid place-items-center" style="width: 180px; height: 180px;">
                    <x-lucide-list-music class="w-12 h-12 text-text-3" />
                    @if ($expandedThumb)
                        <img src="{{ $expandedThumb }}" alt="{{ $meta->title }}"
                             onerror="this.remove()"
                             class="absolute inset-0 w-full h-full object-cover">
                    @endif
                </div>
                <div class="min-w-0 flex-1 flex flex-col gap-2">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-white">PLAYLIST</div>
                    <h1 class="text-white font-extrabold tracking-tight leading-[1.05] truncate self-start" style="font-size: clamp(28px, 4.2vw, 56px);">{{ $meta->title }}</h1>
                    <template x-if="$store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}'">
                        <span data-source-indicator class="block">
                            <x-lucide-volume-1 class="w-4 h-4 text-accent" />
                        </span>
                    </template>
                    @if ($meta->summary)
                        <div class="text-[14px] text-text-2 max-w-prose">{{ $meta->summary }}</div>
                    @endif
                    <div class="flex items-center gap-1.5 text-[13px] text-text-2 flex-wrap">
                        <span class="whitespace-nowrap tabular-nums">{{ $this->tracks->count() }} songs, {{ \App\Support\Duration::format($this->tracks->sum('durationMs')) }}</span>
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
    @if ($this->tracks->isEmpty())
        <div class="flex-1 grid place-items-center p-12 text-center">
            <div class="max-w-md">
                <x-lucide-list-music class="w-12 h-12 mx-auto text-text-3 mb-4" />
                <h2 class="text-xl font-bold mb-2">This playlist is empty</h2>
                <p class="text-text-2 text-sm">Add songs from your library by dragging them onto the playlist in the sidebar.</p>
            </div>
        </div>
    @else
        <div class="flex-1 min-h-0 flex flex-col px-2 pb-2 overflow-hidden" data-region="tracklist">
            <div class="flex-1 min-h-0 flex flex-col relative bg-surface rounded-lg overflow-hidden">
                {{-- Compact toggle button group --}}
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
                         style="grid-template-columns: 20px 1.4fr 1fr 1fr 50px;">
                        <span class="text-right pr-1">#</span>
                        <span>Title</span>
                        <span>Artist</span>
                        <span>Album</span>
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
                <div class="overflow-y-auto scroll flex-1 py-1" x-data="{
                    draggedId: null,
                    overId: null,
                    overPos: null,
                    flash: {},
                    selectedId: null,
                    menu: null,
                    flashRow(id, ok) { this.flash[id] = ok ? 'ok' : 'err'; setTimeout(() => { this.flash[id] = null }, 700) },
                    onDragOver(e, id) {
                        if (!this.draggedId || id === this.draggedId) { this.overId = null; return }
                        const r = e.currentTarget.getBoundingClientRect();
                        this.overPos = (e.clientY - r.top) < r.height / 2 ? 'before' : 'after';
                        this.overId = id;
                    },
                    async onDrop(id) {
                        const dragged = this.draggedId, pos = this.overPos;
                        this.overId = null; this.draggedId = null;
                        if (!dragged || !id || id === dragged || !pos) return;
                        try { const ok = await $wire.moveTrack(dragged, id, pos); this.flashRow(dragged, ok) }
                        catch (_) { this.flashRow(dragged, false) }
                    },
                    openMenu(e, id) { e.preventDefault(); this.selectedId = id; this.menu = { x: e.clientX, y: e.clientY, itemId: id } },
                    closeMenu() { this.menu = null },
                    async removeSelected() {
                        const id = this.menu ? this.menu.itemId : this.selectedId;
                        this.closeMenu();
                        if (!id) return;
                        try { const ok = await $wire.removeTrack(id); this.flashRow(id, ok); if (ok && this.selectedId === id) this.selectedId = null }
                        catch (_) { this.flashRow(id, false) }
                    },
                    onKey(e) {
                        if (!this.selectedId) return;
                        const t = e.target;
                        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
                        if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); this.removeSelected() }
                    },
                }" @keydown.window="onKey($event)" @click.outside="selectedId = null; closeMenu()">
                    @foreach ($this->tracks as $i => $track)
                        @if ($tracksCompact)
                            <button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')"
                                    @click="selectedId = '{{ $track->playlistItemId }}'"
                                    @contextmenu="openMenu($event, '{{ $track->playlistItemId }}')"
                                    draggable="true"
                                    @dragstart="$event.dataTransfer.effectAllowed='copy'; $event.dataTransfer.setData('plextune/track', '{{ $track->id }}'); $event.dataTransfer.setData('plextune/playlist-item', '{{ $track->playlistItemId }}'); draggedId = '{{ $track->playlistItemId }}'"
                                    @dragend="draggedId = null; overId = null"
                                    @dragover.prevent="onDragOver($event, '{{ $track->playlistItemId }}')"
                                    @dragleave="if (!$event.currentTarget.contains($event.relatedTarget) && overId === '{{ $track->playlistItemId }}') overId = null"
                                    @drop.prevent="onDrop('{{ $track->playlistItemId }}')"
                                    :class="{
                                        'drop-before': overId === '{{ $track->playlistItemId }}' && overPos === 'before',
                                        'drop-after': overId === '{{ $track->playlistItemId }}' && overPos === 'after',
                                        'bg-emerald-400/10 ring-1 ring-emerald-400': flash['{{ $track->playlistItemId }}'] === 'ok',
                                        'bg-red-400/10 ring-1 ring-red-400': flash['{{ $track->playlistItemId }}'] === 'err',
                                        'bg-white/[0.04] ring-1 ring-accent/60': selectedId === '{{ $track->playlistItemId }}' && !flash['{{ $track->playlistItemId }}'],
                                    }"
                                    class="row group w-full grid items-center px-4 py-[3px] rounded text-[13px] text-left hover:bg-white/[0.07] transition-colors"
                                    style="grid-template-columns: 20px 1.4fr 1fr 1fr 50px;">
                                <span class="tabular-nums text-text-2 text-right pr-1"
                                      x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}'; } }">
                                    <template x-if="playing">
                                        <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                                    </template>
                                    <template x-if="!playing">
                                        <span>{{ $i + 1 }}</span>
                                    </template>
                                </span>
                                <div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
                                @if ($track->artistId)
                                    <span class="text-text-2 group-hover:text-white truncate hover:underline cursor-pointer"
                                          @click.stop="Livewire.navigate('{{ route('library', ['artist' => $track->artistId]) }}')">{{ $track->artist }}</span>
                                @else
                                    <div class="text-text-2 group-hover:text-white truncate">{{ $track->artist }}</div>
                                @endif
                                @if ($track->artistId && $track->albumId)
                                    <span class="text-text-2 group-hover:text-white truncate hover:underline cursor-pointer"
                                          @click.stop="Livewire.navigate('{{ route('library', ['artist' => $track->artistId, 'album' => $track->albumId]) }}')">{{ $track->album }}</span>
                                @else
                                    <div class="text-text-2 group-hover:text-white truncate">{{ $track->album }}</div>
                                @endif
                                <div class="text-text-2 tabular-nums text-right text-[12px]">{{ \App\Support\Duration::format($track->durationMs) }}</div>
                            </button>
                        @else
                            <button type="button" wire:key="track-{{ $track->id }}" wire:click="playTrack('{{ $track->id }}')"
                                    @click="selectedId = '{{ $track->playlistItemId }}'"
                                    @contextmenu="openMenu($event, '{{ $track->playlistItemId }}')"
                                    draggable="true"
                                    @dragstart="$event.dataTransfer.effectAllowed='copy'; $event.dataTransfer.setData('plextune/track', '{{ $track->id }}'); $event.dataTransfer.setData('plextune/playlist-item', '{{ $track->playlistItemId }}'); draggedId = '{{ $track->playlistItemId }}'"
                                    @dragend="draggedId = null; overId = null"
                                    @dragover.prevent="onDragOver($event, '{{ $track->playlistItemId }}')"
                                    @dragleave="if (!$event.currentTarget.contains($event.relatedTarget) && overId === '{{ $track->playlistItemId }}') overId = null"
                                    @drop.prevent="onDrop('{{ $track->playlistItemId }}')"
                                    :class="{
                                        'drop-before': overId === '{{ $track->playlistItemId }}' && overPos === 'before',
                                        'drop-after': overId === '{{ $track->playlistItemId }}' && overPos === 'after',
                                        'bg-emerald-400/10 ring-1 ring-emerald-400': flash['{{ $track->playlistItemId }}'] === 'ok',
                                        'bg-red-400/10 ring-1 ring-red-400': flash['{{ $track->playlistItemId }}'] === 'err',
                                        'bg-white/[0.04] ring-1 ring-accent/60': selectedId === '{{ $track->playlistItemId }}' && !flash['{{ $track->playlistItemId }}'],
                                    }"
                                    class="row group w-full grid items-center px-4 py-2 rounded text-[14px] text-left hover:bg-white/[0.07] transition-colors"
                                    style="grid-template-columns: 40px 36px 1.6fr 1fr 16px 60px;">
                                <span class="text-text-3 group-hover:text-white grid place-items-center">
                                    <x-lucide-grip-vertical class="w-[14px] h-[14px]" />
                                </span>
                                <span class="tabular-nums text-text-2 text-right"
                                      x-data="{ get playing() { return $store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}'; } }">
                                    <template x-if="playing">
                                        <span class="eq" :class="{ 'is-paused': !$store.player.isPlaying }"><span></span><span></span><span></span></span>
                                    </template>
                                    <template x-if="!playing">
                                        <span>{{ $i + 1 }}</span>
                                    </template>
                                </span>
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
                                        <div class="truncate font-medium" :class="($store.player?.currentId === '{{ $track->id }}' && $store.player?.contextType === 'playlist' && $store.player?.contextId === '{{ $this->playlist }}') ? 'text-accent' : 'text-white'">{{ $track->title }}</div>
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
                                <span class="grid place-items-center text-text-2 hover:text-white">
                                    <x-lucide-heart class="w-3.5 h-3.5" />
                                </span>
                                <div class="text-text-2 tabular-nums text-right">{{ \App\Support\Duration::format($track->durationMs) }}</div>
                            </button>
                        @endif
                    @endforeach

                    <template x-if="menu">
                        <div class="fixed z-50 min-w-[200px] rounded-md border border-white/10 bg-surface-2 py-1 text-[13px] text-text-1 shadow-xl"
                             :style="{ left: menu.x + 'px', top: menu.y + 'px' }"
                             @click.outside="closeMenu()"
                             @keydown.escape.window="closeMenu()"
                             x-cloak>
                            <button type="button" @click="removeSelected()"
                                    class="block w-full px-3 py-1.5 text-left hover:bg-white/10">
                                Remove from playlist
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    @endif
@endif
</div>
