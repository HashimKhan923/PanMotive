<?php

namespace App\Livewire\Seller;

use App\Models\Conversation;
use App\Models\OrderItem;
use App\Models\SellerBlockedBuyer;
use App\Models\SellerCustomerNote;
use App\Models\SellerSavedReply;
use App\Services\AI\AiClient;
use App\Services\MessagingService;
use App\Services\Seller\CustomerInsightsService;
use App\Support\SellerContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.seller')]
class Inbox extends Component
{
    use WithFileUploads;

    #[Url(as: 'c')]
    public ?int $activeId = null;

    public string $body = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $photos = [];

    #[Url]
    public string $filter = 'all'; // needs_reply | all | unread | closed

    #[Url(as: 'q')]
    public string $search = '';

    // Saved-replies manager
    public bool $managingReplies = false;

    public ?int $editingReplyId = null;

    public string $replyTitle = '';

    public string $replyBody = '';

    public string $blockReason = '';

    public function mount(): void
    {
        abort_unless(SellerContext::can('messages'), 403);

        if ($this->activeId) {
            $this->open($this->activeId);
        }
    }

    private function activeConversation(): ?Conversation
    {
        if (! $this->activeId) {
            return null;
        }

        return Conversation::where('seller_id', auth()->id())
            ->with(['buyer:id,name,created_at', 'product:id,title,slug,quantity', 'order:id,order_number', 'messages.sender:id,name'])
            ->find($this->activeId);
    }

    private function owned(): Conversation
    {
        return Conversation::where('seller_id', auth()->id())->findOrFail($this->activeId);
    }

    public function open(int $id): void
    {
        $conversation = Conversation::where('seller_id', auth()->id())->findOrFail($id);
        $conversation->markReadFor('seller');
        $this->activeId = $id;
        $this->reset('body', 'photos', 'blockReason');
        $this->resetValidation();
    }

    public function reply(MessagingService $messaging): void
    {
        abort_unless(SellerContext::can('messages'), 403);

        $this->validate([
            'body' => 'required_without:photos|nullable|string|max:4000',
            'photos' => 'array|max:'.config('marketplace.messages.max_attachments', 4),
            'photos.*' => 'image|max:5120',
        ], ['body.required_without' => 'Write a message or attach a photo.']);

        $conversation = $this->activeConversation();
        abort_unless($conversation, 404);

        $messaging->send($conversation, auth()->user(), $this->body, $this->photos);
        $this->reset('body', 'photos');
        $this->dispatch('toast', message: 'Reply sent');
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    public function toggleClosed(): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $conversation = $this->owned();
        $conversation->update(['closed' => ! $conversation->closed]);
        $this->dispatch('toast', message: $conversation->closed ? 'Conversation archived — it reopens if the buyer writes again.' : 'Conversation reopened.');
    }

    public function markUnread(): void
    {
        $this->owned()->markUnreadForSeller();
        $this->activeId = null;
        $this->dispatch('toast', message: 'Marked as unread.');
    }

