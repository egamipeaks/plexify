<?php

use App\Services\Plex\Dto\MusicSection;
use App\Services\Plex\Exceptions\PlexException;
use App\Services\Plex\PlexCache;
use App\Services\Plex\PlexClient;
use App\Support\AppSetting;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $density = AppSetting::DENSITY_COMFORTABLE;

    public bool $scrobbleEnabled = true;

    public ?int $musicSectionId = null;

    public ?string $resyncedAt = null;

    public ?string $resyncError = null;

    public function mount(PlexClient $plex): void
    {
        $this->density = AppSetting::density();
        $this->scrobbleEnabled = AppSetting::scrobbleEnabled();

        try {
            $this->musicSectionId = $plex->musicSectionId();
        } catch (PlexException) {
            $this->musicSectionId = null;
        }
    }

    public function updatedMusicSectionId(int $value): void
    {
        if (! $this->musicSections->contains(fn (MusicSection $section) => $section->id === $value)) {
            return;
        }

        AppSetting::setMusicSectionId($value);
    }

    public function updatedDensity(string $value): void
    {
        AppSetting::setDensity($value);
    }

    public function updatedScrobbleEnabled(bool $value): void
    {
        AppSetting::setScrobbleEnabled($value);
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

    /**
     * @return Collection<int, MusicSection>
     */
    #[Computed]
    public function musicSections(): Collection
    {
        try {
            return app(PlexClient::class)->musicSections();
        } catch (PlexException) {
            return collect();
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

            @if ($this->musicSections->isNotEmpty())
                <div class="flex items-start justify-between gap-6 py-4 border-b border-white/5">
                    <div class="min-w-0 flex-1">
                        <div class="text-[14px] font-semibold text-white">Music library</div>
                        <div class="text-[12px] text-text-2 mt-0.5 leading-snug">Which Plex library to read from.</div>
                    </div>
                    <div class="flex-none">
                        <select wire:model.live="musicSectionId"
                                class="bg-surface-2 hover:bg-surface-3 transition-colors text-white text-[13px] font-semibold rounded-md px-3 py-1.5">
                            @foreach ($this->musicSections as $section)
                                <option value="{{ $section->id }}">{{ $section->title }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endif

            <div class="mt-6 flex items-center gap-4">
                <button type="button"
                        wire:click="resyncMetadata"
                        wire:loading.attr="disabled"
                        class="bg-accent text-black font-bold px-4 py-2 rounded-full hover:bg-accent-hover transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="resyncMetadata">Resync metadata</span>
                    <span wire:loading wire:target="resyncMetadata" style="display:none">Resyncing…</span>
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

        {{-- Playback --}}
        <section class="bg-surface-1 rounded-xl p-6">
            <h2 class="text-xl font-bold mb-4">Playback</h2>

            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-semibold">Scrobble plays to Plex</div>
                    <div class="text-xs text-text-2 mt-1">Report finished tracks back to your Plex server so play counts and Recently Played stay in sync.</div>
                </div>

                <div class="inline-flex rounded-full bg-surface-2 p-1">
                    <button type="button"
                            wire:click="$set('scrobbleEnabled', true)"
                            @class([
                                'px-4 py-1.5 rounded-full text-sm font-semibold transition-colors',
                                'bg-surface-3 text-text-1' => $scrobbleEnabled,
                                'text-text-2 hover:text-text-1' => ! $scrobbleEnabled,
                            ])>
                        On
                    </button>
                    <button type="button"
                            wire:click="$set('scrobbleEnabled', false)"
                            @class([
                                'px-4 py-1.5 rounded-full text-sm font-semibold transition-colors',
                                'bg-surface-3 text-text-1' => ! $scrobbleEnabled,
                                'text-text-2 hover:text-text-1' => $scrobbleEnabled,
                            ])>
                        Off
                    </button>
                </div>
            </div>
        </section>

        {{-- Display --}}
        <section class="bg-surface-1 rounded-xl p-6">
            <h2 class="text-xl font-bold mb-4">Display</h2>

            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-semibold">Density default</div>
                    <div class="text-xs text-text-2 mt-1">Default for lists you haven't individually adjusted. Each artist, album, and track list has its own Comfortable / Compact toggle in its header.</div>
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
