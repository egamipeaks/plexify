<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public string $playlist = '';

    public function mount(string $playlist): void
    {
        $this->playlist = $playlist;
    }
};
?>

<div class="p-6">
    <h1 class="text-3xl font-black">Playlist {{ $playlist }}</h1>
    <p class="text-text-2 mt-2">Playlist detail view stub.</p>
</div>
