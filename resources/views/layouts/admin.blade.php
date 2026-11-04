<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>@yield('title', $title ?? 'Admin') &middot; {{ config('app.name', 'PanMotive') }} Admin</title>

    <script @nonce>
        try {
            var t = localStorage.getItem('pm-theme');
            if (t === 'dark' || !t) document.documentElement.classList.add('dark');
        } catch (e) {}
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/admin.js'])
    @livewireStyles
    @stack('head')
</head>
<body class="adm-body min-h-screen bg-bg text-fg">
@php($nav = \App\Support\AdminNav::groups(auth()->user()))
<div class="adm-aurora" aria-hidden="true"></div>

<div x-data="{ mobileNav: false }" class="relative flex min-h-screen">
    {{-- ===== SIDEBAR ===== --}}
    <div x-show="mobileNav" x-cloak x-transition.opacity @click="mobileNav = false" class="fixed inset-0 z-40 bg-black/60 lg:hidden"></div>
    <aside class="adm-sidebar fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col transition-transform duration-300 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
           :class="mobileNav ? '!translate-x-0' : ''" style="view-transition-name: adm-sidebar">
        <a href="{{ route('admin.dashboard') }}" class="flex h-16 shrink-0 items-center gap-2.5 px-5">
            <x-ui.logo variant="mark" class="h-7 w-auto shrink-0" />
            <span class="leading-tight">
                <x-ui.logo variant="word" class="block h-3 w-auto" />
                <span class="mt-1 block text-[10px] font-semibold uppercase tracking-[.18em] text-muted">Command center</span>
            </span>
        </a>

        <nav data-adm-nav class="adm-scroll flex-1 space-y-5 overflow-y-auto px-3 pb-6 pt-2 text-[13px]" aria-label="Admin">
            @foreach ($nav as $group)
                <div>
                    @if ($group['label'])
                        <p class="mb-1 px-3 text-[10px] font-bold uppercase tracking-[.16em] text-muted/70">{{ $group['label'] }}</p>
                    @endif
                    <div class="space-y-0.5">
                        @foreach ($group['items'] as $item)
                            <a href="{{ route($item['route']) }}" @class(['adm-nav-link', 'is-active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>
                                <x-ui.icon :name="$item['icon']" class="w-4 h-4 shrink-0" />
                                <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                                @if ($item['badge'] > 0)
                                    <span class="adm-nav-badge">{{ $item['badge'] > 99 ? '99+' : $item['badge'] }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        <div class="shrink-0 border-t border-line/60 p-3">
            <div class="flex items-center gap-2.5 rounded-xl px-2 py-1.5">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-accent/15 text-xs font-bold text-accent">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                <div class="min-w-0 flex-1 leading-tight">
                    <p class="truncate text-xs font-semibold">{{ auth()->user()->name }}</p>
                    <p class="truncate text-[11px] capitalize text-muted">{{ str_replace('_', ' ', auth()->user()->getRoleNames()->first() ?? 'staff') }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="grid h-8 w-8 place-items-center rounded-lg text-muted transition hover:bg-surface-2 hover:text-fg" aria-label="Log out" title="Log out">
                        <x-ui.icon name="external" class="w-4 h-4" />
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- ===== MAIN ===== --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="adm-topbar sticky top-0 z-30 flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
            <button @click="mobileNav = true" class="grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-surface-2 lg:hidden" aria-label="Open menu">
                <x-ui.icon name="menu" class="w-5 h-5" />
            </button>

            <button type="button" @click="$dispatch('adm-palette')" class="adm-search-trigger flex h-10 min-w-0 flex-1 items-center gap-2.5 rounded-xl px-3 text-left text-sm text-muted sm:max-w-md">
                <x-ui.icon name="search" class="w-4 h-4 shrink-0" />
                <span class="min-w-0 flex-1 truncate">Search orders, people, parts, or jump to a page</span>
                <kbd class="hidden rounded-md border border-line bg-surface-2 px-1.5 py-0.5 text-[10px] font-semibold sm:block">Ctrl K</kbd>
            </button>

            <div class="ml-auto flex items-center gap-1">
                <a href="{{ \App\Support\AdminNav::site('home') }}" target="_blank" rel="noopener" class="hidden items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold text-muted transition hover:bg-surface-2 hover:text-fg sm:inline-flex">
                    View site <x-ui.icon name="external" class="w-3.5 h-3.5" />
                </a>
                <button type="button" x-data @click="
                            const d = document.documentElement.classList.toggle('dark');
                            try { localStorage.setItem('pm-theme', d ? 'dark' : 'light'); } catch (e) {}
                        " class="grid h-9 w-9 place-items-center rounded-lg text-muted transition hover:bg-surface-2 hover:text-fg" aria-label="Switch light or dark theme">
                    <x-ui.icon name="moon" class="w-4.5 h-4.5" />
                </button>
                @php($alertCount = \App\Support\AdminNav::openAlerts())
                <a href="{{ route('admin.alerts.index') }}" class="relative grid h-9 w-9 place-items-center rounded-lg text-muted transition hover:bg-surface-2 hover:text-fg" aria-label="Alerts, {{ $alertCount }} open">
                    <x-ui.icon name="bell" class="w-4.5 h-4.5" />
                    @if ($alertCount > 0)
                        <span class="adm-ping absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-rose-500"></span>
                    @endif
                </a>
            </div>
        </header>

        <main class="flex-1 px-4 pb-16 pt-6 sm:px-6 lg:px-8" data-adm-main>
            @if ($errors->any())
                <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300" role="alert">
                    <x-ui.icon name="alert" class="mt-0.5 w-4 h-4 shrink-0" />
                    <ul class="space-y-0.5">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
</div>

@include('admin.partials.palette')
<x-ui.toaster />
@if (session('success') || session('error'))
    <div x-data x-init="setTimeout(() => window.dispatchEvent(new CustomEvent('toast', { detail: { message: @js(session('success') ?? session('error')), kind: @js(session('success') ? 'success' : 'error') } })), 350)"></div>
@endif

@livewireScripts
@stack('scripts')
</body>
</html>
