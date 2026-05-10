<?php

use Livewire\Component;

new class extends Component {};
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
            <div class="px-3 py-6 text-[12px] text-text-3 text-center">No playlists yet</div>
        </div>
    </div>

    {{-- Server chip card --}}
    <livewire:server-chip />
</aside>
