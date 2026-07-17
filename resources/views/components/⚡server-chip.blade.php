<?php

use App\Services\Plex\Exceptions\PlexAuthException;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexClient;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public string $name = '—';
    public string $connection = 'down';
    public bool $reachable = false;
    public ?string $error = null;
    public ?string $library = null;

    public function mount(PlexClient $plex): void
    {
        $this->refreshStatus($plex);
    }

    #[On('library-changed')]
    public function refresh(PlexClient $plex): void
    {
        $this->refreshStatus($plex);
    }

    private function refreshStatus(PlexClient $plex): void
    {
        try {
            $status = $plex->ping();
            $this->name = $status['name'];
            $this->reachable = $status['reachable'];
            $this->connection = $status['connection'];
            $this->library = $plex->musicSectionTitle();
            $this->error = null;
        } catch (PlexAuthException) {
            $this->error = 'Auth error';
            $this->reachable = false;
        } catch (PlexException) {
            $this->error = 'Unreachable';
            $this->reachable = false;
        }
    }
};
?>

<div wire:click="refresh"
     class="bg-surface rounded-lg p-3 flex items-center gap-3 cursor-pointer hover:bg-surface-1 transition-colors">
    <div class="w-9 h-9 rounded-md bg-gradient-to-br from-plex-orange-from to-plex-orange-to grid place-items-center text-black flex-none font-black text-[14px]">P</div>

    <div class="min-w-0 flex-1">
        <div class="text-[13px] font-bold truncate flex items-center gap-1.5">
            {{ $name }}
            <span @class([
                'w-1.5 h-1.5 rounded-full inline-block',
                'bg-accent' => $reachable,
                'bg-red-500' => ! $reachable,
            ])></span>
        </div>
        <div class="text-[11px] text-text-2 truncate flex items-center gap-1">
            @if ($error)
                <x-lucide-triangle-alert class="w-3 h-3" />
                {{ $error }}
            @elseif ($reachable)
                <x-lucide-wifi class="w-3 h-3" />
                {{ collect([$library, ucfirst($connection).' connection'])->filter()->implode(' · ') }}
            @else
                <x-lucide-wifi-off class="w-3 h-3" />
                Unreachable
            @endif
        </div>
    </div>

    <button type="button" class="w-7 h-7 rounded-full grid place-items-center text-text-2 hover:text-white hover:bg-surface-3" title="Refresh">
        <x-lucide-refresh-cw class="w-3.5 h-3.5" />
    </button>
</div>
