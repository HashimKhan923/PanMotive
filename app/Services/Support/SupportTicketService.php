<?php

namespace App\Services\Support;

use App\Models\AdminAlert;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketReplied;
use Illuminate\Support\Facades\DB;

class SupportTicketService
{
    public function open(User $seller, ?User $actor, string $category, string $subject, string $body, ?int $orderItemId = null): SupportTicket
    {
        $ticket = DB::transaction(function () use ($seller, $actor, $category, $subject, $body, $orderItemId) {
            $ticket = SupportTicket::create([
                'seller_id' => $seller->id,
                'opened_by' => $actor?->id ?? $seller->id,
                'category' => $category,
                'subject' => $subject,
                'order_item_id' => $orderItemId,
                'status' => 'open',
                'priority' => in_array($category, ['payouts', 'account'], true) ? 'high' : 'normal',
                'admin_unread' => true,
                'last_reply_at' => now(),
            ]);

            $ticket->messages()->create([
                'user_id' => $actor?->id ?? $seller->id,
                'author_role' => 'seller',
                'body' => $body,
            ]);

            return $ticket;
        });

        // Surfaces in the admin console's alert inbox too, so nothing sits unseen.
        AdminAlert::create([
            'type' => 'support_ticket',
            'message' => "Support ticket {$ticket->ticket_number} from ".($seller->sellerProfile?->business_name ?? $seller->name).": {$subject}",
            'related_order_item_id' => $orderItemId,
        ]);

        return $ticket;
    }

    public function reply(SupportTicket $ticket, User $author, string $role, string $body): void
    {
        DB::transaction(function () use ($ticket, $author, $role, $body) {
            $ticket->messages()->create(['user_id' => $author->id, 'author_role' => $role, 'body' => $body]);

            $ticket->update($role === 'support'
                ? ['status' => 'answered', 'seller_unread' => true, 'admin_unread' => false, 'last_reply_at' => now(), 'resolved_at' => null]
                : ['status' => 'open', 'admin_unread' => true, 'seller_unread' => false, 'last_reply_at' => now(), 'resolved_at' => null]);
        });

        if ($role === 'support') {
            $ticket->seller?->notify(new SupportTicketReplied($ticket));
        }
    }

    public function resolve(SupportTicket $ticket): void
    {
        $ticket->update(['status' => 'resolved', 'resolved_at' => now(), 'admin_unread' => false]);
    }
}
