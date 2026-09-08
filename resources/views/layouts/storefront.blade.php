<!DOCTYPE html>
<html lang="en" class="js" data-cart-count="{{ app(\App\Services\Cart\CartService::class)->itemCount() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name') . ' — Auto Parts for Every Vehicle')</title>
    <meta name="description" content="@yield('meta_description', 'Genuine and aftermarket auto parts from trusted sellers, matched to your exact vehicle. Fast shipping, buyer protection, cashback on every order.')">

    {{-- No-flash theme --}}
    <script @nonce>
        try {
            var t = localStorage.getItem('pm-theme');
            if (t === 'dark' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>

    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0B1B34">
    <link rel="apple-touch-icon" href="/pwa-192x192.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

    @stack('structured_data')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-fg">
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