    /** Answered by phone / nothing to add: clear "needs reply" without sending a message. */
    public function markHandled(): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $this->owned()->forceFill(['awaiting_reply_since' => null])->save();
        $this->dispatch('toast', message: 'Marked as handled.');
    }

    public function toggleBlock(): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $conversation = $this->owned();

        $existing = SellerBlockedBuyer::where('seller_id', auth()->id())->where('buyer_id', $conversation->buyer_id)->first();
        if ($existing) {
            $existing->delete();
            $this->dispatch('toast', message: 'Buyer unblocked.');

            return;
        }

        $this->validate(['blockReason' => 'nullable|string|max:255']);
        SellerBlockedBuyer::create([
            'seller_id' => auth()->id(),
            'buyer_id' => $conversation->buyer_id,
            'reason' => trim($this->blockReason) ?: null,
        ]);
        $conversation->update(['closed' => true, 'awaiting_reply_since' => null]);
        $this->reset('blockReason');
        $this->dispatch('toast', message: "Buyer blocked — they can't message you, make offers or buy your items.");
    }

    // ── Saved replies ────────────────────────────────────────────────────

    public function useReply(int $id, MessagingService $messaging): void
    {
        $reply = SellerSavedReply::where('seller_id', auth()->id())->findOrFail($id);
        $conversation = $this->activeConversation();
        abort_unless($conversation, 404);

        $text = SellerSavedReply::interpolate($reply->body, $messaging->templateVars($conversation));
        $this->body = trim($this->body) === '' ? $text : rtrim($this->body)."\n\n".$text;
        $reply->increment('use_count');
    }

    public function draftWithAi(MessagingService $messaging): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $conversation = $this->activeConversation();
        abort_unless($conversation, 404);

        $draft = $messaging->draftReply($conversation);
        if ($draft === '') {
            $this->dispatch('toast', message: "Couldn't draft a reply right now — try a saved reply instead.");

            return;
        }
        $this->body = $draft;
    }

    public function editReply(?int $id = null): void
    {
        $this->managingReplies = true;
        $this->resetValidation();
        $reply = $id ? SellerSavedReply::where('seller_id', auth()->id())->findOrFail($id) : null;
        $this->editingReplyId = $reply?->id;
        $this->replyTitle = $reply->title ?? '';
        $this->replyBody = $reply->body ?? '';
    }

    public function saveReply(): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $this->validate(['replyTitle' => 'required|string|max:80', 'replyBody' => 'required|string|max:2000']);

        if ($this->editingReplyId) {
            SellerSavedReply::where('seller_id', auth()->id())->findOrFail($this->editingReplyId)
                ->update(['title' => trim($this->replyTitle), 'body' => trim($this->replyBody)]);
        } else {
            abort_if(SellerSavedReply::where('seller_id', auth()->id())->count() >= 50, 422, 'You can keep up to 50 saved replies.');
            SellerSavedReply::create(['seller_id' => auth()->id(), 'title' => trim($this->replyTitle), 'body' => trim($this->replyBody)]);
        }

        $this->reset('editingReplyId', 'replyTitle', 'replyBody');
        $this->dispatch('toast', message: 'Saved reply stored.');
    }

    public function deleteReply(int $id): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        SellerSavedReply::where('seller_id', auth()->id())->findOrFail($id)->delete();
        if ($this->editingReplyId === $id) {
            $this->reset('editingReplyId', 'replyTitle', 'replyBody');
        }
    }

    public function addStarterReplies(): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        if (SellerSavedReply::where('seller_id', auth()->id())->exists()) {
            return;
        }
        foreach (SellerSavedReply::STARTERS as [$title, $body]) {
            SellerSavedReply::create(['seller_id' => auth()->id(), 'title' => $title, 'body' => $body]);
        }
        $this->dispatch('toast', message: 'Added '.count(SellerSavedReply::STARTERS).' starter replies — edit them to sound like you.');
    }

    /** Polled every few seconds: new messages in the open thread count as read since they're on screen. */
    public function refreshThread(): void
    {
        if ($this->activeId) {
            Conversation::where('seller_id', auth()->id())->find($this->activeId)?->markReadFor('seller');
        }
    }

    public function updatedFilter(): void
    {
        $this->activeId = null;
    }

    public function render(MessagingService $messaging, CustomerInsightsService $insights)
    {
        $sellerId = auth()->id();
        $base = Conversation::where('seller_id', $sellerId);

        $term = trim($this->search);
        $conversations = (clone $base)
            ->with(['buyer:id,name', 'product:id,title', 'latestMessage'])
            // A buyer can open a thread from an order without writing yet — don't list empty ones.
            ->where(fn ($q) => $q->whereNotNull('last_message_at')->orWhere('id', $this->activeId))
            ->when($this->filter === 'needs_reply', fn ($q) => $q->where('closed', false)->whereNotNull('awaiting_reply_since'))
            ->when($this->filter === 'unread', fn ($q) => $q->where('seller_unread', '>', 0))
            ->when($this->filter === 'closed', fn ($q) => $q->where('closed', true))
            ->when($this->filter === 'all', fn ($q) => $q->where('closed', false))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('subject', 'like', "%{$term}%")
                ->orWhereHas('buyer', fn ($b) => $b->where('name', 'like', "%{$term}%"))
                ->orWhereHas('product', fn ($p) => $p->where('title', 'like', "%{$term}%"))
                ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$term}%"))
                ->orWhereHas('messages', fn ($m) => $m->where('body', 'like', "%{$term}%"))))
            ->when($this->filter === 'needs_reply',
                fn ($q) => $q->orderBy('awaiting_reply_since'),
                fn ($q) => $q->orderByDesc('last_message_at')->orderByDesc('created_at'))
            ->limit(100)
            ->get();

        $active = $this->activeConversation();

        // Right-hand context: who this buyer is to the store and what they've bought.
        $context = null;
        if ($active) {
            $stats = $insights->query($sellerId)->where('users.id', $active->buyer_id)->first();
            $context = [
                'stats' => $stats,
                'badges' => $stats ? $insights->badges($stats) : [],
                'items' => OrderItem::where('seller_id', $sellerId)
                    ->whereHas('order', fn ($o) => $o->where('buyer_id', $active->buyer_id))
                    ->with('order:id,order_number,placed_at,created_at')
                    ->latest('id')->take(5)->get(),
                'note' => SellerCustomerNote::where('seller_id', $sellerId)->where('buyer_id', $active->buyer_id)->first(),
                'blocked' => SellerBlockedBuyer::where('seller_id', $sellerId)->where('buyer_id', $active->buyer_id)->first(),
                // The buyer's most recent message we've already seen is "Seen" on our side; ours shows Seen once they open it.
                'lastMine' => $active->messages->where('sender_id', $sellerId)->last(),
            ];
        }

        return view('livewire.seller.inbox', [
            'conversations' => $conversations,
            'active' => $active,
            'context' => $context,
            'counts' => [
                'needs_reply' => (clone $base)->where('closed', false)->whereNotNull('awaiting_reply_since')->count(),
                'unread' => (clone $base)->where('seller_unread', '>', 0)->count(),
            ],
            'stats' => $messaging->responseStats($sellerId),
            'savedReplies' => SellerSavedReply::where('seller_id', $sellerId)->orderByDesc('use_count')->orderBy('title')->get(),
            'aiEnabled' => app(AiClient::class)->isConfigured(),
            'targetHours' => config('marketplace.messages.response_target_hours', 24),
        ]);
    }
}
