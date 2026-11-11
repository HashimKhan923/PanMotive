<div class="container-x max-w-2xl py-10">
    <h1 class="text-2xl font-bold sm:text-3xl">Email &amp; marketing preferences</h1>
    <p class="mt-1 text-sm text-muted">Choose which emails we send you. Order updates, receipts, refunds, account and security emails always arrive.</p>

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-muted">Panmotive updates</h2>
    <div class="card mt-3 divide-y divide-line">
        @foreach ($emailTypes as $key => $type)
            @php($on = ! in_array($key, $emailOff, true))
            <div class="flex items-center justify-between gap-4 p-5" wire:key="email-{{ $key }}">
                <div>
                    <p class="font-semibold">{{ $type['label'] }}</p>
                    <p class="text-sm text-muted">{{ $type['hint'] }}</p>
                </div>
                <button wire:click="toggleEmail('{{ $key }}')" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}"
                        class="relative h-6 w-11 shrink-0 rounded-full transition {{ $on ? 'bg-accent' : 'bg-line' }}">
                    <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition {{ $on ? 'left-[22px]' : 'left-0.5' }}"></span>
                    <span class="sr-only">{{ $type['label'] }} emails</span>
                </button>
            </div>
        @endforeach
    </div>

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-muted">Store marketing</h2>
    <div class="card mt-3 divide-y divide-line">
        <div class="flex items-center justify-between gap-4 p-5">
            <div>
                <p class="font-semibold">Store promotions by email</p>
                <p class="text-sm text-muted">Sales, new arrivals and offers from stores you've bought from or follow.</p>
            </div>
            <button wire:click="toggleAll" role="switch" aria-checked="{{ $allOff ? 'false' : 'true' }}"
                    class="relative h-6 w-11 shrink-0 rounded-full transition {{ $allOff ? 'bg-line' : 'bg-accent' }}">
                <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition {{ $allOff ? 'left-0.5' : 'left-[22px]' }}"></span>
                <span class="sr-only">Store promotions by email</span>
            </button>
        </div>

        <div class="p-5">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="font-semibold">Text messages</p>
                    <p class="text-sm text-muted">Occasional deals by SMS. Msg &amp; data rates may apply. Reply STOP to opt out.</p>
                </div>
                <button wire:click="toggleSms" role="switch" aria-checked="{{ $smsOn ? 'true' : 'false' }}"
                        class="relative h-6 w-11 shrink-0 rounded-full transition {{ $smsOn ? 'bg-accent' : 'bg-line' }}">
                    <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition {{ $smsOn ? 'left-[22px]' : 'left-0.5' }}"></span>
                    <span class="sr-only">Text messages</span>
                </button>
            </div>
            @unless ($smsOn)
                <input wire:model="phone" class="input mt-3 h-10 text-sm sm:w-64" placeholder="Mobile number" aria-label="Mobile number">
                @error('phone') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            @endunless
        </div>

        @if ($stores->isNotEmpty())
            <div class="p-5">
                <p class="font-semibold">Stores you've unsubscribed from</p>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($stores as $s)
                        <li class="flex items-center justify-between gap-3" wire:key="u-{{ $s->id }}">
                            <span>{{ $s->seller?->sellerProfile?->business_name ?? $s->seller?->name }}</span>
                            <button wire:click="resubscribe({{ $s->seller_id }})" class="text-xs font-medium text-accent hover:underline">Resubscribe</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>
