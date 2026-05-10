<?php

use Livewire\Component;
use Livewire\Attributes\On;

new class extends Component {
    public string $trackUrl = '';
    public string $trackTitle = '';
    public string $trackArtist = '';

    public function loadTrack(string $url, string $title = '', string $artist = ''): void
    {
        $this->trackUrl = $url;
        $this->trackTitle = $title;
        $this->trackArtist = $artist;

        $this->dispatch('audio-load', url: $url);
    }

    #[On('play-track')]
    public function onPlayTrack(string $url, string $title = '', string $artist = ''): void
    {
        $this->loadTrack($url, $title, $artist);
    }
};
?>

<div class="bg-base h-[88px] flex items-center px-4 gap-4 flex-none"
     x-data="audioPlayer()"
     x-init="init()">

    {{-- Now-playing --}}
    <div class="flex items-center gap-3 min-w-[280px] max-w-[30%]">
        <div class="w-14 h-14 rounded-md bg-surface-2 grid place-items-center flex-none">
            <x-lucide-music class="w-6 h-6 text-text-3" />
        </div>
        <div class="min-w-0 flex-1">
            <div data-region="now-playing-title" class="truncate text-[14px] font-semibold hover:underline cursor-pointer">{{ $trackTitle ?: '' }}</div>
            <div data-region="now-playing-artist" class="truncate text-[11px] text-text-2 hover:underline cursor-pointer">{{ $trackArtist ?: '' }}</div>
        </div>
        <button type="button" class="text-accent hover:scale-110 transition-transform">
            <x-lucide-heart class="w-4 h-4" />
        </button>
    </div>

    {{-- Transport --}}
    <div class="flex-1 flex flex-col items-center gap-1.5 max-w-[722px] mx-auto">
        <div class="flex items-center gap-5">
            <button type="button" class="text-text-2 hover:text-white">
                <x-lucide-shuffle class="w-4 h-4" />
            </button>
            <button type="button" class="text-text-2 hover:text-white">
                <x-lucide-skip-back class="w-[18px] h-[18px] fill-current" />
            </button>
            <button type="button" @click="togglePlay"
                    class="w-9 h-9 rounded-full bg-white text-black grid place-items-center hover:scale-105 active:scale-100 transition-transform">
                <template x-if="!isPlaying">
                    <x-lucide-play class="w-4 h-4 fill-current" />
                </template>
                <template x-if="isPlaying">
                    <x-lucide-pause class="w-4 h-4 fill-current" />
                </template>
            </button>
            <button type="button" class="text-text-2 hover:text-white">
                <x-lucide-skip-forward class="w-[18px] h-[18px] fill-current" />
            </button>
            <button type="button" class="text-text-2 hover:text-white">
                <x-lucide-repeat class="w-4 h-4" />
            </button>
        </div>
        <div class="flex items-center gap-2 w-full text-[11px] text-text-2">
            <span class="tabular-nums w-8 text-right" x-text="formatTime(currentTime)">0:00</span>
            <input type="range" min="0" :max="duration || 0" step="0.1"
                   :value="currentTime"
                   @input="seek($event.target.value)"
                   class="flex-1 accent-white">
            <span class="tabular-nums w-8" x-text="formatTime(duration)">0:00</span>
        </div>
    </div>

    {{-- Right side: queue, cast, volume --}}
    <div class="flex items-center gap-3 min-w-[280px] max-w-[30%] justify-end">
        <button type="button" class="text-text-2 hover:text-white">
            <x-lucide-list-music class="w-4 h-4" />
        </button>
        <button type="button" class="text-text-2 hover:text-white">
            <x-lucide-cast class="w-4 h-4" />
        </button>
        <div class="flex items-center gap-2 group">
            <button type="button" class="text-text-2 hover:text-white">
                <x-lucide-volume-2 class="w-4 h-4" />
            </button>
            <input type="range" min="0" max="1" step="0.01"
                   :value="volume"
                   @input="setVolume($event.target.value)"
                   class="w-24 accent-white">
        </div>
    </div>

    {{-- The audio element. Persistence across wire:navigate is handled by
         @persist('player') in the layout, NOT here — wrapping just <audio>
         in its own @persist re-parents it out of the Alpine x-data scope,
         breaking $refs.audio. --}}
    <audio x-ref="audio"
           @timeupdate="currentTime = $event.target.currentTime"
           @loadedmetadata="duration = $event.target.duration"
           @play="isPlaying = true"
           @pause="isPlaying = false"
           @ended="isPlaying = false"></audio>
</div>

@script
<script>
    window.audioPlayer = function () {
        return {
            isPlaying: false,
            currentTime: 0,
            duration: 0,
            volume: 1,

            init() {
                // Livewire $dispatch surfaces as a CustomEvent on window with the event name as-is.
                // The dispatched payload is in event.detail.
                window.addEventListener('audio-load', (e) => {
                    this.$refs.audio.src = e.detail.url;
                    this.$refs.audio.play().catch(() => {});
                });
                this.$refs.audio.volume = this.volume;
            },

            togglePlay() {
                if (this.$refs.audio.paused) {
                    this.$refs.audio.play();
                } else {
                    this.$refs.audio.pause();
                }
            },

            seek(value) {
                this.$refs.audio.currentTime = parseFloat(value);
            },

            setVolume(value) {
                this.volume = parseFloat(value);
                this.$refs.audio.volume = this.volume;
            },

            formatTime(seconds) {
                if (!seconds || isNaN(seconds)) return '0:00';
                const m = Math.floor(seconds / 60);
                const s = Math.floor(seconds % 60).toString().padStart(2, '0');
                return `${m}:${s}`;
            },
        };
    };
</script>
@endscript
