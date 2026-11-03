<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    use AdminTools;

    public function index(Request $request)
    {
        $owner = $request->query('owner', 'platform');

        return view('admin.coupons.index', [
            'coupons' => Coupon::with('seller.sellerProfile')
                ->when($owner === 'platform', fn ($q) => $q->whereNull('seller_id'))
                ->when($owner === 'seller', fn ($q) => $q->whereNotNull('seller_id'))
                ->when($request->filled('q'), fn ($q) => $q->where('code', 'like', $this->like($request->q)))
                ->latest()->paginate(25)->withQueryString(),
            'owner' => $owner,
            'tabs' => [
                'platform' => ['Pan Motive pays', Coupon::whereNull('seller_id')->count()],
                'seller' => ['Sellers pay', Coupon::whereNotNull('seller_id')->count()],
            ],
            'categories' => Category::whereNull('parent_id')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge(['code' => Str::upper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => 'required|string|min:3|max:30|alpha_dash|unique:coupons,code',
            'type' => 'required|in:percentage,fixed',
            'value' => ['required', 'numeric', 'min:0.01', $request->input('type') === 'percentage' ? 'max:100' : 'max:10000'],
            'min_order_total' => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'usage_limit_per_user' => 'nullable|integer|min:1',
            'category_id' => 'nullable|exists:categories,id',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
        ]);

        $coupon = Coupon::create($data + ['is_active' => true]);
        $this->audit('Created coupon', $coupon, $data);

        return back()->with('success', "{$coupon->code} is live. Pan Motive pays for this discount.");
    }

    public function toggle(Coupon $coupon)
    {
        $coupon->update(['is_active' => ! $coupon->is_active]);
        $this->audit($coupon->is_active ? 'Enabled coupon' : 'Disabled coupon', $coupon);

        return back()->with('success', "{$coupon->code} ".($coupon->is_active ? 'enabled.' : 'disabled.'));
    }
}
