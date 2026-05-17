@props(['role', 'content'])

<div @class([
    'rounded-lg p-4',
    'bg-surface-1 text-text-1' => $role === 'user',
    'bg-surface-2 text-text-2' => $role === 'assistant',
])>
    <div class="mb-1 text-xs uppercase tracking-wide text-text-3">{{ $role }}</div>
    <div class="whitespace-pre-wrap">{{ $content }}</div>
</div>
