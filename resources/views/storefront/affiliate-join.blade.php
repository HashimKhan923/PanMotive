@extends('layouts.storefront')
@section('title', 'Become an affiliate for ' . $sellerProfile->business_name)

@section('content')
<div class="container-x max-w-lg py-12">
    <div class="text-center" data-reveal>
        <span class="pill !bg-accent/15 !text-accent"><x-ui.icon name="sparkles" class="w-3.5 h-3.5" /> Affiliate program</span>
        <h1 class="mt-3 text-2xl font-bold">Earn {{ rtrim(rtrim(number_format($program->commission_pct, 2), '0'), '.') }}% promoting {{ $sellerProfile->business_name }}</h1>
        <p class="mt-1 text-sm text-muted">Get a tracked link, share it, and earn commission on every sale within {{ $program->cookie_days }} days of a click.</p>
    </div>

    <form method="POST" action="{{ route('affiliate.join', $sellerProfile) }}" class="card mt-8 space-y-4 p-6" data-reveal>
        @csrf
        <div>
            <label class="label" for="af-name">Name</label>
            <input id="af-name" name="name" value="{{ old('name', auth()->user()->name ?? '') }}" required class="input">
            @error('name') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="af-email">Email</label>
            <input id="af-email" name="email" type="email" value="{{ old('email', auth()->user()->email ?? '') }}" required class="input">
            @error('email') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="af-channel">Where will you promote? (optional)</label>
            <input id="af-channel" name="channel" value="{{ old('channel') }}" class="input" placeholder="YouTube, Instagram, forum, blog…">
        </div>
        @if ($program->terms)
            <details class="text-sm"><summary class="cursor-pointer text-accent">Program terms</summary><p class="mt-2 whitespace-pre-wrap text-muted">{{ $program->terms }}</p></details>
        @endif
        <button class="btn-accent w-full">{{ $program->auto_approve ? 'Get my link' : 'Apply' }}</button>
        @guest <p class="text-center text-xs text-muted"><a href="{{ route('login') }}" class="text-accent hover:underline">Log in</a> to track earnings in your account.</p> @endguest
    </form>
</div>
@endsection
