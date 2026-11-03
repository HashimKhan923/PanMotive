<?php

namespace App\Livewire\Seller;

use App\Models\Conversation;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Question;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\SellerBlockedBuyer;
use App\Models\SellerCustomerNote;
use App\Models\StoreLoyaltyAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MessagingService;
use App\Services\Seller\CustomerInsightsService;
use App\Support\SellerContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.seller')]
class CustomerProfile extends Component
{
    public int $buyerId;

    public string $notes = '';

    public string $tagsInput = '';

    // Private thank-you / win-back coupon
    public string $couponType = 'percentage';

    public string $couponValue = '10';

    public int $couponDays = 30;

    public string $couponMinOrder = '';

    public string $couponMessage = '';

    public string $blockReason = '';

    public function mount(User $buyer, CustomerInsightsService $insights): void
    {
        // Only buyers who've actually bought from this store are visible to it.
        abort_unless($insights->query(auth()->id())->where('users.id', $buyer->id)->exists(), 404);

        $this->buyerId = $buyer->id;
        $note = SellerCustomerNote::where('seller_id', auth()->id())->where('buyer_id', $buyer->id)->first();
        $this->notes = $note->notes ?? '';
        $this->tagsInput = implode(', ', $note->tags ?? []);
    }

    public function saveNotes(): void
    {
        $this->validate(['notes' => 'nullable|string|max:5000', 'tagsInput' => 'nullable|string|max:300']);

        $tags = collect(explode(',', $this->tagsInput))
            ->map(fn ($t) => strtolower(trim(ltrim(trim($t), '#'))))
            ->map(fn ($t) => preg_replace('/[^a-z0-9\-_ ]/', '', $t))
            ->filter()->unique()->take(10)->values()->all();

        SellerCustomerNote::updateOrCreate(
            ['seller_id' => auth()->id(), 'buyer_id' => $this->buyerId],
            ['notes' => trim($this->notes) ?: null, 'tags' => $tags ?: null],
        );

        $this->tagsInput = implode(', ', $tags);
        $this->dispatch('toast', message: 'Notes saved — only your team can see them.');
    }

    /** Open (or start) a conversation with this customer in the inbox. */
    public function message(MessagingService $messaging)
    {
        abort_unless(SellerContext::can('messages'), 403);

        $buyer = User::findOrFail($this->buyerId);
        $conversation = Conversation::where('seller_id', auth()->id())->where('buyer_id', $buyer->id)
            ->where('closed', false)->latest('last_message_at')->first();

        if (! $conversation) {
            $latestOrder = Order::where('buyer_id', $buyer->id)
                ->whereHas('items', fn ($q) => $q->where('seller_id', auth()->id()))->latest()->first();
            $conversation = $messaging->threadFor($buyer, auth()->user(), null, $latestOrder,
                $latestOrder ? "About your order {$latestOrder->order_number}" : 'A note from '.auth()->user()->sellerProfile?->business_name);
        }

        return redirect()->route('seller.messages.index', ['c' => $conversation->id]);
    }

    /**
     * A one-time code only this customer can use, on this store's items — eBay's "send coupon to
     * buyer" / Etsy's thank-you offer. Delivered through Messages so it lands in their inbox.
     */
    public function sendCoupon(MessagingService $messaging): void
    {
        abort_unless(SellerContext::can('marketing'), 403);

        $this->validate([
            'couponType' => 'required|in:percentage,fixed',
            'couponValue' => $this->couponType === 'percentage' ? 'required|numeric|min:5|max:50' : 'required|numeric|min:1|max:500',
            'couponDays' => 'required|integer|min:7|max:90',
            'couponMinOrder' => 'nullable|numeric|min:0|max:10000',
            'couponMessage' => 'nullable|string|max:500',
        ], [], ['couponValue' => 'discount', 'couponDays' => 'validity', 'couponMinOrder' => 'minimum order']);

        abort_if(SellerBlockedBuyer::isBlocked(auth()->id(), $this->buyerId), 422, 'Unblock this buyer first.');

        $buyer = User::findOrFail($this->buyerId);
        $store = auth()->user()->sellerProfile?->business_name ?? auth()->user()->name;

        do {
            $code = 'FOR'.strtoupper(Str::random(7));
        } while (Coupon::where('code', $code)->exists());

        $coupon = Coupon::create([
            'code' => $code,
            'type' => $this->couponType,
            'value' => round((float) $this->couponValue, 2),
            'min_order_total' => $this->couponMinOrder !== '' ? round((float) $this->couponMinOrder, 2) : null,
            'usage_limit' => 1,
            'usage_limit_per_user' => 1,
            'seller_id' => auth()->id(),
            'user_id' => $buyer->id,
            'starts_at' => now(),
            'expires_at' => now()->addDays($this->couponDays)->endOfDay(),
            'is_active' => true,
        ]);

        $off = $coupon->type === 'percentage' ? rtrim(rtrim(number_format((float) $coupon->value, 2), '0'), '.').'% off' : '$'.number_format((float) $coupon->value, 2).' off';
        $body = trim($this->couponMessage) !== ''
            ? trim($this->couponMessage)."\n\n"
            : 'Thanks for being a customer, '.Str::before($buyer->name, ' ').'! ';
        $body .= "Here's a private code just for you: {$code} — {$off} your next order from {$store}"
            .($coupon->min_order_total ? ' of $'.number_format((float) $coupon->min_order_total, 2).' or more' : '')
            .'. Valid until '.$coupon->expires_at->format('M j, Y').'. Enter it at checkout.';

        $conversation = $messaging->threadFor($buyer, auth()->user(), null, null, "A thank-you from {$store}");
        $messaging->send($conversation, auth()->user(), $body);

        $this->reset('couponMessage', 'couponMinOrder');
        $this->dispatch('toast', message: "Code {$code} sent to {$buyer->name}'s inbox.");
    }

