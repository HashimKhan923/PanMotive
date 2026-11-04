<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Jobs\SendAdminBroadcast;
use App\Mail\CampaignMail;
use App\Models\AdminBroadcast;
use App\Support\Html\DescriptionSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/** Panmotive's own email to its buyers and sellers. Drafted first, tested on yourself, then sent once. */
class BroadcastController extends Controller
{
    use AdminTools;

    public function index()
    {
        $reach = [];
        foreach (array_keys(AdminBroadcast::AUDIENCES) as $audience) {
            foreach (['marketing', 'notice'] as $kind) {
                $reach[$audience][$kind] = AdminBroadcast::audienceQuery($audience, $kind)->count();
            }
        }

        return view('admin.broadcasts.index', [
            'broadcasts' => AdminBroadcast::with('author:id,name')->latest()->paginate(20),
            'reach' => $reach,
            'audiences' => AdminBroadcast::AUDIENCES,
        ]);
    }

    public function store(Request $request, DescriptionSanitizer $sanitizer)
    {
        $data = $this->validated($request);
        $data['body'] = $sanitizer->sanitize($data['body'], 100000);

        $broadcast = AdminBroadcast::create($data + ['status' => 'draft', 'created_by' => $request->user()->id]);
        $this->audit('Drafted broadcast', $broadcast, ['subject' => $data['subject']]);

        return back()->with('success', 'Draft saved. Send yourself a test before sending it to everyone.');
    }

    public function test(Request $request, AdminBroadcast $broadcast)
    {
        Mail::to($request->user()->email)->send(new CampaignMail('[Test] '.$broadcast->subject, (string) $broadcast->preview, (string) $broadcast->body, null, 'Panmotive', true));

        return back()->with('success', 'Test sent to '.$request->user()->email.'.');
    }

    public function send(Request $request, AdminBroadcast $broadcast)
    {
        if ($broadcast->status !== 'draft') {
            return back()->with('error', 'This broadcast has already been sent.');
        }

        $count = AdminBroadcast::audienceQuery($broadcast->audience, $broadcast->kind)->count();
        $request->validate(['confirm' => ['required', Rule::in([(string) $count])]], ['confirm.in' => "Type {$count} to confirm the number of people."]);

        $broadcast->update(['status' => 'queued', 'recipients' => $count]);
        SendAdminBroadcast::dispatch($broadcast->id);
        $this->audit('Sent broadcast', $broadcast, ['subject' => $broadcast->subject, 'recipients' => $count, 'audience' => $broadcast->audience, 'kind' => $broadcast->kind]);

        return back()->with('success', "Sending to {$count} people.");
    }

    public function destroy(AdminBroadcast $broadcast)
    {
        if ($broadcast->status !== 'draft') {
            return back()->with('error', 'Only drafts can be deleted. Sent broadcasts stay on record.');
        }

        $broadcast->delete();

        return back()->with('success', 'Draft deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'subject' => 'required|string|max:150',
            'preview' => 'nullable|string|max:150',
            'body' => 'required|string|max:100000',
            'audience' => ['required', Rule::in(array_keys(AdminBroadcast::AUDIENCES))],
            'kind' => 'required|in:marketing,notice',
        ]);
    }
}
