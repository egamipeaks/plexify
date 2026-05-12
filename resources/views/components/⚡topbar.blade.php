<?php

use Livewire\Component;

new class extends Component {
    //
};
?>

<div class="flex items-center gap-4 px-6 h-16 flex-none">
    <div class="flex gap-2">
        <button type="button" onclick="history.back()"
                class="w-8 h-8 rounded-full bg-black/60 hover:bg-black grid place-items-center text-white">
            <x-lucide-chevron-left class="w-4 h-4" />
        </button>
        <button type="button" onclick="history.forward()"
                class="w-8 h-8 rounded-full bg-black/60 hover:bg-black grid place-items-center text-text-3">
            <x-lucide-chevron-right class="w-4 h-4" />
        </button>
    </div>

    <div class="flex-1 max-w-[760px]"
         x-data="topbarSearch(@js(request()->query('q', '')))">
        <div class="flex items-center gap-3 bg-surface-1 hover:bg-surface-2 transition-colors rounded-full h-11 px-4 ring-1 ring-transparent focus-within:ring-white/30">
            <x-lucide-search class="w-[18px] h-[18px] text-white" />
            <input type="text"
                   id="topbar-search"
                   x-ref="searchInput"
                   x-model="term"
                   x-on:input.debounce.300ms="$dispatch('plextune-search', { term })"
                   placeholder="What do you want to play?"
                   class="bg-transparent outline-none text-[14px] flex-1 placeholder:text-text-2">
            <button type="button" x-show="term !== ''" x-cloak data-region="search-clear"
                    @click="term = ''; $refs.searchInput.focus(); $dispatch('plextune-search', { term: '' })"
                    class="text-text-3 hover:text-white flex-none">
                <x-lucide-x class="w-4 h-4" />
            </button>
            <span x-show="term === ''" class="text-[11px] text-text-3 border border-text-3/40 rounded px-1.5 py-0.5">&#8984;K</span>
        </div>
    </div>

    <div class="flex-1"></div>

    <a href="{{ route('settings') }}" wire:navigate
       title="Settings"
       class="w-9 h-9 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 grid place-items-center text-white text-[13px] font-bold hover:ring-2 hover:ring-white/40 transition-all">P</a>
</div>
