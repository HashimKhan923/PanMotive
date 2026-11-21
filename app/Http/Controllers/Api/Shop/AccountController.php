<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shop\ShopPresenter;
use App\Models\Affiliate;
use App\Models\AffiliateProgram;
use App\Models\AppPushToken;
use App\Models\Conversation;
use App\Models\MarketingUnsubscribe;
use App\Models\Message;
use App\Models\Offer;
use App\Models\Product;
use App\Models\SellerBlockedBuyer;
use App\Models\SellerProfile;
use App\Models\Subscription;
use App\Services\Cart\CartService;
use App\Services\Marketing\AffiliateService;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\ReferralService;
use App\Services\Marketing\StoreLoyaltyService;
use App\Services\Membership\MembershipService;
use App\Services\MessagingService;
use App\Services\Seller\OfferService;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\EmailPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The shopper's account in the app: messages with sellers, wallet, Panmotive Plus, Subscribe & Save,
 * offers, store points, affiliate links, email/SMS preferences, push notifications — and compare,
 * which works for guests too (the app keeps the list on the phone).
 */
class AccountController extends Controller
{
    // ---- Messages -------------------------------------------------------------------------------

    public function conversations(Request $request): JsonResponse
    {
        $list = Conversation::where('buyer_id', $request->user()->id)
            ->with(['seller.sellerProfile', 'product:id,title,slug', 'latestMessage'])
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')->get();

        return response()->json(['conversations' => $list->map(fn (Conversation $c) => [
            'id' => $c->id,
            'seller' => $c->seller?->sellerProfile ? ['name' => $c->seller->sellerProfile->business_name, 'slug' => $c->seller->sellerProfile->slug, 'logo' => $c->seller->sellerProfile->logoUrl()] : null,
            'subject' => $c->subject ?? $c->product?->title,
            'last' => $c->latestMessage->first()?->body,
            'last_at' => $c->last_message_at?->toIso8601String(),
            'unread' => (int) $c->buyer_unread,
        ])->values(), 'unread_total' => (int) $list->sum('buyer_unread')]);
    }

    public function conversation(Request $request, int $id): JsonResponse
    {
        $c = Conversation::where('buyer_id', $request->user()->id)
            ->with(['seller.sellerProfile', 'product:id,title,slug', 'order:id,order_number', 'messages.sender:id,name'])->findOrFail($id);
        $c->markReadFor('buyer');

        return response()->json(['conversation' => [
            'id' => $c->id,
            'seller' => $c->seller?->sellerProfile ? ['name' => $c->seller->sellerProfile->business_name, 'slug' => $c->seller->sellerProfile->slug, 'logo' => $c->seller->sellerProfile->logoUrl(), 'reply_time' => $c->seller->sellerProfile->responseLabel()] : null,
            'product' => $c->product ? ['title' => $c->product->title, 'slug' => $c->product->slug] : null,
            'order' => $c->order?->order_number,
            'blocked' => SellerBlockedBuyer::isBlocked($c->seller_id, $request->user()->id),
            'messages' => $c->messages->map(fn (Message $m) => [
                'id' => $m->id,
                'mine' => $m->sender_id === $request->user()->id,
                'body' => $m->body,
                'photos' => collect($m->attachmentLinks())->pluck('url')->values(),
                'at' => $m->created_at?->toIso8601String(),
                'read' => $m->read_at !== null,
            ])->values(),
        ]]);
    }

    public function reply(Request $request, int $id, MessagingService $messaging): JsonResponse
    {
        $request->validate([
            'body' => 'required_without:photos|nullable|string|max:4000',
            'photos' => 'array|max:'.config('marketplace.messages.max_attachments', 4),
            'photos.*' => 'image|max:5120',
        ], ['body.required_without' => 'Write a message or attach a photo.']);
        $c = Conversation::where('buyer_id', $request->user()->id)->findOrFail($id);

        $message = $messaging->send($c, $request->user(), (string) $request->input('body', ''), $request->file('photos', []));

        return response()->json(['id' => $message->id], 201);
    }

    /** Ask the seller about a product (opens or reuses the thread). */
    public function messageSeller(Request $request, int $productId, MessagingService $messaging): JsonResponse
    {
        $request->validate(['body' => 'required|string|min:2|max:4000']);
        $product = Product::listed()->with('seller')->findOrFail($productId);
        abort_if($product->seller_id === $request->user()->id, 422, 'This is your own listing.');

        $c = $messaging->threadFor($request->user(), $product->seller, $product);
        $messaging->send($c, $request->user(), (string) $request->body);

        return response()->json(['conversation_id' => $c->id], 201);
    }

    // ---- Wallet + Plus --------------------------------------------------------------------------

