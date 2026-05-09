<?php

use Livewire\Component;

new class extends Component {};
?>

<div class="bg-surface rounded-lg h-full p-2 flex flex-col gap-1">
    <a href="{{ route('library') }}" wire:navigate
       @class([
           'flex items-center gap-3 px-3 py-2 rounded text-sm font-bold transition-colors',
           'bg-surface-2 text-white' => request()->routeIs('library'),
           'text-text-2 hover:text-white' => ! request()->routeIs('library'),
       ])>
        <span>Home</span>
    </a>

    <a href="{{ route('search') }}" wire:navigate
       @class([
           'flex items-center gap-3 px-3 py-2 rounded text-sm font-bold transition-colors',
           'bg-surface-2 text-white' => request()->routeIs('search'),
           'text-text-2 hover:text-white' => ! request()->routeIs('search'),
       ])>
        <span>Search</span>
    </a>

    <a href="{{ route('library') }}" wire:navigate
       @class([
           'flex items-center gap-3 px-3 py-2 rounded text-sm font-bold transition-colors',
           'text-text-2 hover:text-white',
       ])>
        <span>Your Library</span>
    </a>

    <a href="{{ route('settings') }}" wire:navigate
       @class([
           'flex items-center gap-3 px-3 py-2 rounded text-sm font-bold transition-colors',
           'bg-surface-2 text-white' => request()->routeIs('settings'),
           'text-text-2 hover:text-white' => ! request()->routeIs('settings'),
       ])>
        <span>Settings</span>
    </a>
</div>
