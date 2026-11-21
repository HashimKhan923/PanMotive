<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shop\ShopPresenter;
use App\Models\Answer;
use App\Models\Product;
use App\Models\Question;
use App\Models\Review;
use App\Models\SellerBlockedBuyer;
use App\Notifications\NewProductQuestion;
use App\Notifications\QuestionAnswered;
use App\Services\Cart\WishlistService;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Wishlist (with price-drop info), reviews and questions & answers, for the shopping app. */
class EngagementController extends Controller
{
    public function wishlist(WishlistService $wishlist): JsonResponse
    {
        $list = $wishlist->current();
        $products = $list->products()->listed()->with('seller.sellerProfile', 'media')->withCount('activeVariants')
            ->orderByPivot('created_at', 'desc')->get();
        $addedAt = DB::table('wishlist_items')->where('wishlist_id', $list->id)->pluck('price_at_add', 'product_id');

        $present = new ShopPresenter;
        $present->prime($products);

        return response()->json([
            'products' => $products->map(function (Product $p) use ($present, $addedAt) {
                $card = $present->card($p);
                $then = isset($addedAt[$p->id]) ? (float) $addedAt[$p->id] : null;
                $card['saved'] = true;
                $card['price_when_saved'] = $then;
                $card['dropped_by'] = $then && $card['effective_price'] < $then ? round($then - $card['effective_price'], 2) : null;

                return $card;
            })->values(),
        ]);
    }

    public function wishlistIds(WishlistService $wishlist): JsonResponse
    {
        return response()->json(['ids' => $wishlist->ids()]);
    }

    public function toggleWishlist(Request $request, WishlistService $wishlist): JsonResponse
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);

        return response()->json(['saved' => $wishlist->toggle((int) $data['product_id']), 'count' => $wishlist->count()]);
    }

    public function reviews(Request $request, int $productId, ReviewService $service): JsonResponse
    {
        $product = Product::listed()->findOrFail($productId);
        $reviews = $product->reviews()->where('status', 'published')->with('user:id,name')
            ->when($request->input('sort') === 'helpful', fn ($q) => $q->orderByDesc('helpful_count'))
            ->latest()->paginate(10, ['*'], 'page', (int) $request->input('page', 1));
        $user = $request->user();

        return response()->json([
            'reviews' => $reviews->getCollection()->map(fn (Review $r) => [
                'id' => $r->id,
                'rating' => (int) $r->rating,
                'title' => $r->title,
                'body' => $r->body,
                'author' => $r->user ? self::firstNameInitial($r->user->name) : 'Shopper',
                'verified' => (bool) $r->is_verified,
                'helpful' => (int) $r->helpful_count,
                'seller_response' => $r->seller_response,
                'date' => $r->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ShopPresenter::page($reviews),
            'can_review' => $user ? $service->canReview($user, $product) : false,
            'has_reviewed' => $user ? Review::where('product_id', $product->id)->where('user_id', $user->id)->exists() : false,
        ]);
    }

    public function writeReview(Request $request, int $productId, ReviewService $service): JsonResponse
    {
        $product = Product::listed()->findOrFail($productId);
        abort_unless($service->canReview($request->user(), $product), 403, 'You can review parts you\'ve bought and received.');
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:120',
            'body' => 'required|string|min:10|max:2000',
        ]);

        $review = $service->submit($request->user(), $product, (int) $data['rating'], $data['title'] ?? null, $data['body']);

        return response()->json(['message' => 'Thanks — your review is published.', 'id' => $review->id], 201);
    }

    public function helpful(Request $request, int $reviewId): JsonResponse
    {
        // Once per shopper (or per phone for guests).
        $who = $request->user()?->id ?? (app()->bound('shop.device') ? app('shop.device') : $request->ip());
        if (Cache::add("review-helpful:{$reviewId}:{$who}", 1, now()->addYear())) {
            Review::whereKey($reviewId)->where('status', 'published')->increment('helpful_count');
        }

        return response()->json(['helpful' => (int) Review::whereKey($reviewId)->value('helpful_count')]);
    }

    public function questions(Request $request, int $productId): JsonResponse
    {
        $product = Product::listed()->findOrFail($productId);
        $questions = $product->questions()->with(['user:id,name', 'answers.user:id,name'])
            ->latest()->paginate(10, ['*'], 'page', (int) $request->input('page', 1));

        return response()->json([
            'questions' => $questions->getCollection()->map(fn (Question $q) => [
                'id' => $q->id,
                'body' => $q->body,
                'author' => $q->user ? self::firstNameInitial($q->user->name) : ($q->author_name ?: 'Shopper'),
                'date' => $q->created_at?->toIso8601String(),
                'answers' => $q->answers->map(fn (Answer $a) => [
                    'id' => $a->id,
                    'body' => $a->body,
                    'is_seller' => (bool) $a->is_seller,
                    'author' => $a->is_seller ? 'Seller' : ($a->user ? self::firstNameInitial($a->user->name) : ($a->author_name ?: 'Shopper')),
                    'date' => $a->created_at?->toIso8601String(),
                ])->values(),
            ])->values(),
            'meta' => ShopPresenter::page($questions),
        ]);
    }

    public function ask(Request $request, int $productId): JsonResponse
    {
        $product = Product::listed()->findOrFail($productId);
        $data = $request->validate(['body' => 'required|string|min:8|max:500']);

        if (SellerBlockedBuyer::isBlocked($product->seller_id, $request->user()->id)) {
            return response()->json(['message' => 'This seller isn\'t accepting questions from your account.'], 422);
        }

        $question = Question::create([
            'product_id' => $product->id, 'user_id' => $request->user()->id, 'author_name' => null,
            'body' => $data['body'], 'status' => 'published',
        ]);
        if ($request->user()->id !== $product->seller_id) {
            $product->seller?->notify(new NewProductQuestion($question->setRelation('product', $product)));
        }

        return response()->json(['message' => 'Question posted.', 'id' => $question->id], 201);
    }

    public function answer(Request $request, int $questionId): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|min:2|max:1000']);
        $question = Question::with('product')->findOrFail($questionId);
        abort_unless($question->product && $question->product->isListed(), 404);

        $answer = Answer::create([
            'question_id' => $question->id, 'user_id' => $request->user()->id, 'author_name' => null,
            'is_seller' => $request->user()->id === $question->product->seller_id, 'body' => $data['body'],
        ]);
        if ($answer->is_seller && $question->user && $question->user_id !== $request->user()->id) {
            $question->user->notify(new QuestionAnswered($answer->setRelation('question', $question)));
        }

        return response()->json(['message' => 'Answer posted.', 'id' => $answer->id], 201);
    }

    /** "Sarah K." — shoppers' full names are never shown to other shoppers. */
    private static function firstNameInitial(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $first = $parts[0] ?? 'Shopper';

        return count($parts) > 1 ? $first.' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : $first;
    }
}
