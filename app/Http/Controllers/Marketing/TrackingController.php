<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\AutomationSend;
use App\Models\CampaignRecipient;
use Illuminate\Support\Facades\Cookie;

class TrackingController extends Controller
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private function pixel()
    {
        return response(base64_decode(self::GIF), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /** Automation email open pixel. */
    public function automationOpen(string $token)
    {
        AutomationSend::where('token', $token)->whereNull('opened_at')->update(['opened_at' => now()]);

        return $this->pixel();
    }

    /** Automation click-through — records the click, drops the campaign attribution cookie, redirects. */
    public function automationClick(string $token)
    {
        $send = AutomationSend::with('automation.seller.sellerProfile')->where('token', $token)->first();

        if (! $send) {
            return redirect()->route('home');
        }

        $send->increment('clicks');
        \App\Models\MarketingEvent::click($send->automation->seller_id, 'automation', $send->marketing_automation_id);
        $send->forceFill(['clicked_at' => $send->clicked_at ?? now()])->save();

        Cookie::queue('pm_automation', $token, 60 * 24 * 7);

        $profile = $send->automation->seller->sellerProfile;

        return redirect()->to($profile ? route('sellers.show', $profile) : route('home'));
    }

    /** 1×1 transparent gif — marks the email opened. */
    public function open(string $token)
    {
        CampaignRecipient::where('token', $token)->whereNull('opened_at')->update(['opened_at' => now()]);

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($gif, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /** Click-through — records the click, drops an attribution cookie, redirects. */
    public function click(string $token)
    {
        $recipient = CampaignRecipient::with('campaign.seller.sellerProfile')->where('token', $token)->first();

        if (! $recipient) {
            return redirect()->route('home');
        }

        $recipient->increment('clicks');
        \App\Models\MarketingEvent::click($recipient->campaign->seller_id, 'email', $recipient->marketing_campaign_id);
        $recipient->forceFill(['clicked_at' => $recipient->clicked_at ?? now()])->save();

        Cookie::queue('pm_campaign', $token, 60 * 24 * 7); // 7-day conversion window

        $profile = $recipient->campaign->seller->sellerProfile;

        return redirect()->to($profile?->slug ? route('sellers.show', $profile) : route('home'));
    }
}
