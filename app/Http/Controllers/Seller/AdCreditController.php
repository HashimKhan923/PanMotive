<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\AdCreditPurchase;
use App\Services\Seller\AdCreditPurchaseService;
use Illuminate\Http\Request;

class AdCreditController extends Controller
{
    /** Stripe Checkout sends the seller back here after paying. */
    public function return(Request $request, AdCreditPurchaseService $purchases)
    {
        $sessionId = (string) $request->query('session_id');

        // Only ever confirm this seller's own purchase.
        abort_unless(
            $sessionId !== '' && AdCreditPurchase::where('seller_id', auth()->id())->where('stripe_session_id', $sessionId)->exists(),
            404
        );

        try {
            $purchase = $purchases->fulfil($sessionId);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('seller.ad-credit.index')
                ->with('success', 'Payment received — your credit will appear in a moment.');
        }

        return $purchase?->status === 'paid'
            ? redirect()->route('seller.ad-credit.index')
                ->with('success', '$'.number_format((float) $purchase->amount, 2).' ad credit added — your ads are good to go.')
                ->with('celebrate', true)
            : redirect()->route('seller.ad-credit.index')
                ->with('success', 'Payment is still processing — your credit will appear as soon as it clears.');
    }
}
