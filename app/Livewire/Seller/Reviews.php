<?php

namespace App\Livewire\Seller;

use App\Models\Product;
use App\Models\Review;
use App\Models\SellerSavedReply;
use App\Services\AI\AiClient;
use App\Services\Seller\ReviewResponseService;
use App\Support\SellerContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Product reviews on this store's listings: public replies, saved replies, AI drafts and reporting. */
#[Layout('layouts.seller')]
class Reviews extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'all'; // all | unanswered | critical | reported

    #[Url]
    public string $stars = '';

    #[Url(as: 'product')]
    public string $productId = '';

    /** @var array<int, string> */
    public array $reply = [];

    public ?int $reportingId = null;

    public string $reportReason = '';

    public string $reportNote = '';

    public function updating($name): void
    {
        if (in_array($name, ['filter', 'stars', 'productId'], true)) {
            $this->resetPage();
        }
    }

    private function owned(int $id): Review
    {
        return Review::whereHas('product', fn ($q) => $q->where('seller_id', auth()->id()))
            ->with('product', 'user')->findOrFail($id);
    }

    public function respond(int $id, ReviewResponseService $service): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $this->validate(["reply.$id" => 'required|string|min:5|max:1500'], [], ["reply.$id" => 'response']);

        $service->respond($this->owned($id), $this->reply[$id]);
        unset($this->reply[$id]);
        $this->dispatch('toast', message: 'Response posted on the listing.');
    }

    public function edit(int $id): void
    {
        $this->reply[$id] = (string) $this->owned($id)->seller_response;
    }

    public function removeResponse(int $id, ReviewResponseService $service): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $service->remove($this->owned($id));
        $this->dispatch('toast', message: 'Response removed.');
    }

    public function useReply(int $reviewId, int $replyId): void
    {
        $saved = SellerSavedReply::where('seller_id', auth()->id())->findOrFail($replyId);
        $review = $this->owned($reviewId);
        $name = $review->user?->name ?? '';

        $text = SellerSavedReply::interpolate($saved->body, [
            '{buyer_name}' => $name,
            '{buyer_first_name}' => Str::before($name, ' '),
            '{store_name}' => auth()->user()->sellerProfile?->business_name ?? auth()->user()->name,
            '{product_title}' => $review->product->title,
        ]);
        $current = trim($this->reply[$reviewId] ?? '');
        $this->reply[$reviewId] = $current === '' ? $text : $current."\n\n".$text;
        $saved->increment('use_count');
    }

    public function suggest(int $id, ReviewResponseService $service): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $draft = $service->draft($this->owned($id), auth()->user()->sellerProfile?->business_name ?? 'our store');
        $draft === ''
            ? $this->dispatch('toast', message: "Couldn't draft a reply right now.")
            : $this->reply[$id] = $draft;
    }

    public function startReport(int $id): void
    {
        $this->reportingId = $this->owned($id)->id;
        $this->reset('reportReason', 'reportNote');
        $this->resetValidation();
    }

    public function submitReport(ReviewResponseService $service): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $this->validate(['reportReason' => 'required', 'reportNote' => 'nullable|string|max:1000'], ['reportReason.required' => 'Choose a reason.']);
        $service->report($this->owned((int) $this->reportingId), $this->reportReason, $this->reportNote ?: null);
        $this->reportingId = null;
        $this->dispatch('toast', message: 'Reported — Pan Motive will review it. The review stays up meanwhile.');
    }

    public function render(AiClient $ai)
    {
        $sellerId = auth()->id();
        $base = Review::whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))->where('status', 'published');

        $all = (clone $base)->get(['id', 'rating', 'seller_response', 'reported_at', 'created_at']);
        $recent = $all->where('created_at', '>=', now()->subDays(90));
        $prior = $all->whereBetween('created_at', [now()->subDays(180), now()->subDays(90)]);

        $reviews = (clone $base)
            ->when($this->productId !== '', fn ($q) => $q->where('product_id', (int) $this->productId))
            ->when($this->stars !== '', fn ($q) => $q->where('rating', (int) $this->stars))
            ->when($this->filter === 'unanswered', fn ($q) => $q->whereNull('seller_response'))
            ->when($this->filter === 'critical', fn ($q) => $q->where('rating', '<=', 3))
            ->when($this->filter === 'reported', fn ($q) => $q->whereNotNull('reported_at'))
            ->with(['product:id,title,slug', 'user:id,name'])
            // Critical + unanswered first: those are the ones that cost sales.
            ->when($this->filter === 'unanswered', fn ($q) => $q->orderBy('rating'))
            ->latest()
            ->paginate(15);

        return view('livewire.seller.reviews', [
            'reviews' => $reviews,
            'summary' => [
                'total' => $all->count(),
                'avg' => $all->count() ? round($all->avg('rating'), 2) : null,
                'avg_90' => $recent->count() ? round($recent->avg('rating'), 2) : null,
                'avg_prior' => $prior->count() ? round($prior->avg('rating'), 2) : null,
                'unanswered' => $all->whereNull('seller_response')->count(),
                'critical_unanswered' => $all->whereNull('seller_response')->where('rating', '<=', 3)->count(),
                'response_rate' => $all->count() ? round($all->whereNotNull('seller_response')->count() / $all->count() * 100) : null,
                'distribution' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($s) => [$s => $all->where('rating', $s)->count()]),
                'reported' => $all->whereNotNull('reported_at')->count(),
            ],
            'products' => Product::where('seller_id', $sellerId)->whereHas('reviews')->orderBy('title')->get(['id', 'title']),
            'savedReplies' => SellerSavedReply::where('seller_id', $sellerId)->orderByDesc('use_count')->orderBy('title')->get(),
            'aiEnabled' => $ai->isConfigured(),
            'reasons' => ReviewResponseService::REPORT_REASONS,
        ]);
    }
}
