{{-- Seller cancel dialog. Expects $item (OrderItem) and $cancelReasons. Open with: document.getElementById('cancel-{{ $item->id }}').showModal() --}}
@php($paid = $item->status->value !== 'placed')
<dialog id="cancel-{{ $item->id }}" class="card w-full max-w-md p-6 text-left backdrop:bg-black/50">
    <form method="POST" action="{{ route('seller.orders.cancel', $item) }}" class="space-y-4">
        @csrf
        <div>
            <h3 class="font-semibold">Cancel {{ $item->product_title }}?</h3>
            <p class="mt-1 text-sm text-muted">
                Order {{ $item->order->order_number }} ·
                @if ($paid)
                    the buyer is refunded automatically and the stock goes back on your shelf.
                @else
                    payment hasn't cleared, so nothing is charged.
                @endif
            </p>
        </div>
        <div>
            <label class="label" for="cr-{{ $item->id }}">Reason</label>
            <select id="cr-{{ $item->id }}" name="reason" class="input" required>
                <option value="">Choose a reason…</option>
                @foreach ($cancelReasons as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-muted">Cancellations for reasons other than "buyer asked" or "suspected fraud" count toward your cancellation rate.</p>
        </div>
        <div>
            <label class="label" for="cn-{{ $item->id }}">Note to buyer <span class="text-muted">(optional)</span></label>
            <textarea id="cn-{{ $item->id }}" name="note" rows="2" maxlength="500" class="input" placeholder="e.g. Sorry — our last unit was damaged in the warehouse."></textarea>
        </div>
        @if (($siblingCount ?? 0) > 0)
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="cancel_all" value="1" class="rounded border-line text-accent">
                Cancel all {{ $siblingCount + 1 }} unshipped items from you on this order
            </label>
        @endif
        <div class="flex gap-2 pt-1">
            <button class="btn btn-sm flex-1 bg-rose-600 text-white hover:bg-rose-700">Cancel order</button>
            <button type="button" onclick="this.closest('dialog').close()" class="btn-ghost btn-sm">Keep order</button>
        </div>
    </form>
</dialog>
