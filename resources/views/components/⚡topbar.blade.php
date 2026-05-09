<?php

use Livewire\Component;

new class extends Component {
    public string $query = '';
};
?>

<div class="bg-surface rounded-lg h-16 flex items-center gap-4 px-6">
    <div class="flex gap-2">
        <button type="button" onclick="history.back()"
                class="w-8 h-8 rounded-full bg-black/60 grid place-items-center text-text-2 hover:text-white">
            ‹
        </button>
        <button type="button" onclick="history.forward()"
                class="w-8 h-8 rounded-full bg-black/60 grid place-items-center text-text-2 hover:text-white">
            ›
        </button>
    </div>

    <div class="flex-1 max-w-[760px]">
        <input type="text"
               wire:model.live.debounce.300ms="query"
               placeholder="Search your library"
               class="w-full bg-surface-1 rounded-full h-11 px-4 text-sm text-white placeholder:text-text-3 focus:outline-none focus:ring-1 focus:ring-text-2">
    </div>

    <a href="{{ route('settings') }}" wire:navigate
       class="w-8 h-8 rounded-full bg-surface-2 grid place-items-center text-text-2 hover:text-white">
        ⚙
    </a>
</div>