    public function wallet(Request $request, ReferralService $referrals): JsonResponse
    {
        $user = $request->user();
        $tx = $user->creditTransactions()->latest()->paginate(20, ['*'], 'page', (int) $request->input('page', 1));

        return response()->json([
            'balance' => round((float) $user->wallet_balance, 2),
            'referral_code' => $referrals->getOrCreateCode($user),
            'referral_link' => route('register', ['ref' => $referrals->getOrCreateCode($user)]),
            'transactions' => $tx->getCollection()->map(fn ($t) => [
                'type' => $t->type, 'amount' => round((float) $t->amount, 2), 'balance_after' => round((float) $t->balance_after, 2),
                'description' => $t->description, 'at' => $t->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ShopPresenter::page($tx),
        ]);
    }

    public function plus(Request $request, MembershipService $membership): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'price' => $membership->price(),
            'term_months' => $membership->termMonths(),
            'perks' => config('marketplace.plus.perks', []),
            'active' => $user ? $membership->isActive($user) : false,
            'until' => $user?->plus_until?->toIso8601String(),
            'auto_renew' => (bool) ($user?->plus_auto_renew ?? false),
            'wallet_balance' => $user ? round((float) $user->wallet_balance, 2) : null,
        ]);
    }

    public function joinPlus(Request $request, MembershipService $membership): JsonResponse
    {
        $request->validate(['auto_renew' => 'sometimes|boolean']);
        try {
            $until = $membership->join($request->user(), $request->boolean('auto_renew', true));
        } catch (\RuntimeException) {
            return response()->json(['message' => 'Not enough Panmotive credit to start membership. Top up your wallet first.'], 422);
        }

        return response()->json(['message' => 'Welcome to Panmotive Plus! Active through '.$until->format('M j, Y').'.', 'until' => $until->toIso8601String()]);
    }

    public function cancelPlus(Request $request, MembershipService $membership): JsonResponse
    {
        $membership->cancelAutoRenew($request->user());

        return response()->json(['message' => 'Auto-renew turned off. Your membership stays active until it expires.']);
    }

    // ---- Subscribe & Save -----------------------------------------------------------------------

    public function subscriptions(Request $request): JsonResponse
    {
        $subs = Subscription::where('buyer_id', $request->user()->id)->with(['product.media', 'variant', 'seller.sellerProfile'])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")->orderBy('next_run_at')->get();

        return response()->json([
            'subscriptions' => $subs->map(fn (Subscription $s) => [
                'id' => $s->id, 'status' => $s->status, 'quantity' => (int) $s->quantity,
                'interval_days' => (int) $s->interval_days, 'interval' => Subscription::INTERVALS[$s->interval_days] ?? "Every {$s->interval_days} days",
                'discount_pct' => (float) $s->discount_pct, 'next_run_at' => $s->next_run_at?->toIso8601String(),
                'orders_count' => (int) $s->orders_count,
                'product' => $s->product ? ['title' => $s->product->title, 'slug' => $s->product->slug, 'image' => $s->product->getFirstMediaUrl('images', 'thumb') ?: null] : null,
                'variant' => $s->variant?->label,
                'seller' => $s->seller?->sellerProfile?->business_name,
            ])->values(),
            'intervals' => collect(Subscription::INTERVALS)->map(fn ($l, $d) => ['days' => $d, 'label' => $l])->values(),
        ]);
    }

    public function subscribe(Request $request, SubscriptionService $service): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'quantity' => 'required|integer|min:1|max:20',
            'interval_days' => 'required|integer|in:'.implode(',', array_keys(Subscription::INTERVALS)),
        ]);
        $product = Product::listed()->findOrFail($data['product_id']);
        abort_unless($product->subscription_enabled, 422, 'This seller does not offer Subscribe & Save on this part.');

        if (Subscription::where('buyer_id', $request->user()->id)->where('product_id', $product->id)->where('status', '!=', 'cancelled')->exists()) {
            return response()->json(['message' => 'You already have a subscription for this part — manage it in Subscriptions.'], 422);
        }

        $service->create($request->user(), $product, [
            'product_variant_id' => $data['variant_id'] ?? null,
            'quantity' => $data['quantity'],
            'interval_days' => $data['interval_days'],
            'discount_pct' => (float) $product->subscription_discount_pct,
        ]);

        return response()->json(['message' => 'Subscription created — first delivery in '.$data['interval_days'].' days. Cancel or skip anytime.'], 201);
    }

    public function updateSubscription(Request $request, Subscription $subscription, SubscriptionService $service): JsonResponse
    {
        abort_unless($subscription->buyer_id === $request->user()->id, 403);
        $data = $request->validate([
            'action' => 'required|in:pause,resume,cancel,skip,schedule',
            'interval_days' => 'required_if:action,schedule|integer|in:'.implode(',', array_keys(Subscription::INTERVALS)),
        ]);
        match ($data['action']) {
            'pause' => $service->pause($subscription),
            'resume' => $service->resume($subscription),
            'cancel' => $service->cancel($subscription),
            'skip' => $service->skipNext($subscription),
            'schedule' => $service->setSchedule($subscription, (int) $data['interval_days']),
        };

        return response()->json(['message' => 'Subscription updated.']);
    }

    // ---- Make an offer ----------------------------------------------------------------------------

    public function myOffer(Request $request, int $productId): JsonResponse
    {
        $offer = $this->currentOffer($request, $productId);

        return response()->json(['offer' => $offer ? self::offer($offer) : null]);
    }

    public function makeOffer(Request $request, int $productId, OfferService $offers): JsonResponse
    {
        $data = $request->validate([
            'price' => 'required|numeric|min:0.01',
            'quantity' => 'required|integer|min:1|max:99',
            'message' => 'nullable|string|max:300',
        ]);
        $product = Product::listed()->findOrFail($productId);

        try {
            $offer = $offers->create($request->user(), $product, (float) $data['price'], (int) $data['quantity'], $data['message'] ?? null);
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => ['price' => $e->errors() ? collect($e->errors())->flatten()->all() : []]], 422);
        }

        return response()->json([
            'message' => $offer->status === 'accepted' ? 'Offer accepted instantly! Buy it now at your price.' : 'Offer sent — the seller has 48 hours to respond.',
            'offer' => self::offer($offer),
        ], 201);
    }

    public function respondToCounter(Request $request, int $productId, OfferService $offers): JsonResponse
    {
        $data = $request->validate(['action' => 'required|in:accept,decline']);
        $offer = Offer::where('product_id', $productId)->where('buyer_id', $request->user()->id)
            ->where('status', 'countered')->latest()->firstOrFail();

        if ($data['action'] === 'decline') {
            $offers->declineCounter($offer);

            return response()->json(['message' => 'Counter-offer declined.']);
        }
        try {
            $offer = $offers->accept($offer, 'buyer');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Deal! Buy it now at the agreed price.', 'offer' => self::offer($offer->fresh())]);
    }

    /** Put the agreed quantity in the cart; the app then checks out with the returned code. */
    public function buyOffer(Request $request, int $productId, CartService $cart): JsonResponse
    {
        $offer = Offer::where('product_id', $productId)->where('buyer_id', $request->user()->id)
            ->where('status', 'accepted')->where('expires_at', '>', now())->latest()->firstOrFail();

        $inCart = (int) collect($cart->lines())->filter(fn ($l) => $l['product']->id === $productId)->sum('quantity');
        if ($inCart < $offer->quantity) {
            $cart->add($productId, $offer->quantity - $inCart);
        }

        return response()->json(['coupon' => $offer->coupon_code, 'message' => 'Added to your cart at the agreed price.']);
    }

    // ---- Store points + affiliate ---------------------------------------------------------------

    public function redeemPoints(Request $request, SellerProfile $sellerProfile, StoreLoyaltyService $loyalty): JsonResponse
    {
        $points = (int) $request->validate(['points' => 'required|integer|min:1'])['points'];
        $value = $loyalty->redeemToWallet($request->user(), $sellerProfile->user_id, $points);

        return response()->json(['message' => "Redeemed {$points} points for \${$value} in Panmotive credit.", 'value' => (float) $value]);
    }

    public function joinAffiliate(Request $request, SellerProfile $sellerProfile, AffiliateService $service): JsonResponse
    {
        $program = AffiliateProgram::where('seller_id', $sellerProfile->user_id)->where('is_active', true)->firstOrFail();
        $data = $request->validate(['name' => 'required|string|max:120', 'channel' => 'nullable|string|max:120']);

        $affiliate = $service->join($program, $data['name'], $request->user()->email, $data['channel'] ?? null, $request->user());

        return response()->json(['message' => $affiliate->status === 'approved' ? "You're in! Your link is ready." : 'Application submitted — the seller will review it.'], 201);
    }

    public function affiliates(Request $request): JsonResponse
    {
        $rows = Affiliate::where('user_id', $request->user()->id)->with('program.seller.sellerProfile')->withCount('conversions')->get();

        return response()->json(['affiliates' => $rows->map(fn (Affiliate $a) => [
            'store' => $a->program?->seller?->sellerProfile?->business_name,
            'status' => $a->status,
            'commission_pct' => (float) ($a->program?->commission_pct ?? 0),
            'link' => $a->status === 'approved' ? route('affiliate.go', $a) : null,
            'conversions' => (int) $a->conversions_count,
        ])->values()]);
    }

    // ---- Preferences + push ---------------------------------------------------------------------

    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'emails' => collect(EmailPreferences::for($user))->map(fn ($cat, $key) => [
                'key' => $key, 'label' => $cat['label'], 'hint' => $cat['hint'], 'on' => EmailPreferences::wants($user, $key),
            ])->values(),
            'store_marketing' => ! MarketingUnsubscribe::where('user_id', $user->id)->whereNull('seller_id')->exists(),
            'sms' => $user->sms_opt_in_at !== null,
            'phone' => $user->phone,
        ]);
    }

    public function updatePreferences(Request $request, MarketingConsent $consent): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => 'nullable|string|max:60', 'on' => 'nullable|boolean',
            'store_marketing' => 'nullable|boolean',
            'sms' => 'nullable|boolean', 'phone' => ['nullable', 'regex:/^\+?[0-9 ()\-.]{10,20}$/'],
        ], ['phone.regex' => 'Enter a valid mobile number.']);

        if (isset($data['email'])) {
            abort_unless(array_key_exists($data['email'], EmailPreferences::for($user)), 404);
            EmailPreferences::set($user, $data['email'], (bool) ($data['on'] ?? true));
        }
        if (array_key_exists('store_marketing', $data) && $data['store_marketing'] !== null) {
            $data['store_marketing'] ? $consent->resubscribe($user, null) : $consent->unsubscribe($user, null, 'preferences');
        }
        if (array_key_exists('sms', $data) && $data['sms'] !== null) {
            if ($data['sms']) {
                if (blank($data['phone'] ?? $user->phone)) {
                    throw ValidationException::withMessages(['phone' => 'Enter a mobile number for text messages.']);
                }
                $user->forceFill(['phone' => trim((string) ($data['phone'] ?? $user->phone)), 'sms_opt_in_at' => now()])->save();
            } else {
                $user->forceFill(['sms_opt_in_at' => null])->save();
            }
        }

        return $this->preferences($request);
    }

    /** The phone's Expo push token, so order and price-drop alerts reach it. */
    public function registerPush(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_\-]+\]$/'],
            'platform' => 'nullable|in:ios,android',
        ]);

        AppPushToken::updateOrCreate(['token' => $data['token']], [
            'user_id' => $request->user()->id,
            'platform' => $data['platform'] ?? null,
            'device_id' => app()->bound('shop.device') ? substr((string) app('shop.device'), 0, 80) : null,
            'last_seen_at' => now(),
        ]);

        return response()->json(['message' => 'Notifications on.']);
    }

    public function unregisterPush(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string|max:255']);
        AppPushToken::where('user_id', $request->user()->id)->where('token', $request->token)->delete();

        return response()->json(['message' => 'Notifications off.']);
    }

    // ---- Compare (guests too) -------------------------------------------------------------------

    public function compare(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))->map(fn ($v) => (int) $v)->filter()->unique()->take(\App\Support\Compare::MAX)->values();
        $products = Product::listed()->whereIn('id', $ids)
            ->with(['seller.sellerProfile', 'attributeValues.attribute', 'fitments.make', 'fitments.model', 'media'])
            ->withCount('activeVariants')->get()->sortBy(fn ($p) => $ids->search($p->id))->values();

        $present = new ShopPresenter;
        $present->prime($products);
        $names = $products->flatMap(fn ($p) => $p->attributeValues->map(fn ($av) => $av->attribute?->name))->filter()->unique()->values();

        return response()->json([
            'max' => \App\Support\Compare::MAX,
            'products' => $products->map(fn (Product $p) => $present->card($p) + [
                'vehicles' => $p->fitments->map(fn ($v) => trim($v->year.' '.$v->make?->name.' '.$v->model?->name))->unique()->take(12)->values(),
                'vehicles_total' => $p->fitments->count(),
                'attributes' => $names->mapWithKeys(fn ($n) => [$n => $p->attributeValues->first(fn ($av) => $av->attribute?->name === $n)?->value]),
            ])->values(),
            'attribute_names' => $names,
        ]);
    }

    /** @return array<string, mixed> */
    private static function offer(Offer $o): array
    {
        return [
            'id' => $o->id, 'status' => $o->status, 'quantity' => (int) $o->quantity,
            'price' => round((float) $o->offer_price, 2), 'counter_price' => $o->counter_price !== null ? round((float) $o->counter_price, 2) : null,
            'coupon' => $o->status === 'accepted' ? $o->coupon_code : null,
            'expires_at' => $o->expires_at?->toIso8601String(),
        ];
    }

    private function currentOffer(Request $request, int $productId): ?Offer
    {
        return Offer::where('product_id', $productId)->where('buyer_id', $request->user()->id)
            ->where(fn ($q) => $q->whereIn('status', ['pending', 'countered'])
                ->orWhere(fn ($a) => $a->where('status', 'accepted')->where('expires_at', '>', now())
                    ->whereHas('coupon', fn ($c) => $c->where('times_used', 0))))
            ->latest()->first();
    }
}
