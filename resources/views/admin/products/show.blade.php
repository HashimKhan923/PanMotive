@extends('layouts.admin')

@section('title', $product->title)

@section('content')
<x-admin.header :title="$product->title" :subtitle="($product->seller?->sellerProfile?->business_name ?? 'Unknown seller').' · '.($product->category?->name ?? 'No category')" :back="route('admin.products.index')">
    <x-admin.status :value="$product->status === 'archived' ? 'removed' : $product->status" />
    @if ($product->status === 'active')
        <a href="{{ \App\Support\AdminNav::site('products.show', $product->slug) }}" target="_blank" rel="noopener" class="btn-outline btn-sm">View on site <x-ui.icon name="external" class="w-3.5 h-3.5" /></a>
    @else
        <x-admin.action :url="route('admin.products.moderate', $product)" label="Approve & publish" tone="primary" body="This listing goes live for shoppers.">
            <input type="hidden" name="decision" value="approve">
        </x-admin.action>
    @endif
    @if ($product->status !== 'rejected')
        <x-admin.action :url="route('admin.products.moderate', $product)" label="Reject" reason reason-label="Reason (the seller will see this)" body="The listing is hidden and the seller is told why.">
            <input type="hidden" name="decision" value="reject">
        </x-admin.action>
    @endif
    @if ($product->status !== 'archived')
        <x-admin.action :url="route('admin.products.moderate', $product)" label="Remove listing" tone="danger" reason reason-label="Reason (the seller will see this)" body="Use for counterfeit or prohibited items. Past orders are not affected." confirm="Remove">
            <input type="hidden" name="decision" value="remove">
        </x-admin.action>
    @endif
</x-admin.header>

@if ($product->moderation_note)
    <div class="mb-4 flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
        <x-ui.icon name="alert" class="w-4 h-4 shrink-0" /> Staff note: {{ $product->moderation_note }}
    </div>
@endif

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-enter>
    <x-admin.stat label="Price shoppers pay" :value="$product->effectivePrice()" prefix="$" :decimals="2" icon="dollar" :hint="$product->isOnDeal() ? 'On sale, normally $'.number_format((float) $product->price, 2) : 'Regular price'" />
    <x-admin.stat label="In stock" :value="(int) $product->quantity" icon="archive" />
    <x-admin.stat label="Units sold" :value="$sold" icon="chart" />
    <x-admin.stat label="Views" :value="(int) $product->views_count" icon="search" />
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        <x-admin.card title="Photos">
            @if ($product->media->isEmpty())
                <p class="text-sm text-muted">No photos.</p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach ($product->media as $m)
                        <img src="{{ $m->getUrl('thumb') }}" alt="" loading="lazy" class="h-24 w-24 rounded-xl bg-surface-2 object-cover">
                    @endforeach
                </div>
            @endif
        </x-admin.card>
        <x-admin.card title="Description">
            <x-ui.rich-text :content="$product->description" class="text-sm" />
        </x-admin.card>
    </div>

    <div class="space-y-4">
        <x-admin.card title="Filing" subtitle="Price and stock stay the seller's to change.">
            <form method="POST" action="{{ route('admin.products.update', $product) }}" class="space-y-3">
                @csrf @method('PATCH')
                <div><label class="label" for="p-title">Title</label><input id="p-title" name="title" value="{{ old('title', $product->title) }}" maxlength="255" class="input text-sm" required></div>
                <div><label class="label" for="p-cat">Category</label>
                    <select id="p-cat" name="category_id" class="input text-sm">
                        @foreach ($categories as $c)<option value="{{ $c->id }}" @selected($product->category_id === $c->id)>{{ $c->parent ? $c->parent->name.' › ' : '' }}{{ $c->name }}</option>@endforeach
                    </select></div>
                <div><label class="label" for="p-brand">Brand</label>
                    <select id="p-brand" name="brand_id" class="input text-sm">
                        <option value="">No brand</option>
                        @foreach ($brands as $b)<option value="{{ $b->id }}" @selected($product->brand_id === $b->id)>{{ $b->name }}</option>@endforeach
                    </select></div>
                <button class="btn-accent btn-sm w-full">Save</button>
            </form>
        </x-admin.card>

        <x-admin.card title="Details">
            <dl class="space-y-2 text-[13px]">
                <div class="flex justify-between gap-3"><dt class="text-muted">Condition</dt><dd class="capitalize">{{ $product->condition }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">SKU</dt><dd>{{ $product->sku ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Part number</dt><dd>{{ $product->part_number ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">OEM number</dt><dd>{{ $product->oem_number ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Fits</dt><dd>{{ number_format($product->fitments->count()) }} vehicles</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Listed</dt><dd>{{ $product->created_at->format('M j, Y') }}</dd></div>
                @if ($product->seller?->sellerProfile)
                    <div class="flex justify-between gap-3"><dt class="text-muted">Seller</dt><dd>@can('sellers.approve')<a href="{{ route('admin.sellers.show', $product->seller->sellerProfile) }}" class="hover:text-accent">{{ $product->seller->sellerProfile->business_name }}</a>@else{{ $product->seller->sellerProfile->business_name }}@endcan</dd></div>
                @endif
            </dl>
        </x-admin.card>

        @if ($history->isNotEmpty())
            <x-admin.card title="Admin history">
                <ul class="space-y-2.5 text-[13px]">
                    @foreach ($history as $h)
                        <li><p class="font-medium">{{ $h->description }}</p><p class="text-xs text-muted">{{ $h->causer?->name ?? 'System' }} · {{ $h->created_at->diffForHumans() }}</p></li>
                    @endforeach
                </ul>
            </x-admin.card>
        @endif
    </div>
</div>
@endsection
