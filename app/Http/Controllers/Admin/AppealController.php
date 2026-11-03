<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\PolicyViolation;
use App\Services\Seller\AccountHealthService;
use Illuminate\Http\Request;

class AppealController extends Controller
{
    use AdminTools;

    public function index(Request $request)
    {
        $tab = $request->query('status', 'appeals');

        $query = PolicyViolation::with('seller.sellerProfile')
            ->when($tab === 'appeals', fn ($q) => $q->whereNotNull('appeal_submitted_at')->whereNull('appeal_decided_at'))
            ->when($tab === 'active', fn ($q) => $q->where('status', 'active'))
            ->latest();

        return view('admin.appeals.index', [
            'violations' => $query->paginate(25)->withQueryString(),
            'tab' => $tab,
            'tabs' => [
                'appeals' => ['Waiting on a decision', PolicyViolation::whereNotNull('appeal_submitted_at')->whereNull('appeal_decided_at')->count()],
                'active' => ['Active violations', PolicyViolation::where('status', 'active')->count()],
                'all' => ['All', PolicyViolation::count()],
            ],
        ]);
    }

    public function decide(Request $request, PolicyViolation $policyViolation, AccountHealthService $health)
    {
        abort_if($policyViolation->appeal_submitted_at === null || $policyViolation->appeal_decided_at !== null, 422, 'There is no open appeal on this violation.');

        $data = $request->validate(['decision' => 'required|in:uphold,lift']);

        $health->decideAppeal($policyViolation, $data['decision'] === 'uphold', $request->user());
        $this->audit($data['decision'] === 'uphold' ? 'Upheld policy violation' : 'Lifted policy violation', $policyViolation);

        $this->tell($policyViolation->seller, $data['decision'] === 'uphold' ? 'Your appeal was not successful' : 'Your appeal was successful',
            "{$policyViolation->title}: ".($data['decision'] === 'uphold' ? 'the violation stays on your account.' : 'the violation was lifted and no longer counts against your store.'));

        return back()->with('success', $data['decision'] === 'uphold' ? 'Violation upheld.' : 'Violation lifted.');
    }
}
