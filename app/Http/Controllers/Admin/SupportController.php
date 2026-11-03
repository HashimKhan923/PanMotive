<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->string('status', 'open')->toString();

        $tickets = SupportTicket::with('seller.sellerProfile')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 ELSE 1 END")
            ->latest('last_reply_at')
            ->paginate(25)->withQueryString();

        $counts = SupportTicket::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return view('admin.support.index', compact('tickets', 'status', 'counts'));
    }

    public function show(SupportTicket $ticket)
    {
        $ticket->update(['admin_unread' => false]);
        $ticket->load('messages.user', 'seller.sellerProfile', 'orderItem.order');

        return view('admin.support.show', compact('ticket'));
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $tickets)
    {
        $data = $request->validate(['body' => 'required|string|min:2|max:5000']);
        $tickets->reply($ticket, $request->user(), 'support', trim($data['body']));

        return back()->with('success', 'Reply sent — the seller has been notified.');
    }

    public function resolve(SupportTicket $ticket, SupportTicketService $tickets)
    {
        $tickets->resolve($ticket);

        return redirect()->route('admin.support.index')->with('success', "Ticket {$ticket->ticket_number} resolved.");
    }
}
