<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Membership\MembershipService;
use Illuminate\Http\Request;
use RuntimeException;

class MembershipController extends Controller
{
    public function __construct(private MembershipService $membership) {}

    public function show()
    {
        return view('storefront.plus', [
            'price' => $this->membership->price(),
            'termMonths' => $this->membership->termMonths(),
            'perks' => config('marketplace.plus.perks', []),
            'active' => $this->membership->isActive(auth()->user()),
        ]);
    }

    public function join(Request $request)
    {
        $request->validate(['auto_renew' => 'sometimes|boolean']);

        try {
            $until = $this->membership->join(auth()->user(), $request->boolean('auto_renew', true));
        } catch (RuntimeException $e) {
            return back()->withErrors(['plus' => 'Not enough PanMotive credit to start membership. Top up your wallet first.']);
        }

        return redirect()->route('plus.show')
            ->with('success', 'Welcome to PanMotive Plus! Active through '.$until->format('M j, Y').'.');
    }

    public function cancel()
    {
        $this->membership->cancelAutoRenew(auth()->user());

        return back()->with('success', 'Auto-renew turned off. Your membership stays active until it expires.');
    }
}
