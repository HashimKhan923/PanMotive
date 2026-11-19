<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="js{{ request()->cookie('pm-theme') !== 'light' ? ' dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Panmotive') }}</title>

    @include('layouts.partials.theme-init', ['defaultDark' => true])

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-fg">
    <div class="grid min-h-screen lg:grid-cols-2">
        {{-- brand panel --}}
        <div class="relative isolate hidden overflow-hidden bg-[#15191E] p-12 text-white lg:flex lg:flex-col lg:justify-between">
            {{-- photo + charcoal wash so the words always read, then the brand's red forward cut on the edge --}}
            <img src="/images/auth-aero.jpg" alt="" aria-hidden="true" class="pm-banner-kenburns absolute inset-0 -z-20 h-full w-full object-cover opacity-60">
            <div class="absolute inset-0 -z-10 bg-gradient-to-t from-[#15191E] via-[#15191E]/75 to-[#15191E]/30" aria-hidden="true"></div>
            <span class="absolute inset-y-0 -right-10 -z-10 w-16 -skew-x-[16deg] bg-gradient-to-b from-[#E51B2B] to-[#BB1220]" aria-hidden="true"></span>
            <a href="/" wire:navigate class="relative flex items-center" aria-label="Panmotive home">
                <x-ui.logo class="h-[30px] w-auto" />
            </a>
            <div class="relative">
                <h1 class="text-3xl font-bold leading-tight">The right part, every time.</h1>
                <p class="mt-3 max-w-sm text-white/80">Find parts and accessories for your vehicle.</p>
                <div class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-white/85">
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="shield" class="w-4 h-4 text-white" /> Payment held until delivery</span>
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="truck" class="w-4 h-4 text-white" /> Independent sellers across the US</span>
                </div>
            </div>
            <p class="relative text-xs text-white/80">&copy; {{ date('Y') }} Panmotive</p>
        </div>

        {{-- form panel --}}
        <div class="flex flex-col items-center justify-center px-6 py-12">
            <a href="/" wire:navigate class="mb-8 flex items-center lg:hidden" aria-label="Panmotive home">
                <x-ui.logo variant="stacked" class="h-16 w-auto" />
            </a>

            <div class="w-full max-w-md">
                {{ $slot }}
            </div>
        </div>
    </div>
    @livewireScripts
</body>
</html>
