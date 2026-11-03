@extends('layouts.admin')

@section('title', 'Coupons')

@section('content')
<x-admin.header title="Coupons" subtitle="Discounts across the site. You can also switch off a seller's coupon if it breaks policy.">
    <x-admin.action :url="route('admin.coupons.store')" label="New coupon" tone="primary" icon="plus" confirm="Create coupon" body="Pan Motive pays for this discount, not the seller.">
        <div><label class="label">Code</label><input name="code" class="input text-sm uppercase" minlength="3" maxlength="30" required></div>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label">Type</label><select name="type" class="input text-sm"><option value="percentage">Percent off</option><option value="fixed">Dollars off</option></select></div>
            <div><label class="label">Value</label><input type="number" step="0.01" min="0.01" name="value" class="input text-sm" required></div>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label">Minimum order ($)</label><input type="number" step="0.01" min="0" name="min_order_total" class="input text-sm"></div>
            <div><label class="label">Largest discount ($)</label><input type="number" step="0.01" min="0" name="max_discount_amount" class="input text-sm"></div>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label">Total uses</label><input type="number" min="1" name="usage_limit" class="input text-sm" placeholder="No limit"></div>
            <div><label class="label">Uses per buyer</label><input type="number" min="1" name="usage_limit_per_user" value="1" class="input text-sm"></div>
        </div>
        <div><label class="label">Only for category</label><select name="category_id" class="input text-sm"><option value="">Everything</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label">Starts</label><input type="date" name="starts_at" class="input text-sm"></div>
            <div><label class="label">Ends</label><input type="date" name="expires_at" class="input text-sm"></div>
        </div>
    </x-admin.action>
</x-admin.header>

<x-admin.tabs :tabs="$tabs" param="owner" :current="$owner" />
<x-admin.filters :search="request('q', '')" placeholder="Coupon code"><input type="hidden" name="owner" value="{{ $owner }}"></x-admin.filters>

<x-admin.card flush>
    @if ($coupons->isEmpty())
        <x-admin.empty icon="tag" title="No coupons here" />
    @else
        <table class="adm-table">
            <thead><tr><th>Code</th><th>Discount</th>@if ($owner === 'seller')<th>Store</th>@endif<th>Status</th><th class="num">Used</th><th>Ends</th><th></th></tr></thead>
            <tbody>
                @foreach ($coupons as $c)
                    @php($expired = $c->expires_at && $c->expires_at->isPast())
                    <tr>
                        <td class="font-mono font-semibold">{{ $c->code }}</td>
                        <td>{{ $c->type === 'percentage' ? rtrim(rtrim(number_format((float) $c->value, 2), '0'), '.').'% off' : '$'.number_format((float) $c->value, 2).' off' }}
                            @if ((float) $c->min_order_total > 0)<p class="text-xs text-muted">over ${{ number_format((float) $c->min_order_total, 2) }}</p>@endif</td>
                        @if ($owner === 'seller')<td class="text-muted">{{ $c->seller?->sellerProfile?->business_name ?? '—' }}</td>@endif
                        <td><x-admin.status :value="$expired ? 'expired' : ($c->is_active ? 'active' : 'off')" :label="$expired ? 'Expired' : ($c->is_active ? 'Active' : 'Disabled')" /></td>
                        <td class="num">{{ number_format((int) $c->times_used) }}{{ $c->usage_limit ? ' / '.number_format($c->usage_limit) : '' }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $c->expires_at?->format('M j, Y') ?? 'Never' }}</td>
                        <td class="text-right">
                            <x-admin.action :url="route('admin.coupons.toggle', $c)" :label="$c->is_active ? 'Disable' : 'Enable'" size="xs" :tone="$c->is_active ? 'danger' : 'default'" :title="($c->is_active ? 'Disable ' : 'Enable ').$c->code" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $coupons->links() }}</div>
@endsection
