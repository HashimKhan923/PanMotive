@extends('layouts.admin')

@section('title', 'Reviews')

@section('content')
<x-admin.header title="Reviews" subtitle="Sellers can report a review but can't remove one. You make that call here." />
<x-admin.tabs :tabs="$tabs" :current="$tab" />
<x-admin.filters :search="request('q', '')" placeholder="Words in the review">
    <input type="hidden" name="status" value="{{ $tab }}">
    <select name="rating" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="Rating">
        <option value="">Any rating</option>
        @foreach ([5, 4, 3, 2, 1] as $r)<option value="{{ $r }}" @selected((string) request('rating') === (string) $r)>{{ $r }} stars</option>@endforeach
    </select>
</x-admin.filters>

@if ($reviews->isEmpty())
    <x-admin.card><x-admin.empty icon="star" title="Nothing here" text="No reviews match this tab." /></x-admin.card>
@else
    <div class="space-y-3">
        @foreach ($reviews as $r)
            <x-admin.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.rating :value="$r->rating" />
                            <span class="text-xs font-semibold tabular-nums">{{ $r->rating }} of 5</span>
                            <x-admin.status :value="$r->status === 'rejected' ? 'removed' : $r->status" />
                            @if ($r->reported_at)<x-admin.status value="escalated" label="Reported" />@endif
                        </div>
                        @if ($r->title)<p class="mt-2 text-sm font-semibold">{{ $r->title }}</p>@endif
                        <p class="mt-1 text-[13px] text-muted">{{ $r->body }}</p>
                        <p class="mt-2 text-xs text-muted">
                            {{ $r->user?->name ?? 'Unknown' }} on
                            @can('catalog.manage')<a href="{{ route('admin.products.show', $r->product_id) }}" class="hover:text-accent">{{ $r->product?->title }}</a>@else{{ $r->product?->title }}@endcan
                            · {{ $r->created_at->format('M j, Y') }}{{ $r->is_verified ? ' · Verified purchase' : '' }}
                        </p>
                        @if ($r->reported_at)
                            <div class="mt-3 rounded-xl bg-surface-2 p-3 text-[13px]">
                                <p class="text-xs font-semibold text-muted">Seller's report: {{ str_replace('_', ' ', (string) $r->report_reason) }}</p>
                                @if ($r->report_note)<p class="mt-1">{{ $r->report_note }}</p>@endif
                            </div>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($r->status === 'rejected')
                            <x-admin.action :url="route('admin.reviews.decide', $r)" label="Restore" size="xs" body="It shows on the product again and counts towards the rating."><input type="hidden" name="decision" value="restore"></x-admin.action>
                        @else
                            @if ($r->reported_at)
                                <x-admin.action :url="route('admin.reviews.decide', $r)" label="Keep review" size="xs" tone="primary" body="The report is closed and the review stays."><input type="hidden" name="decision" value="keep"></x-admin.action>
                            @endif
                            <x-admin.action :url="route('admin.reviews.decide', $r)" label="Remove" size="xs" tone="danger" body="It stops showing and the product rating is recalculated."><input type="hidden" name="decision" value="remove"></x-admin.action>
                        @endif
                    </div>
                </div>
            </x-admin.card>
        @endforeach
    </div>
    <div class="mt-4">{{ $reviews->links() }}</div>
@endif
@endsection
