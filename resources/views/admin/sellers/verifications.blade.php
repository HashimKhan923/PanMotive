@extends('layouts.admin')

@section('title', 'Identity checks')

@section('content')
<x-admin.header title="Identity checks" subtitle="Documents sellers have submitted. Approve or reject each one." />

@if ($verifications->isEmpty())
    <x-admin.card><x-admin.empty icon="shield" title="Nothing waiting for review" text="New submissions appear here." /></x-admin.card>
@else
    <div class="space-y-3">
        @foreach ($verifications as $v)
            <x-admin.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $v->legal_name }}</p>
                        <p class="mt-0.5 text-xs text-muted">
                            {{ $v->seller?->sellerProfile?->business_name }} · {{ $v->seller?->email }} ·
                            {{ strtoupper($v->tax_id_type) }} ending {{ $v->tax_id_last4 }} ·
                            submitted {{ optional($v->submitted_at)->format('M j, Y') }}
                        </p>
                        <p class="mt-1 text-xs text-muted">{{ $v->registered_address }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['business' => ['business_doc_path', 'Business document'], 'id' => ['id_doc_path', 'Photo ID'], 'address' => ['address_proof_path', 'Address proof']] as $type => [$col, $label])
                                @if ($v->$col)
                                    <a href="{{ route('admin.verifications.document', [$v, $type]) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-full border border-line px-2.5 py-0.5 text-[11px] font-semibold hover:border-accent hover:text-accent"><x-ui.icon name="external" class="w-4 h-4" /> View {{ strtolower($label) }}</a>
                                @else
                                    <x-admin.status value="off" :label="$label.' missing'" />
                                @endif
                            @endforeach
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-admin.action :url="route('admin.verifications.review', $v)" label="Approve" tone="primary" body="Confirm the documents match this business.">
                            <input type="hidden" name="decision" value="approve">
                        </x-admin.action>
                        <x-admin.action :url="route('admin.verifications.review', $v)" label="Reject" tone="danger" reason reason-label="Reason (the seller will see this)">
                            <input type="hidden" name="decision" value="reject">
                        </x-admin.action>
                    </div>
                </div>
            </x-admin.card>
        @endforeach
    </div>
    <div class="mt-4">{{ $verifications->links() }}</div>
@endif
@endsection
