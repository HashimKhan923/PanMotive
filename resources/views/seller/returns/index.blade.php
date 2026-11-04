@extends('layouts.seller')

@section('title', 'Returns')

@section('content')
<div>
    <h1 class="text-2xl font-bold">Returns</h1>
    <p class="mt-1 text-sm text-muted">Approve a return to send the buyer your instructions, mark it received when it arrives, then refund. Respond within 48 hours or it escalates to Panmotive.</p>

    @if ($errors->has('return'))
        <div class="mt-4 rounded-xl border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-400">{{ $errors->first('return') }}</div>
    @endif

    {{-- insights --}}
    <div class="mt-6 grid gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Open</p><p class="mt-1 text-2xl font-bold">{{ $openCount }}</p>
            @if ($overdueCount)<p class="text-xs font-semibold text-rose-600">{{ $overdueCount }} past the 48h deadline</p>@else<p class="text-xs text-muted">none overdue</p>@endif</div>
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Return rate (90 days)</p>
            <p class="mt-1 text-2xl font-bold {{ $returnRate > 5 ? 'text-amber-600' : '' }}">{{ $returnRate }}%</p><p class="text-xs text-muted">of items sold</p></div>
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Top reasons</p>
            <ul class="mt-1 space-y-0.5 text-sm">
                @forelse ($topReasons as $reason => $n)<li class="flex justify-between gap-2"><span class="truncate">{{ $reason }}</span><span class="text-muted">{{ $n }}</span></li>
                @empty<li class="text-muted">—</li>@endforelse
            </ul></div>
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Most returned</p>
            <ul class="mt-1 space-y-0.5 text-sm">
                @forelse ($topProducts as $title => $n)<li class="flex justify-between gap-2"><span class="truncate">{{ $title }}</span><span class="text-muted">{{ $n }}</span></li>
                @empty<li class="text-muted">—</li>@endforelse
            </ul></div>
    </div>

    <div class="mt-6 flex gap-1 border-b border-line text-sm">
        @foreach (['open' => 'Open', 'escalated' => 'Escalated', 'closed' => 'Closed', 'all' => 'All'] as $key => $label)
            <a href="{{ route('seller.returns.index', ['status' => $key]) }}"
               class="-mb-px border-b-2 px-3 py-2 font-medium transition {{ $filter === $key ? 'border-accent text-accent' : 'border-transparent text-muted hover:text-fg' }}">
                {{ $label }} @if ($key === 'open' && $openCount)<span class="ml-1 rounded-full bg-accent px-1.5 text-xs text-accent-fg">{{ $openCount }}</span>@endif
            </a>
        @endforeach
    </div>

    <div class="mt-4 space-y-3">
        @forelse ($returns as $rma)
            @php($s = $rma->status)
            <div class="card p-5" id="rma-{{ $rma->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $rma->rma_number }}</p>
                        <p class="text-sm text-muted">
                            {{ $rma->orderItem->product_title }}@if ($rma->orderItem->variant_label) — {{ $rma->orderItem->variant_label }}@endif
                            × {{ $rma->orderItem->quantity }} ·
                            <a href="{{ route('seller.orders.show', $rma->orderItem) }}" class="hover:text-accent">{{ $rma->orderItem->order->order_number }}</a>
                            @if ($rma->orderItem->order->buyer) · {{ $rma->orderItem->order->buyer->name }} @endif
                        </p>
                        <p class="mt-1 text-sm"><span class="text-muted">Reason:</span> {{ $rma->reason }}</p>
                        @if ($rma->detail) <p class="mt-1 text-sm text-muted">"{{ $rma->detail }}"</p> @endif
                        @if ($rma->resolution_note) <p class="mt-1 text-xs text-muted">Your note: {{ $rma->resolution_note }}</p> @endif
                    </div>
                    <div class="text-right">
                        <span class="pill capitalize {{ match ($s) { 'refunded' => '!bg-emerald-500/10 !text-emerald-600', 'rejected' => '!bg-rose-500/10 !text-rose-600', 'requested' => '!bg-amber-500/15 !text-amber-700 dark:!text-amber-400', default => '' } }}">{{ $s }}</span>
                        @php($h = $rma->hoursToRespond())
                        @if ($rma->escalated_at)
                            <p class="mt-1 text-xs font-semibold text-rose-500">Escalated {{ $rma->escalated_at->diffForHumans() }} · Panmotive reviewing</p>
                        @elseif ($h !== null)
                            <p class="mt-1 text-xs font-semibold {{ $h <= 0 ? 'text-rose-500' : ($h <= 12 ? 'text-amber-600' : 'text-muted') }}">
                                {{ $h <= 0 ? 'Overdue — escalating to Panmotive' : "Respond within {$h}h" }}
                            </p>
                        @endif
                        @if (in_array($s, ['requested', 'approved', 'received'], true))
                            <p class="mt-1 text-xs text-muted">Refund would be ${{ number_format($refundPreview($rma), 2) }}</p>
                        @endif
                    </div>
                </div>

                {{-- timeline --}}
                <ol class="mt-3 space-y-1 border-t border-line pt-3 text-xs text-muted">
                    @foreach ($rma->events as $e)
                        <li class="flex flex-wrap gap-x-2">
                            <span class="font-medium capitalize text-fg">{{ $e->event }}</span>
                            <span>· {{ ucfirst($e->actor_role) }}{{ $e->actor ? ' ('.$e->actor->name.')' : '' }}</span>
                            <span>· {{ $e->created_at->format('M j, g:i A') }}</span>
                            @if ($e->note) <span>— {{ $e->note }}</span> @endif
                        </li>
                    @endforeach
                </ol>

                @if (in_array($s, ['requested', 'approved', 'received'], true) && \App\Support\SellerContext::can('returns.manage'))
                    <form method="POST" action="{{ route('seller.returns.update', $rma) }}" class="mt-4 space-y-3 border-t border-line pt-4">
                        @csrf
                        <div>
                            <label class="label" for="note-{{ $rma->id }}">
                                @if ($s === 'requested') Return instructions or reason <span class="text-muted">(required to decline)</span>
                                @else Note to buyer <span class="text-muted">(optional)</span> @endif
                            </label>
                            <textarea id="note-{{ $rma->id }}" name="note" rows="2" maxlength="1000" class="input text-sm"
                                      placeholder="{{ $s === 'requested' ? 'e.g. Ship it back to 12 Industrial Way, Dallas TX 75201 in the original box. Or: why you can\'t accept it.' : '' }}"></textarea>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($s === 'requested')
                                <button name="decision" value="approve" class="btn-accent btn-sm">Approve return</button>
                                <button name="decision" value="refund_without_return" class="btn-outline btn-sm"
                                        onclick="return confirm('Refund now and let the buyer keep the item? Best for low-value parts where return shipping costs more than the item.')">Refund — no return needed</button>
                                <button name="decision" value="reject" class="btn-ghost btn-sm text-muted hover:text-rose-600">Decline</button>
                            @elseif ($s === 'approved')
                                <label class="mr-2 flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="restock" value="1" checked class="rounded border-line text-accent focus:ring-accent">
                                    Put it back in stock
                                </label>
                                <button name="decision" value="receive" class="btn-accent btn-sm">Mark received</button>
                                <button name="decision" value="refund" class="btn-outline btn-sm"
                                        onclick="return confirm('Refund without marking the item received?')">Refund now</button>
                            @elseif ($s === 'received')
                                <button name="decision" value="refund" class="btn-accent btn-sm"
                                        onclick="return confirm('Refund ${{ number_format($refundPreview($rma), 2) }} to the buyer?')">Issue refund</button>
                            @endif
                        </div>
                    </form>
                @endif
            </div>
        @empty
            <div class="card grid place-items-center p-16 text-center">
                <x-ui.icon name="refresh" class="w-12 h-12 text-muted" />
                <p class="mt-3 font-semibold">No returns here</p>
                <p class="mt-1 text-sm text-muted">Return requests from buyers will appear in this queue.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $returns->links() }}</div>
</div>
@endsection
