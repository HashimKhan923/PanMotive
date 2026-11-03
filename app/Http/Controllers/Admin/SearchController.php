<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ctrl+K: finds across the console, only returning what this staff member may open. */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $user = $request->user();
        $like = '%'.addcslashes($q, '%_\\').'%';
        $rows = collect();

        if ($user->can('orders.view')) {
            Order::where('order_number', 'like', $like)->orWhere('guest_email', 'like', $like)->latest()->take(4)->get()
                ->each(fn ($o) => $rows->push(['kind' => 'Order', 'label' => $o->order_number, 'group' => '$'.number_format((float) $o->total, 2), 'url' => route('admin.orders.show', $o)]));
        }
        if ($user->can('users.view')) {
            User::where('name', 'like', $like)->orWhere('email', 'like', $like)->take(4)->get()
                ->each(fn ($u) => $rows->push(['kind' => 'Person', 'label' => $u->name, 'group' => $u->email, 'url' => route('admin.users.show', $u)]));
        }
        if ($user->can('sellers.approve')) {
            SellerProfile::where('business_name', 'like', $like)->take(4)->get()
                ->each(fn ($s) => $rows->push(['kind' => 'Store', 'label' => $s->business_name, 'group' => ucfirst($s->status), 'url' => route('admin.sellers.show', $s)]));
        }
        if ($user->can('catalog.manage')) {
            Product::where('title', 'like', $like)->orWhere('part_number', 'like', $like)->orWhere('sku', 'like', $like)->take(4)->get()
                ->each(fn ($p) => $rows->push(['kind' => 'Part', 'label' => $p->title, 'group' => $p->status, 'url' => route('admin.products.show', $p)]));
        }

        return response()->json($rows->values());
    }
}
