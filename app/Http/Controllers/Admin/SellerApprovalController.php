<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\SellerProfile;
use App\Models\SellerVerification;
use App\Notifications\AccountNotice;
use App\Notifications\SellerApproved;
use App\Notifications\SellerRejected;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SellerApprovalController extends Controller
{
    use AdminTools;

    public function verifications()
    {
        $verifications = SellerVerification::where('status', 'pending')
            ->with('seller.sellerProfile')->latest('submitted_at')->paginate(20);

        return view('admin.sellers.verifications', compact('verifications'));
    }

    public function reviewVerification(Request $request, SellerVerification $sellerVerification)
    {
        abort_unless(auth()->user()->can('sellers.approve'), 403);

        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($data['decision'] === 'approve') {
            $sellerVerification->update([
                'status' => 'approved', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'rejection_reason' => null,
            ]);
            $sellerVerification->seller?->sellerProfile?->update(['kyc_status' => 'verified']);
        } else {
            $sellerVerification->update([
                'status' => 'rejected', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(),
                'rejection_reason' => $data['reason'] ?: 'Documents could not be verified.',
            ]);
            $sellerVerification->seller?->sellerProfile?->update(['kyc_status' => 'rejected']);
        }

        $this->audit("Identity check {$data['decision']}d", $sellerVerification, ['reason' => $data['reason'] ?? null]);

        $this->tell($sellerVerification->seller, $data['decision'] === 'approve' ? 'Your identity check was approved' : 'Your identity check needs another look',
            $data['decision'] === 'approve' ? 'Your documents were approved.' : 'Reason: '.$sellerVerification->rejection_reason.'. Please upload them again in Verification.');

        return back()->with('success', "Verification {$data['decision']}d.");
    }

    /** Staff must see the documents to judge them. They stay on the private disk, and every view is logged. */
    public function document(SellerVerification $sellerVerification, string $type)
    {
        $column = ['business' => 'business_doc_path', 'id' => 'id_doc_path', 'address' => 'address_proof_path'][$type] ?? abort(404);
        $path = $sellerVerification->{$column};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        $this->audit('Viewed identity document', $sellerVerification, ['document' => $type]);

        return Storage::disk('local')->response($path);
    }

    public function approve(SellerProfile $sellerProfile)
    {
        abort_unless(auth()->user()->can('sellers.approve'), 403);

        $sellerProfile->update(['status' => 'approved', 'kyc_status' => 'verified']);

        app(\App\Services\Seller\SellerReferralService::class)->activateFor($sellerProfile->user);

        $sellerProfile->user->notify(new SellerApproved());

        $this->audit('Seller approved', $sellerProfile);

        return back()->with('success', 'Seller approved.');
    }

    public function reject(Request $request, SellerProfile $sellerProfile)
    {
        abort_unless(auth()->user()->can('sellers.approve'), 403);

        $request->validate(['reason' => 'required|string|max:1000']);

        $sellerProfile->update(['status' => 'rejected', 'kyc_status' => 'rejected']);
        $sellerProfile->user->notify(new SellerRejected($request->reason));

        $this->audit('Seller rejected', $sellerProfile, ['reason' => $request->reason]);

        return back()->with('success', 'Seller rejected.');
    }

    public function suspend(Request $request, SellerProfile $sellerProfile)
    {
        abort_unless(auth()->user()->can('sellers.suspend'), 403);

        $request->validate(['reason' => 'required|string|max:1000']);

        $sellerProfile->update(['status' => 'suspended']);
        $sellerProfile->user->notify(new AccountNotice(
            'Your Pan Motive store has been suspended',
            "Your listings are hidden and you can't sell for now. Reason: {$request->reason}. Reply through Help & support if you think this is a mistake."
        ));

        $this->audit('Seller suspended', $sellerProfile, ['reason' => $request->reason]);

        return back()->with('success', 'Seller suspended. Their listings are hidden from shoppers.');
    }

    public function reinstate(SellerProfile $sellerProfile)
    {
        abort_unless($sellerProfile->status === 'suspended', 422);

        $sellerProfile->update(['status' => 'approved']);
        $sellerProfile->user->notify(new AccountNotice('Your Pan Motive store is active again', 'Your suspension has been lifted and your listings are visible to shoppers again.'));

        $this->audit('Seller reinstated', $sellerProfile);

        return back()->with('success', 'Seller reinstated. Their listings are live again.');
    }
}
