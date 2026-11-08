<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\AdminAlert;
use App\Models\BuyerClaim;
use App\Models\PolicyViolation;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\SellerVerification;
use App\Models\SupportTicket;
use App\Models\User;

/** The console's menu, filtered to what this staff member may do, with live counts of open work. */
class AdminNav
{
    public static function groups(User $user): array
    {
        $c = self::counts();

        // [route, label, icon, badge, permission (null = every staff member), also active on]
        $groups = [
            [null, [
                ['admin.dashboard', 'Overview', 'chart', 0, null, null],
                ['admin.insights.index', 'Insights', 'sparkles', 0, 'finance.view', null],
                ['admin.alerts.index', 'Alerts', 'bell', $c['alerts'], null, null],
            ]],
            ['People', [
                ['admin.users.index', 'Buyers & accounts', 'users', 0, 'users.view', 'admin.users.*'],
                ['admin.sellers.index', 'Sellers', 'store', $c['pending_sellers'], 'sellers.approve', 'admin.sellers.*'],
                ['admin.verifications.index', 'Identity checks', 'shield', $c['kyc'], 'sellers.approve', null],
                ['admin.staff.index', 'Staff & roles', 'user', 0, 'staff.manage', 'admin.staff.*'],
            ]],
            ['Catalog', [
                ['admin.products.index', 'Products', 'box', $c['pending_listings'], 'catalog.manage', 'admin.products.*'],
                ['admin.categories.index', 'Categories', 'grid', 0, 'catalog.manage', 'admin.categories.*'],
                ['admin.brands.index', 'Brands', 'tag', 0, 'catalog.manage', 'admin.brands.*'],
                ['admin.vehicles.index', 'Vehicles', 'truck', 0, 'catalog.manage', 'admin.vehicles.*'],
                ['admin.reviews.index', 'Reviews', 'star', $c['reported_reviews'], 'reviews.moderate', null],
                ['admin.questions.index', 'Questions', 'help', 0, 'reviews.moderate', null],
            ]],
            ['Orders', [
                ['admin.orders.index', 'All orders', 'list', 0, 'orders.view', 'admin.orders.*'],
                ['admin.returns.index', 'Returns', 'refresh', $c['escalated_returns'], 'orders.view', null],
                ['admin.subscriptions.index', 'Subscribe & Save', 'clock', 0, 'orders.view', null],
                ['admin.disputes.index', 'Disputes & claims', 'alert', $c['claims'], 'orders.view', null],
            ]],
            ['Money', [
                ['admin.finance.overview', 'Finance overview', 'dollar', 0, 'finance.view', null],
                ['admin.finance.ledger', 'Commission ledger', 'list', 0, 'finance.view', null],
                ['admin.finance.payouts', 'Payouts', 'archive', 0, 'finance.view', null],
                ['admin.finance.wallets', 'Wallets & credit', 'dollar', 0, 'finance.view', null],
                ['admin.finance.tax', 'Tax forms', 'list', 0, 'finance.view', null],
            ]],
            ['Growth', [
                ['admin.coupons.index', 'Coupons', 'tag', 0, 'coupons.manage', 'admin.coupons.*'],
                ['admin.ads.index', 'Seller ads', 'sparkles', 0, 'marketing.manage', null],
                ['admin.offsite-ads.index', 'Offsite Ads', 'external', 0, 'marketing.manage', null],
                ['admin.broadcasts.index', 'Broadcast email', 'mail', 0, 'marketing.manage', null],
                ['admin.campaigns.index', 'Seller campaigns', 'message', 0, 'marketing.manage', null],
                ['admin.affiliates.index', 'Affiliates', 'users', 0, 'marketing.manage', null],
            ]],
            ['Content', [
                ['admin.content.index', 'Homepage & announcement', 'bell', 0, 'content.manage', null],
                ['admin.banners.index', 'Homepage banners', 'image', 0, 'content.manage', 'admin.banners.*'],
                ['admin.pages.index', 'Pages', 'list', 0, 'content.manage', 'admin.pages.*'],
            ]],
            ['Support', [
                ['admin.support.index', 'Seller support', 'message', $c['tickets'], null, 'admin.support.*'],
                ['admin.appeals.index', 'Policy & appeals', 'shield', $c['appeals'], 'sellers.approve', null],
            ]],
            ['Platform', [
                ['admin.settings.index', 'Settings', 'cog', 0, 'settings.manage', null],
                ['admin.audit.index', 'Audit log', 'clock', 0, 'audit.view', null],
                ['admin.system.index', 'System health', 'wrench', 0, 'system.manage', null],
            ]],
        ];

        return collect($groups)->map(function ($group) use ($user) {
            $items = collect($group[1])
                ->filter(fn ($i) => $i[4] === null || $user->can($i[4]))
                ->map(fn ($i) => [
                    'route' => $i[0], 'label' => $i[1], 'icon' => $i[2], 'badge' => $i[3],
                    'active' => request()->routeIs($i[0]) || ($i[5] && request()->routeIs($i[5])),
                ])->values()->all();

            return ['label' => $group[0], 'items' => $items];
        })->filter(fn ($group) => $group['items'] !== [])->values()->all();
    }

    /**
     * A link to the shop. The console runs on its own subdomain in production, where route() would
     * keep that host, and "/" there is the admin dashboard rather than the storefront.
     */
    public static function site(string $route, mixed $parameters = []): string
    {
        return app()->environment('local')
            ? route($route, $parameters)
            : rtrim((string) config('app.url'), '/').route($route, $parameters, false);
    }

    public static function openAlerts(): int
    {
        return self::counts()['alerts'];
    }

    /** @return array<string, int> open work, shared by the menu badges and the dashboard queue */
    public static function counts(): array
    {
        return once(fn () => [
            'alerts' => AdminAlert::whereNull('resolved_at')->count(),
            'pending_sellers' => SellerProfile::where('status', 'pending')->count(),
            'kyc' => SellerVerification::where('status', 'pending')->count(),
            'pending_listings' => Product::where('status', 'pending_review')->count(),
            'reported_reviews' => Review::whereNotNull('reported_at')->where('status', 'published')->count(),
            'escalated_returns' => ReturnRequest::whereNotNull('escalated_at')->whereNull('resolved_at')->count(),
            'claims' => BuyerClaim::where('status', 'escalated')->count(),
            'tickets' => SupportTicket::where('admin_unread', true)->count(),
            'appeals' => PolicyViolation::whereNotNull('appeal_submitted_at')->whereNull('appeal_decided_at')->count(),
        ]);
    }
}
