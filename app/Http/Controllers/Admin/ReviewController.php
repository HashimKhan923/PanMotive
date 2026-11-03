<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    use AdminTools;

    public function index(Request $request)
    {
        $tab = $request->query('status', 'reported');

        $reviews = Review::with('product:id,title,slug', 'user:id,name')
            ->when($tab === 'reported', fn ($q) => $q->whereNotNull('reported_at')->where('status', 'published'))
            ->when(in_array($tab, ['published', 'pending', 'rejected'], true), fn ($q) => $q->where('status', $tab))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('body', 'like', $this->like($request->q))->orWhere('title', 'like', $this->like($request->q))))
            ->when($request->filled('rating'), fn ($q) => $q->where('rating', $request->integer('rating')))
            ->latest()->paginate(20)->withQueryString();

        $counts = Review::selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        return view('admin.reviews.index', [
            'reviews' => $reviews, 'tab' => $tab,
            'tabs' => [
                'reported' => ['Reported', Review::whereNotNull('reported_at')->where('status', 'published')->count()],
                'published' => ['Published', $counts['published'] ?? 0],
                'pending' => ['Pending', $counts['pending'] ?? 0],
                'rejected' => ['Removed', $counts['rejected'] ?? 0],
                'all' => ['All', $counts->sum()],
            ],
        ]);
    }

    public function decide(Request $request, Review $review)
    {
        $data = $request->validate(['decision' => 'required|in:keep,remove,restore']);
        $wasReported = $review->reported_at !== null;

        $review->update(match ($data['decision']) {
            'keep' => ['status' => 'published', 'reported_at' => null, 'report_reason' => null, 'report_note' => null],
            'restore' => ['status' => 'published'],
            'remove' => ['status' => 'rejected', 'reported_at' => null],
        });
        $review->product?->recalculateRatings();
        $this->audit(['keep' => 'Kept reported review', 'restore' => 'Restored review', 'remove' => 'Removed review'][$data['decision']], $review);

        if ($wasReported) {
            $this->tell($review->product?->seller, 'A decision on the review you reported',
                $data['decision'] === 'remove' ? 'We removed the review and updated your rating.' : 'We checked the review and it stays up.');
        }

        return back()->with('success', $data['decision'] === 'remove' ? 'Review removed. The product rating was updated.' : 'Review is published.');
    }

    public function questions(Request $request)
    {
        $tab = $request->query('status', 'published');

        return view('admin.reviews.questions', [
            'questions' => Question::with('product:id,title,slug', 'answers')
                ->when($tab !== 'all', fn ($q) => $q->where('status', $tab))
                ->when($request->filled('q'), fn ($q) => $q->where('body', 'like', $this->like($request->q)))
                ->latest()->paginate(20)->withQueryString(),
            'tab' => $tab,
            'tabs' => ['published' => 'Published', 'pending' => 'Pending', 'rejected' => 'Removed', 'all' => 'All'],
        ]);
    }

    public function decideQuestion(Request $request, Question $question)
    {
        $data = $request->validate(['decision' => 'required|in:publish,remove']);

        $question->update(['status' => $data['decision'] === 'publish' ? 'published' : 'rejected']);
        $this->audit($data['decision'] === 'publish' ? 'Published question' : 'Removed question', $question);

        return back()->with('success', $data['decision'] === 'publish' ? 'Question published.' : 'Question removed.');
    }
}
