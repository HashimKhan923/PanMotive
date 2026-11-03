<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Jobs\ReleaseSellerPayout;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\BuyerRefundService;
use App\Services\Orders\OrderCancellationService;
use App\Services\Orders\OrderStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderController extends Controller
{
    use AdminTools;

    public function index(Request $request)
    {
        $status = $request->query('status', '');
        $counts = Order::selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        $tabs = ['' => ['All', $counts->sum()]];
        foreach (OrderStatus::cases() as $case) {
            if (($counts[$case->value] ?? 0) > 0) {
                $tabs[$case->value] = [$case->label(), $counts[$case->value]];
            }
        }

        return view('admin.orders.index', [
            'orders' => $this->filtered($request)->with('buyer:id,name,email', 'items:id,order_id,seller_id,status')->withCount('items')
                ->latest()->paginate(25)->withQueryString(),
            'status' => $status,
            'tabs' => $tabs,
        ]);
    }

    public function export(Request $request)
    {
        $orders = $this->filtered($request)->with('buyer:id,name,email')->latest()->limit(20000)->get();

        return response()->streamDownload(function () use ($orders) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Order', 'Date', 'Buyer', 'Email', 'Status', 'Subtotal', 'Shipping', 'Discount', 'Credit used', 'Paid by card']);
            foreach ($orders as $o) {
                fputcsv($out, array_map(fn ($v) => $this->csvCell($v), [
                    $o->order_number, $o->created_at->toDateString(), $o->buyer?->name ?? $o->guest_name, $o->buyer?->email ?? $o->guest_email,
                    $o->status->value, $o->subtotal, $o->shipping_total, $o->discount_total, $o->credits_used, $o->total,
                ]));
            }
            fclose($out);
        }, 'orders-'.now()->format('Y-m-d').'.csv');
    }

    private function filtered(Request $request)
    {
        return Order::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->filled('seller'), fn ($q) => $q->whereHas('items', fn ($i) => $i->where('seller_id', $request->integer('seller'))))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('order_number', 'like', $this->like($request->q))
                ->orWhere('guest_email', 'like', $this->like($request->q))
                ->orWhereHas('buyer', fn ($b) => $b->where('email', 'like', $this->like($request->q))->orWhere('name', 'like', $this->like($request->q)))
                ->orWhereHas('items', fn ($i) => $i->where('tracking_number', 'like', $this->like($request->q)))))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')));
    }

    public function show(Order $order, BuyerRefundService $refunds)
    {
        $order->load('buyer', 'items.seller.sellerProfile', 'items.statusHistory', 'items.returnRequests', 'items.product:id,slug');

        return view('admin.orders.show', [
            'order' => $order,
            'paidFor' => $order->items->mapWithKeys(fn ($i) => [$i->id => $refunds->paidForLine($i)]),
            'timeline' => $order->items->flatMap->statusHistory->sortByDesc('created_at')->values(),
            'cancelReasons' => OrderCancellationService::REASONS,
        ]);
    }

    public function note(Request $request, Order $order)
    {
        $data = $request->validate(['order_item_id' => 'required|integer', 'note' => 'required|string|max:1000']);
        $item = $order->items()->findOrFail($data['order_item_id']);

        $item->statusHistory()->create(['status' => 'staff_note', 'note' => $data['note'], 'changed_by' => $request->user()->id]);

        return back()->with('success', 'Note added.');
    }

    public function cancelLine(Request $request, OrderItem $orderItem, OrderCancellationService $cancellations)
    {
        abort_unless($request->user()->can('refunds.issue'), 403);

        $data = $request->validate(['reason' => 'required|string', 'note' => 'nullable|string|max:500']);

        try {
            $refunded = $cancellations->cancel($orderItem, $data['reason'], trim('By Pan Motive staff. '.($data['note'] ?? '')), $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        $this->audit('Cancelled order line', $orderItem, $data + ['refunded' => $refunded]);

        return back()->with('success', 'Line cancelled'.($refunded > 0 ? ' and $'.number_format($refunded, 2).' refunded.' : '.'));
    }

    /**
     * Refund what the buyer paid for a line, split the way they paid. If the seller was already paid,
     * that payout is pulled back first. Stock is put back only if it hadn't shipped.
     */
    public function refundLine(Request $request, OrderItem $orderItem, BuyerRefundService $refunds, OrderStockService $stock)
    {
        abort_unless($request->user()->can('refunds.issue'), 403);

        $data = $request->validate(['reason' => 'required|string|max:500', 'include_shipping' => 'nullable|boolean']);

        if (in_array($orderItem->status, [OrderStatus::Refunded, OrderStatus::Cancelled, OrderStatus::Placed], true)) {
            return back()->withErrors(['refund' => 'This line has nothing left to refund.']);
        }

        $wasUnshipped = in_array($orderItem->status, OrderStatus::cancellable(), true);

        try {
            $amount = DB::transaction(function () use ($orderItem, $data, $refunds, $request, $stock, $wasUnshipped) {
                $amount = $refunds->refundLine($orderItem, 'admin', "Refund from Pan Motive on order {$orderItem->order->order_number}", (bool) ($data['include_shipping'] ?? false));

                $orderItem->update(['status' => OrderStatus::Refunded]);
                $orderItem->statusHistory()->create([
                    'status' => OrderStatus::Refunded->value,
                    'note' => 'Refunded $'.number_format($amount, 2).' by Pan Motive staff: '.$data['reason'],
                    'changed_by' => $request->user()->id,
                ]);
                $orderItem->returnRequests()->whereNotIn('status', ['refunded', 'rejected'])->update([
                    'status' => 'refunded', 'resolved_by' => $request->user()->id, 'resolved_at' => now(),
                    'resolution_note' => 'Refunded by Pan Motive staff.',
                ]);
                if ($wasUnshipped) {
                    $stock->release($orderItem, "Order {$orderItem->order->order_number} refunded by staff");
                }

                return $amount;
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        $orderItem->order->syncStatusFromLines();
        $this->audit('Refunded order line', $orderItem, $data + ['amount' => $amount]);
        $this->tellBuyer($orderItem->order, 'Your refund is on its way', 'We refunded $'.number_format($amount, 2)." for {$orderItem->product_title} on order {$orderItem->order->order_number}. Card refunds usually take 5 to 10 business days.");
        $this->tell($orderItem->seller, 'A line on your order was refunded', "Pan Motive refunded {$orderItem->product_title} on order {$orderItem->order->order_number}. Reason: {$data['reason']}.");

        return back()->with('success', '$'.number_format($amount, 2).' refunded to the buyer.');
    }

    /** For a carrier that never reported delivery: starts the normal payout, same as auto-confirm. */
    public function markDelivered(Request $request, OrderItem $orderItem)
    {
        abort_unless($request->user()->can('disputes.resolve'), 403);

        $data = $request->validate(['reason' => 'required|string|max:500']);

        if (! in_array($orderItem->status, OrderStatus::deliverable(), true)) {
            return back()->withErrors(['deliver' => 'Only shipped lines can be marked delivered.']);
        }

        $orderItem->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);
        $orderItem->statusHistory()->create(['status' => OrderStatus::Delivered->value, 'note' => 'Marked delivered by staff: '.$data['reason'], 'changed_by' => $request->user()->id]);

        app(\App\Services\Integrations\WebhookDispatcher::class)->orderEvent('order.delivered', [$orderItem->fresh('order')]);
        ReleaseSellerPayout::dispatch($orderItem->id);
        $this->audit('Marked line delivered', $orderItem, $data);
        $this->tell($orderItem->seller, 'Order marked as delivered', "Pan Motive marked {$orderItem->product_title} on order {$orderItem->order->order_number} as delivered, so your payout has started.");

        return back()->with('success', 'Marked delivered. The seller payout has started.');
    }
}
