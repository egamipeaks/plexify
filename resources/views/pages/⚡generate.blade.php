<?php

use App\Ai\Agents\PlaylistGeneratorAgent;
use App\Ai\Support\ProposalStore;
use App\Models\AiPlaylistProposal;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public bool $aiConfigured = false;

    public string $input = '';

    public ?string $conversationId = null;

    /** @var list<array{role: string, content: string}> */
    public array $messages = [];

    public bool $thinking = false;

    public function mount(): void
    {
        $this->aiConfigured = ! empty(getenv('OPENAI_API_KEY'));
    }

    public function proposal(): ?AiPlaylistProposal
    {
        if ($this->conversationId === null) {
            return null;
        }

        $latest = app(ProposalStore::class)->latest($this->conversationId);

        return $latest && $latest->status === 'pending' ? $latest : null;
    }

    public function acceptProposal(): void
    {
        if ($this->conversationId === null) {
            return;
        }

        $store = app(ProposalStore::class);
        $proposal = $store->latest($this->conversationId);

        if (! $proposal || $proposal->status !== 'pending') {
            return;
        }

        $tracks = $proposal->payload['tracks'] ?? [];

        if (empty($tracks)) {
            $this->dispatch('notify', type: 'error', message: 'Proposal has no tracks.');

            return;
        }

        try {
            $plex = app(\App\Services\Plex\PlexClient::class);

            $plexPlaylistId = $plex->createPlaylist($proposal->name, $tracks[0]['ratingKey']);

            foreach (array_slice($tracks, 1) as $track) {
                $plex->addTrackToPlaylist($plexPlaylistId, $track['ratingKey']);
            }

            $store->markAccepted($this->conversationId, $plexPlaylistId);

            $this->dispatch('playlist-created', id: $plexPlaylistId);
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: 'Could not save playlist: '.$e->getMessage());
        }
    }

    public function discardProposal(): void
    {
        if ($this->conversationId !== null) {
            app(ProposalStore::class)->markDiscarded($this->conversationId);
        }
    }

    public function startNew(): void
    {
        $this->conversationId = null;
        $this->messages = [];
        $this->input = '';
    }

    public function send(): void
    {
        $text = trim($this->input);

        if ($text === '') {
            return;
        }

        $this->messages[] = ['role' => 'user', 'content' => $text];
        $this->input = '';
        $this->thinking = true;

        try {
            $user = User::where('email', 'plexify@local')->firstOrFail();

            if ($this->conversationId === null) {
                $this->conversationId = app(ConversationStore::class)
                    ->storeConversation($user->id, Str::limit($text, 50, preserveWords: true));
            }

            $agent = app(PlaylistGeneratorAgent::class);
            $response = $agent->continue($this->conversationId, as: $user)->prompt($text);

            $this->messages[] = ['role' => 'assistant', 'content' => $response->text];
        } catch (\Throwable $e) {
            Log::error('AI playlist generator send failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'conversation_id' => $this->conversationId,
                'trace' => $e->getTraceAsString(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'AI request failed: '.$e->getMessage());
        } finally {
            $this->thinking = false;
        }
    }
}; ?>

<div class="h-full overflow-y-auto p-6">
    <div class="mx-auto max-w-3xl">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-bold text-text-1">Generate a playlist</h1>
            @if ($aiConfigured && ! empty($messages))
                <button type="button" wire:click="startNew" class="text-sm text-text-2 hover:text-text-1">New conversation</button>
            @endif
        </div>

        @if (! $aiConfigured)
            <div class="rounded-lg bg-surface-1 p-6 text-text-2">
                <p class="mb-2 font-semibold text-text-1">AI generator not configured</p>
                <p>Set <code>OPENAI_API_KEY</code> in your <code>.env</code> file to enable AI playlist generation.</p>
            </div>
        @else
            <div class="mb-6 space-y-4" data-testid="messages">
                @foreach ($messages as $msg)
                    <x-generate-message :role="$msg['role']" :content="$msg['content']" />
                @endforeach

                <div wire:loading wire:target="send" class="flex items-center gap-2 text-text-3" data-testid="thinking">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                        <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                    <span class="italic">Searching your library...</span>
                </div>
            </div>

            @if ($p = $this->proposal())
                <div class="mb-6">
                    <x-generate-proposal-card :proposal="$p" />
                </div>
            @endif

            <form wire:submit="send" class="sticky bottom-0 bg-base pt-4">
                <div class="flex gap-2">
                    <textarea wire:model="input" wire:loading.attr="disabled" wire:target="send" rows="2" placeholder="Describe the playlist you want..."
                              class="flex-1 rounded-lg bg-surface-1 p-3 text-text-1 disabled:opacity-50"></textarea>
                    <button type="submit" wire:loading.attr="disabled" wire:target="send" class="rounded-lg bg-accent px-4 py-2 font-semibold text-black hover:bg-accent-hover disabled:opacity-50">
                        <span wire:loading.remove wire:target="send">Send</span>
                        <span wire:loading wire:target="send">Working...</span>
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
