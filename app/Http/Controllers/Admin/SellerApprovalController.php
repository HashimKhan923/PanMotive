<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerProfile;
use App\Notifications\SellerApproved;
use App\Notifications\SellerRejected;
use Illuminate\Http\Request;

class SellerApprovalController extends Controller
{
    public function index()
    {
        $pending = SellerProfile::where('status', 'pending')->with('user')->latest()->paginate(20);

        return view('admin.sellers.pending', compact('pending'));
    }

    public function show(SellerProfile $sellerProfile)
    {
        return view('admin.sellers.show', ['profile' => $sellerProfile->load('user')]);
    }

    public function approve(SellerProfile $sellerProfile)
    {
        abort_unless(auth()->user()->can('sellers.approve'), 403);

        $sellerProfile->update(['status' => 'approved', 'kyc_status' => 'verified']);

        $sellerProfile->user->notify(new SellerApproved());

        activity()->causedBy(auth()->user())->performedOn($sellerProfile)
            ->log('Seller approved');

        return back()->with('success', 'Seller approved.');
    }

    public function reject(Request $request, SellerProfile $sellerProfile)
    {
        abort_unless(auth()->user()->can('sellers.approve'), 403);

        $request->validate(['reason' => 'required|string|max:1000']);

        $sellerProfile->update(['status' => 'rejected', 'kyc_status' => 'rejected']);
        $sellerProfile->user->notify(new SellerRejected($request->reason));

        activity()->causedBy(auth()->user())->performedOn($sellerProfile)
            ->withProperties(['reason' => $request->reason])
            ->log('Seller rejected');

        return back()->with('success', 'Seller rejected.');
    }

    public function suspend(Request $request, SellerProfile $sellerProfile)
    {
        abort_unless(auth()->user()->can('sellers.suspend'), 403);

        $request->validate(['reason' => 'required|string|max:1000']);

        $sellerProfile->update(['status' => 'suspended']);

        activity()->causedBy(auth()->user())->performedOn($sellerProfile)
            ->withProperties(['reason' => $request->reason])
            ->log('Seller suspended');

        return back()->with('success', 'Seller suspended.');
    }
}
