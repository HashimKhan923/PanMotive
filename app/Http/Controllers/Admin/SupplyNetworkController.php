<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SupplyConnection;
use App\Services\Seller\SupplyNetworkService;
use Illuminate\Http\Request;

/** Admin view of the Supply Network: every partnership, resold sales and margins, and a way to end one. */
class SupplyNetworkController extends Controller
{
    use AdminTools;

    public function index(Request $request)
    {
        $status = $request->query('status', '');
        $counts = SupplyConnection::selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');
        $sold = OrderItem::whereNotNull('reseller_id')->whereNotIn('status', ['placed', 'cancelled', 'refunded']);

        return view('admin.supply.index', [
            'connections' => SupplyConnection::with('supplier.sellerProfile', 'reseller.sellerProfile')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->latest()->paginate(25)->withQueryString(),
            'recent' => OrderItem::whereNotNull('reseller_id')->with('order:id,order_number', 'seller.sellerProfile', 'reseller.sellerProfile')
                ->latest('id')->take(20)->get(),
            'status' => $status,
            'tabs' => [
                '' => ['All', $counts->sum()], 'pending' => ['Pending', $counts['pending'] ?? 0],
                'approved' => ['Active', $counts['approved'] ?? 0], 'ended' => ['Ended', ($counts['ended'] ?? 0) + ($counts['declined'] ?? 0)],
            ],
            'stats' => [
                'sales' => (float) (clone $sold)->sum('line_total'),
                'margins' => (float) (clone $sold)->sum('reseller_margin'),
                'listings' => Product::whereNotNull('source_product_id')->where('status', 'active')->count(),
            ],
        ]);
    }

    public function end(Request $request, SupplyConnection $connection, SupplyNetworkService $supply)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $supply->end($connection, $request->user());
        $this->audit('Ended Supply Network partnership', $connection, $data);

        return back()->with('success', 'Partnership ended. The reseller\'s copies are no longer for sale.');
    }
}
