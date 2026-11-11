@extends('layouts.storefront')
@section('title', 'Email preferences — ' . config('app.name'))

@section('content')
<div class="container-x max-w-lg py-16">
    <div class="card p-8 text-center" data-reveal>
        <x-ui.icon name="mail" class="mx-auto w-12 h-12 text-fg" />

        @if ($done)
            <h1 class="mt-4 text-2xl font-bold">{{ $label }} emails are off</h1>
            <p class="mt-2 text-sm text-muted">You won't get these emails any more. Order updates, receipts and messages still arrive as usual.</p>
            <form method="POST" action="{{ request()->fullUrl() }}" class="mt-6">
                <button name="action" value="resume" class="btn-ghost btn-sm">Turn them back on</button>
            </form>
        @else
            <h1 class="mt-4 text-2xl font-bold">Stop {{ strtolower($label) }} emails?</h1>
            <p class="mt-2 text-sm text-muted">{{ $hint }} Order updates and receipts aren't affected.</p>
            <form method="POST" action="{{ request()->fullUrl() }}" class="mt-6">
                <button name="action" value="stop" class="btn-accent w-full">Stop these emails</button>
            </form>
        @endif

        @auth
            <a href="{{ route('marketing.preferences') }}" class="mt-6 inline-block text-xs text-fg underline underline-offset-2">Manage all email preferences</a>
        @endauth
    </div>
</div>
@endsection
