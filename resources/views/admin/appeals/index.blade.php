@extends('layouts.admin')

@section('title', 'Policy & appeals')

@section('content')
<x-admin.header title="Policy & appeals" subtitle="Violations on seller accounts, and appeals waiting on a decision." />
<x-admin.tabs :tabs="$tabs" :current="$tab" />

@if ($violations->isEmpty())
    <x-admin.card><x-admin.empty icon="shield" title="Nothing here" text="No violations match this tab." /></x-admin.card>
@else
    <div class="space-y-3">
        @foreach ($violations as $v)
            <x-admin.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold">{{ $v->title }}</p>
                            <x-admin.status :value="$v->status" />
                            <span class="text-xs capitalize text-muted">{{ $v->severity }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-muted">
                            @if ($v->seller?->sellerProfile)
                                <a href="{{ route('admin.sellers.show', $v->seller->sellerProfile) }}" class="hover:text-accent">{{ $v->seller->sellerProfile->business_name }}</a> ·
                            @endif
                            issued {{ $v->created_at->format('M j, Y') }}
                        </p>
                        @if ($v->description)<p class="mt-2 text-[13px] text-muted">{{ $v->description }}</p>@endif
                        @if ($v->appeal_text)
                            <div class="mt-3 rounded-xl bg-surface-2 p-3 text-[13px]"><p class="mb-1 text-xs font-semibold text-muted">Seller's appeal</p>{{ $v->appeal_text }}</div>
                        @endif
                    </div>
                    @if ($v->appeal_submitted_at && ! $v->appeal_decided_at)
                        <div class="flex items-center gap-2">
                            <x-admin.action :url="route('admin.appeals.decide', $v)" label="Lift violation" tone="primary" body="It stops counting against the store."><input type="hidden" name="decision" value="lift"></x-admin.action>
                            <x-admin.action :url="route('admin.appeals.decide', $v)" label="Uphold" tone="danger" body="The appeal is refused and the violation stays."><input type="hidden" name="decision" value="uphold"></x-admin.action>
                        </div>
                    @endif
                </div>
            </x-admin.card>
        @endforeach
    </div>
    <div class="mt-4">{{ $violations->links() }}</div>
@endif
@endsection
