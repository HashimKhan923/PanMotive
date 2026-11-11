<?php

namespace App\Livewire\Storefront;

use App\Models\MarketingUnsubscribe;
use App\Services\Marketing\MarketingConsent;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Buyer-side control over store marketing: global email opt-out, per-store opt-outs, SMS opt-in. */
#[Layout('layouts.storefront')]
class MarketingPreferences extends Component
{
    public string $phone = '';

    public function mount(): void
    {
        $this->phone = (string) auth()->user()->phone;
    }

    public function toggleAll(MarketingConsent $consent): void
    {
        $user = auth()->user();
        $off = MarketingUnsubscribe::where('user_id', $user->id)->whereNull('seller_id')->exists();
        $off ? $consent->resubscribe($user, null) : $consent->unsubscribe($user, null, 'preferences');
        $this->dispatch('toast', message: $off ? 'Store marketing emails are back on.' : 'Unsubscribed from all store marketing.');
    }

    public function resubscribe(int $sellerId, MarketingConsent $consent): void
    {
        $consent->resubscribe(auth()->user(), $sellerId);
        $this->dispatch('toast', message: 'Resubscribed.');
    }

    /** Switch one optional email (price drops, Q&A answers…) on or off. */
    public function toggleEmail(string $category): void
    {
        $user = auth()->user();
        abort_unless(array_key_exists($category, \App\Support\EmailPreferences::for($user)), 404);

        $on = ! \App\Support\EmailPreferences::wants($user, $category);
        \App\Support\EmailPreferences::set($user, $category, $on);
        $label = \App\Support\EmailPreferences::categories()[$category]['label'];
        $this->dispatch('toast', message: $label.' emails '.($on ? 'on.' : 'off.'));
    }

    public function toggleSms(): void
    {
        $user = auth()->user();
        if ($user->sms_opt_in_at) {
            $user->forceFill(['sms_opt_in_at' => null])->save();
            $this->dispatch('toast', message: 'Text messages turned off.');

            return;
        }

        $this->validate(['phone' => ['required', 'regex:/^\+?[0-9 ()\-.]{10,20}$/']], ['phone.regex' => 'Enter a valid mobile number.']);
        $user->forceFill(['phone' => trim($this->phone), 'sms_opt_in_at' => now()])->save();
        $this->dispatch('toast', message: 'Text messages on. Reply STOP to any text to opt out.');
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.storefront.marketing-preferences', [
            'allOff' => MarketingUnsubscribe::where('user_id', $user->id)->whereNull('seller_id')->exists(),
            'stores' => MarketingUnsubscribe::where('user_id', $user->id)->whereNotNull('seller_id')
                ->with('seller.sellerProfile')->latest()->get(),
            'smsOn' => $user->sms_opt_in_at !== null,
            'emailTypes' => \App\Support\EmailPreferences::for($user),
            'emailOff' => (array) $user->email_opt_outs,
        ]);
    }
}
