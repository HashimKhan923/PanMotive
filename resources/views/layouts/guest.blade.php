<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'PanMotive') }}</title>

    <script @nonce>
        try {
            var t = localStorage.getItem('pm-theme');
            if (t === 'dark' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-fg">
    <div class="grid min-h-screen lg:grid-cols-2">
        {{-- brand panel --}}
        <div class="relative hidden overflow-hidden bg-brand p-12 text-brand-fg lg:flex lg:flex-col lg:justify-between">
            <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-accent/20 blur-3xl"></div>
            <a href="/" wire:navigate class="relative flex items-center" aria-label="PanMotive home">
                <x-ui.logo class="h-10 w-auto" />
            </a>
            <div class="relative">
                <h1 class="text-3xl font-bold leading-tight">Every part. Every vehicle. Fast.</h1>
                <p class="mt-3 max-w-sm text-white/70">Escrow-protected checkout, verified sellers, and cashback on every delivered order.</p>
                <div class="mt-8 flex gap-6 text-sm text-white/70">
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="shield" class="w-4 h-4 text-accent" /> Buyer protection</span>
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="truck" class="w-4 h-4 text-accent" /> Fast US shipping</span>
                </div>
            </div>
            <p class="relative text-xs text-white/40">&copy; {{ date('Y') }} PanMotive</p>
        </div>

        {{-- form panel --}}
        <div class="flex flex-col items-center justify-center px-6 py-12">
            <a href="/" wire:navigate class="mb-8 flex items-center lg:hidden" aria-label="PanMotive home">
                <x-ui.logo variant="stacked" class="h-20 w-auto" />
            </a>

            <div class="w-full max-w-md">
                {{ $slot }}
            </div>
        </div>
    </div>
    @livewireScripts
</body>
</html>
