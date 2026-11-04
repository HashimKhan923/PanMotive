<?php

namespace App\Livewire\Seller;

use App\Models\ApiToken;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.seller')]
class ApiAccess extends Component
{
    public const ABILITIES = ['products:read', 'products:write', 'orders:read', 'orders:write'];
    public const EVENTS = ['order.created', 'order.paid', 'order.shipped', 'order.delivered', 'order.cancelled', 'return.requested'];

    /** Endpoint whose full signing secret is currently shown. */
    public ?int $revealedId = null;

    // token form
    public string $tokenName = '';
    public array $tokenAbilities = ['products:read', 'orders:read'];
    public ?string $freshToken = null;

    // webhook form
    public string $hookUrl = '';
    public array $hookEvents = ['order.created', 'order.shipped'];

    public function createToken(): void
    {
        $this->validate(['tokenName' => 'required|string|max:60']);
        $abilities = array_values(array_intersect($this->tokenAbilities, self::ABILITIES));
        abort_if(empty($abilities), 422);

        $issued = ApiToken::issue(auth()->user(), $this->tokenName, $abilities);
        $this->freshToken = $issued['plain'];
        $this->reset('tokenName');
        $this->dispatch('toast', message: 'Token created — copy it now, it won\'t be shown again.');
    }

    public function revokeToken(int $id): void
    {
        ApiToken::where('seller_id', auth()->id())->whereKey($id)->update(['revoked_at' => now()]);
        $this->dispatch('toast', message: 'Token revoked');
    }

    public function addEndpoint(): void
    {
        $this->validate(['hookUrl' => 'required|url|max:255']);
        if ($problem = \App\Services\Integrations\WebhookUrlGuard::problem($this->hookUrl)) {
            $this->addError('hookUrl', $problem);

            return;
        }
        $events = array_values(array_intersect($this->hookEvents, self::EVENTS));
        if (empty($events)) {
            $this->addError('hookEvents', 'Pick at least one event.');

            return;
        }
        abort_if(WebhookEndpoint::where('seller_id', auth()->id())->count() >= 10, 422, 'You can have up to 10 webhook endpoints.');

        WebhookEndpoint::create([
            'seller_id' => auth()->id(),
            'url' => $this->hookUrl,
            'secret' => 'whsec_'.Str::random(40),
            'events' => $events,
        ]);

        $this->reset('hookUrl');
        $this->dispatch('toast', message: 'Webhook endpoint added');
    }

    public function toggleEndpoint(int $id): void
    {
        $e = WebhookEndpoint::where('seller_id', auth()->id())->findOrFail($id);
        if (! $e->is_active && ($problem = \App\Services\Integrations\WebhookUrlGuard::problem($e->url))) {
            $this->dispatch('toast', message: "Can't turn it back on: {$problem}");

            return;
        }
        $e->update(['is_active' => ! $e->is_active, 'failure_count' => 0, 'last_status' => $e->is_active ? 'paused' : $e->last_status]);
    }

    public function reveal(int $id): void
    {
        $this->revealedId = $this->revealedId === $id ? null : WebhookEndpoint::where('seller_id', auth()->id())->findOrFail($id)->id;
    }

    /** New signing secret — use after a leak. The old one stops working immediately. */
    public function rotateSecret(int $id): void
    {
        WebhookEndpoint::where('seller_id', auth()->id())->findOrFail($id)->update(['secret' => 'whsec_'.Str::random(40)]);
        $this->revealedId = $id;
        $this->dispatch('toast', message: 'New secret created — update it in your app.');
    }

    /** Send a sample event right now so the seller can check their endpoint and signature check. */
    public function sendTest(int $id): void
    {
        $e = WebhookEndpoint::where('seller_id', auth()->id())->findOrFail($id);
        \App\Jobs\DeliverWebhook::dispatchSync($e->id, [
            'event' => 'test.ping',
            'created_at' => now()->toIso8601String(),
            'data' => ['message' => 'Hello from Panmotive — your webhook endpoint works.', 'order_number' => 'PM-TEST'],
        ]);
        $last = $e->deliveries()->first();
        $this->dispatch('toast', message: $last?->success ? "Test delivered ({$last->status})." : 'Test failed: '.($last?->error ?? $last?->status ?? 'no response'));
    }

    public function deleteEndpoint(int $id): void
    {
        WebhookEndpoint::where('seller_id', auth()->id())->whereKey($id)->delete();
    }

    public function render()
    {
        return view('livewire.seller.api-access', [
            'tokens' => ApiToken::where('seller_id', auth()->id())->latest()->get(),
            'endpoints' => WebhookEndpoint::where('seller_id', auth()->id())->with(['deliveries' => fn ($q) => $q->limit(10)])->latest()->get(),
            'abilities' => self::ABILITIES,
            'events' => self::EVENTS,
            'baseUrl' => url('/api/v1'),
        ]);
    }
}
