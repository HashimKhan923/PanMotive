{{-- resources/views/layouts/storefront.blade.php --}}
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'PartsMarket — Auto Parts for Every Vehicle')</title>
    <meta name="description" content="@yield('meta_description', 'Shop genuine and aftermarket auto parts fitted to your exact vehicle.')">

    {{-- PWA --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0f172a">
    <link rel="apple-touch-icon" href="/pwa-192x192.png">

    {{-- AI-generated JSON-LD structured data injected per-page --}}
    @stack('structured_data')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-white text-slate-900 antialiased">
    @include('storefront.partials.header')

    <main>
        {{ $slot ?? '' }}
        @yield('content')
    </main>

    @include('storefront.partials.footer')

    @livewire('storefront.support-chat')

    @livewireScripts
    @stack('scripts')
</body>
</html>
