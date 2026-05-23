<?php

use Livewire\Component;
use Livewire\Attributes\On;
use App\Services\Plex\PlexClient;
use App\Support\AppSetting;
use App\Livewire\Concerns\TogglesFavorite;

new class extends Component {
    use TogglesFavorite;

    public bool $scrobbleEnabled = true;

    public string $scrobbleUrlTemplate = '';

    public function mount(PlexClient $plex): void
    {
        $this->scrobbleEnabled = AppSetting::scrobbleEnabled();
        $this->scrobbleUrlTemplate = $plex->scrobbleUrl('__KEY__');
    }

    #[On('play-track')]
    public function onPlayTrack(array $queue, int $index = 0, bool $shuffle = false, ?string $contextType = null, ?string $contextId = null): void
    {
        $this->dispatch('queue-load', queue: $queue, index: $index, shuffle: $shuffle, contextType: $contextType, contextId: $contextId);
    }
};
?>

<div class="bg-base h-[88px] flex items-center px-4 gap-4 flex-none"
     data-region="player"
     x-data="audioPlayer(@js($scrobbleEnabled), @js($scrobbleUrlTemplate))"
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
        <template x-if="current">
            <button type="button"
                    data-control="heart"
                    @click.stop="
                        if (!current) return;
                        const ratingKey = current.id;
                        const cur = $store.favorites.isHearted(ratingKey, 0) ? 10 : 0;
                        const next = cur === 10 ? 0 : 10;
                        $store.favorites.set(ratingKey, next);
                        $wire.toggleHeart(ratingKey, next).then(ok => { if (!ok) $store.favorites.set(ratingKey, cur); });
                    "
                    :class="current && $store.favorites.isHearted(current.id, 0) ? 'text-accent' : 'text-text-2 hover:text-white'"
                    :aria-label="current && $store.favorites.isHearted(current.id, 0) ? 'Remove from favorites' : 'Add to favorites'"
                    class="transition-transform hover:scale-110">
                <span :class="current && $store.favorites.isHearted(current.id, 0) ? 'w-4 h-4 fill-current' : 'w-4 h-4'">
                    <x-lucide-heart class="w-full h-full" />
                </span>
            </button>
        </template>
    </div>

    {{-- Transport --}}
    <div class="flex-1 flex flex-col items-center gap-1.5 max-w-[722px] mx-auto">
        <div class="flex items-center gap-5">
            <button type="button" @click="toggleShuffle()"
                    :class="shuffle ? 'text-accent' : 'text-text-2 hover:text-white'">
                <x-lucide-shuffle class="w-4 h-4" />
            </button>
            <button type="button" data-control="previous" @click="previous()" :disabled="queue.length === 0"
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
            <button type="button" data-control="mute" @click="toggleMute()" class="text-text-2 hover:text-white">
                <x-lucide-volume-2 x-show="!muted" x-cloak data-icon="unmuted" class="w-4 h-4" />
                <x-lucide-volume-x x-show="muted" x-cloak data-icon="muted" class="w-4 h-4" />
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
           @play="isPlaying = true; consecutiveErrors = 0; $store.player.isPlaying = true; if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'playing'"
           @pause="isPlaying = false; $store.player.isPlaying = false; if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'paused'"
           @ended="scrobbleCurrent(); next()"
           x-on:error="onTrackError()"></audio>
</div>

