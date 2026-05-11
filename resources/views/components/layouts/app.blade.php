<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Plexify' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Outfit:wght@100..900&display=swap" rel="stylesheet">
    {{-- Register the playback store before Alpine walks the DOM, so tracklist rows
         (which read $store.player) subscribe to it from their first render. --}}
    <script>
        document.addEventListener('alpine:init', () => {
            window.Alpine.store('player', { currentId: null, isPlaying: false });
        });
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-base text-text-1">
    <div class="app-shell h-screen w-screen flex flex-col gap-2 p-2">
        {{-- Middle region: sidebar + main (topbar lives inside main) --}}
        <div class="flex gap-2 flex-1 min-h-0">
            <div class="w-[320px] flex-none flex flex-col gap-2 min-h-0">
                <livewire:sidebar />
            </div>
            <main class="flex-1 min-w-0 bg-surface rounded-lg flex flex-col overflow-hidden">
                <livewire:topbar />
                <div class="flex-1 min-h-0 overflow-hidden">
                    {{ $slot }}
                </div>
            </main>
        </div>

        {{-- Player region --}}
        <div class="flex-none">
            @persist('player')
                <livewire:player />
            @endpersist
        </div>
    </div>

    @livewireScripts
</body>
</html>
