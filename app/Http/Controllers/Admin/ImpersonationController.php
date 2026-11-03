<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "View as this person", for support. Staff accounts can't be viewed this way, it needs its own
 * permission, a reason is required, and both ends are written to the audit log.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user)
    {
        abort_unless($request->user()->can('users.impersonate'), 403);
        abort_if($user->hasAnyRole(['super_admin', 'support_agent', 'finance', 'catalog_manager']), 403, 'Staff accounts cannot be viewed as.');
        abort_if($request->session()->has('impersonator_id'), 422, 'Stop the current session first.');

        $data = $request->validate(['reason' => 'required|string|max:500']);
        $admin = $request->user();

        activity('admin')->causedBy($admin)->performedOn($user)->withProperties(['reason' => $data['reason']])->log('Started viewing as user');

        $request->session()->put('impersonator_id', $admin->id);
        Auth::login($user);

        return redirect()->to($user->sellerProfile ? route('seller.dashboard') : \App\Support\AdminNav::site('home'));
    }

    public function stop(Request $request)
    {
        $adminId = $request->session()->pull('impersonator_id');
        abort_unless($adminId, 403);

        $viewed = $request->user();
        $admin = User::findOrFail($adminId);
        abort_unless($admin->can('users.impersonate'), 403);

        Auth::login($admin);
        activity('admin')->causedBy($admin)->performedOn($viewed)->log('Stopped viewing as user');

        return redirect()->route('admin.users.show', $viewed)->with('success', 'You are back in your admin account.');
    }
}
