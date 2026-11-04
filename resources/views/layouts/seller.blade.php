<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="js{{ request()->cookie('pm-theme') === 'dark' ? ' dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $title ?? 'Seller Portal') &middot; {{ config('app.name', 'Panmotive') }}</title>

    @include('layouts.partials.theme-init', ['defaultDark' => false])

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    {{-- The seller portal is a working tool: content is always visible, never gated behind scroll-reveal animation. --}}
    <style>[data-reveal],[data-reveal-group] > *{opacity:1 !important;transform:none !important}</style>
    @stack('head')
</head>
<body class="min-h-screen bg-bg text-fg">
@include('partials.impersonation-banner')
@php
    $sellerProfile = auth()->user()?->sellerProfile;
    $unreadMessages = auth()->user()?->unreadSellerMessageCount() ?? 0;
    $openOffers = auth()->user()?->offersReceived()->whereIn('status', ['pending', 'countered'])->count() ?? 0;
    $newOrders = \App\Models\OrderItem::where('seller_id', auth()->id())->where('status', \App\Enums\OrderStatus::Paid->value)->count();
    $unansweredQuestions = \App\Models\Question::where('status', 'published')
        ->whereHas('product', fn ($q) => $q->where('seller_id', auth()->id()))
        ->whereDoesntHave('answers', fn ($a) => $a->where('is_seller', true))->count();
    $supportUnread = \App\Models\SupportTicket::where('seller_id', auth()->id())->where('seller_unread', true)->count();

    // Grouped nav. Each item: [route, label, icon, badge, ability (null = always visible)].
    $navGroups = [
        ['label' => null, 'icon' => null, 'items' => [
            ['seller.dashboard', 'Dashboard', 'chart', 0, null],
        ]],
        ['label' => 'Catalog', 'icon' => 'box', 'items' => [
            ['seller.products.index', 'Products', 'box', 0, 'products.view'],
            ['seller.inventory.index', 'Inventory', 'archive', 0, 'inventory.manage'],
            ['seller.bundles.index', 'Bundles', 'grid', 0, 'products.manage'],
            ['seller.shipping.index', 'Shipping policies', 'truck', 0, 'products.manage'],
        ]],
        ['label' => 'Orders', 'icon' => 'truck', 'items' => [
            ['seller.orders.index', 'Orders', 'truck', $newOrders, 'orders.view'],
            ['seller.returns.index', 'Returns', 'refresh', 0, 'returns.manage'],
            ['seller.offers.index', 'Offers', 'tag', $openOffers, 'orders.view'],
            ['seller.subscriptions.index', 'Subscriptions', 'refresh', 0, 'orders.view'],
        ]],
        ['label' => 'Customers', 'icon' => 'users', 'items' => [
            ['seller.customers.index', 'Customers', 'users', 0, 'orders.view'],
            ['seller.messages.index', 'Messages', 'mail', $unreadMessages, 'messages'],
            ['seller.questions.index', 'Product Q&A', 'help', $unansweredQuestions, 'messages'],
        ]],
        ['label' => 'Marketing', 'icon' => 'sparkles', 'items' => [
            ['seller.marketing.index', 'Overview', 'sparkles', 0, 'marketing'],
            ['seller.campaigns.index', 'Campaigns', 'mail', 0, 'marketing'],
            ['seller.automations.index', 'Automations', 'refresh', 0, 'marketing'],
            ['seller.social.index', 'Social studio', 'chart', 0, 'marketing'],
            ['seller.ads.index', 'Ad manager', 'dollar', 0, 'marketing'],
            ['seller.ad-credit.index', 'Ad credit', 'dollar', 0, 'marketing'],
            ['seller.offsite-ads.index', 'Offsite Ads', 'external', 0, 'marketing'],
            ['seller.affiliates.index', 'Affiliates', 'user', 0, 'marketing'],
            ['seller.promotions.index', 'Promotions', 'tag', 0, 'marketing'],
            ['seller.storefront.edit', 'Storefront builder', 'store', 0, 'settings.manage'],
            ['seller.marketing.analytics', 'Marketing insights', 'chart', 0, 'marketing'],
        ]],
        ['label' => 'Performance', 'icon' => 'shield', 'items' => [
            ['seller.analytics.index', 'Analytics', 'chart', 0, 'finances.view'],
            ['seller.insights.index', 'Listing insights', 'alert', 0, 'products.view'],
            ['seller.reviews.index', 'Reviews', 'star', 0, null],
            ['seller.scorecard.index', 'Performance & tiers', 'shield', 0, null],
            ['seller.account-health.index', 'Account health', 'shield', 0, null],
        ]],
        ['label' => 'Finance', 'icon' => 'dollar', 'items' => [
            ['seller.payouts.index', 'Payouts', 'dollar', 0, 'payouts.view'],
            ['seller.finance.ledger', 'Ledger & reports', 'list', 0, 'finances.view'],
            ['seller.tax.index', 'Tax & statements', 'list', 0, 'finances.view'],
        ]],
        ['label' => 'Settings', 'icon' => 'cog', 'items' => [
            ['seller.settings.edit', 'Store settings', 'cog', 0, 'settings.manage'],
            ['seller.team.index', 'Team & permissions', 'user', 0, 'team.manage'],
            ['seller.verification.index', 'Verification', 'shield', 0, 'settings.manage'],
            ['seller.api.index', 'API & webhooks', 'external', 0, 'settings.manage'],
        ]],
        ['label' => null, 'icon' => null, 'items' => [
            ['seller.support.index', 'Help & support', 'help', $supportUnread, null],
        ]],
    ];

    $canDo = fn (?string $ability) => $ability === null || \App\Support\SellerContext::can($ability);
    $navItemActive = fn (string $route) => request()->routeIs($route)
        || ($route === 'seller.products.index' && request()->routeIs('seller.products.*'));

    // Drop items the actor can't see, then drop groups left empty. Track which group holds the active route.
    $activeGroup = null;
    $navGroups = collect($navGroups)
        ->map(function ($group) use ($canDo, $navItemActive, &$activeGroup) {
            $group['items'] = array_values(array_filter($group['items'], fn ($i) => $canDo($i[4] ?? null)));
            $group['badge'] = collect($group['items'])->sum(fn ($i) => (int) ($i[3] ?? 0));
            $group['active'] = collect($group['items'])->contains(fn ($i) => $navItemActive($i[0]));
            if ($group['active'] && $group['label']) {
                $activeGroup = $group['label'];
            }
            return $group;
        })
        ->filter(fn ($group) => count($group['items']) > 0)
        ->values();
