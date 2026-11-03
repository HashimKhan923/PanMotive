<?php

namespace App\Livewire\Storefront;

use App\Models\Answer;
use App\Models\Product;
use App\Models\Question;
use App\Notifications\NewProductQuestion;
use App\Notifications\QuestionAnswered;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

class ProductQuestions extends Component
{
    use WithPagination;

    public Product $product;

    public bool $showForm = false;

    #[Validate('required|string|min:8|max:500')]
    public string $questionBody = '';

    #[Validate('nullable|string|max:80')]
    public ?string $guestName = null;

    /** @var array<int, string> */
    public array $answerBody = [];

    public ?int $answeringId = null;

    public function mount(Product $product): void
    {
        $this->product = $product;
    }

    public function getCurrentUserNameProperty(): ?string
    {
        return Auth::user()?->name;
    }

    public function ask(): void
    {
        $this->validate();

        if (\App\Models\SellerBlockedBuyer::isBlocked($this->product->seller_id, Auth::id())) {
            $this->addError('questionBody', "This seller isn't accepting questions from your account.");

            return;
        }

        $question = Question::create([
            'product_id' => $this->product->id,
            'user_id' => Auth::id(),
            'author_name' => Auth::check() ? null : ($this->guestName ?: 'Guest'),
            'body' => $this->questionBody,
            'status' => 'published',
        ]);

        // Let the seller know so it lands in their Q&A queue right away.
        if (Auth::id() !== $this->product->seller_id) {
            $this->product->seller?->notify(new NewProductQuestion($question->setRelation('product', $this->product)));
        }

        $this->reset('questionBody', 'guestName', 'showForm');
        $this->dispatch('toast', message: 'Question posted.');
    }

    public function startAnswer(int $questionId): void
    {
        abort_unless(Auth::check(), 403);
        $this->answeringId = $questionId;
    }

    public function answer(int $questionId): void
    {
        abort_unless(Auth::check(), 403);

        $body = trim($this->answerBody[$questionId] ?? '');
        $this->validate(["answerBody.$questionId" => 'required|string|min:2|max:1000']);

        $question = Question::where('product_id', $this->product->id)->findOrFail($questionId);

        $answer = Answer::create([
            'question_id' => $question->id,
            'user_id' => Auth::id(),
            'author_name' => null,
            'is_seller' => Auth::id() === $this->product->seller_id,
            'body' => $body,
        ]);

        // The asker hears back when the seller (not another shopper) answers.
        if ($answer->is_seller && $question->user && $question->user_id !== Auth::id()) {
            $question->user->notify(new QuestionAnswered($answer->setRelation('question', $question->setRelation('product', $this->product))));
        }

        unset($this->answerBody[$questionId]);
        $this->answeringId = null;
        $this->dispatch('toast', message: 'Answer posted.');
    }

    public function render()
    {
        $questions = $this->product->questions()
            ->with(['user:id,name', 'answers.user:id,name'])
            ->paginate(8);

        return view('livewire.storefront.product-questions', [
            'questions' => $questions,
        ]);
    }
}
