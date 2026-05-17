<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public bool $aiConfigured = false;

    public function mount(): void
    {
        $this->aiConfigured = ! empty(getenv('OPENAI_API_KEY'));
    }
}; ?>

<div class="h-full overflow-y-auto p-6">
    <div class="mx-auto max-w-3xl">
        <h1 class="mb-6 text-2xl font-bold text-text-1">Generate a playlist</h1>

        @if (! $aiConfigured)
            <div class="rounded-lg bg-surface-1 p-6 text-text-2">
                <p class="mb-2 font-semibold text-text-1">AI generator not configured</p>
                <p>Set <code>OPENAI_API_KEY</code> in your <code>.env</code> file to enable AI playlist generation.</p>
            </div>
        @else
            <p class="text-text-2">Chat UI lands in Task 19.</p>
        @endif
    </div>
</div>
