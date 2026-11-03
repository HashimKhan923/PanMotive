<?php

namespace App\Livewire\Storefront;

use App\Models\Conversation;
use App\Models\SellerBlockedBuyer;
use App\Services\MessagingService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.storefront')]
class BuyerMessages extends Component
{
    use WithFileUploads;

    #[Url(as: 'c')]
    public ?int $activeId = null;

    public string $body = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $photos = [];

    public function mount(): void
    {
        if ($this->activeId) {
            $this->open($this->activeId);
        }
    }

    private function activeConversation(): ?Conversation
    {
        if (! $this->activeId) {
            return null;
        }

        return Conversation::where('buyer_id', auth()->id())
            ->with(['seller.sellerProfile', 'product:id,title,slug', 'order:id,order_number', 'messages.sender:id,name'])
            ->find($this->activeId);
    }

    public function open(int $id): void
    {
        $conversation = Conversation::where('buyer_id', auth()->id())->findOrFail($id);
        $conversation->markReadFor('buyer');
        $this->activeId = $id;
        $this->reset('body', 'photos');
        $this->resetValidation();
    }

    /** Polled: keeps the open thread fresh and marks new seller replies as read. */
    public function refreshThread(): void
    {
        if ($this->activeId) {
            Conversation::where('buyer_id', auth()->id())->find($this->activeId)?->markReadFor('buyer');
        }
    }

    public function reply(MessagingService $messaging): void
    {
        $this->validate([
            'body' => 'required_without:photos|nullable|string|max:4000',
            'photos' => 'array|max:'.config('marketplace.messages.max_attachments', 4),
            'photos.*' => 'image|max:5120',
        ], ['body.required_without' => 'Write a message or attach a photo.']);

        $conversation = $this->activeConversation();
        abort_unless($conversation, 404);

        // An archived thread reopens when the buyer writes again (Conversation::post).
        $messaging->send($conversation, auth()->user(), $this->body, $this->photos);
        $this->reset('body', 'photos');
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    public function render()
    {
        $conversations = Conversation::where('buyer_id', auth()->id())
            ->with(['seller.sellerProfile', 'product:id,title', 'latestMessage'])
            ->where(fn ($q) => $q->whereNotNull('last_message_at')->orWhere('id', $this->activeId))
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();

        $active = $this->activeConversation();

        return view('livewire.storefront.buyer-messages', [
            'conversations' => $conversations,
            'active' => $active,
            'blocked' => $active && SellerBlockedBuyer::isBlocked($active->seller_id, auth()->id()),
            'lastMine' => $active?->messages->where('sender_id', auth()->id())->last(),
        ]);
    }
}
