<?php

use Livewire\Component;
use Livewire\Attributes\On;

new class extends Component {
    #[On('play-track')]
    public function onPlayTrack(array $queue, int $index = 0, bool $shuffle = false): void
    {
        $this->dispatch('queue-load', queue: $queue, index: $index, shuffle: $shuffle);
    }
};
?>

<div class="bg-base h-[88px] flex items-center px-4 gap-4 flex-none"
     x-data="audioPlayer()"
     x-init="init()">

    {{-- Now-playing --}}
    <div class="flex items-center gap-3 min-w-[280px] max-w-[30%]">
        <template x-if="current && current.artwork">
            <img :src="current.artwork" :alt="current.title"
                 @click="goToAlbum()"
                 class="w-14 h-14 rounded-md flex-none object-cover cursor-pointer">
        </template>
        <template x-if="!(current && current.artwork)">
            <div class="w-14 h-14 rounded-md bg-surface-2 grid place-items-center flex-none cursor-pointer"
                 @click="goToAlbum()">
                <x-lucide-music class="w-6 h-6 text-text-3" />
            </div>
        </template>
        <div class="min-w-0 flex-1">
            <div data-region="now-playing-title" class="truncate text-[14px] font-semibold hover:underline cursor-pointer"
                 @click="goToAlbum()" x-text="current ? current.title : ''"></div>
            <div data-region="now-playing-artist" class="truncate text-[11px] text-text-2 hover:underline cursor-pointer"
                 @click="goToArtist()" x-text="current ? current.artist : ''"></div>
        </div>
        <button type="button" class="text-accent hover:scale-110 transition-transform">
            <x-lucide-heart class="w-4 h-4" />
        </button>
    </div>

    {{-- Transport --}}
    <div class="flex-1 flex flex-col items-center gap-1.5 max-w-[722px] mx-auto">
        <div class="flex items-center gap-5">
            <button type="button" @click="toggleShuffle()"
                    :class="shuffle ? 'text-accent' : 'text-text-2 hover:text-white'">
                <x-lucide-shuffle class="w-4 h-4" />
            </button>
            <button type="button" @click="previous()" :disabled="queue.length === 0"
                    class="text-text-2 hover:text-white disabled:opacity-40 disabled:hover:text-text-2">
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
            <button type="button" @click="next()" :disabled="queue.length === 0"
                    class="text-text-2 hover:text-white disabled:opacity-40 disabled:hover:text-text-2">
                <x-lucide-skip-forward class="w-[18px] h-[18px] fill-current" />
            </button>
            <button type="button" @click="cycleRepeat()"
                    :class="repeat !== 'off' ? 'text-accent' : 'text-text-2 hover:text-white'">
                <template x-if="repeat !== 'one'">
                    <x-lucide-repeat class="w-4 h-4" />
                </template>
                <template x-if="repeat === 'one'">
                    <x-lucide-repeat-1 class="w-4 h-4" />
                </template>
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
           @play="isPlaying = true; consecutiveErrors = 0; $store.player.isPlaying = true"
           @pause="isPlaying = false; $store.player.isPlaying = false"
           @ended="next()"
           x-on:error="onTrackError()"></audio>
</div>