@endphp

<div x-data="{ sidebar: false }" class="flex min-h-screen">
    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r border-line bg-surface transition-transform lg:sticky lg:top-0 lg:h-screen lg:self-start lg:translate-x-0"
           :class="sidebar ? 'translate-x-0' : '-translate-x-full'">
        <div class="flex h-16 items-center gap-2 border-b border-line px-5">
            <x-ui.logo class="h-[21px] w-auto" />
            <span class="ml-1 rounded bg-surface-2 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted">Seller</span>
        </div>

        <nav class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto p-3 text-sm"
             x-data="{
                 open: (() => { try { return JSON.parse(localStorage.getItem('pm-seller-nav')) || {} } catch (e) { return {} } })(),
                 activeGroup: @js($activeGroup),
                 init() { if (this.activeGroup) this.open[this.activeGroup] = true },
                 toggle(g) { this.open[g] = ! this.open[g]; this.persist() },
                 isOpen(g) { return !! this.open[g] },
                 persist() { try { localStorage.setItem('pm-seller-nav', JSON.stringify(this.open)) } catch (e) {} },
             }">
            @foreach ($navGroups as $group)
                @if ($group['label'] === null)
                    {{-- top-level items with no group --}}
                    @foreach ($group['items'] as [$route, $label, $icon, $badge, $ability])
                        <a href="{{ route($route) }}"
                           class="flex items-center gap-3 rounded-lg px-3 py-2 font-medium transition
                                  {{ $navItemActive($route) ? 'bg-surface-2 text-fg' : 'text-muted hover:bg-surface-2 hover:text-fg' }}">
                            <x-ui.icon :name="$icon" class="w-6 h-6" />
                            <span class="flex-1">{{ $label }}</span>
                            @if ($badge > 0)
                                <span class="rounded-full bg-accent px-1.5 text-xs font-semibold text-accent-fg">{{ $badge }}</span>
                            @endif
                        </a>
                    @endforeach
                @else
                    <div class="mt-1">
                        <button type="button" @click="toggle('{{ $group['label'] }}')"
                                class="flex w-full items-center gap-3 rounded-lg px-3 py-2 font-semibold transition
                                       {{ $group['active'] ? 'text-fg' : 'text-muted hover:bg-surface-2 hover:text-fg' }}">
                            <x-ui.icon :name="$group['icon']" class="w-6 h-6" />
                            <span class="flex-1 text-left">{{ $group['label'] }}</span>
                            @if ($group['badge'] > 0)
                                <span x-show="! isOpen('{{ $group['label'] }}')"
                                      class="rounded-full bg-accent px-1.5 text-[10px] font-bold text-accent-fg">{{ $group['badge'] }}</span>
                            @elseif ($group['active'])
                                <span x-show="! isOpen('{{ $group['label'] }}')" class="h-1.5 w-1.5 rounded-full bg-accent"></span>
                            @endif
                            <x-ui.icon name="chevron-down" class="w-4 h-4 shrink-0 transition-transform"
                                       x-bind:class="isOpen('{{ $group['label'] }}') ? 'rotate-180' : ''" />
                        </button>

                        <div x-show="isOpen('{{ $group['label'] }}')" x-collapse x-cloak class="mt-0.5 space-y-0.5">
                            @foreach ($group['items'] as [$route, $label, $icon, $badge, $ability])
                                <a href="{{ route($route) }}"
                                   class="flex items-center gap-3 rounded-lg py-2 pl-10 pr-3 font-medium transition
                                          {{ $navItemActive($route) ? 'bg-surface-2 text-fg' : 'text-muted hover:bg-surface-2 hover:text-fg' }}">
                                    <span class="flex-1">{{ $label }}</span>
                                    @if ($badge > 0)
                                        <span class="rounded-full bg-accent px-1.5 text-[10px] font-bold text-accent-fg">{{ $badge }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </nav>

        <div class="mt-auto p-3">
            <a href="{{ route('home') }}" target="_blank"
               class="flex items-center gap-2 rounded-lg px-3 py-2 text-xs text-muted hover:text-fg">
                <x-ui.icon name="external" class="w-4 h-4" /> View storefront
            </a>
        </div>
    </aside>

    <div x-show="sidebar" x-cloak @click="sidebar = false" class="fixed inset-0 z-30 bg-black/50 lg:hidden"></div>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur lg:px-8">
            <button @click="sidebar = true" class="btn-ghost !p-2 lg:hidden" aria-label="Menu"><x-ui.icon name="menu" class="w-6 h-6" /></button>

            <div class="min-w-0">
                <p class="truncate text-sm font-semibold">
                    {{ $sellerProfile?->business_name ?? 'Your store' }}
                    @if (\App\Support\SellerContext::isTeamMember())
                        <span class="ml-1 rounded bg-surface-2 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-fg">{{ \App\Support\SellerContext::membership()->role }}</span>
                    @endif
                </p>
                @if ($sellerProfile && $sellerProfile->rating_count > 0)
                    <p class="flex items-center gap-1 text-xs text-muted">
                        <x-ui.icon name="star" class="w-4 h-4 fill-current text-fg" />
                        {{ number_format($sellerProfile->rating, 1) }} · {{ $sellerProfile->rating_count }} ratings
                    </p>
                @endif
            </div>

            <div class="ml-auto flex items-center gap-1">
                @php($unreadNotes = auth()->user()?->unreadNotifications()->count() ?? 0)
                <a href="{{ route('seller.notifications.index') }}" class="relative btn-ghost !p-2.5" aria-label="Notifications">
                    <x-ui.icon name="bell" class="w-6 h-6" />
                    @if ($unreadNotes > 0)
                        <span class="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-accent px-1 text-[10px] font-bold text-accent-fg">{{ $unreadNotes }}</span>
                    @endif
                </a>

                <button type="button" @click="$store.theme.toggle()" class="btn-ghost !p-2.5" aria-label="Toggle dark mode">
                    <x-ui.icon name="sun" class="w-6 h-6 dark:hidden" />
                    <x-ui.icon name="moon" class="hidden w-6 h-6 dark:block" />
                </button>

                <div class="relative" x-data="{ acct: false }" @click.outside="acct = false">
                    <button @click="acct = !acct" class="btn-ghost !p-2.5" aria-label="Account">
                        <x-ui.icon name="user" class="w-6 h-6" />
                    </button>
                    <div x-show="acct" x-cloak x-transition.origin.top.right
                         class="absolute right-0 z-50 mt-2 w-52 overflow-hidden rounded-xl border border-line bg-surface py-1 shadow-card-hover">
                        <div class="border-b border-line px-4 py-2.5">
                            <p class="text-xs text-muted">Signed in as</p>
                            <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                        </div>
                        <a href="{{ route('seller.settings.edit') }}" class="block px-4 py-2 text-sm hover:bg-surface-2">Store settings</a>
                        <a href="{{ route('dashboard') }}" class="block px-4 py-2 text-sm hover:bg-surface-2">Buyer account</a>
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-line">
                            @csrf
                            <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-rose-500 hover:bg-surface-2">Log out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-8">
            @php($kyc = config('marketplace.kyc.required') ? \App\Models\SellerVerification::where('seller_id', auth()->id())->first() : null)
            @if (config('marketplace.kyc.required') && (! $kyc || $kyc->status !== 'approved') && request()->routeIs('seller.dashboard'))
                <a href="{{ route('seller.verification.index') }}" class="mb-6 flex items-start gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 hover:bg-amber-500/15 dark:text-amber-400">
                    <x-ui.icon name="shield" class="mt-0.5 w-4 h-4 shrink-0" />
                    <span>
                        <span class="font-semibold">
                            {{ ($kyc?->status === 'pending') ? 'Identity verification is in review.' : (($kyc?->status === 'rejected') ? 'Your verification needs attention.' : 'Verify your identity to keep selling.') }}
                        </span>
                        <span class="block text-xs">US marketplace rules require every seller to verify their business and identity. Finish this before your first payout.</span>
                    </span>
                </a>
            @endif

            @if (session('success'))
                <div class="mb-6 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm font-medium text-emerald-600 dark:text-emerald-400">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="mb-6 rounded-xl bg-rose-500/10 px-4 py-3 text-sm font-medium text-rose-600">{{ session('error') }}</div>
            @endif

            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
</div>

<x-ui.toaster />

@if (session('celebrate'))
    <script @nonce>window.addEventListener('load', () => window.dispatchEvent(new Event('celebrate')));</script>
@endif

@livewireScripts
@stack('scripts')
</body>
</html>
