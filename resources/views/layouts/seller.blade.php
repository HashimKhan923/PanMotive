<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'Seller Portal' }} &middot; {{ config('app.name', 'PanMotive') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @stack('head')
    </head>
    <body class="font-sans antialiased bg-gray-50 text-gray-900">
        <div class="min-h-screen flex">
            <aside class="w-60 shrink-0 bg-white border-r border-gray-200 hidden md:flex flex-col">
                <div class="h-16 flex items-center px-6 font-semibold border-b border-gray-200">
                    {{ config('app.name', 'PanMotive') }} <span class="ml-1 text-gray-400 font-normal">Seller</span>
                </div>
                <nav class="flex-1 p-4 space-y-1 text-sm">
                    <a href="{{ route('seller.dashboard') }}" class="block rounded px-3 py-2 hover:bg-gray-100">Dashboard</a>
                </nav>
            </aside>

            <div class="flex-1 flex flex-col min-w-0">
                <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-6">
                    <div class="font-medium">{{ $header ?? 'Seller Portal' }}</div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="text-sm text-gray-600 hover:text-gray-900">Log out</button>
                    </form>
                </header>

                <main class="flex-1 p-6">
                    {{ $slot }}
                </main>
            </div>
        </div>
        @stack('scripts')
    </body>
</html>
