@props(['order', 'reasons' => false])
{{-- Order risk from Panmotive's own checks (App\Services\AI\FraudScoringService). Sellers see the
     level and what to do; admins also see why. --}}
@php
    $risk = \App\Support\OrderInsight::risk($order);
    $fill = ['low' => 'w-1/4 bg-emerald-500', 'medium' => 'w-3/5 bg-amber-500', 'high' => 'w-full bg-rose-500'][$risk['level']];
@endphp
<div {{ $attributes }}>
    <p class="text-sm">{{ $risk['advice'] }}</p>
    <div class="mt-3 h-2 overflow-hidden rounded-full bg-surface-2" role="img" aria-label="Risk: {{ $risk['label'] }}">
        <div class="h-full rounded-full {{ $fill }} transition-[width] duration-700"></div>
    </div>
    <div class="mt-1.5 flex justify-between text-xs text-muted">
        @foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $key => $label)
            <span class="{{ $risk['level'] === $key ? 'font-semibold text-fg' : '' }}">{{ $label }}</span>
        @endforeach
    </div>
    @if ($reasons)
        <ul class="mt-3 space-y-1 text-xs text-muted">
            @forelse ($risk['reasons'] as $why)
                <li class="flex gap-1.5"><x-ui.icon name="alert" class="w-4 h-4 shrink-0 text-amber-600" /> {{ $why }}</li>
            @empty
                <li class="flex gap-1.5"><x-ui.icon name="check" class="w-4 h-4 shrink-0 text-emerald-600" /> No warning signs. Score {{ $risk['score'] }}/100.</li>
            @endforelse
        </ul>
    @endif
</div>
