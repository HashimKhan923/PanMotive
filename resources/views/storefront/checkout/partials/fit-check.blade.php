{{-- Fit check before paying: which parts the Fit Guarantee covers for the buyer's vehicle. --}}
<div class="card p-5" data-reveal>
    <div class="flex items-start gap-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full {{ $fit['vehicle'] && $fit['covered'] === $fit['total'] ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">
            <x-ui.icon name="{{ $fit['vehicle'] && $fit['covered'] === $fit['total'] ? 'shield' : 'info' }}" class="h-5 w-5" />
        </span>
        <div class="min-w-0">
            @if (! $fit['vehicle'])
                <p class="font-semibold">Check the fit before you pay</p>
                <p class="mt-0.5 text-sm text-muted">Add your vehicle and parts listed for it are covered by the <a href="{{ route('fit-guarantee') }}" class="font-medium text-fg underline underline-offset-2">Panmotive Fit Guarantee</a>.</p>
                <a href="{{ route('home') }}#garage" class="btn-outline btn-sm mt-3">Add your vehicle</a>
            @elseif ($fit['covered'] === $fit['total'])
                <p class="font-semibold">Everything fits your {{ $fit['vehicle'] }}</p>
                <p class="mt-0.5 text-sm text-muted">{{ $fit['total'] === 1 ? 'This part is' : 'All '.$fit['total'].' parts are' }} covered by the <a href="{{ route('fit-guarantee') }}" class="font-medium text-fg underline underline-offset-2">Panmotive Fit Guarantee</a>. If it doesn't fit, you get a full refund and free return shipping.</p>
            @else
                <p class="font-semibold">{{ $fit['covered'] }} of {{ $fit['total'] }} parts confirmed for your {{ $fit['vehicle'] }}</p>
                <p class="mt-0.5 text-sm text-muted">Parts marked "Fit not confirmed" aren't listed for your vehicle, so the <a href="{{ route('fit-guarantee') }}" class="font-medium text-fg underline underline-offset-2">Fit Guarantee</a> doesn't cover them. Check with the seller if you're not sure.</p>
                <a href="{{ route('home') }}#garage" class="mt-2 inline-block text-xs font-semibold text-fg underline underline-offset-2">Shopping for a different vehicle?</a>
            @endif
        </div>
    </div>
</div>
