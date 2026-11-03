<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class StaffController extends Controller
{
    use AdminTools;

    private const ROLES = ['super_admin' => 'Super admin', 'support_agent' => 'Support agent', 'finance' => 'Finance', 'catalog_manager' => 'Catalog manager'];

    /** What each permission lets someone do, in plain words, grouped for the matrix. */
    private const PERMISSIONS = [
        'People' => [
            'users.view' => 'See accounts', 'users.manage' => 'Edit and suspend accounts', 'users.impersonate' => 'View as a user',
            'sellers.approve' => 'Approve sellers and identity checks', 'sellers.suspend' => 'Suspend sellers',
            'sellers.manage' => 'Commission, tiers and payout holds', 'staff.manage' => 'Manage staff and roles',
        ],
        'Catalog & content' => [
            'catalog.manage' => 'Products, categories, brands, vehicles', 'reviews.moderate' => 'Moderate reviews and questions',
            'coupons.manage' => 'Coupons', 'marketing.manage' => 'Ads', 'content.manage' => 'Pages and announcements',
        ],
        'Orders & money' => [
            'orders.view' => 'See orders', 'disputes.resolve' => 'Decide disputes', 'refunds.issue' => 'Issue refunds',
            'finance.view' => 'See finance', 'finance.export' => 'Export finance data', 'wallet.adjust' => 'Adjust wallets',
        ],
        'Platform' => [
            'settings.manage' => 'Change settings', 'audit.view' => 'See audit log', 'system.manage' => 'System tools',
        ],
    ];

    public function index()
    {
        return view('admin.staff.index', [
            'staff' => User::whereHas('roles', fn ($q) => $q->whereIn('name', array_keys(self::ROLES)))->with('roles')->orderBy('name')->get(),
            'roles' => self::ROLES,
            'groups' => self::PERMISSIONS,
            'matrix' => Role::whereIn('name', array_keys(self::ROLES))->with('permissions')->get()
                ->mapWithKeys(fn ($r) => [$r->name => $r->permissions->pluck('name')->all()]),
        ]);
    }

    /** New staff get a password-reset link instead of a password someone else has seen. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
        ]);

        $user = User::where('email', $data['email'])->first();
        $isNew = ! $user;
        if ($isNew) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::password(40)]);
        }

        $user->syncRoles(array_values(array_diff($user->getRoleNames()->all(), array_keys(self::ROLES))));
        $user->assignRole($data['role']);
        $this->audit('Added staff member', $user, ['role' => $data['role']]);

        if ($isNew) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return back()->with('success', $isNew ? "Invite sent to {$user->email}." : "{$user->name} is now staff.");
    }

    public function role(Request $request, User $user)
    {
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(self::ROLES))]]);

        if ($data['role'] !== 'super_admin' && $this->wouldLeaveNoSuperAdmin($user)) {
            return back()->with('error', 'There must always be at least one super admin.');
        }

        $old = $user->getRoleNames()->intersect(array_keys(self::ROLES))->first();
        $user->syncRoles([...array_diff($user->getRoleNames()->all(), array_keys(self::ROLES)), $data['role']]);
        $this->audit('Changed staff role', $user, ['from' => $old, 'to' => $data['role']]);

        return back()->with('success', 'Role updated.');
    }

    public function remove(User $user)
    {
        if ($user->is(auth()->user())) {
            return back()->with('error', "You can't remove yourself.");
        }
        if ($this->wouldLeaveNoSuperAdmin($user)) {
            return back()->with('error', 'There must always be at least one super admin.');
        }

        $user->syncRoles(array_values(array_diff($user->getRoleNames()->all(), array_keys(self::ROLES))));
        $this->audit('Removed staff member', $user);

        return back()->with('success', "{$user->name} no longer has staff access.");
    }

    /** Super admin always keeps everything, so nobody can lock the whole team out of the console. */
    public function matrix(Request $request)
    {
        $data = $request->validate(['permissions' => 'array', 'permissions.*' => 'array', 'permissions.*.*' => 'string']);
        $known = collect(self::PERMISSIONS)->flatMap(fn ($g) => array_keys($g))->all();

        foreach (array_diff(array_keys(self::ROLES), ['super_admin']) as $name) {
            $wanted = array_values(array_intersect($data['permissions'][$name] ?? [], $known));
            $role = Role::findByName($name);
            $before = $role->permissions->pluck('name')->sort()->values()->all();
            $role->syncPermissions(Permission::whereIn('name', $wanted)->get());

            if ($before !== collect($wanted)->sort()->values()->all()) {
                $this->audit("Changed permissions for {$name}", null, ['from' => $before, 'to' => $wanted]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('success', 'Permissions saved.');
    }

    private function wouldLeaveNoSuperAdmin(User $user): bool
    {
        return $user->hasRole('super_admin') && User::role('super_admin')->count() <= 1;
    }
}
