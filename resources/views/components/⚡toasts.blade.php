<?php

use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    /** @var list<array{type: string, message: string}> */
    public array $toasts = [];

    #[On('notify')]
    public function notify(string $message, string $type = 'error'): void
    {
        $this->toasts[] = ['type' => $type, 'message' => $message];
    }

    public function dismiss(int $index): void
    {
        unset($this->toasts[$index]);
        $this->toasts = array_values($this->toasts);
    }
};
?>

<div class="fixed bottom-24 right-4 z-[2000] flex flex-col gap-2 w-[320px] pointer-events-none">
    @foreach ($toasts as $i => $toast)
        <div wire:key="toast-{{ $i }}"
             x-data
             x-init="setTimeout(() => $wire.dismiss({{ $i }}), 5000)"
             class="pointer-events-auto flex items-start gap-3 rounded-lg bg-surface-2 ring-1 ring-white/10 shadow-2xl px-4 py-3 text-[13px]">
            <x-lucide-circle-alert class="w-4 h-4 text-red-400 flex-none mt-0.5" />
            <div class="flex-1 min-w-0 text-text-1">{{ $toast['message'] }}</div>
            <button type="button" wire:click="dismiss({{ $i }})" class="text-text-3 hover:text-white flex-none">
                <x-lucide-x class="w-3.5 h-3.5" />
            </button>
        </div>
    @endforeach
</div>
