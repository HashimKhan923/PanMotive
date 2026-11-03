<?php

namespace App\Livewire\Seller;

use App\Models\Answer;
use App\Models\Product;
use App\Models\Question;
use App\Models\SellerSavedReply;
use App\Notifications\QuestionAnswered;
use App\Services\AI\AiClient;
use App\Support\SellerContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Every shopper question on this store's listings, with inline answering. */
#[Layout('layouts.seller')]
class Questions extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'unanswered';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'product')]
    public string $productId = '';

    /** @var array<int, string> */
    public array $reply = [];

    public ?int $editingAnswerId = null;

    public string $editBody = '';

    public function updating($name): void
    {
        if (in_array($name, ['filter', 'search', 'productId'], true)) {
            $this->resetPage();
        }
    }

    private function owned(int $id): Question
    {
        return Question::whereHas('product', fn ($q) => $q->where('seller_id', auth()->id()))
            ->with('product', 'user')->findOrFail($id);
    }

    private function ownedAnswer(int $answerId): Answer
    {
        return Answer::where('is_seller', true)
            ->whereHas('question.product', fn ($q) => $q->where('seller_id', auth()->id()))
            ->findOrFail($answerId);
    }

    public function answer(int $id): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $this->validate(["reply.$id" => 'required|string|min:2|max:1000'], [], ["reply.$id" => 'answer']);

        $question = $this->owned($id);
        $answer = Answer::create([
            'question_id' => $question->id,
            'user_id' => auth()->id(),
            'is_seller' => true,
            'body' => trim($this->reply[$id]),
        ]);

        if ($question->user && $question->user_id !== auth()->id()) {
            $question->user->notify(new QuestionAnswered($answer->setRelation('question', $question)));
        }

        unset($this->reply[$id]);
        $this->dispatch('toast', message: 'Answer published on the listing.');
    }

    public function editAnswer(int $answerId): void
    {
        $this->editingAnswerId = $this->ownedAnswer($answerId)->id;
        $this->editBody = $this->ownedAnswer($answerId)->body;
        $this->resetValidation();
    }

    /** Fix a typo or update an answer (e.g. after a restock) without losing its place on the listing. */
    public function updateAnswer(): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $this->validate(['editBody' => 'required|string|min:2|max:1000'], [], ['editBody' => 'answer']);

        $this->ownedAnswer((int) $this->editingAnswerId)->update(['body' => trim($this->editBody)]);
        $this->reset('editingAnswerId', 'editBody');
        $this->dispatch('toast', message: 'Answer updated.');
    }

    public function deleteAnswer(int $answerId): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $this->ownedAnswer($answerId)->delete();
        $this->dispatch('toast', message: 'Answer removed.');
    }

    /** Hide spam / abusive questions from the listing (or bring them back). */
    public function toggleHidden(int $id): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $question = $this->owned($id);
        $question->update(['status' => $question->status === 'rejected' ? 'published' : 'rejected']);
        $this->dispatch('toast', message: $question->status === 'rejected' ? 'Question hidden from the listing.' : 'Question restored.');
    }

    public function useReply(int $questionId, int $replyId): void
    {
        $saved = SellerSavedReply::where('seller_id', auth()->id())->findOrFail($replyId);
        $question = $this->owned($questionId);
        $name = $question->user?->name ?? $question->author_name ?? '';

        $text = SellerSavedReply::interpolate($saved->body, [
            '{buyer_name}' => $name,
            '{buyer_first_name}' => Str::before($name, ' '),
            '{store_name}' => auth()->user()->sellerProfile?->business_name ?? auth()->user()->name,
            '{product_title}' => $question->product->title,
        ]);

        $current = trim($this->reply[$questionId] ?? '');
        $this->reply[$questionId] = $current === '' ? $text : $current."\n\n".$text;
        $saved->increment('use_count');
    }

    /** Suggest an answer from the listing's own details and fitment — the seller reviews before publishing. */
    public function suggestAnswer(int $questionId, AiClient $ai): void
    {
        abort_unless(SellerContext::can('messages'), 403);
        $question = $this->owned($questionId);
        $product = $question->product->load('fitments.make', 'fitments.model', 'brand');

        if (! $ai->isConfigured()) {
            return;
        }

        $fitment = $product->fitments->take(25)->map(fn ($v) => $v->label())->implode('; ');
        $context = "Product: {$product->title}"
            .($product->brand ? "\nBrand: {$product->brand->name}" : '')
            .($product->sku ? "\nSKU / part number: {$product->sku}" : '')
            ."\nPrice: $".number_format((float) $product->effectivePrice(), 2)
            ."\nIn stock: ".max(0, (int) $product->quantity)
            ."\nDescription: ".Str::limit(strip_tags((string) $product->description), 1200)
            .($fitment ? "\nConfirmed fitment: {$fitment}".($product->fitments->count() > 25 ? ' (and more)' : '') : "\nConfirmed fitment: none listed");

        try {
            $draft = trim($ai->complete(
                'You answer shopper questions on an auto-parts listing for the seller. Answer in 1–3 short sentences using ONLY the facts given. '
                .'If the facts do not answer it (e.g. fitment for a vehicle not listed), say so honestly and ask for the year/make/model or VIN. '
                .'No greetings, no signature, no promises about shipping dates or discounts.',
                "{$context}\n\nShopper question: {$question->body}",
                220,
            ));
        } catch (\Throwable $e) {
            report($e);
            $draft = '';
        }

        if ($draft === '') {
            $this->dispatch('toast', message: "Couldn't suggest an answer right now.");

            return;
        }

        $this->reply[$questionId] = $draft;
    }

    public function render(AiClient $ai)
    {
        $sellerId = auth()->id();
        $target = config('marketplace.messages.question_target_hours', 48);
        $base = Question::whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))
            ->when($this->productId !== '', fn ($q) => $q->where('product_id', (int) $this->productId));

        $unanswered = fn ($q) => $q->where('status', 'published')->whereDoesntHave('answers', fn ($a) => $a->where('is_seller', true));

        $counts = [
            'unanswered' => $unanswered(clone $base)->count(),
            'answered' => (clone $base)->where('status', 'published')
                ->whereHas('answers', fn ($a) => $a->where('is_seller', true))->count(),
            'hidden' => (clone $base)->where('status', 'rejected')->count(),
        ];

        $questions = (clone $base)
            ->when($this->filter === 'unanswered', $unanswered)
            ->when($this->filter === 'answered', fn ($q) => $q->where('status', 'published')
                ->whereHas('answers', fn ($a) => $a->where('is_seller', true)))
            ->when($this->filter === 'hidden', fn ($q) => $q->where('status', 'rejected'))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('body', 'like', '%'.trim($this->search).'%')
                ->orWhereHas('product', fn ($p) => $p->where('title', 'like', '%'.trim($this->search).'%'))))
            ->with('product:id,title,slug', 'user:id,name', 'answers.user:id,name')
            // Oldest first while there's a queue to clear; newest first when browsing history.
            ->when($this->filter === 'unanswered', fn ($q) => $q->oldest(), fn ($q) => $q->latest())
            ->paginate(15);

        // Answer speed over the last 30 days (time from question to the seller's first answer).
        $recent = Question::whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))
            ->where('created_at', '>=', now()->subDays(30))->where('status', 'published')
            ->with(['answers' => fn ($a) => $a->where('is_seller', true)->oldest()])
            ->get(['id', 'created_at']);
        $hours = $recent->map(fn ($q) => $q->answers->first() ? $q->created_at->diffInMinutes($q->answers->first()->created_at) / 60 : null);
        $answeredHours = $hours->filter(fn ($h) => $h !== null);
        $judged = $recent->filter(fn ($q) => $q->answers->isNotEmpty() || $q->created_at->lt(now()->subHours($target)));

        return view('livewire.seller.questions', [
            'questions' => $questions,
            'counts' => $counts,
            'stats' => [
                'overdue' => $unanswered(Question::whereHas('product', fn ($q) => $q->where('seller_id', $sellerId)))
                    ->where('created_at', '<', now()->subHours($target))->count(),
                'avg_hours' => $answeredHours->isNotEmpty() ? round($answeredHours->avg(), 1) : null,
                'within_target' => $judged->isNotEmpty()
                    ? round($judged->filter(fn ($q) => $q->answers->isNotEmpty() && $q->created_at->diffInMinutes($q->answers->first()->created_at) <= $target * 60)->count() / $judged->count() * 100, 1)
                    : null,
            ],
            'targetHours' => $target,
            'products' => Product::where('seller_id', $sellerId)->whereHas('questions')->orderBy('title')->get(['id', 'title']),
            'savedReplies' => SellerSavedReply::where('seller_id', $sellerId)->orderByDesc('use_count')->orderBy('title')->get(),
            'aiEnabled' => $ai->isConfigured(),
        ]);
    }
}
