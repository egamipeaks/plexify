<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component {
    //
};
?>

<div class="h-full grid place-items-center text-center px-6">
    <div>
        <div class="text-[20px] font-bold text-white mb-1">Search your library</div>
        <div class="text-text-2 text-[14px]">Find any song, artist, or album from your Plex server.</div>
    </div>
</div>
