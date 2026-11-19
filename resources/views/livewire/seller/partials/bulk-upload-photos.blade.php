{{-- Photo download progress for a bulk import (photos land on their own queue after each product is made). --}}
@php
    $pDone = min($photos['done'], $photos['total']);
    $pTotal = $photos['total'];
    $pPct = $pTotal > 0 ? (int) floor(100 * $pDone / $pTotal) : 0;
    $pending = $pTotal > 0 && $pDone < $pTotal;
@endphp
@if ($pTotal > 0)
    <div class="mt-4 rounded-lg bg-surface-2 px-3 py-2.5" @if ($result && $pending) wire:poll.3s="refreshProgress" @endif>
        <div class="flex items-center justify-between gap-3 text-xs">
            <span class="font-medium {{ $pending ? 'text-fg' : 'text-emerald-600 dark:text-emerald-400' }}">
                @if ($pending)
                    Photos: {{ number_format($pDone) }} of {{ number_format($pTotal) }} saved
                @else
                    All {{ number_format($pTotal) }} photos saved
                @endif
            </span>
            <span class="tabular-nums text-muted">{{ $pPct }}%</span>
        </div>
        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-line" role="progressbar" aria-label="Photo download progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pPct }}">
            <div class="h-full rounded-full bg-emerald-500 transition-[width] duration-700 ease-out" style="width: {{ $pPct }}%"></div>
        </div>
        @if ($pending)
            <p class="mt-1.5 text-xs text-muted">Photos keep downloading in the background even if you leave this page. Please don't delete these products until they're done.</p>
        @endif
    </div>
@endif
