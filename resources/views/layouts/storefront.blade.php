<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'PanMotive') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @stack('head')
    </head>
    <body class="font-sans antialiased bg-white text-gray-900">
        <div class="min-h-screen flex flex-col">
            <header class="border-b border-gray-100">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                    <a href="{{ route('home') }}" class="font-semibold text-lg">{{ config('app.name', 'PanMotive') }}</a>
                    <nav class="flex items-center gap-4 text-sm">
                        @auth
                            <a href="{{ route('dashboard') }}" class="text-gray-600 hover:text-gray-900">Dashboard</a>
                        @else
                            <a href="{{ route('login') }}" class="text-gray-600 hover:text-gray-900">Log in</a>
                            <a href="{{ route('register') }}" class="text-gray-600 hover:text-gray-900">Register</a>
                        @endauth
                    </nav>
                </div>
            </header>

            <main class="flex-1">
                {{ $slot }}
            </main>

            <footer class="border-t border-gray-100 py-6 text-center text-sm text-gray-500">
                &copy; {{ date('Y') }} {{ config('app.name', 'PanMotive') }}
            </footer>
        </div>
        @stack('scripts')
    </body>
</html>