    public function toggleBlock(): void
    {
        abort_unless(SellerContext::can('messages'), 403);

        $existing = SellerBlockedBuyer::where('seller_id', auth()->id())->where('buyer_id', $this->buyerId)->first();
        if ($existing) {
            $existing->delete();
            $this->dispatch('toast', message: 'Buyer unblocked.');

            return;
        }

        $this->validate(['blockReason' => 'nullable|string|max:255']);
        SellerBlockedBuyer::create(['seller_id' => auth()->id(), 'buyer_id' => $this->buyerId, 'reason' => trim($this->blockReason) ?: null]);
        Conversation::where('seller_id', auth()->id())->where('buyer_id', $this->buyerId)->update(['closed' => true, 'awaiting_reply_since' => null]);
        $this->reset('blockReason');
        $this->dispatch('toast', message: "Buyer blocked — they can't message you, make offers or buy your items.");
    }

    public function render(CustomerInsightsService $insights)
    {
        $sellerId = auth()->id();
        $buyer = User::findOrFail($this->buyerId);
        $stats = $insights->query($sellerId)->where('users.id', $buyer->id)->first();

        $items = OrderItem::where('seller_id', $sellerId)
            ->whereHas('order', fn ($o) => $o->where('buyer_id', $buyer->id))
            ->with('order:id,order_number,created_at', 'product:id,slug')
            ->latest()->get();

        $topProducts = $items->reject(fn ($i) => in_array($i->status->value, CustomerInsightsService::excludedStatuses(), true))
            ->groupBy('product_id')
            ->map(fn ($g) => ['title' => $g->first()->product_title, 'units' => $g->sum('quantity'), 'spent' => $g->sum('line_total'), 'slug' => $g->first()->product?->slug])
            ->sortByDesc('spent')->take(5)->values();

        $loyalty = auth()->user()->sellerProfile?->loyaltyEnabled()
            ? StoreLoyaltyAccount::where('seller_id', $sellerId)->where('user_id', $buyer->id)->first()
            : null;

        return view('livewire.seller.customer-profile', [
            'buyer' => $buyer,
            'stats' => $stats,
            'badges' => $insights->badges($stats),
            'location' => $insights->lastLocation($sellerId, $buyer->id),
            'items' => $items->take(50),
            'topProducts' => $topProducts,
            'returns' => ReturnRequest::where('user_id', $buyer->id)
                ->whereHas('orderItem', fn ($q) => $q->where('seller_id', $sellerId))->latest()->get(),
            'reviews' => Review::where('user_id', $buyer->id)
                ->whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))->with('product:id,title,slug')->latest()->get(),
            'questions' => Question::where('user_id', $buyer->id)
                ->whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))->with('product:id,title')->latest()->take(10)->get(),
            'subscriptions' => Subscription::where('seller_id', $sellerId)->where('buyer_id', $buyer->id)->with('product:id,title')->get(),
            'loyalty' => $loyalty,
            'follows' => $buyer->followedSellers()->where('users.id', $sellerId)->exists(),
            'coupons' => Coupon::where('seller_id', $sellerId)->where('user_id', $buyer->id)->latest()->take(5)->get(),
            'blocked' => SellerBlockedBuyer::where('seller_id', $sellerId)->where('buyer_id', $buyer->id)->first(),
        ]);
    }
}
