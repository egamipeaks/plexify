{{--
    One album card. Used in the Recently Added grid.
    Expects: $album (App\Services\Plex\Dto\Album), $thumbUrl (?string).
--}}
<a href="{{ route('library', ['artist' => $album->artistId, 'album' => $album->id]) }}" wire:navigate
   wire:key="album-card-{{ $album->id }}"
   class="group flex flex-col gap-2 p-3 rounded-lg bg-surface hover:bg-surface-1 transition-colors">
    <div class="relative rounded-md overflow-hidden bg-surface-2 grid place-items-center aspect-square shadow-lg">
        <x-lucide-disc class="w-8 h-8 text-text-3" />
        @if ($album->thumb && $thumbUrl)
            <img src="{{ $thumbUrl }}" alt="{{ $album->title }}" loading="lazy"
                 onerror="this.remove()"
                 class="absolute inset-0 w-full h-full object-cover">
        @endif
    </div>
    <div class="min-w-0">
        <div class="text-[14px] font-semibold text-white truncate">{{ $album->title }}</div>
        <div class="text-[12px] text-text-2 truncate">{{ collect([$album->artist, $album->year])->filter()->implode(' · ') }}</div>
    </div>
</a>
