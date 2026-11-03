@extends('layouts.admin')

@section('title', 'Products')

@section('content')
<x-admin.header title="Products" subtitle="Every listing from every seller. Review new ones and take down anything against policy." />

<x-admin.tabs :tabs="$tabs" :current="$status" />

<x-admin.filters :search="request('q', '')" placeholder="Title, SKU or part number">
    @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
    <select name="category" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="Category">
        <option value="">All categories</option>
        @foreach ($categories as $c)<option value="{{ $c->id }}" @selected((string) request('category') === (string) $c->id)>{{ $c->name }}</option>@endforeach
    </select>
    @if (request('quality'))<input type="hidden" name="quality" value="{{ request('quality') }}">@endif
    <select name="stock" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="Stock">
        <option value="">Any stock</option>
        <option value="in" @selected(request('stock') === 'in')>In stock</option>
        <option value="out" @selected(request('stock') === 'out')>Out of stock</option>
    </select>
</x-admin.filters>

<div x-data="{ picked: [] }">
    <div x-show="picked.length" x-cloak x-transition class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-accent/40 bg-accent/10 px-4 py-2 text-[13px]">
        <span class="font-semibold"><span x-text="picked.length"></span> selected</span>
        <span class="flex-1"></span>
        <template x-for="d in [['approve', 'Approve'], ['reject', 'Reject'], ['remove', 'Remove']]" :key="d[0]">
            <button type="button" class="h-8 rounded-lg border border-line bg-surface px-3 text-xs font-semibold hover:border-accent/50"
                    x-text="d[1]" @click="$refs.bulkDecision.value = d[0]; $refs.bulkReason.required = d[0] !== 'approve'; $refs.bulk.showModal()"></button>
        </template>
    </div>

    <dialog x-ref="bulk" class="w-full max-w-md rounded-2xl border border-line bg-surface p-6 text-fg backdrop:bg-black/60">
        <form method="POST" action="{{ route('admin.products.bulk') }}">
            @csrf
            <input type="hidden" name="decision" x-ref="bulkDecision">
            <template x-for="id in picked" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <h3 class="text-base font-bold">Change <span x-text="picked.length"></span> listings</h3>
            <label class="label mt-4" for="bulk-why">Reason (sellers see this when a listing is taken down)</label>
            <textarea id="bulk-why" x-ref="bulkReason" name="reason" rows="3" maxlength="255" class="input text-sm"></textarea>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" @click="$refs.bulk.close()" class="h-9 rounded-lg px-3.5 text-[13px] font-semibold text-muted hover:bg-surface-2">Cancel</button>
                <button class="btn-accent btn-sm">Confirm</button>
            </div>
        </form>
    </dialog>

    <x-admin.card flush>
        @if ($products->isEmpty())
            <x-admin.empty icon="box" title="No listings match" text="Try another tab or search." />
        @else
            <table class="adm-table">
                <thead><tr><th class="w-8"><span class="sr-only">Select</span></th><th>Listing</th><th>Seller</th><th>Category</th><th>Status</th><th class="num">Stock</th><th class="num">Price</th></tr></thead>
                <tbody>
                    @foreach ($products as $p)
                        <tr>
                            <td><input type="checkbox" value="{{ $p->id }}" x-model.number="picked" class="rounded text-accent focus:ring-accent" aria-label="Select {{ $p->title }}"></td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <img src="{{ $p->getMedia('images')->first()?->getUrl('thumb') ?: asset('pwa-192x192.png') }}" alt="" loading="lazy" class="h-10 w-10 shrink-0 rounded-lg bg-surface-2 object-cover">
                                    <div class="min-w-0 max-w-[340px]">
                                        <a href="{{ route('admin.products.show', $p) }}" class="block truncate font-medium hover:text-accent">{{ $p->title }}</a>
                                        <p class="truncate text-xs text-muted">{{ $p->brand?->name ?? 'No brand' }}{{ $p->part_number ? ' · '.$p->part_number : '' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap text-muted">{{ $p->seller?->sellerProfile?->business_name ?? '—' }}</td>
                            <td class="whitespace-nowrap text-muted">{{ $p->category?->name }}</td>
                            <td><x-admin.status :value="$p->status === 'archived' ? 'removed' : $p->status" /></td>
                            <td class="num">{{ number_format((int) $p->quantity) }}</td>
                            <td class="num">${{ number_format($p->effectivePrice(), 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-admin.card>
</div>
<div class="mt-4">{{ $products->links() }}</div>
@endsection
