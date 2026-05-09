<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public function playTestTrack(): void
    {
        $this->dispatch('play-track',
            url: '/test-track.mp3',
            title: 'Test Track',
            artist: 'Local File',
        );
    }
};
?>

<div class="p-6">
    <h1 class="text-3xl font-black">Library</h1>
    <p class="text-text-2 mt-2">Library view stub.</p>

    <button type="button" wire:click="playTestTrack"
            class="mt-6 px-4 py-2 bg-accent text-black rounded-full font-bold hover:bg-accent-hover">
        Play test track
    </button>
</div>
