<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class TrackController extends Controller
{
    public function form()
    {
        return view('storefront.track.form');
    }

    public function lookup(Request $request)
    {
        $data = $request->validate([
            'order_number' => 'required|string|max:40',
            'email' => 'required|email|max:255',
        ]);

        $order = Order::where('order_number', strtoupper(trim($data['order_number'])))
            ->with('items')
            ->first();

        $email = strtolower(trim($data['email']));
        $ok = $order && strtolower((string) $order->contactEmail()) === $email;

        if (! $ok) {
            return back()->withErrors(['order_number' => 'We couldn\'t find an order matching that number and email.'])->withInput();
        }

        return view('storefront.track.show', compact('order'));
    }
}
