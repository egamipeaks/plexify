@props(['proposal'])

<div class="rounded-lg border border-surface-3 bg-surface-1 p-6" data-testid="proposal-card">
    <h2 class="text-xl font-bold text-text-1">{{ $proposal->name }}</h2>
    @if ($proposal->description)
        <p class="mt-1 text-text-2">{{ $proposal->description }}</p>
    @endif

    <ol class="mt-4 max-h-96 space-y-2 overflow-y-auto">
        @foreach ($proposal->payload['tracks'] as $i => $track)
            <li class="flex items-baseline gap-3 text-sm">
                <span class="w-6 text-right text-text-3">{{ $i + 1 }}</span>
                <div class="flex-1">
                    <div class="text-text-1">
                        {{ $track['title'] }}
                        <span class="text-text-3">{{ $track['artist'] }} . {{ $track['album'] }}</span>
                    </div>
                    @if (! empty($track['reason']))
                        <div class="text-xs italic text-text-3">{{ $track['reason'] }}</div>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>

    <div class="mt-4 flex gap-2">
        <button type="button" wire:click="acceptProposal" class="rounded-lg bg-accent px-4 py-2 font-semibold text-black hover:bg-accent-hover">Accept and save</button>
        <button type="button" wire:click="$set('input', 'Please refine this proposal: ')" class="rounded-lg bg-surface-2 px-4 py-2 text-text-1">Refine</button>
        <button type="button" wire:click="discardProposal" class="rounded-lg bg-surface-2 px-4 py-2 text-text-1">Discard</button>
    </div>
</div>
