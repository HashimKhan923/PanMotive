@extends('layouts.admin')

@section('title', 'Questions')

@section('content')
<x-admin.header title="Questions" subtitle="Public questions on product pages." />
<x-admin.tabs :tabs="$tabs" :current="$tab" />
<x-admin.filters :search="request('q', '')" placeholder="Words in the question"><input type="hidden" name="status" value="{{ $tab }}"></x-admin.filters>

@if ($questions->isEmpty())
    <x-admin.card><x-admin.empty icon="help" title="Nothing here" /></x-admin.card>
@else
    <div class="space-y-3">
        @foreach ($questions as $q)
            <x-admin.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">{{ $q->body }}</p>
                        <p class="mt-1 text-xs text-muted">
                            {{ $q->author_name ?? 'Shopper' }} on
                            @can('catalog.manage')<a href="{{ route('admin.products.show', $q->product_id) }}" class="hover:text-accent">{{ $q->product?->title }}</a>@else{{ $q->product?->title }}@endcan
                            · {{ $q->created_at->format('M j, Y') }}
                        </p>
                        @foreach ($q->answers as $a)
                            <div class="mt-2 rounded-xl bg-surface-2 p-3 text-[13px]"><p class="mb-0.5 text-xs font-semibold text-muted">{{ $a->is_seller ? 'Seller' : ($a->author_name ?? 'Shopper') }}</p>{{ $a->body }}</div>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-2">
                        <x-admin.status :value="$q->status === 'rejected' ? 'removed' : $q->status" />
                        @if ($q->status === 'published')
                            <x-admin.action :url="route('admin.questions.decide', $q)" label="Remove" size="xs" tone="danger"><input type="hidden" name="decision" value="remove"></x-admin.action>
                        @else
                            <x-admin.action :url="route('admin.questions.decide', $q)" label="Publish" size="xs" tone="primary"><input type="hidden" name="decision" value="publish"></x-admin.action>
                        @endif
                    </div>
                </div>
            </x-admin.card>
        @endforeach
    </div>
    <div class="mt-4">{{ $questions->links() }}</div>
@endif
@endsection
