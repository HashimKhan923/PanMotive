@extends('layouts.storefront')
@section('title', 'Email preferences — ' . config('app.name'))

@section('content')
<div class="container-x max-w-lg py-16">
    <div class="card p-8 text-center" data-reveal>
        <x-ui.icon name="mail" class="mx-auto w-12 h-12 text-fg" />

        @if ($done)
            <h1 class="mt-4 text-2xl font-bold">You're unsubscribed</h1>
            <p class="mt-2 text-sm text-muted">
                @if (! empty($all) || ! $store)
                    You won't get marketing emails from any store on Panmotive.
                @else
                    You won't get marketing emails from <strong>{{ $store }}</strong> any more.
                @endif
                Order updates, receipts and messages still arrive as usual.
            </p>
            @if ($store && empty($all))
                <form method="POST" action="{{ request()->fullUrl() }}" class="mt-6">
                    <input type="hidden" name="scope" value="all">
                    <button class="btn-ghost btn-sm">Unsubscribe from all stores instead</button>
                </form>
            @endif
        @else
            <h1 class="mt-4 text-2xl font-bold">Unsubscribe{{ $store ? ' from '.$store : '' }}?</h1>
            <p class="mt-2 text-sm text-muted">
                {{ $store ? "You'll stop getting promotions and news from {$store}." : "You'll stop getting promotions from stores on Panmotive." }}
                Order updates and receipts aren't affected.
            </p>
            <form method="POST" action="{{ request()->fullUrl() }}" class="mt-6 space-y-3">
                <button name="scope" value="{{ $store ? 'store' : 'all' }}" class="btn-accent w-full">Unsubscribe</button>
                @if ($store)
                    <button name="scope" value="all" class="btn-ghost btn-sm w-full">Unsubscribe from all stores</button>
                @endif
            </form>
        @endif

        @auth
            <a href="{{ route('marketing.preferences') }}" class="mt-6 inline-block text-xs text-fg underline underline-offset-2">Manage all marketing preferences</a>
        @endauth
    </div>
</div>
@endsection
