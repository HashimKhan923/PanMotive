<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerTeamMember;
use App\Services\Seller\TeamService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function accept(Request $request, string $token, TeamService $team)
    {
        $member = SellerTeamMember::where('invite_token', $token)->first();

        if (! $member) {
            return redirect()->route('seller.dashboard')->with('error', 'That invitation link is no longer valid.');
        }

        // Not logged in, or logged in as the wrong account.
        if (! $request->user()) {
            session(['url.intended' => route('seller.team.accept', $token)]);

            return redirect()->route('login')->with('status', 'Sign in with '.$member->email.' to accept your team invitation.');
        }

        try {
            $team->accept($token, $request->user());
        } catch (ValidationException $e) {
            return redirect()->route('seller.dashboard')->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('seller.dashboard')
            ->with('success', 'You\'re on the team. Welcome aboard!');
    }
}
