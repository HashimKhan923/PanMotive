<?php

namespace App\Livewire\Storefront;

use App\Models\Product;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

class ProductReviews extends Component
{
    use WithPagination;

    public Product $product;

    public bool $showForm = false;

    #[Validate('required|integer|min:1|max:5')]
    public int $rating = 5;

    #[Validate('nullable|string|max:120')]
    public ?string $title = null;

    #[Validate('required|string|min:10|max:2000')]
    public ?string $body = null;

    public function mount(Product $product): void
    {
        $this->product = $product;
    }

    public function getCanReviewProperty(ReviewService $reviews): bool
    {
        return Auth::check() && $reviews->canReview(Auth::user(), $this->product);
    }

    public function getHasReviewedProperty(): bool
    {
        return Auth::check()
            && Review::where('product_id', $this->product->id)->where('user_id', Auth::id())->exists();
    }

    public function submit(ReviewService $reviews): void
    {
        abort_unless($this->canReview, 403);

        $this->validate();

        $reviews->submit(Auth::user(), $this->product, $this->rating, $this->title, $this->body);

        $this->reset('showForm', 'title', 'body');
        $this->rating = 5;
        $this->product->refresh();
        $this->dispatch('toast', message: 'Thanks — your review is published.');
    }

    public function markHelpful(int $reviewId): void
    {
        $key = 'helpful_review_'.$reviewId;

        if (session()->has($key)) {
            return;
        }

        Review::whereKey($reviewId)->increment('helpful_count');
        session()->put($key, true);
    }

    public function render()
    {
        $reviews = $this->product->reviews()
            ->where('status', 'published')
            ->with('user:id,name')
            ->latest()
            ->paginate(5);

        $counts = $this->product->reviews()
            ->where('status', 'published')
            ->selectRaw('rating, COUNT(*) as c')
            ->groupBy('rating')
            ->pluck('c', 'rating');

        $breakdown = collect(range(5, 1))->mapWithKeys(fn ($star) => [$star => (int) ($counts[$star] ?? 0)]);

        return view('livewire.storefront.product-reviews', [
            'reviews' => $reviews,
            'breakdown' => $breakdown,
            'total' => $breakdown->sum(),
        ]);
    }
}
