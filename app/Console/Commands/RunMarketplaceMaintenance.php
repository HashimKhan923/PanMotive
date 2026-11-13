<?php

namespace App\Console\Commands;

use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Seller\BoostService;
use App\Services\Seller\OfferService;
use App\Services\Seller\RepricingService;
use App\Services\Seller\SellerScorecardService;
use Illuminate\Console\Command;

class RunMarketplaceMaintenance extends Command
{
    protected $signature = 'marketplace:maintain';

    protected $description = 'Expire stale offers, end finished boosts, and refresh seller tiers';

    public function handle(BoostService $boosts, OfferService $offers, SellerScorecardService $scorecard, RepricingService $repricer): int
    {
        $endedBoosts = $boosts->sweepExpired();
        app(\App\Services\Seller\AdServingService::class)->endExpired();
        \App\Models\AdCampaign::whereDate('spend_day', '<', now()->toDateString())->update(['spent_today' => 0]);
        $expiredOffers = $offers->expireStale();
        // Orders never paid (abandoned at payment, unpaid subscription orders) free their stock.
        $expiredUnpaid = app(\App\Services\Orders\UnpaidOrderService::class)->expireStale();
        // Campaigns paused by the daily email cap pick up where they left off.
        app(\App\Services\Marketing\CampaignSender::class)->resumeCapped();
        // Social studio auto-queue: one product post a day for stores that switched it on.
        app(\App\Services\Marketing\Social\SocialToolkit::class)->runQueue();
        app(\App\Services\Orders\UnpaidOrderService::class)->remindUnpaid();
        $repriced = $repricer->runAll();
        app(\App\Services\Seller\SellerReferralService::class)->sweepExpired();

        // Publish scheduled social posts + refresh their engagement.
        $duePosts = \App\Models\SocialPost::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())->pluck('id');
        foreach ($duePosts as $id) {
            \App\Jobs\PublishSocialPost::dispatchSync($id);
        }
        app(\App\Services\Marketing\Social\SocialPublisher::class)->refreshMetrics();

        // Send due email/SMS campaigns.
        \App\Models\MarketingCampaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())->get()
            ->each(fn ($c) => app(\App\Services\Marketing\CampaignSender::class)->send($c));

        app(\App\Services\Marketing\AffiliateService::class)->approveDelivered();

        // Scheduled time away ends on its own.
        \App\Models\SellerProfile::where('vacation_mode', true)->whereNotNull('vacation_until')
            ->where('vacation_until', '<=', now())
            ->update(['vacation_mode' => false, 'vacation_until' => null, 'vacation_pause_sales' => false]);

        // Close out store sales whose end date passed (listings go back to their own prices).
        app(\App\Services\Marketing\SaleEventService::class)->finishEnded();

        // Fire behavioural marketing automations (abandoned cart, post-purchase, win-back, ...).
        $automated = app(\App\Services\Marketing\MarketingAutomationService::class)->run();

        // Buyer-side: recurring orders, membership renewals, wishlist price-drop alerts.
        $subOrders = app(\App\Services\Subscriptions\SubscriptionService::class)->runDue();
        $renewed = app(\App\Services\Membership\MembershipService::class)->renewDue();
        $priceAlerts = app(\App\Services\Buyer\WishlistAlertService::class)->sweep();

        // Seller ops: withdraw team invitations nobody accepted, flag low stock, work account health.
        $staleInvites = app(\App\Services\Seller\TeamService::class)->expireStaleInvites();
        $lowStock = app(\App\Services\Seller\InventoryService::class)->sweepLowStock();
        $health = app(\App\Services\Seller\AccountHealthService::class);
        $escalatedClaims = $health->escalateStaleClaims();
        $liftedViolations = $health->expireOldViolations();

        // Auto-escalate returns the seller didn't respond to in time.
        $escalated = 0;
        \App\Models\ReturnRequest::where('status', 'requested')
            ->whereNull('escalated_at')
            ->where('respond_by', '<', now())
            ->with('orderItem')
            ->chunk(100, function ($returns) use (&$escalated) {
                foreach ($returns as $r) {
                    $r->update(['escalated_at' => now(), 'escalated_by' => 'system']);
                    $r->log('escalated', 'system', null, 'Auto-escalated — seller did not respond within the SLA window');
                    \App\Models\AdminAlert::create([
                        'type' => 'return_sla_breach',
                        'message' => "Return {$r->rma_number} auto-escalated (SLA breach).",
                        'related_order_item_id' => $r->order_item_id,
                    ]);
                    $escalated++;
                }
            });

        $tiered = 0;
        SellerProfile::where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('tier_evaluated_at')->orWhere('tier_evaluated_at', '<', now()->subDay()))
            ->with('user')
            ->chunk(100, function ($profiles) use ($scorecard, &$tiered) {
                foreach ($profiles as $profile) {
                    if ($profile->user) {
                        $scorecard->refreshTier($profile->user);
                        $tiered++;
                    }
                }
            });

        $this->info("Ended {$endedBoosts} boosts, expired {$expiredOffers} offers, cancelled {$expiredUnpaid} unpaid orders, repriced {$repriced} listings, sent {$automated} automations, placed {$subOrders} subscription orders, renewed {$renewed} memberships, sent {$priceAlerts} price-drop alerts, withdrew {$staleInvites} stale invites, flagged {$lowStock} low-stock listings, escalated {$escalated} returns + {$escalatedClaims} claims, lifted {$liftedViolations} violations, re-tiered {$tiered} sellers.");

        return self::SUCCESS;
    }
}
