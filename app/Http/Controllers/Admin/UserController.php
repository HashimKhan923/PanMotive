<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Marketing\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use AdminTools;

    public const STAFF_ROLES = ['super_admin', 'support_agent', 'finance', 'catalog_manager'];

    public function index(Request $request)
    {
        $tab = $request->query('type', '');
        $staff = fn ($q) => $q->whereIn('name', self::STAFF_ROLES);
        $sellers = fn ($q) => $q->where('name', 'seller');

        $query = User::with('roles', 'sellerProfile:id,user_id,business_name,slug')
            ->withCount('orders')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', $this->like($request->q))
                ->orWhere('email', 'like', $this->like($request->q))
                ->orWhere('phone', 'like', $this->like($request->q))))
            ->when($tab === 'buyers', fn ($q) => $q->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', [...self::STAFF_ROLES, 'seller'])))
            ->when($tab === 'sellers', fn ($q) => $q->whereHas('roles', $sellers))
            ->when($tab === 'staff', fn ($q) => $q->whereHas('roles', $staff))
            ->when($tab === 'plus', fn ($q) => $q->where('plus_until', '>', now()))
            ->when($tab === 'banned', fn ($q) => $q->where('is_active', false));

        $query->when($request->query('sort') === 'spend', fn ($q) => $q->orderByDesc('lifetime_spend'), fn ($q) => $q->latest());

        return view('admin.users.index', [
            'users' => $query->paginate(25)->withQueryString(),
            'tab' => $tab,
            'tabs' => [
                '' => ['All', User::count()],
                'buyers' => ['Buyers', User::whereDoesntHave('roles', fn ($r) => $r->whereIn('name', [...self::STAFF_ROLES, 'seller']))->count()],
                'sellers' => ['Sellers', User::whereHas('roles', $sellers)->count()],
                'staff' => ['Staff', User::whereHas('roles', $staff)->count()],
                'plus' => ['Plus members', User::where('plus_until', '>', now())->count()],
                'banned' => ['Suspended', User::where('is_active', false)->count()],
            ],
        ]);
    }

    public function show(User $user)
    {
        $user->load('roles', 'sellerProfile');

        return view('admin.users.show', [
            'user' => $user,
            'isStaff' => $user->hasAnyRole(self::STAFF_ROLES),
            'orders' => $user->orders()->latest()->take(10)->get(),
            'orderTotal' => (float) $user->orders()->whereNotIn('status', ['placed', 'cancelled'])->sum('total'),
            'orderCount' => $user->orders()->count(),
            'credits' => $user->creditTransactions()->latest()->take(10)->get(),
            'signals' => [
                'Refunded items' => \App\Models\OrderItem::whereHas('order', fn ($q) => $q->where('buyer_id', $user->id))->where('status', 'refunded')->count(),
                'Return requests' => \App\Models\ReturnRequest::where('user_id', $user->id)->count(),
                'Claims opened' => \App\Models\BuyerClaim::where('buyer_id', $user->id)->count(),
                'Stores that blocked them' => \Illuminate\Support\Facades\DB::table('seller_blocked_buyers')->where('buyer_id', $user->id)->count(),
            ],
            'history' => \Spatie\Activitylog\Models\Activity::where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->with('causer')->latest()->take(10)->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->guardStaff($user);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => 'nullable|string|max:30',
        ]);

        $before = $user->only(array_keys($data));
        $user->forceFill($data + ($data['email'] !== $user->email ? ['email_verified_at' => null] : []))->save();
        $this->audit('Edited user details', $user, ['before' => $before, 'after' => $data]);

        return back()->with('success', 'Details saved.');
    }

    public function ban(Request $request, User $user)
    {
        $this->guardStaff($user);
        abort_if($user->is($request->user()), 422, 'You cannot suspend your own account.');

        $data = $request->validate(['reason' => 'required|string|max:255']);

        $user->forceFill(['is_active' => false, 'banned_reason' => $data['reason']])->save();
        $this->audit('Suspended account', $user, ['reason' => $data['reason']]);

        return back()->with('success', "{$user->name} is suspended and has been signed out.");
    }

    public function unban(User $user)
    {
        $this->guardStaff($user);

        $user->forceFill(['is_active' => true, 'banned_reason' => null])->save();
        $this->audit('Restored account', $user);

        return back()->with('success', "{$user->name} can sign in again.");
    }

    public function sendPasswordReset(User $user)
    {
        $this->guardStaff($user);

        $status = Password::sendResetLink(['email' => $user->email]);
        $this->audit('Sent password reset link', $user);

        return back()->with($status === Password::RESET_LINK_SENT ? 'success' : 'error',
            $status === Password::RESET_LINK_SENT ? 'Password reset link sent.' : 'A link was sent very recently. Please wait a minute and try again.');
    }

    public function setPlus(Request $request, User $user)
    {
        $data = $request->validate(['until' => 'nullable|date|after:today', 'reason' => 'required|string|max:255']);

        $user->forceFill(['plus_until' => $data['until'] ?? null, 'plus_auto_renew' => false])->save();
        $this->audit(filled($data['until'] ?? null) ? 'Granted Plus membership' : 'Ended Plus membership', $user, $data);

        return back()->with('success', 'Plus membership updated.');
    }

    /** Store credit is real money the platform owes, so every change needs a reason and stays in the ledger. */
    public function adjustWallet(Request $request, User $user, WalletService $wallet)
    {
        $data = $request->validate([
            'direction' => 'required|in:add,remove',
            'amount' => 'required|numeric|min:0.01|max:10000',
            'reason' => 'required|string|max:255',
        ]);

        $amount = round((float) $data['amount'], 2);
        if ($data['direction'] === 'remove' && $amount > (float) $user->wallet_balance) {
            return back()->withErrors(['amount' => 'That is more than their $'.number_format((float) $user->wallet_balance, 2).' balance.']);
        }

        $wallet->credit($user, $data['direction'] === 'add' ? $amount : -$amount, 'admin_adjustment', 'Panmotive adjustment: '.$data['reason']);
        $this->audit('Adjusted wallet', $user, $data);

        return back()->with('success', 'Wallet is now $'.number_format((float) $user->fresh()->wallet_balance, 2).'.');
    }

    /** Only someone who manages staff can change another staff account. */
    private function guardStaff(User $user): void
    {
        abort_if($user->hasAnyRole(self::STAFF_ROLES) && ! auth()->user()->can('staff.manage'), 403);
    }
}
