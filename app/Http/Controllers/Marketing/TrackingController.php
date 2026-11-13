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
    public function click(\Illuminate\Http\Request $request, string $token)
    {
        $recipient = CampaignRecipient::with('campaign.seller.sellerProfile')->where('token', $token)->first();

        if (! $recipient) {
            return redirect()->route('home');
        }

        $profile = $recipient->campaign->seller->sellerProfile;
        $fallback = $profile?->slug ? route('sellers.show', $profile) : route('home');

        // Designed emails say which link was clicked and where it goes. The address is signed, so
        // nobody can turn our click links into a redirect to some other site.
        $key = (string) $request->query('k', 'link');
        $url = (string) $request->query('u', '');
        $valid = $url !== '' && preg_match('~^https?://~i', $url)
            && hash_equals(\App\Services\Marketing\CampaignRenderer::sign($token, $key, $url), (string) $request->query('s', ''));
        $target = $valid ? $url : $fallback;

        // Email security scanners open every link the moment an email arrives. Keep their
        // clicks out of the seller's numbers.
        $bot = $request->isMethod('HEAD')
            || preg_match('/bot|crawl|spider|preview|scan|python|curl|wget|headless|barracuda|mimecast|proofpoint|safelinks|urldefense/i', (string) $request->userAgent())
            || ($recipient->sent_at && $recipient->sent_at->diffInSeconds(now()) < 10);

        \App\Models\CampaignClick::create([
            'marketing_campaign_id' => $recipient->marketing_campaign_id, 'campaign_recipient_id' => $recipient->id,
            'link_key' => \Illuminate\Support\Str::limit(preg_replace('/[^a-z0-9:_-]/i', '', $key), 40, ''),
            'url' => \Illuminate\Support\Str::limit($target, 500, ''), 'is_bot' => (bool) $bot, 'clicked_at' => now(),
        ]);

        if (! $bot) {
            $recipient->increment('clicks');
            \App\Models\MarketingEvent::click($recipient->campaign->seller_id, 'email', $recipient->marketing_campaign_id);
            $recipient->forceFill(['clicked_at' => $recipient->clicked_at ?? now()])->save();
            Cookie::queue('pm_campaign', $token, 60 * 24 * 7); // 7-day conversion window
        }

        return redirect()->to($target);
    }
}
