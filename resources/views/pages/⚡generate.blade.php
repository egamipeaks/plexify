<?php

use App\Ai\Agents\PlaylistGeneratorAgent;
use App\Models\User;
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
            $this->dispatch('notify', type: 'error', message: 'AI request failed: '.$e->getMessage());
        } finally {
            $this->thinking = false;
        }
    }
}; ?>

<div class="h-full overflow-y-auto p-6">
    <div class="mx-auto max-w-3xl">
        <h1 class="mb-6 text-2xl font-bold text-text-1">Generate a playlist</h1>

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

                @if ($thinking)
                    <div class="italic text-text-3">Thinking...</div>
                @endif
            </div>

            <form wire:submit="send" class="sticky bottom-0 bg-base pt-4">
                <div class="flex gap-2">
                    <textarea wire:model="input" rows="2" placeholder="Describe the playlist you want..."
                              class="flex-1 rounded-lg bg-surface-1 p-3 text-text-1"></textarea>
                    <button type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-black hover:bg-accent-hover">Send</button>
                </div>
            </form>
        @endif
    </div>
</div>
