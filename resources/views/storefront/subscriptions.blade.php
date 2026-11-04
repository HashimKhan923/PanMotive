@extends('layouts.storefront')
@section('title', 'Subscribe & Save')

@section('content')
<div class="container-x max-w-3xl py-10">
    <h1 class="text-2xl font-bold">Subscribe &amp; Save</h1>
    <p class="mt-1 text-sm text-muted">Recurring deliveries for the parts and fluids you reorder. Skip, pause, or cancel anytime.</p>

    <div class="mt-6 space-y-4">
        @forelse ($subscriptions as $sub)
            <div class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('products.show', $sub->product->slug) }}" class="font-semibold hover:text-accent">{{ $sub->product->title }}</a>
                            @if ($sub->variant)<span class="block text-xs text-muted">{{ $sub->variant->label }}</span>@endif
                            <p class="mt-1 text-xs text-muted">
                                Qty {{ $sub->quantity }} · {{ $sub->intervalLabel() }} ·
                                <span class="font-semibold text-accent">{{ rtrim(rtrim(number_format($sub->discount_pct, 2), '0'), '.') }}% off</span>
                                (${{ number_format($sub->unitPrice(), 2) }}/ea)
                            </p>
                        </div>
                    </div>
                    <span class="pill {{ ['active' => '!bg-emerald-500/15 !text-emerald-600', 'paused' => '!bg-amber-500/15 !text-amber-600', 'cancelled' => '!bg-surface-2 !text-muted'][$sub->status] }}">
                        {{ ucfirst($sub->status) }}
                    </span>
                </div>

                @if ($sub->status !== 'cancelled')
                    <div class="mt-3 border-t border-line pt-3 text-xs text-muted">
                        @if ($sub->status === 'active')
                            Next delivery {{ optional($sub->next_run_at)->format('M j, Y') ?? 'soon' }}
                        @else
                            Paused — resume to restart deliveries
                        @endif
                        · {{ $sub->orders_count }} {{ Str::plural('order', $sub->orders_count) }} so far
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @if ($sub->status === 'active')
                            <form method="POST" action="{{ route('subscriptions.update', $sub) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="skip"><button class="btn-outline btn-sm">Skip next</button></form>
                            <form method="POST" action="{{ route('subscriptions.update', $sub) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="pause"><button class="btn-ghost btn-sm">Pause</button></form>
                        @else
                            <form method="POST" action="{{ route('subscriptions.update', $sub) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="resume"><button class="btn-accent btn-sm">Resume</button></form>
                        @endif

                        <form method="POST" action="{{ route('subscriptions.update', $sub) }}" class="flex items-center gap-2">
                            @csrf @method('PATCH')
                            <input type="hidden" name="action" value="schedule">
                            <select name="interval_days" onchange="this.form.submit()" class="input h-8 w-auto py-0 text-xs">
                                @foreach (\App\Models\Subscription::INTERVALS as $days => $label)
                                    <option value="{{ $days }}" @selected($sub->interval_days === $days)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </form>

                        <form method="POST" action="{{ route('subscriptions.update', $sub) }}" class="ml-auto" onsubmit="return confirm('Cancel this subscription?')">
                            @csrf @method('PATCH')<input type="hidden" name="action" value="cancel">
                            <button class="text-xs font-semibold text-rose-500 hover:underline">Cancel</button>
                        </form>
                    </div>
                @endif
            </div>
        @empty
            <div class="card grid place-items-center p-16 text-center">
                <x-ui.icon name="refresh" class="w-12 h-12 text-muted" />
                <p class="mt-3 font-semibold">No subscriptions yet</p>
                <p class="mt-1 text-sm text-muted">Look for "Subscribe &amp; Save" on parts you buy regularly — brake fluid, filters, wipers.</p>
                <a href="{{ route('products.index') }}" class="btn-accent btn-sm mt-4">Browse parts</a>
            </div>
        @endforelse
    </div>
</div>
@endsection