@script
<script>
    // The `player` store is registered from the layout's <head> (before Alpine walks
    // the DOM) so tracklist rows pick it up on first render; init() below is a no-op
    // fallback in case that script is ever removed.
    window.audioPlayer = function (scrobbleEnabled, scrobbleUrlTemplate) {
        return {
            isPlaying: false,
            currentTime: 0,
            duration: 0,
            volume: 1,
            muted: false,

            queue: [],         // playback order: [{ id, url, title, artist, artwork, albumId, artistId }]
            originalQueue: [],  // the unshuffled order, so toggling shuffle off restores it
            index: 0,           // position in `queue` of the current track
            shuffle: false,
            repeat: 'off',      // 'off' | 'all' | 'one'
            consecutiveErrors: 0,
            contextType: null,
            contextId: null,
            scrobbleEnabled: !!scrobbleEnabled,
            scrobbleUrlTemplate: scrobbleUrlTemplate || '',

            get current() {
                return this.queue[this.index] ?? null;
            },

            init() {
                if (!Alpine.store('player')) {
                    Alpine.store('player', { currentId: null, isPlaying: false, contextType: null, contextId: null });
                }

                // Guard against double-registration (Livewire re-runs the script block on component updates).
                const firstInit = !window.__plexifyPlayerInited;
                window.__plexifyPlayerInited = true;

                // Livewire $dispatch surfaces as a CustomEvent on window with the event name as-is;
                // the payload is in event.detail.
                if (firstInit) window.addEventListener('queue-load', (e) => {
                    this.consecutiveErrors = 0;
                    this.originalQueue = e.detail.queue ?? [];
                    const startIndex = e.detail.index ?? 0;
                    this.shuffle = !!e.detail.shuffle;
                    this.contextType = e.detail.contextType ?? null;
                    this.contextId = e.detail.contextId ?? null;
                    Alpine.store('player').contextType = this.contextType;
                    Alpine.store('player').contextId = this.contextId;
                    if (this.shuffle) {
                        this.applyShuffle(startIndex);
                        this.loadAndPlay(0);
                    } else {
                        this.queue = [...this.originalQueue];
                        this.loadAndPlay(startIndex);
                    }
                });
                let storedVolume = null;
                try { storedVolume = localStorage.getItem('plextune.volume'); } catch (e) {}
                const parsedVolume = parseFloat(storedVolume);
                if (Number.isFinite(parsedVolume) && parsedVolume >= 0 && parsedVolume <= 1) {
                    this.volume = parsedVolume;
                }
                this.$refs.audio.volume = this.volume;

                if (firstInit) {
                    if ('mediaSession' in navigator) {
                        navigator.mediaSession.setActionHandler('play', () => { if (this.$refs.audio.paused) this.$refs.audio.play().catch(() => {}); });
                        navigator.mediaSession.setActionHandler('pause', () => this.$refs.audio.pause());
                        navigator.mediaSession.setActionHandler('previoustrack', () => this.previous());
                        navigator.mediaSession.setActionHandler('nexttrack', () => this.next());
                    }
                }

                if (firstInit) {
                    const isTypingTarget = (el) => {
                        if (!el) return false;
                        const tag = el.tagName;
                        if (tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable) return true;
                        if (tag !== 'INPUT') return false;
                        // Only text-entry inputs swallow Space as a character;
                        // sliders, checkboxes, etc. do not, so Space stays a shortcut there.
                        const nonText = ['range', 'checkbox', 'radio', 'button', 'submit', 'reset', 'color', 'file', 'image'];
                        return !nonText.includes((el.type || 'text').toLowerCase());
                    };
                    window.addEventListener('keydown', (e) => {
                        // Focus search: Cmd/Ctrl+K, or "/" when not typing.
                        if ((e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey)) {
                            e.preventDefault();
                            document.getElementById('topbar-search')?.focus();
                            return;
                        }
                        if (e.key === '/' && !isTypingTarget(e.target) && !e.metaKey && !e.ctrlKey && !e.altKey) {
                            e.preventDefault();
                            document.getElementById('topbar-search')?.focus();
                            return;
                        }
                        if (isTypingTarget(e.target)) return;
                        if (e.metaKey || e.ctrlKey || e.altKey) return;
                        if (e.key === ' ') {
                            e.preventDefault();
                            this.togglePlay();
                            return;
                        }
                        if (e.key === 'ArrowRight') { this.next(); return; }
                        if (e.key === 'ArrowLeft') { this.previous(); return; }
                    });
                }
            },

            loadAndPlay(i) {
                if (i < 0 || i >= this.queue.length) {
                    return;
                }
                this.index = i;
                this.currentTime = 0;
                this.duration = 0;
                Alpine.store('player').currentId = this.queue[i].id;
                if ('mediaSession' in navigator) {
                    const t = this.queue[i];
                    navigator.mediaSession.metadata = new MediaMetadata({
                        title: t.title || '',
                        artist: t.artist || '',
                        artwork: t.artwork ? [{ src: t.artwork }] : [],
                    });
                }
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

            scrobbleCurrent() {
                if (!this.scrobbleEnabled || !this.scrobbleUrlTemplate || !this.current) {
                    return;
                }
                try {
                    fetch(this.scrobbleUrlTemplate.replace('__KEY__', encodeURIComponent(this.current.id)), { mode: 'no-cors' }).catch(() => {});
                } catch (_) {}
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
                this.muted = false;
                this.$refs.audio.muted = false;
                this.$refs.audio.volume = this.volume;
                try { localStorage.setItem('plextune.volume', this.volume); } catch (e) {}
            },

            toggleMute() {
                this.muted = !this.muted;
                this.$refs.audio.muted = this.muted;
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
