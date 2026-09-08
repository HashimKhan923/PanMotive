{{-- resources/views/storefront/partials/header.blade.php --}}
<header class="border-b border-slate-100">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <a href="{{ route('home') }}" class="text-lg font-semibold tracking-tight">
            {{ config('app.name', 'PartsMarket') }}
        </a>

        <nav class="flex items-center gap-6 text-sm">
            <a href="{{ route('home') }}" class="text-slate-600 hover:text-slate-900">Shop</a>

            @auth
                <a href="{{ route('dashboard') }}" class="text-slate-600 hover:text-slate-900">Dashboard</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-slate-600 hover:text-slate-900">Log out</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="text-slate-600 hover:text-slate-900">Log in</a>
                <a href="{{ route('register') }}"
                   class="rounded-md bg-slate-900 px-3 py-1.5 text-white hover:bg-slate-700">Register</a>
            @endauth
        </nav>
    </div>
</header>
