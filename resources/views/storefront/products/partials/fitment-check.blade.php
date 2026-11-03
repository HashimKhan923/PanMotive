@php
    $garage = app(\App\Services\GarageService::class);
    $garageId = $garage->vehicleId();
    $fitsGarage = $garageId && $product->fitments->contains('id', $garageId);
    $hasFitments = $product->fitments->isNotEmpty();
@endphp

<div class="mt-5 rounded-2xl border border-line bg-surface p-4">
    @if ($garageId)
        @if ($fitsGarage)
            <p class="stamp-in inline-flex items-center gap-2 rounded-lg border-2 border-emerald-500/60 px-2.5 py-1 text-sm font-extrabold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">
                <x-ui.icon name="check" class="w-4 h-4" /> Fits your {{ $garage->label() }}
            </p>
        @elseif ($hasFitments)
            <p class="inline-flex items-center gap-2 text-sm font-semibold text-rose-500">
                <x-ui.icon name="x" class="w-4 h-4" /> Not listed as a fit for your {{ $garage->label() }}
            </p>
            <p class="mt-1 text-xs text-muted">Double-check the fitment table below or ask the seller before buying.</p>
        @else
            <p class="text-sm text-muted">The seller hasn't published fitment data — confirm compatibility with your {{ $garage->label() }} before ordering.</p>
        @endif
        <a href="{{ route('home') }}#garage" class="mt-1 inline-block text-xs text-accent hover:underline">Change vehicle</a>
    @else
        <p class="text-sm font-medium">Will this fit your vehicle?</p>
        <p class="mt-1 text-xs text-muted">Set your vehicle to see an instant fit check on every part.</p>
        <a href="{{ route('home') }}#garage" class="btn-outline btn-sm mt-2">Select your vehicle</a>
    @endif
</div>
