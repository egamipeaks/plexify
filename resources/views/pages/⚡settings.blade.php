<?php

use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexCache;
use App\Services\Plex\PlexClient;
use App\Support\AppSetting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $density = AppSetting::DENSITY_COMFORTABLE;

    public ?string $resyncedAt = null;

    public ?string $resyncError = null;

    public function mount(): void
    {
        $this->density = AppSetting::density();
    }

    public function updatedDensity(string $value): void
    {
        AppSetting::setDensity($value);
    }

    public function resyncMetadata(PlexCache $cache, PlexClient $plex): void
    {
        $cache->flushAll();

        try {
            $plex->ping();
            $this->resyncedAt = now()->format('H:i:s');
            $this->resyncError = null;
        } catch (PlexException) {
            $this->resyncedAt = null;
            $this->resyncError = 'Cache cleared, but Plex is unreachable.';
        }
    }

    #[Computed]
    public function serverInfo(): array
    {
        $tokenSet = ! empty(config('services.plex.token'));

        try {
            $status = app(PlexClient::class)->ping();

            return [
                'name' => $status['name'] ?? null,
                'reachable' => (bool) ($status['reachable'] ?? false),
                'connection' => $status['connection'] ?? 'down',
                'tokenSet' => $tokenSet,
            ];
        } catch (PlexException) {
            return [
                'name' => null,
                'reachable' => false,
                'connection' => 'down',
                'tokenSet' => $tokenSet,
            ];
        }
    }
}; ?>

<div class="p-6">
    <div class="max-w-3xl mx-auto space-y-6">
        <header>
            <h1 class="text-3xl font-black">Settings</h1>
            <p class="text-text-2 mt-2">Configure Plexify.</p>
        </header>

        {{-- Plex Server --}}
        <section class="bg-surface-1 rounded-xl p-6">
            <h2 class="text-xl font-bold mb-4">Plex Server</h2>

            @php($info = $this->serverInfo)

            <div class="space-y-3 text-sm">
                <div class="flex items-center gap-2">
                    @if ($info['reachable'])
                        <span class="w-2.5 h-2.5 rounded-full bg-accent"></span>
                        <span class="text-text-1">Connected</span>
                    @elseif ($info['tokenSet'])
                        <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
                        <span class="text-text-1">Token set, server unreachable</span>
                    @else
                        <span class="w-2.5 h-2.5 rounded-full bg-surface-3"></span>
                        <span class="text-text-2">No token configured</span>
                    @endif
                </div>

                @if ($info['name'])
                    <div class="flex justify-between">
                        <span class="text-text-2">Server</span>
                        <span class="text-text-1 font-semibold">{{ $info['name'] }}</span>
                    </div>
                @endif

                @if ($info['reachable'])
                    <div class="flex justify-between">
                        <span class="text-text-2">Connection</span>
                        <span class="text-text-1 font-semibold capitalize">{{ $info['connection'] }}</span>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex items-center gap-4">
                <button type="button"
                        wire:click="resyncMetadata"
                        wire:loading.attr="disabled"
                        class="bg-accent text-black font-bold px-4 py-2 rounded-full hover:bg-accent-hover transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="resyncMetadata">Resync metadata</span>
                    <span wire:loading wire:target="resyncMetadata">Resyncing…</span>
                </button>

                @if ($resyncedAt)
                    <span class="text-sm text-accent">Cleared at {{ $resyncedAt }}</span>
                @elseif ($resyncError)
                    <span class="text-sm text-red-400">{{ $resyncError }}</span>
                @else
                    <span class="text-sm text-text-3">Clears cached artists, albums, tracks, and playlists.</span>
                @endif
            </div>
        </section>

        {{-- Display --}}
        <section class="bg-surface-1 rounded-xl p-6">
            <h2 class="text-xl font-bold mb-4">Display</h2>

            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-semibold">Density</div>
                    <div class="text-xs text-text-2 mt-1">Comfortable spaces rows out. Compact fits more on screen.</div>
                </div>

                <div class="inline-flex rounded-full bg-surface-2 p-1">
                    <button type="button"
                            wire:click="$set('density', 'comfortable')"
                            @class([
                                'px-4 py-1.5 rounded-full text-sm font-semibold transition-colors',
                                'bg-surface-3 text-text-1' => $density === 'comfortable',
                                'text-text-2 hover:text-text-1' => $density !== 'comfortable',
                            ])>
                        Comfortable
                    </button>
                    <button type="button"
                            wire:click="$set('density', 'compact')"
                            @class([
                                'px-4 py-1.5 rounded-full text-sm font-semibold transition-colors',
                                'bg-surface-3 text-text-1' => $density === 'compact',
                                'text-text-2 hover:text-text-1' => $density !== 'compact',
                            ])>
                        Compact
                    </button>
                </div>
            </div>
        </section>

        {{-- About --}}
        <section class="bg-surface-1 rounded-xl p-6">
            <h2 class="text-xl font-bold mb-4">About</h2>

            <div class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-text-2">Plexify</span>
                    <span class="text-text-1 font-semibold">{{ config('app.version') }}</span>
                </div>
                <p class="text-text-2 leading-relaxed">
                    Built with Laravel, Livewire, Tailwind, and Lucide icons. Streams from Plex Media Server.
                </p>
            </div>
        </section>
    </div>
</div>
