{{--
    One playlist row for the sidebar (used both inside folders and at root).
    Expects: $p (App\Services\Plex\Dto\Playlist), $renaming (bool), $thumbUrl (?string).
    Renders inside the playlists card's x-data scope (uses dropTarget / flash / draggingTrack / openMenu).
--}}
@php($isActive = request()->routeIs('playlist') && (string) request()->route('playlist') === $p->id)
<div wire:key="sidebar-pl-{{ $p->id }}"
     x-show="matches(@js($p->title))"
     :class="{
        'ring-1 ring-emerald-400 rounded-md': flash['pl-{{ $p->id }}'] === 'ok',
        'ring-1 ring-red-400 rounded-md': flash['pl-{{ $p->id }}'] === 'err',
     }">
    @if ($renaming)
        <div class="w-full flex items-center gap-3 px-2 py-1.5 rounded-md text-text-2">
            <div class="rounded-sm flex-none bg-surface-2 grid place-items-center" style="width: 32px; height: 32px;">
                <x-lucide-list-music class="w-3.5 h-3.5 text-text-3" />
            </div>
            <input type="text" value="{{ $p->title }}"
                   x-init="$el.focus(); $el.select()"
                   @keydown.enter="$el.blur()"
                   @keydown.escape="$wire.set('renamingPlaylistId', null)"
                   @blur="$wire.renamePlaylist('{{ $p->id }}', $event.target.value)"
                   class="flex-1 min-w-0 bg-white/10 ring-1 ring-white/30 rounded px-1.5 py-0.5 text-[13px] font-medium text-white outline-none">
        </div>
    @else
        <a href="{{ route('playlist', $p->id) }}" wire:navigate draggable="true"
           @dragstart="$event.dataTransfer.effectAllowed='move'; $event.dataTransfer.setData('plextune/playlist', '{{ $p->id }}')"
           @contextmenu="openMenu($event, 'playlist', '{{ $p->id }}')"
           @dragover.prevent="if (draggingTrack) dropTarget = 'pl-{{ $p->id }}'"
           @dragleave="dropTarget = null"
           @drop="dropTrackOn('pl-{{ $p->id }}', '{{ $p->id }}', $event)"
           :class="dropTarget === 'pl-{{ $p->id }}' ? 'bg-accent/15 ring-1 ring-accent/40' : ''"
           @class([
               'w-full flex items-center gap-3 px-2 py-1.5 rounded-md transition-colors',
               'text-white bg-surface-2' => $isActive,
               'text-text-2 hover:text-white hover:bg-surface-2' => ! $isActive,
           ])>
            <div class="relative rounded-sm flex-none bg-surface-2 grid place-items-center overflow-hidden" style="width: 32px; height: 32px;">
                <x-lucide-list-music class="w-3.5 h-3.5 text-text-3" />
                @if ($p->thumb && $thumbUrl)
                    <img src="{{ $thumbUrl }}" alt="{{ $p->title }}" draggable="false" loading="lazy"
                         onerror="this.remove()"
                         class="absolute inset-0 w-full h-full object-cover">
                @endif
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-[14px] truncate">{{ $p->title }}</div>
                <div class="text-[12px] text-text-3 truncate">Playlist · {{ $p->trackCount }} songs</div>
            </div>
        </a>
    @endif
</div>
