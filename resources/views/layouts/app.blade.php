<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="js" data-cart-count="{{ app(\App\Services\Cart\CartService::class)->itemCount() }}">
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
    @include('storefront.partials.header')

    @livewire('storefront.garage-banner')

    @if (isset($header))
        <header class="border-b border-line bg-surface">
            <div class="container-x py-6">
                {{ $header }}
            </div>
        </header>
    @endif

    <main>
        {{ $slot }}
    </main>

    @include('storefront.partials.footer')

    <x-ui.toaster />

    @php($pmCompare = app(\App\Support\Compare::class))
    <script type="application/json" id="compare-seed">@json(['ids' => $pmCompare->ids(), 'count' => $pmCompare->count(), 'max' => \App\Support\Compare::MAX])</script>

    @livewireScripts
    @stack('scripts')
</body>
</html>
