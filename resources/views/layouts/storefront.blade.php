<!DOCTYPE html>
<html lang="en" class="js" data-cart-count="{{ app(\App\Services\Cart\CartService::class)->itemCount() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
    <meta name="theme-color" content="#17181D">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

    @stack('structured_data')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-fg">
@include('partials.impersonation-banner')
@include('storefront.partials.announcement-bar')
    @include('storefront.partials.header')

    @livewire('storefront.garage-banner')

    <main>
        {{ $slot ?? '' }}
        @yield('content')
    </main>

    @include('storefront.partials.footer')

    @livewire('storefront.support-chat')

    <x-storefront.mini-cart />
    <x-ui.toaster />

    @php($pmCompare = app(\App\Support\Compare::class))
    <script type="application/json" id="compare-seed">@json(['ids' => $pmCompare->ids(), 'count' => $pmCompare->count(), 'max' => \App\Support\Compare::MAX])</script>

    @livewireScripts
    @stack('scripts')
</body>
</html>
