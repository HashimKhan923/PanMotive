<?php

namespace App\Http\Controllers\Api\Shop;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\BuyerClaim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Services\Buyer\OrderActionRefused;
use App\Services\Buyer\OrderActions;
use App\Services\FitGuarantee;
use App\Services\MessagingService;
use App\Services\Payments\CheckoutPaymentService;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The buyer's orders in the app: list, detail + tracking timeline, pay now, delivery, returns, claims. */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('buyer_id', $request->user()->id)->with('items.product.media')->latest()
            ->paginate(15, ['*'], 'page', (int) $request->input('page', 1));

        return response()->json([
            'orders' => $orders->getCollection()->map(fn (Order $o) => [
                'number' => $o->order_number,
                'status' => $o->status->value,
                'status_label' => $o->status === OrderStatus::Placed ? 'Awaiting payment' : $o->status->label(),
                'total' => round((float) $o->total, 2),
                'placed_at' => ($o->placed_at ?? $o->created_at)?->toIso8601String(),
                'items_count' => (int) $o->items->sum('quantity'),
                'images' => $o->items->take(4)->map(fn ($i) => $i->product?->getFirstMediaUrl('images', 'thumb') ?: null)->filter()->values(),
                'progress' => self::progress($o),
            ])->values(),
            'meta' => \App\Http\Resources\Shop\ShopPresenter::page($orders),
        ]);
    }

    public function show(Request $request, string $number, ReviewService $reviews): JsonResponse
    {
        $order = $this->orderFor($request, $number);
        $order->load('items.product.media', 'items.seller.sellerProfile', 'items.statusHistory', 'items.returnRequests.events');
        $user = $request->user();

        return response()->json(['order' => [
            'number' => $order->order_number,
            'status' => $order->status->value,
            'status_label' => $order->status === OrderStatus::Placed ? 'Awaiting payment' : $order->status->label(),
            'placed_at' => ($order->placed_at ?? $order->created_at)?->toIso8601String(),
            'pay_by' => $order->status === OrderStatus::Placed
                ? $order->created_at->copy()->addHours((int) config('marketplace.unpaid_order_hours', 48))->toIso8601String() : null,
            'totals' => [
                'subtotal' => round((float) $order->subtotal, 2),
                'shipping' => round((float) $order->shipping_total, 2),
                'tax' => round((float) $order->tax_total, 2),
                'discount' => round((float) $order->discount_total, 2),
                'credits' => round((float) $order->credits_used, 2),
                'total' => round((float) $order->total, 2),
            ],
            'ship_to' => $order->shipping_address,
            'progress' => self::progress($order),
            'items' => $order->items->map(function (OrderItem $item) use ($user, $reviews) {
                $return = $item->returnRequests->sortByDesc('id')->first(fn ($r) => in_array($r->status, ['requested', 'approved', 'received'], true))
                    ?? $item->returnRequests->sortByDesc('id')->first();
                $claim = BuyerClaim::where('order_item_id', $item->id)->latest('id')->first();
                $status = $item->status->value;
                $delivered = in_array($status, ['delivered', 'payout_released'], true);

                return [
                    'id' => $item->id,
                    'title' => $item->product_title,
                    'variant' => $item->variant_label,
                    'slug' => $item->product?->slug,
                    'product_id' => $item->product_id,
                    'image' => $item->product?->getFirstMediaUrl('images', 'thumb') ?: null,
                    'quantity' => (int) $item->quantity,
                    'line_total' => round((float) $item->line_total, 2),
                    'status' => $status,
                    'status_label' => $item->status->label(),
                    'seller' => $item->seller?->sellerProfile ? ['name' => $item->seller->sellerProfile->business_name, 'slug' => $item->seller->sellerProfile->slug] : null,
                    'tracking' => $item->tracking_number ? [
                        'carrier' => $item->carrier, 'number' => $item->tracking_number, 'url' => $item->trackingUrl(),
                        'shipped_at' => $item->shipped_at?->toIso8601String(), 'delivered_at' => $item->delivered_at?->toIso8601String(),
                    ] : null,
                    'timeline' => $item->statusHistory->sortBy('created_at')->map(fn ($h) => [
                        'status' => $h->status,
                        'label' => OrderStatus::tryFrom((string) $h->status)?->label() ?? ucfirst(str_replace('_', ' ', (string) $h->status)),
                        'note' => $h->note,
                        'at' => $h->created_at?->toIso8601String(),
                    ])->values(),
                    'fit' => $item->fit_vehicle_label ? ['vehicle' => $item->fit_vehicle_label, 'guaranteed' => (bool) $item->fit_confirmed] : null,
                    'return' => $return ? [
                        'id' => $return->id, 'rma' => $return->rma_number, 'status' => $return->status, 'reason' => $return->reason,
                        'resolution' => $return->resolution_note, 'fit_guarantee' => (bool) $return->fit_guarantee,
                        'escalated' => $return->escalated_at !== null,
                        'can_escalate' => $return->escalated_at === null && in_array($return->status, ['requested', 'approved'], true),
                        'events' => $return->events->map(fn ($e) => [
                            'event' => $e->event, 'by' => $e->actor_role, 'note' => $e->note, 'at' => $e->created_at?->toIso8601String(),
                        ])->values(),
                    ] : null,
                    'claim' => $claim ? ['number' => $claim->claim_number, 'status' => $claim->status, 'reason' => BuyerClaim::REASONS[$claim->reason] ?? $claim->reason] : null,
                    'can' => [
                        'confirm_delivery' => in_array($status, ['shipped', 'out_for_delivery'], true),
                        'return' => $delivered && ! ($return && in_array($return->status, ['requested', 'approved', 'received'], true)) && $item->isWithinReturnWindow(),
                        'claim' => in_array($status, OrderActions::CLAIM_ELIGIBLE, true) && ! ($claim && in_array($claim->status, ['open', 'seller_responded', 'escalated'], true)),
                        'review' => $user && $item->product ? $delivered && $reviews->canReview($user, $item->product) : false,
                        'message_seller' => (bool) $item->seller_id && (bool) $user,
                    ],
                    'return_deadline' => $item->returnDeadline()?->toIso8601String(),
                    'return_reasons' => $delivered ? OrderActions::returnReasons($item) : [],
                ];
            })->values(),
            'claim_reasons' => collect(BuyerClaim::REASONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'fit_guarantee_reason' => FitGuarantee::REASON,
        ]]);
    }

    /** Polled by the app after the payment sheet, until Stripe has confirmed the payment. Guests send their access key. */
    public function status(Request $request, string $number): JsonResponse
    {
        $order = $this->orderFor($request, $number);

        return response()->json([
            'number' => $order->order_number,
            'status' => $order->status->value,
            'paid' => $order->status !== OrderStatus::Placed && $order->status !== OrderStatus::Cancelled,
            'total' => round((float) $order->total, 2),
        ]);
    }

    /** Pay for an order that's still awaiting payment (e.g. a Subscribe & Save order, or a payment sheet that was closed). */
    public function pay(Request $request, string $number, CheckoutPaymentService $payments): JsonResponse
    {
        $order = $this->orderFor($request, $number);
        if ($order->status !== OrderStatus::Placed) {
            return response()->json(['message' => $order->status === OrderStatus::Cancelled ? 'This order was cancelled.' : 'This order is already paid.'], 422);
        }

        try {
            $intent = $payments->intentFor($order);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Card payments aren\'t available right now — please try again shortly.'], 503);
        }

        return response()->json(['payment' => [
            'client_secret' => $intent->client_secret,
            'publishable_key' => config('services.stripe.key'),
            'merchant_name' => config('app.name'),
            'amount' => (int) round($order->total * 100),
        ]]);
    }

    public function confirmDelivery(Request $request, OrderItem $orderItem, OrderActions $actions): JsonResponse
    {
        $this->own($request, $orderItem);

        return $this->run(fn () => $actions->confirmDelivery($orderItem, $request->user()), 'Delivery confirmed — thanks!');
    }

    public function requestReturn(Request $request, OrderItem $orderItem, OrderActions $actions): JsonResponse
    {
        $this->own($request, $orderItem);
        $data = $request->validate(['reason' => 'required|string|max:120', 'detail' => 'nullable|string|max:2000']);

        return $this->run(function () use ($actions, $orderItem, $request, $data) {
            $rma = $actions->requestReturn($orderItem, $request->user(), $data['reason'], $data['detail'] ?? null);

            return "Return started — your RMA number is {$rma->rma_number}.";
        });
    }

    public function escalateReturn(Request $request, ReturnRequest $returnRequest, OrderActions $actions): JsonResponse
    {
        abort_unless($returnRequest->user_id === $request->user()->id, 403);
        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        return $this->run(fn () => $actions->escalateReturn($returnRequest, $request->user(), $data['note'] ?? null),
            'Escalated — Panmotive support will review within 1 business day.');
    }

    public function claim(Request $request, OrderItem $orderItem, OrderActions $actions): JsonResponse
    {
        $this->own($request, $orderItem);
        $data = $request->validate([
            'reason' => 'required|in:'.implode(',', array_keys(BuyerClaim::REASONS)),
            'detail' => 'required|string|max:2000',
        ]);

        return $this->run(function () use ($actions, $orderItem, $request, $data) {
            $claim = $actions->fileClaim($orderItem, $request->user(), $data['reason'], $data['detail']);

            return "Claim {$claim->claim_number} filed. The seller has 72 hours to respond before Panmotive steps in.";
        });
    }

    /** Open (or reuse) a message thread with the seller of one line, tied to this order. */
    public function contactSeller(Request $request, OrderItem $orderItem, MessagingService $messaging): JsonResponse
    {
        $this->own($request, $orderItem);
        abort_unless($orderItem->seller, 404);
        $conversation = $messaging->threadFor($request->user(), $orderItem->seller, null, $orderItem->order);

        return response()->json(['conversation_id' => $conversation->id]);
    }

    /** Guest order lookup by order number + email, like the website's "Track an order". */
    public function track(Request $request): JsonResponse
    {
        $data = $request->validate(['number' => 'required|string|max:40', 'email' => 'required|email']);
        $order = Order::where('order_number', strtoupper(trim($data['number'])))->first();
        $email = strtolower(trim($data['email']));

        $matches = $order && (strtolower((string) $order->guest_email) === $email || strtolower((string) $order->buyer?->email) === $email);
        if (! $matches) {
            return response()->json(['message' => 'We couldn\'t find an order with that number and email.'], 404);
        }

        return response()->json(['number' => $order->order_number, 'access' => CartController::guestAccess($order)]);
    }

    /** 0–4: placed → paid → shipped → delivered (for the progress bar). */
    private static function progress(Order $order): int
    {
        $statuses = $order->items->map(fn ($i) => $i->status->value);

        return match (true) {
            $order->status === OrderStatus::Cancelled => 0,
            $statuses->isNotEmpty() && $statuses->every(fn ($s) => in_array($s, ['delivered', 'payout_released', 'return_requested', 'return_approved', 'refunded'], true)) => 4,
            $statuses->contains(fn ($s) => in_array($s, ['shipped', 'out_for_delivery'], true)) => 3,
            $order->status !== OrderStatus::Placed => 2,
            default => 1,
        };
    }

    /** The order for its buyer — or, for a guest, with the access key returned at checkout / by track. */
    private function orderFor(Request $request, string $number): Order
    {
        $order = Order::where('order_number', $number)->firstOrFail();
        $user = $request->user();
        $key = (string) $request->header('X-Order-Access', $request->query('access', ''));

        $allowed = ($user && $order->buyer_id === $user->id)
            || ($key !== '' && hash_equals(CartController::guestAccess($order), $key));
        abort_unless($allowed, 404);

        return $order;
    }

    private function own(Request $request, OrderItem $item): void
    {
        abort_unless($item->order && $item->order->buyer_id === $request->user()->id, 403);
    }

    private function run(callable $action, ?string $message = null): JsonResponse
    {
        try {
            $result = $action();
        } catch (OrderActionRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]], 422);
        }

        return response()->json(['message' => is_string($result) ? $result : $message]);
    }
}
