<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerBlockedBuyer;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use App\Services\AI\AiClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessagingService
{
    /**
     * Find an open buyer↔seller thread (scoped to a product or an order when given) or start one.
     */
    public function threadFor(User $buyer, User $seller, ?Product $product = null, ?Order $order = null, ?string $subject = null): Conversation
    {
        $query = Conversation::where('buyer_id', $buyer->id)
            ->where('seller_id', $seller->id)
            ->where('closed', false);

        $query = match (true) {
            $product !== null => $query->where('product_id', $product->id),
            $order !== null => $query->whereNull('product_id')->where('order_id', $order->id),
            default => $query->whereNull('product_id'),
        };

        return $query->latest('last_message_at')->first()
            ?? Conversation::create([
                'buyer_id' => $buyer->id,
                'seller_id' => $seller->id,
                'product_id' => $product?->id,
                'order_id' => $order?->id,
                'subject' => $subject ?? match (true) {
                    $product !== null => "Question about {$product->title}",
                    $order !== null => "About order {$order->order_number}",
                    default => 'New conversation',
                },
                'buyer_unread' => 0,
                'seller_unread' => 0,
            ]);
    }

    /**
     * @param  array<int, UploadedFile>  $photos
     */
    public function send(Conversation $conversation, User $sender, string $body, array $photos = []): Message
    {
        if ($sender->id === $conversation->buyer_id && SellerBlockedBuyer::isBlocked($conversation->seller_id, $sender->id)) {
            throw ValidationException::withMessages(['body' => "This seller isn't accepting messages from your account."]);
        }

        $attachments = collect($photos)
            ->take(config('marketplace.messages.max_attachments', 4))
            ->map(fn (UploadedFile $f) => [
                'path' => $f->store("messages/{$conversation->id}", 'public'),
                'name' => Str::limit($f->getClientOriginalName(), 80, ''),
            ])->values()->all();

        $body = trim($body);
        if ($body === '' && $attachments) {
            $body = count($attachments) === 1 ? 'Sent a photo' : 'Sent '.count($attachments).' photos';
        }

        $message = $conversation->post($sender, $body, $attachments);

        $recipientId = $sender->id === $conversation->buyer_id
            ? $conversation->seller_id
            : $conversation->buyer_id;
        $recipientUnread = $sender->id === $conversation->buyer_id ? $conversation->seller_unread : $conversation->buyer_unread;

        // Email only for the first unread message in a thread, so a burst of chat isn't a burst of mail.
        User::find($recipientId)?->notify(new NewMessageReceived($message, email: (int) $recipientUnread === 1));

        if ($sender->id === $conversation->buyer_id) {
            $this->awayReply($conversation);
        }

        return $message;
    }

    /**
     * While the seller is on vacation, the buyer gets one automatic reply per conversation. It
     * doesn't count as the seller answering — the thread still shows as needing a reply.
     */
    private function awayReply(Conversation $conversation): void
    {
        $seller = User::with('sellerProfile')->find($conversation->seller_id);
        $profile = $seller?->sellerProfile;
        if (! $profile?->isOnVacation() || $conversation->away_reply_sent_at) {
            return;
        }

        $until = $profile->vacation_until ? ' until '.$profile->vacation_until->format('M j') : '';
        $text = ($profile->vacation_message ?: "Thanks for your message! We're away{$until} and will reply as soon as we're back.")
            .' (automatic reply)';

        $waiting = $conversation->awaiting_reply_since;
        $conversation->post($seller, $text);
        $conversation->forceFill(['awaiting_reply_since' => $waiting, 'away_reply_sent_at' => now()])->save();
    }

    /**
     * Inbox health for the last $days: how quickly the seller answers buyers.
     *
     * @return array{needs_reply:int, overdue:int, avg_first_response_hours:?float, within_target_rate:?float}
     */
    public function responseStats(int $sellerId, int $days = 30): array
    {
        $target = config('marketplace.messages.response_target_hours', 24);

        $waiting = Conversation::where('seller_id', $sellerId)->where('closed', false)->whereNotNull('awaiting_reply_since');

        $samples = collect();
        Conversation::where('seller_id', $sellerId)
            ->where('created_at', '>=', now()->subDays($days))
            ->with('messages:id,conversation_id,sender_id,created_at')
            ->get(['id', 'buyer_id', 'seller_id'])
            ->each(function (Conversation $c) use ($samples) {
                $firstBuyer = $c->messages->firstWhere('sender_id', $c->buyer_id);
                if (! $firstBuyer) {
                    return;
                }
                $reply = $c->messages->first(fn ($m) => $m->sender_id === $c->seller_id && $m->created_at->gte($firstBuyer->created_at));
                // Unanswered threads count against the rate once they're past the target.
                $samples->push($reply
                    ? $firstBuyer->created_at->diffInMinutes($reply->created_at) / 60
                    : ($firstBuyer->created_at->lt(now()->subHours(config('marketplace.messages.response_target_hours', 24))) ? INF : null));
            });

        $answered = $samples->filter(fn ($h) => $h !== null && $h !== INF);
        $judged = $samples->filter(fn ($h) => $h !== null);

        return [
            'needs_reply' => (clone $waiting)->count(),
            'overdue' => (clone $waiting)->where('awaiting_reply_since', '<', now()->subHours($target))->count(),
            'avg_first_response_hours' => $answered->isNotEmpty() ? round($answered->avg(), 1) : null,
            'within_target_rate' => $judged->isNotEmpty()
                ? round($judged->filter(fn ($h) => $h <= $target)->count() / $judged->count() * 100, 1)
                : null,
        ];
    }

    /** Values for {placeholders} in saved replies, from the thread's buyer, product and order. */
    public function templateVars(Conversation $conversation): array
    {
        $seller = $conversation->seller ?? User::find($conversation->seller_id);
        $item = $this->latestItem($conversation);
        $buyerName = $conversation->buyer?->name ?? '';

        return [
            '{buyer_name}' => $buyerName,
            '{buyer_first_name}' => Str::before($buyerName, ' '),
            '{store_name}' => $seller?->sellerProfile?->business_name ?? $seller?->name ?? '',
            '{product_title}' => $conversation->product?->title ?? $item?->product_title ?? '',
            '{order_number}' => $conversation->order?->order_number ?? $item?->order?->order_number ?? '',
            '{tracking_number}' => $item?->tracking_number ?? '',
            '{tracking_url}' => $item?->trackingUrl() ?? '',
        ];
    }

    /** The order line this thread is most likely about: its order's line, else the buyer's latest with this seller. */
    public function latestItem(Conversation $conversation): ?OrderItem
    {
        return OrderItem::where('seller_id', $conversation->seller_id)
            ->when($conversation->order_id,
                fn ($q) => $q->where('order_id', $conversation->order_id),
                fn ($q) => $q->whereHas('order', fn ($o) => $o->where('buyer_id', $conversation->buyer_id)))
            ->when($conversation->product_id, fn ($q) => $q->orderByRaw('product_id = ? desc', [$conversation->product_id]))
            ->with('order:id,order_number,placed_at,created_at')
            ->latest('id')->first();
    }

    /**
     * Draft a reply with AI from the thread, the listing and the order status. Returns '' when AI
     * isn't configured or fails — the seller just writes their own.
     */
    public function draftReply(Conversation $conversation): string
    {
        $ai = app(AiClient::class);
        if (! $ai->isConfigured()) {
            return '';
        }

        $conversation->loadMissing('messages', 'product', 'seller.sellerProfile');
        $item = $this->latestItem($conversation);
        $store = $conversation->seller?->sellerProfile?->business_name ?? 'our store';

        $context = collect([
            $conversation->product ? 'Listing: '.$conversation->product->title.' — $'.number_format((float) $conversation->product->effectivePrice(), 2)
                .', '.((int) $conversation->product->quantity > 0 ? $conversation->product->quantity.' in stock' : 'out of stock')
                .'. Details: '.Str::limit(strip_tags((string) $conversation->product->description), 600) : null,
            $item ? "Order {$item->order?->order_number}: {$item->product_title} x{$item->quantity}, status ".$item->status->label()
                .($item->tracking_number ? ", tracking {$item->carrier} {$item->tracking_number}" : '') : null,
        ])->filter()->implode("\n");

        $thread = $conversation->messages->take(-10)
            ->map(fn ($m) => ($m->sender_id === $conversation->seller_id ? 'Seller' : 'Buyer').': '.Str::limit($m->body, 500))
            ->implode("\n");

        try {
            return trim($ai->complete(
                "You write replies for {$store}, an auto-parts seller on the Pan Motive marketplace. Be friendly, brief (2–5 sentences), "
                .'and factual. Only state facts given in the context; if fitment or details are unknown, ask for the VIN or year/make/model. '
                .'Never promise refunds, discounts or dates the context does not support. Never ask the buyer to pay or talk off-platform. '
                .'Return only the reply text, no greeting placeholder or signature block.',
                "Context:\n{$context}\n\nConversation so far:\n{$thread}\n\nWrite the seller's next reply.",
                300,
            ));
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }
}
