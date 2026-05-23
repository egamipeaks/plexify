@props([
    'ratingKey',
    'rating' => 0,
    'size' => 'sm',
    'alwaysVisible' => false,
])

@php
    $iconSize = $size === 'md' ? 'w-4 h-4' : 'w-3.5 h-3.5';
@endphp

<button type="button"
        data-control="heart"
        data-rating-key="{{ $ratingKey }}"
        x-data="{ get hearted() { return $store.favorites.isHearted('{{ $ratingKey }}', {{ (int) $rating }}); } }"
        @click.stop="
            const cur = hearted ? 10 : 0;
            const next = cur === 10 ? 0 : 10;
            $store.favorites.set('{{ $ratingKey }}', next);
            $wire.toggleHeart('{{ $ratingKey }}', next).then(ok => {
                if (!ok) $store.favorites.set('{{ $ratingKey }}', cur);
            });
        "
        :class="hearted
            ? 'text-accent opacity-100'
            : ({{ $alwaysVisible ? 'true' : 'false' }} ? 'text-text-2 hover:text-white opacity-100' : 'text-text-3 opacity-0 group-hover:opacity-100 hover:text-white')"
        :aria-label="hearted ? 'Remove from favorites' : 'Add to favorites'"
        class="grid place-items-center transition-opacity">
    <span :class="hearted ? '{{ $iconSize }} fill-current' : '{{ $iconSize }}'">
        <x-lucide-heart class="w-full h-full" />
    </span>
</button>
