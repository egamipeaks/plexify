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

<div class="bg-surface rounded-lg h-[72px] flex items-center gap-4 px-4"
     x-data="audioPlayer()"
     x-init="init()">

    {{-- Now-playing --}}
    <div class="flex items-center gap-3 w-1/3 min-w-0">
        <div class="w-14 h-14 rounded bg-surface-2 flex-none"></div>
        <div class="min-w-0">
            <div class="text-sm font-bold truncate">{{ $trackTitle ?: '—' }}</div>
            <div class="text-xs text-text-2 truncate">{{ $trackArtist ?: '' }}</div>
        </div>
    </div>

    {{-- Transport --}}
    <div class="flex flex-col items-center gap-1 flex-1 min-w-0">
        <div class="flex items-center gap-4">
            <button type="button" @click="togglePlay"
                    class="w-8 h-8 rounded-full bg-white grid place-items-center text-black hover:scale-105 transition-transform">
                <span x-show="!isPlaying">▶</span>
                <span x-show="isPlaying" x-cloak>❚❚</span>
            </button>
        </div>
        <div class="w-full flex items-center gap-2 text-xs text-text-2 tabular-nums">
            <span x-text="formatTime(currentTime)">0:00</span>
            <input type="range" min="0" :max="duration || 0" step="0.1"
                   :value="currentTime"
                   @input="seek($event.target.value)"
                   class="flex-1 accent-white">
            <span x-text="formatTime(duration)">0:00</span>
        </div>
    </div>

    {{-- Volume --}}
    <div class="flex items-center gap-2 w-32">
        <span class="text-text-2 text-sm">🔊</span>
        <input type="range" min="0" max="1" step="0.01"
               :value="volume"
               @input="setVolume($event.target.value)"
               class="flex-1 accent-white">
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