@script
<script>
    if (window.Alpine && !Alpine.store('player')) {
        Alpine.store('player', { currentId: null, isPlaying: false });
    }
    document.addEventListener('alpine:init', () => {
        if (!Alpine.store('player')) {
            Alpine.store('player', { currentId: null, isPlaying: false });
        }
    });

    window.audioPlayer = function () {
        return {
            isPlaying: false,
            currentTime: 0,
            duration: 0,
            volume: 1,

            queue: [],         // playback order: [{ id, url, title, artist, artwork }]
            originalQueue: [],  // the unshuffled order, so toggling shuffle off restores it
            index: 0,           // position in `queue` of the current track
            shuffle: false,
            repeat: 'off',      // 'off' | 'all' | 'one'
            consecutiveErrors: 0,

            get current() {
                return this.queue[this.index] ?? null;
            },

            init() {
                if (!Alpine.store('player')) {
                    Alpine.store('player', { currentId: null, isPlaying: false });
                }
                // Livewire $dispatch surfaces as a CustomEvent on window with the event name as-is;
                // the payload is in event.detail.
                window.addEventListener('queue-load', (e) => {
                    this.consecutiveErrors = 0;
                    this.originalQueue = e.detail.queue ?? [];
                    const startIndex = e.detail.index ?? 0;
                    this.shuffle = !!e.detail.shuffle;
                    if (this.shuffle) {
                        this.applyShuffle(startIndex);
                        this.loadAndPlay(0);
                    } else {
                        this.queue = [...this.originalQueue];
                        this.loadAndPlay(startIndex);
                    }
                });
                this.$refs.audio.volume = this.volume;
            },

            loadAndPlay(i) {
                if (i < 0 || i >= this.queue.length) {
                    return;
                }
                this.index = i;
                this.currentTime = 0;
                this.duration = 0;
                Alpine.store('player').currentId = this.queue[i].id;
                this.$refs.audio.src = this.queue[i].url;
                this.$refs.audio.play().catch(() => {});
            },

            togglePlay() {
                if (!this.current) {
                    return;
                }
                if (this.$refs.audio.paused) {
                    this.$refs.audio.play();
                } else {
                    this.$refs.audio.pause();
                }
            },

            next(skipRepeatOne = false) {
                if (this.repeat === 'one' && !skipRepeatOne) {
                    this.$refs.audio.currentTime = 0;
                    this.$refs.audio.play().catch(() => {});
                    return;
                }
                if (this.index < this.queue.length - 1) {
                    this.loadAndPlay(this.index + 1);
                    return;
                }
                if (this.repeat === 'all' && this.queue.length > 0) {
                    this.loadAndPlay(0);
                    return;
                }
                // End of queue with repeat off: stop, keep the queue loaded.
                this.$refs.audio.pause();
                this.isPlaying = false;
            },

            previous() {
                if (this.$refs.audio.currentTime > 3 || this.index === 0) {
                    this.$refs.audio.currentTime = 0;
                    return;
                }
                this.loadAndPlay(this.index - 1);
            },

            jumpTo(i) {
                this.loadAndPlay(i);
            },

            toggleShuffle() {
                if (!this.shuffle) {
                    this.shuffle = true;
                    if (this.queue.length > 0) {
                        this.applyShuffle(this.index);
                    }
                } else {
                    this.shuffle = false;
                    const currentId = this.current ? this.current.id : null;
                    this.queue = [...this.originalQueue];
                    const newIndex = this.queue.findIndex((t) => t.id === currentId);
                    this.index = newIndex >= 0 ? newIndex : 0;
                }
            },

            // Rebuild this.queue as [track at sourceIndex of originalQueue, ...Fisher-Yates(rest)]
            // and set this.index = 0. Does NOT touch the <audio> element, so playback continues.
            applyShuffle(sourceIndex) {
                const list = [...this.originalQueue];
                if (list.length === 0) {
                    this.queue = [];
                    this.index = 0;
                    return;
                }
                const idx = Math.min(Math.max(sourceIndex, 0), list.length - 1);
                const head = list.splice(idx, 1)[0];
                for (let i = list.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [list[i], list[j]] = [list[j], list[i]];
                }
                this.queue = [head, ...list];
                this.index = 0;
            },

            cycleRepeat() {
                this.repeat = this.repeat === 'off' ? 'all' : (this.repeat === 'all' ? 'one' : 'off');
            },

            onTrackError() {
                if (this.queue.length === 0) {
                    return;
                }
                this.consecutiveErrors++;
                if (this.consecutiveErrors > this.queue.length) {
                    console.warn('audioPlayer: every track in the queue failed to load; stopping.');
                    this.$refs.audio.pause();
                    this.isPlaying = false;
                    return;
                }
                this.next(true);
            },

            goToAlbum() {
                const c = this.current;
                if (!c || !c.albumId) {
                    return;
                }
                const params = new URLSearchParams();
                if (c.artistId) {
                    params.set('artist', c.artistId);
                }
                params.set('album', c.albumId);
                Livewire.navigate('/?' + params.toString());
            },

            goToArtist() {
                const c = this.current;
                if (!c || !c.artistId) {
                    return;
                }
                Livewire.navigate('/?artist=' + encodeURIComponent(c.artistId));
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
