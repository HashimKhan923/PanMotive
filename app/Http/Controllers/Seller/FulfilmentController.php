<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Fulfilment\LabelService;
use Illuminate\Http\Request;

class FulfilmentController extends Controller
{
    /** Today's work: accepted, not-yet-shipped items grouped by order. */
    public function pickList()
    {
        $items = OrderItem::where('seller_id', auth()->id())
            ->where('status', OrderStatus::SellerAccepted->value)
            ->with('order:id,order_number,shipping_address,placed_at,created_at', 'product:id,title,sku,part_number')
            ->orderBy('order_id')
            ->get()
            ->groupBy('order_id');

        return view('seller.fulfilment.pick-list', ['orders' => $items]);
    }

    public function packingSlip(Order $order)
    {
        $items = $this->sellerItems($order);
        abort_if($items->isEmpty(), 403);

        return view('seller.fulfilment.packing-slip', compact('order', 'items'));
    }

    public function labelForm(Order $order, LabelService $labels)
    {
        $items = $this->sellerItems($order)->where('status', OrderStatus::SellerAccepted)->values();
        abort_if($items->isEmpty(), 404);

        $profile = auth()->user()->sellerProfile;
        $quote = null;
        $error = null;

        if (LabelService::available() && $profile->hasShipFrom()) {
            try {
                $quote = $labels->quote($order, $items, $profile);
                // Remember which shipment belongs to this order, so the buy step can't be pointed elsewhere.
                session(["label_shipment.{$order->id}" => $quote['shipment_id']]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $error = $e->validator->errors()->first();
            }
        }

        return view('seller.fulfilment.label', [
            'order' => $order,
            'items' => $items,
            'weight' => $labels->weightLbs($items),
            'available' => LabelService::available(),
            'hasShipFrom' => $profile->hasShipFrom(),
            'quote' => $quote,
            'error' => $error,
        ]);
    }

    public function buyLabel(Request $request, Order $order, LabelService $labels)
    {
        $data = $request->validate(['rate_id' => 'required|string|max:64']);
        $items = $this->sellerItems($order)->where('status', OrderStatus::SellerAccepted)->values();
        $shipmentId = session("label_shipment.{$order->id}");
        abort_unless($shipmentId, 419, 'Rates expired — reload the label page.');

        $label = $labels->buy($items, $shipmentId, $data['rate_id']);
        session()->forget("label_shipment.{$order->id}");

        return redirect()->route('seller.orders.show', $items->first())
            ->with('success', "Label bought — {$label->carrier} {$label->tracking_number}, \$".number_format((float) $label->rate, 2).' taken from this order\'s payout.'
                .($label->savings() > 0 ? ' You saved $'.number_format($label->savings(), 2).' vs retail.' : ''))
            ->with('label_url', $label->label_url)
            ->with('celebrate', true);
    }

    private function sellerItems(Order $order)
    {
        return $order->items()->where('seller_id', auth()->id())->with('product:id,title,sku,part_number,weight_lbs')->get();
    }
}
