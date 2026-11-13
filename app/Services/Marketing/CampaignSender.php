<?php

namespace App\Services\Marketing;

use App\Mail\CampaignMail;
use App\Models\MarketingCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CampaignSender
{
    public function __construct(private AudienceResolver $audience, private MarketingConsent $consent) {}

    /** SMS only goes out through a real provider — never pretend a text was sent. */
    public static function smsAvailable(): bool
    {
        return (bool) (config('services.twilio.sid') && config('services.twilio.token') && config('services.twilio.from'));
    }

    /** Who this campaign can actually reach: the audience minus opt-outs, blocked buyers and (SMS) non-consenting numbers. */
    public function reachable(int $sellerId, string $type, string $channel = 'email'): Builder
    {
        $q = $this->consent->scopeAudience($this->audience->query($sellerId, $type), $sellerId);

        return $channel === 'sms'
            ? $q->whereNotNull('sms_opt_in_at')->whereNotNull('phone')
            : $q->whereNotNull('email');
    }

    /** Campaigns this store has sent (or is sending) in the last 7 days — capped to protect buyers' inboxes. */
    public function sentThisWeek(int $sellerId): int
    {
        return MarketingCampaign::where('seller_id', $sellerId)
            ->whereIn('status', ['sending', 'sent'])
            ->where('sent_at', '>=', now()->subDays(7))->count();
    }

    public function weeklyLimitReached(int $sellerId): bool
    {
        return $this->sentThisWeek($sellerId) >= (int) config('marketplace.marketing.campaigns_per_week', 3);
    }

    /** Campaign emails sent across the whole site today (for the daily cap while on Gmail). */
    public static function sentToday(): int
    {
        return \App\Models\CampaignRecipient::where('sent_at', '>=', now()->startOfDay())->count();
    }

    /** How many more campaign emails may go out today; null = no cap (a bulk email service is connected). */
    public static function remainingToday(): ?int
    {
        if (\App\Support\MailSettings::bulkReady() || in_array(config('mail.default'), ['log', 'array'], true)) {
            return null;
        }

        return max(0, (int) config('marketplace.marketing.campaign_daily_cap', 300) - self::sentToday());
    }

    /** Campaigns paused by the daily cap carry on the next day (called from the hourly job). */
    public function resumeCapped(): int
    {
        $n = 0;
        MarketingCampaign::where('status', 'capped')->orderBy('sent_at')->each(function ($c) use (&$n) {
            if (self::remainingToday() === 0) {
                return false;
            }
            $this->send($c);
            $n++;
        });

        return $n;
    }

    public function send(MarketingCampaign $campaign): void
    {
        if (! in_array($campaign->status, ['draft', 'scheduled', 'failed', 'capped'], true)) {
            return;
        }
        $resuming = $campaign->status === 'capped';

        if (in_array($campaign->seller?->sellerProfile?->status, ['suspended', 'rejected'], true) || $campaign->seller?->is_active === false) {
            $campaign->update(['status' => 'failed', 'stats' => array_merge($campaign->stats ?? [], ['error' => 'This store is not active.'])]);

            return;
        }

        if ($campaign->channel === 'sms' && ! self::smsAvailable()) {
            $campaign->update(['status' => 'failed', 'stats' => array_merge($campaign->stats ?? [], ['error' => 'SMS is not set up on this marketplace yet.'])]);

            return;
        }

        if (! $resuming && $this->weeklyLimitReached($campaign->seller_id)) {
            $campaign->update(['status' => 'failed', 'stats' => array_merge($campaign->stats ?? [], [
                'error' => 'Weekly limit of '.config('marketplace.marketing.campaigns_per_week').' campaigns reached — try again in a few days.',
            ])]);

            return;
        }

        $campaign->update(['status' => 'sending', 'sent_at' => $campaign->sent_at ?? now()]);

        $store = $campaign->seller->sellerProfile?->business_name ?? 'the store';
        $type = $campaign->audience['type'] ?? 'all_customers';
        $designed = $campaign->channel === 'email' && ! empty($campaign->design);
        $renderer = app(CampaignRenderer::class);
        $mailer = config('marketplace.marketing.campaign_mailer') ?: null;
        $budget = $campaign->channel === 'email' ? self::remainingToday() : null;
        $capped = false;

        $this->reachable($campaign->seller_id, $type, $campaign->channel)
            ->chunkById(200, function ($users) use ($campaign, $store, $designed, $renderer, $mailer, &$budget, &$capped) {
                foreach ($users as $user) {
                    $recipient = $campaign->recipients()->firstOrCreate(['user_id' => $user->id]);
                    if ($recipient->sent_at) {
                        continue; // a retried send never mails the same person twice
                    }
                    if ($budget !== null && $budget <= 0) {
                        $capped = true;

                        return false; // the rest go tomorrow
                    }

                    try {
                        if ($campaign->channel === 'sms') {
                            $rendered = $this->render($campaign, $user, $store, $recipient->token);
                            $this->sendSms($user, Str::limit(strip_tags($rendered['body']), 300, '')."\nReply STOP to opt out.");
                        } elseif ($designed) {
                            $out = $renderer->render($campaign, $user, $recipient->token, 'send');
                            Mail::mailer($mailer)->to($user->email)->send(new \App\Mail\DesignedCampaignMail(
                                $out['subject'], $out['html'], $store, $campaign->seller?->email,
                                $this->consent->unsubscribeUrl($user, $campaign->seller_id),
                            ));
                        } else {
                            $rendered = $this->render($campaign, $user, $store, $recipient->token);
                            Mail::mailer($mailer)->to($user->email)->send(new CampaignMail(
                                $rendered['subject'], $rendered['preview'], $rendered['body'],
                                $this->consent->unsubscribeUrl($user, $campaign->seller_id), $store,
                            ));
                        }
                        $recipient->update(['sent_at' => now(), 'failure' => null]);
                        if ($budget !== null) {
                            $budget--;
                        }
                    } catch (\Throwable $e) {
                        $recipient->update(['failure' => Str::limit($e->getMessage(), 120)]);
                    }
                }
            });

        $waiting = $capped ? $this->reachable($campaign->seller_id, $type, $campaign->channel)
            ->whereNotIn('users.id', $campaign->recipients()->whereNotNull('sent_at')->select('user_id'))->count() : 0;
        $campaign->update(['status' => $capped ? 'capped' : 'sent', 'stats' => array_merge($campaign->stats ?? [], ['waiting' => $waiting])]);
        $this->refreshStats($campaign);
    }

    /** Recompute engagement stats (called after opens/clicks/conversions land). */
    public function refreshStats(MarketingCampaign $campaign): void
    {
        // A fresh query per count — reusing one builder would AND the conditions together.
        $r = fn () => $campaign->recipients();

        $campaign->update(['stats' => array_merge($campaign->stats ?? [], [
            'recipients' => $r()->whereNotNull('sent_at')->count(),
            'failed' => $r()->whereNull('sent_at')->whereNotNull('failure')->count(),
            'opened' => $r()->whereNotNull('opened_at')->count(),
            'clicked' => $r()->whereNotNull('clicked_at')->count(),
            'clicks' => \App\Models\CampaignClick::where('marketing_campaign_id', $campaign->id)->where('is_bot', false)->count(),
            'bounced' => $r()->whereNotNull('bounced_at')->count(),
            'complaints' => $r()->whereNotNull('complained_at')->count(),
            'converted' => $r()->whereNotNull('converted_order_id')->count(),
            'revenue' => (float) $r()->sum('conversion_value'),
            'unsubscribed' => \App\Models\MarketingUnsubscribe::where('seller_id', $campaign->seller_id)
                ->whereIn('user_id', $r()->select('user_id'))
                ->where('created_at', '>=', $campaign->sent_at ?? $campaign->created_at)->count(),
        ])]);
    }

    /** @return array{subject:string, preview:string, body:string} */
    public function render(MarketingCampaign $campaign, User $user, string $store, string $token): array
    {
        $shopUrl = route('m.click', $token);
        $first = Str::before($user->name ?: 'there', ' ');

        $swap = fn (string $s) => str_replace(
            ['{{name}}', '{{store}}', '{{shop_url}}'],
            [$first, $store, $shopUrl],
            $s
        );

        $body = $swap($campaign->body);

        if ($campaign->channel === 'email') {
            $body = nl2br(e($body));
            $body = str_replace(e($shopUrl), '<a href="'.$shopUrl.'" style="color:#BB1220;font-weight:600">'.$shopUrl.'</a>', $body);
            $body .= '<img src="'.route('m.open', $token).'" width="1" height="1" alt="" style="display:none">';
        }

        return [
            'subject' => $swap($campaign->subject ?: "News from {$store}"),
            'preview' => $swap($campaign->preview ?: ''),
            'body' => $body,
        ];
    }

    private function sendSms(User $user, string $text): void
    {
        $sid = config('services.twilio.sid');

        Http::withBasicAuth($sid, config('services.twilio.token'))->asForm()->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => config('services.twilio.from'),
                'To' => $user->phone,
                'Body' => $text,
            ])->throw();
    }
}
