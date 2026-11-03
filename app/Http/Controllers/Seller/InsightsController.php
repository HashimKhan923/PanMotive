<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Seller\InsightsService;
use Illuminate\Http\Request;

class InsightsController extends Controller
{
    public function index(InsightsService $insights)
    {
        $sellerId = auth()->id();

        return view('seller.insights.index', [
            'stale' => $insights->staleListings($sellerId),
            'hotLowStock' => $insights->hotLowStock($sellerId),
            'demand' => $insights->outOfStockDemand($sellerId),
            'questions' => $insights->unansweredQuestions($sellerId),
            'lookers' => $insights->trafficNoSales($sellerId),
            'lowQuality' => $insights->lowQuality($sellerId),
        ]);
    }

    /**
     * One-click markdown on a stale listing — created as a real Sale (Promotions → Sales), so it
     * ends on time, restores the listing's own price, and never stacks on an existing sale.
     */
    public function runSale(Request $request, Product $product, \App\Services\Marketing\SaleEventService $sales)
    {
        abort_unless($product->seller_id === auth()->id(), 403);

        $pct = max(5, min(50, (int) $request->integer('pct', 15)));
        $days = max(1, min(30, (int) $request->integer('days', 14)));

        try {
            $sales->create(auth()->user(), "{$pct}% off — {$product->title}", $pct, now(), now()->addDays($days), [$product->id]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->with('error', 'Couldn\'t start a sale on '.$product->title.' — it already has a sale price.');
        }

        return back()->with('success', "{$pct}% sale started on {$product->title} — ends in {$days} days. Manage it under Promotions → Sales.");
    }
}
