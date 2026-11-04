@extends('layouts.storefront')
@section('title', 'PanMotive Plus')

@section('content')
<div class="container-x max-w-3xl py-12">
    <div class="text-center" data-reveal>
        <span class="pill !bg-accent/10 !text-accent">Membership</span>
        <h1 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">PanMotive <span class="text-accent">Plus</span></h1>
        <p class="mx-auto mt-3 max-w-xl text-muted">Free shipping on every order, extra cashback, and first access to deals — for people who wrench year-round.</p>
    </div>

    <div class="card mt-8 overflow-hidden" data-reveal>
        <div class="border-b border-line bg-surface-2 p-6 text-center">
            <p class="text-4xl font-extrabold">${{ number_format($price, 0) }}<span class="text-base font-medium text-muted">/year</span></p>
            <p class="mt-1 text-xs text-muted">Billed from your PanMotive wallet · {{ $termMonths }} months</p>
        </div>
        <div class="p-6">
            <ul class="space-y-3">
                @foreach ($perks as $perk)
                    <li class="flex items-start gap-3 text-sm">
                        <x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-accent" />
                        <span>{{ $perk }}</span>
                    </li>
                @endforeach
            </ul>

            @if ($errors->has('plus'))
                <p class="mt-4 rounded-lg bg-rose-500/10 px-3 py-2 text-sm text-rose-600">{{ $errors->first('plus') }}</p>
            @endif

            <div class="mt-6">
                @auth
                    @if ($active)
                        <div class="rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-400">
                            You're a Plus member through <strong>{{ auth()->user()->plus_until->format('M j, Y') }}</strong>.
                            @if (auth()->user()->plus_auto_renew)
                                <form method="POST" action="{{ route('plus.cancel') }}" class="mt-2">
                                    @csrf
                                    <button class="text-xs font-semibold text-rose-500 hover:underline">Turn off auto-renew</button>
                                </form>
                            @else
                                <span class="block text-xs text-muted">Auto-renew is off.</span>
                            @endif
                        </div>
                    @else
                        <form method="POST" action="{{ route('plus.join') }}">
                            @csrf
                            <label class="mb-3 flex items-center gap-2 text-xs text-muted">
                                <input type="checkbox" name="auto_renew" value="1" checked class="rounded border-line text-accent focus:ring-accent">
                                Auto-renew each year (cancel anytime)
                            </label>
                            <button class="btn-accent w-full">Join Plus — ${{ number_format($price, 0) }}</button>
                        </form>
                    @endif
                @else
                    <a href="{{ route('login') }}" class="btn-accent w-full">Sign in to join</a>
                @endauth
            </div>
        </div>
    </div>

    <p class="mt-4 text-center text-xs text-muted">Membership fees are non-refundable. Free-shipping applies to standard delivery on eligible items.</p>
</div>
@endsection
