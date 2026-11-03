<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Pan Motive') }}</title>

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

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-fg">
    <div class="grid min-h-screen lg:grid-cols-2">
        {{-- brand panel --}}
        <div class="relative hidden overflow-hidden bg-brand p-12 text-brand-fg lg:flex lg:flex-col lg:justify-between">
            <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-accent/20 blur-3xl"></div>
            <a href="/" wire:navigate class="relative flex items-center gap-2">
                <span class="grid h-10 w-10 place-items-center rounded-xl bg-accent text-accent-fg">
                    <x-ui.icon name="wrench" class="w-6 h-6" />
                </span>
                <span class="text-xl font-extrabold tracking-tight">Pan<span class="text-accent">Motive</span></span>
            </a>
            <div class="relative">
                <h1 class="text-3xl font-bold leading-tight">Every part. Every vehicle. Fast.</h1>
                <p class="mt-3 max-w-sm text-white/70">Escrow-protected checkout, verified sellers, and cashback on every delivered order.</p>
                <div class="mt-8 flex gap-6 text-sm text-white/70">
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="shield" class="w-4 h-4 text-accent" /> Buyer protection</span>
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="truck" class="w-4 h-4 text-accent" /> Fast US shipping</span>
                </div>
            </div>
            <p class="relative text-xs text-white/40">&copy; {{ date('Y') }} Pan Motive</p>
        </div>

        {{-- form panel --}}
        <div class="flex flex-col items-center justify-center px-6 py-12">
            <a href="/" wire:navigate class="mb-8 flex items-center gap-2 lg:hidden">
                <span class="grid h-9 w-9 place-items-center rounded-xl bg-accent text-accent-fg">
                    <x-ui.icon name="wrench" class="w-5 h-5" />
                </span>
                <span class="text-lg font-extrabold tracking-tight">Pan<span class="text-accent">Motive</span></span>
            </a>

            <div class="w-full max-w-md">
                {{ $slot }}
            </div>
        </div>
    </div>
    @livewireScripts
</body>
</html>
