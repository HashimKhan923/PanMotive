@php
    $announcement = config('marketplace.platform.announcement');
    $until = config('marketplace.platform.announcement_until');
    $live = filled($announcement) && (blank($until) || now()->lt(\Illuminate\Support\Carbon::parse($until)));
    $tone = ['accent' => 'bg-accent text-accent-fg', 'info' => 'bg-brand text-brand-fg', 'warning' => 'bg-warning-bg text-amber-600', 'danger' => 'bg-rose-600 text-white'][config('marketplace.platform.announcement_tone')] ?? 'bg-accent text-accent-fg';
@endphp
@if ($live)
    <div class="{{ $tone }} px-4 py-2 text-center text-xs font-semibold sm:text-sm">
        @if ($link = config('marketplace.platform.announcement_link'))
            <a href="{{ $link }}" class="underline-offset-2 hover:underline">{{ $announcement }}</a>
        @else
            {{ $announcement }}
        @endif
    </div>
@endif
@if (session('error'))
    <div class="bg-rose-600 px-4 py-2 text-center text-xs font-semibold text-white sm:text-sm" role="alert">{{ session('error') }}</div>
@endif
