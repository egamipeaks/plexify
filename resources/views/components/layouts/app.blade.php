<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Plextune' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-bg text-text-1">
    <div class="flex flex-col gap-2 p-2 h-screen w-screen">
        {{-- Topbar region --}}
        <div class="flex-none">
            <livewire:topbar />
        </div>

        {{-- Middle region: sidebar + main --}}
        <div class="flex gap-2 flex-1 min-h-0">
            <aside class="w-[280px] flex-none">
                <livewire:sidebar />
            </aside>
            <main class="flex-1 min-w-0">
                <div class="bg-surface rounded-lg h-full overflow-auto">
                    {{ $slot }}
                </div>
            </main>
        </div>

        {{-- Player region --}}
        <div class="flex-none">
            <livewire:player />
        </div>
    </div>

    @livewireScripts
</body>
</html>
