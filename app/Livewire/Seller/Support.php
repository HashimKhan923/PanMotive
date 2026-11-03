<?php

namespace App\Livewire\Seller;

use App\Models\OrderItem;
use App\Models\SupportTicket;
use App\Services\Support\SupportTicketService;
use App\Support\SellerContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Seller help centre: answers to common questions, plus tickets with the Pan Motive support team. */
#[Layout('layouts.seller')]
class Support extends Component
{
    #[Url(as: 'ticket')]
    public ?int $ticketId = null;

    public bool $composing = false;

    public string $category = 'orders';

    public string $subject = '';

    public string $body = '';

    public string $orderNumber = '';

    public string $reply = '';

    public function mount(): void
    {
        if ($this->ticketId) {
            $this->markRead();
        }
    }

    public function open(int $id): void
    {
        $this->ticketId = $id;
        $this->composing = false;
        $this->reply = '';
        $this->markRead();
    }

    public function back(): void
    {
        $this->ticketId = null;
    }

    private function markRead(): void
    {
        SupportTicket::where('seller_id', auth()->id())->whereKey($this->ticketId)->update(['seller_unread' => false]);
    }

    public function submit(SupportTicketService $tickets): void
    {
        $this->validate([
            'category' => 'required|in:'.implode(',', array_keys(SupportTicket::CATEGORIES)),
            'subject' => 'required|string|min:5|max:160',
            'body' => 'required|string|min:15|max:5000',
            'orderNumber' => 'nullable|string|max:30',
        ], [], ['body' => 'message']);

        $orderItemId = null;
        if (trim($this->orderNumber) !== '') {
            $orderItemId = OrderItem::where('seller_id', auth()->id())
                ->whereHas('order', fn ($o) => $o->where('order_number', strtoupper(trim($this->orderNumber))))
                ->value('id');
            if (! $orderItemId) {
                $this->addError('orderNumber', 'No order with that number on your store.');

                return;
            }
        }

        $ticket = $tickets->open(auth()->user(), SellerContext::actor(), $this->category, trim($this->subject), trim($this->body), $orderItemId);

        $this->reset('subject', 'body', 'orderNumber', 'composing');
        $this->ticketId = $ticket->id;
        $this->dispatch('toast', message: "Ticket {$ticket->ticket_number} sent — we usually reply within one business day.");
    }

    public function sendReply(SupportTicketService $tickets): void
    {
        $this->validate(['reply' => 'required|string|min:2|max:5000']);
        $ticket = SupportTicket::where('seller_id', auth()->id())->findOrFail($this->ticketId);

        $tickets->reply($ticket, SellerContext::actor() ?? auth()->user(), 'seller', trim($this->reply));
        $this->reply = '';
        $this->dispatch('toast', message: 'Reply sent to support.');
    }

    public function resolve(SupportTicketService $tickets): void
    {
        $ticket = SupportTicket::where('seller_id', auth()->id())->findOrFail($this->ticketId);
        $tickets->resolve($ticket);
        $this->dispatch('toast', message: 'Marked as resolved. Reply any time to reopen it.');
    }

    /** @return list<array{q:string,a:string}> */
    private function faqs(): array
    {
        $rate = rtrim(rtrim(number_format((float) config('marketplace.default_commission_rate'), 2), '0'), '.');
        $confirm = (int) config('marketplace.auto_confirm_delivery_days');
        $instant = config('marketplace.instant_payout_fee_pct');

        return [
            ['q' => 'What does it cost to sell on Pan Motive?', 'a' => "No listing or monthly fees. We take a flat {$rate}% commission on each sale, shown on every order before you ship. Higher performance tiers earn a lower rate automatically — see Performance & tiers."],
            ['q' => 'When do I get paid?', 'a' => "Your payout is held in escrow and released when the buyer confirms delivery, or automatically {$confirm} days after delivery if they don't respond. Want it sooner? Use Instant payout on a shipped order ({$instant}% fee, free for Top Rated and Powerseller)."],
            ['q' => 'A buyer paid but I can\'t fill the order — what do I do?', 'a' => 'Open the order and choose "Cancel order". The buyer is refunded automatically and the stock goes back on your shelf. Only "buyer asked" and "suspected fraud" cancellations are excused from your cancellation rate, so keep your stock counts accurate.'],
            ['q' => 'How long do I have to respond to a return?', 'a' => 'You have 48 hours to approve or reject a return request. After that it escalates to Pan Motive automatically and may count against your account health.'],
            ['q' => 'Why isn\'t my listing showing in search?', 'a' => 'Check that it\'s Active (not a draft), has stock, at least one photo, a category and vehicle fitment. Listing insights shows exactly what each listing is missing.'],
            ['q' => 'How do I bulk upload my catalog?', 'a' => 'Products → Bulk upload accepts a Shopify product export or our CSV template. Images, variants and categories are imported automatically; everything lands as a draft for you to review.'],
            ['q' => 'Can my staff help run the store?', 'a' => 'Yes — Settings → Team & permissions lets you invite people with exactly the access they need (for example orders and shipping only, no payouts).'],
        ];
    }

    public function render()
    {
        $sellerId = auth()->id();
        $tickets = SupportTicket::where('seller_id', $sellerId)->latest('last_reply_at')->get();
        $active = $this->ticketId
            ? SupportTicket::where('seller_id', $sellerId)->with('messages.user:id,name', 'orderItem.order:id,order_number')->find($this->ticketId)
            : null;

        return view('livewire.seller.support', [
            'tickets' => $tickets,
            'active' => $active,
            'categories' => SupportTicket::CATEGORIES,
            'faqs' => $this->faqs(),
        ]);
    }
}
