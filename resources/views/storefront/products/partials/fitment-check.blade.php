@php
    $garage = app(\App\Services\GarageService::class);
    $garageId = $garage->vehicleId();
    $fitsGarage = $garageId && $product->fitments->contains('id', $garageId);
    $hasFitments = $product->fitments->isNotEmpty();
@endphp

<div class="mt-5 rounded-2xl border border-line bg-surface p-4">
    @if ($garageId)
        @if ($fitsGarage)
            <p class="stamp-in inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-sm font-semibold text-emerald-600">
                <x-ui.icon name="check" class="w-4 h-4" /> Fits your {{ $garage->label() }}
            </p>
            <p class="mt-2 flex items-start gap-1.5 text-xs text-muted">
                <x-ui.icon name="shield" class="mt-px h-4 w-4 shrink-0 text-emerald-600" />
                <span><a href="{{ route('fit-guarantee') }}" class="font-semibold text-fg underline underline-offset-2">Panmotive Fit Guarantee:</a> the seller listed this part for your vehicle. If it doesn't fit, you get a full refund and free return shipping.</span>
            </p>
        @else
            <p class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 px-3 py-1 text-sm font-semibold text-amber-600">
                <x-ui.icon name="info" class="w-4 h-4" /> Compatibility not confirmed
            </p>
            <p class="mt-1.5 text-xs text-muted">{{ $hasFitments ? 'This part is not listed for your '.$garage->label().'.' : 'The seller has not listed vehicles for this part.' }} Contact the seller before ordering.</p>
        @endif
        <a href="{{ route('home') }}#garage" class="mt-2 inline-block text-xs font-semibold text-fg underline underline-offset-2">Change vehicle</a>
    @else
        <p class="text-sm font-semibold">Check the listed vehicle compatibility before ordering.</p>
        <p class="mt-1 text-xs text-muted">Select your vehicle to compare it with the seller's list. Parts listed for your vehicle are covered by the <a href="{{ route('fit-guarantee') }}" class="font-medium text-fg underline underline-offset-2">Fit Guarantee</a>.</p>
        <a href="{{ route('home') }}#garage" class="btn-outline btn-sm mt-2">Select your vehicle</a>
    @endif
</div>
