<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Seller\ReviewResponseService;
use Illuminate\Http\Request;

/** Form-post endpoint for replying to a review (the Reviews page itself is Livewire). */
class ReviewController extends Controller
{
    public function respond(Request $request, Review $review, ReviewResponseService $service)
    {
        abort_unless($review->product?->seller_id === auth()->id(), 403);
        $data = $request->validate(['response' => 'required|string|min:5|max:1500']);

        $service->respond($review, $data['response']);

        return back()->with('success', 'Response posted.');
    }
}
