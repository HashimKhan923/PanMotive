<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\EmailPreferences;
use Illuminate\Http\Request;

/**
 * Turn off one kind of optional email from the link in that email, without logging in. Same
 * pattern as marketing unsubscribe: GET only asks (link scanners open every link), POST does it,
 * and POST is also the RFC 8058 one-click target that Gmail's "Unsubscribe" button calls.
 */
class EmailStopController extends Controller
{
    public function show(User $user, string $category)
    {
        $info = $this->category($category);

        return view('storefront.email-stop', [
            'label' => $info['label'],
            'hint' => $info['hint'],
            'done' => ! EmailPreferences::wants($user, $category),
        ]);
    }

    public function store(Request $request, User $user, string $category)
    {
        $info = $this->category($category);
        EmailPreferences::set($user, $category, $request->input('action') === 'resume');

        if ($request->has('List-Unsubscribe') || $request->expectsJson()) {
            return response()->noContent();
        }

        return view('storefront.email-stop', [
            'label' => $info['label'],
            'hint' => $info['hint'],
            'done' => ! EmailPreferences::wants($user->fresh(), $category),
        ]);
    }

    /** @return array{label: string, hint: string, seller: bool} */
    private function category(string $category): array
    {
        return EmailPreferences::categories()[$category] ?? abort(404);
    }
}
