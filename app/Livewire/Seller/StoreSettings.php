<?php

namespace App\Livewire\Seller;

use App\Services\Payments\StripeConnectService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.seller')]
class StoreSettings extends Component
{
    #[Validate('required|string|max:255')]
    public string $business_name = '';

    #[Validate('required|in:sole_prop,llc,corp')]
    public string $business_type = 'sole_prop';

    #[Validate('nullable|string|max:600')]
    public string $bio = '';

    #[Validate('nullable|string|max:2000')]
    public string $return_policy = '';

    #[Validate('required|in:usps,ups,fedex,dhl')]
    public string $default_carrier = 'usps';

    #[Validate('required|in:1-2,3-5,5-7')]
    public string $processing_days = '1-2';

    public bool $vacation_mode = false;

    #[Validate('nullable|string|max:200')]
    public string $vacation_message = '';

    #[Validate('nullable|date|after:now')]
    public ?string $vacation_until = null;

    public bool $vacation_pause_sales = false;

    // Returns
    #[Validate('required|in:30,60,90')]
    public int $return_window_days = 30;

    #[Validate('required|in:buyer,seller')]
    public string $return_shipping = 'buyer';

    // Ship-from address (for carrier labels and accurate shipping)
    /** @var array{name:string, company:string, line1:string, line2:string, city:string, state:string, postal_code:string, phone:string} */
    public array $ship_from = ['name' => '', 'company' => '', 'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'postal_code' => '', 'phone' => ''];

    // Per-store loyalty
    public bool $loyalty_enabled = false;
    #[Validate('required|integer|min:1|max:100')]
    public int $points_per_dollar = 5;
    #[Validate('required|integer|min:1|max:10')]
    public int $redeem_cents_per_point = 1;
    #[Validate('required|integer|min:100|max:10000')]
    public int $min_redeem = 500;

    #[Validate('numeric|min:0|max:100')]
    public float $handling_fee = 0;

    #[Validate('nullable|numeric|min:0|max:100000')]
    public ?float $free_shipping_threshold = null;

    public function mount(): void
    {
        $profile = auth()->user()->sellerProfile;
        $s = $profile->shipping_settings ?? [];

        $this->business_name = $profile->business_name ?? '';
        $this->business_type = $profile->business_type ?? 'sole_prop';
        $this->bio = $profile->bio ?? '';
        $this->return_policy = $profile->return_policy ?? '';
        $this->vacation_mode = (bool) $profile->vacation_mode;
        $this->vacation_message = $profile->vacation_message ?? '';
        $this->vacation_until = $profile->vacation_until?->format('Y-m-d\TH:i');
        $this->vacation_pause_sales = (bool) $profile->vacation_pause_sales;
        $this->return_window_days = (int) ($profile->return_window_days ?: 30);
        $this->return_shipping = $profile->return_shipping ?: 'buyer';
        $this->ship_from = array_merge($this->ship_from, array_map(fn ($v) => (string) $v, $profile->ship_from ?? []));
        $this->handling_fee = (float) ($profile->handling_fee ?? 0);
        $this->free_shipping_threshold = $profile->free_shipping_threshold !== null ? (float) $profile->free_shipping_threshold : null;
        $this->default_carrier = $s['default_carrier'] ?? 'usps';
        $this->processing_days = $s['processing_days'] ?? '1-2';

        $l = array_merge(\App\Services\Marketing\StoreLoyaltyService::DEFAULTS, $profile->loyalty ?? []);
        $this->loyalty_enabled = (bool) $l['enabled'];
        $this->points_per_dollar = (int) $l['points_per_dollar'];
        $this->redeem_cents_per_point = (int) $l['redeem_cents_per_point'];
        $this->min_redeem = (int) $l['min_redeem'];
    }

    public function save(): void
    {
        $this->validate();
        $this->validate([
            'ship_from.name' => 'nullable|string|max:100',
            'ship_from.company' => 'nullable|string|max:100',
            'ship_from.line1' => 'nullable|required_with:ship_from.city|string|max:120',
            'ship_from.line2' => 'nullable|string|max:120',
            'ship_from.city' => 'nullable|required_with:ship_from.line1|string|max:80',
            'ship_from.state' => 'nullable|required_with:ship_from.line1|string|max:40',
            'ship_from.postal_code' => ['nullable', 'required_with:ship_from.line1', 'regex:/^\d{5}(-\d{4})?$/'],
            'ship_from.phone' => ['nullable', 'regex:/^\+?[0-9 ()\-.]{10,20}$/'],
        ], ['ship_from.postal_code.regex' => 'Enter a 5-digit ZIP code.'], [
            'ship_from.line1' => 'street address', 'ship_from.city' => 'city', 'ship_from.state' => 'state', 'ship_from.postal_code' => 'ZIP code',
        ]);

        $profile = auth()->user()->sellerProfile;
        $shipFrom = array_map(fn ($v) => trim((string) $v), $this->ship_from);
        $shipFrom['state'] = \App\Services\Shipping\ShippingEstimateService::normalizeState($shipFrom['state']) ?? $shipFrom['state'];

        $profile->update([
            'business_name' => $this->business_name,
            'business_type' => $this->business_type,
            'bio' => $this->bio ?: null,
            'return_policy' => $this->return_policy ?: null,
            'vacation_mode' => $this->vacation_mode,
            'vacation_message' => $this->vacation_message ?: null,
            'vacation_until' => $this->vacation_mode && $this->vacation_until ? $this->vacation_until : null,
            'vacation_pause_sales' => $this->vacation_mode && $this->vacation_pause_sales,
            'return_window_days' => $this->return_window_days,
            'return_shipping' => $this->return_shipping,
            'ship_from' => array_filter($shipFrom) ? $shipFrom : null,
            'handling_fee' => $this->handling_fee,
            'free_shipping_threshold' => $this->free_shipping_threshold,
            // Merge, don't replace — other shipping keys must survive a settings save.
            'shipping_settings' => array_merge($profile->shipping_settings ?? [], [
                'default_carrier' => $this->default_carrier,
                'processing_days' => $this->processing_days,
            ]),
            'loyalty' => [
                'enabled' => $this->loyalty_enabled,
                'points_per_dollar' => $this->points_per_dollar,
                'redeem_cents_per_point' => $this->redeem_cents_per_point,
                'min_redeem' => $this->min_redeem,
            ],
        ]);

        $this->dispatch('toast', message: 'Store settings saved');
    }

    public function connectStripe(StripeConnectService $stripe): void
    {
        $profile = auth()->user()->sellerProfile;

        if (! $profile->stripe_account_id) {
            $profile->update(['stripe_account_id' => $stripe->createConnectedAccount(auth()->user(), $profile)]);
        }

        $this->redirect($stripe->generateOnboardingLink($profile->stripe_account_id), navigate: false);
    }

    public function render()
    {
        return view('livewire.seller.store-settings', [
            'profile' => auth()->user()->sellerProfile,
        ]);
    }
}
