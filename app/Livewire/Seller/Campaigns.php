<?php

namespace App\Livewire\Seller;

use App\Models\MarketingCampaign;
use App\Models\Product;
use App\Services\Marketing\AudienceResolver;
use App\Services\Marketing\CampaignSender;
use App\Services\Marketing\MarketingCopyService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.seller')]
class Campaigns extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;

    public string $channel = 'email';
    public string $name = '';
    public string $audienceType = 'all_customers';
    public string $subject = '';
    public string $preview = '';
    public string $body = "Hi {{name}},\n\n";
    public ?int $featureProductId = null;
    public string $goal = 'new_arrivals';
    public string $when = 'now';
    public ?string $scheduledAt = null;

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'channel' => 'required|in:'.(CampaignSender::smsAvailable() ? 'email,sms' : 'email'),
            'audienceType' => 'required|in:'.implode(',', array_keys(AudienceResolver::TYPES)),
            'subject' => 'required_if:channel,email|nullable|string|max:120',
            'body' => 'required|string|max:5000',
            'scheduledAt' => 'nullable|date|after:now',
        ];
    }

    /** People this campaign can actually reach (opted-out and blocked buyers excluded). */
    public function getAudienceCountProperty(CampaignSender $sender): int
    {
        return $sender->reachable(auth()->id(), $this->audienceType, $this->channel)->count();
    }

    public function newCampaign(): void
    {
        $this->reset('editingId', 'name', 'subject', 'preview', 'scheduledAt', 'featureProductId');
        $this->body = "Hi {{name}},\n\n";
        $this->channel = 'email';
        $this->audienceType = 'all_customers';
        $this->when = 'now';
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $c = $this->owned($id);
        // Sent campaigns are history — editing one would reset it to a draft and let it go out twice.
        abort_unless(in_array($c->status, ['draft', 'scheduled', 'failed'], true), 422, 'Sent campaigns can be duplicated, not edited.');
        $this->editingId = $c->id;
        $this->channel = $c->channel;
        $this->name = $c->name;
        $this->audienceType = $c->audience['type'] ?? 'all_customers';
        $this->subject = $c->subject ?? '';
        $this->preview = $c->preview ?? '';
        $this->body = $c->body;
        $this->when = $c->scheduled_at ? 'later' : 'now';
        $this->scheduledAt = $c->scheduled_at?->format('Y-m-d\TH:i');
        $this->showForm = true;
    }

    /** Start a new draft from an existing campaign (the usual way to re-run one that worked). */
    public function duplicate(int $id): void
    {
        $c = $this->owned($id);
        $this->newCampaign();
        $this->channel = $c->channel === 'sms' && ! CampaignSender::smsAvailable() ? 'email' : $c->channel;
        $this->name = \Illuminate\Support\Str::limit($c->name, 110, '').' (copy)';
        $this->audienceType = $c->audience['type'] ?? 'all_customers';
        $this->subject = $c->subject ?? '';
        $this->preview = $c->preview ?? '';
        $this->body = $c->body;
    }

    public function delete(int $id): void
    {
        $c = $this->owned($id);
        abort_unless(in_array($c->status, ['draft', 'scheduled', 'failed'], true), 422, 'Sent campaigns are kept for your records.');
        $c->delete();
        $this->dispatch('toast', message: 'Campaign deleted.');
    }

    public function generate(MarketingCopyService $copy): void
    {
        $product = $this->featureProductId ? Product::where('seller_id', auth()->id())->find($this->featureProductId) : null;
        $store = auth()->user()->sellerProfile?->business_name;
        $out = $copy->emailCopy($this->goal, $product, $store);

        $this->subject = $out['subject'];
        $this->preview = $out['preview'];
        $this->body = $out['body'];
        $this->dispatch('toast', message: 'Draft written — edit and send.');
    }

    public function save(bool $sendNow = false): void
    {
        $data = $this->validate();
        abort_unless(\App\Support\SellerContext::can('marketing'), 403);

        if ($sendNow && $this->when !== 'later' && app(CampaignSender::class)->weeklyLimitReached(auth()->id())) {
            $this->addError('name', "You've sent ".config('marketplace.marketing.campaigns_per_week').' campaigns this week — the most we allow per store, to keep buyers from tuning out. Save it as a draft or schedule it for later.');

            return;
        }

        $campaign = $this->editingId ? $this->owned($this->editingId) : new MarketingCampaign(['seller_id' => auth()->id()]);
        $scheduled = ($this->when === 'later' && $this->scheduledAt) ? Carbon::parse($this->scheduledAt) : null;

        $campaign->fill([
            'channel' => $this->channel,
            'name' => $this->name,
            'audience' => ['type' => $this->audienceType],
            'subject' => $this->channel === 'email' ? $this->subject : null,
            'preview' => $this->preview ?: null,
            'body' => $this->body,
            'status' => $scheduled ? 'scheduled' : 'draft',
            'scheduled_at' => $scheduled,
        ])->save();

        if ($sendNow && ! $scheduled) {
            $this->queueSend($campaign);
            $this->dispatch('celebrate');
        } elseif ($scheduled) {
            $this->dispatch('toast', message: 'Scheduled for '.$scheduled->format('M j, g:i A'));
        } else {
            $this->dispatch('toast', message: 'Saved as draft.');
        }

        $this->showForm = false;
    }

    public function sendDraft(int $id): void
    {
        abort_unless(\App\Support\SellerContext::can('marketing'), 403);
        $campaign = $this->owned($id);
        if (app(CampaignSender::class)->weeklyLimitReached(auth()->id())) {
            $this->dispatch('toast', message: 'Weekly campaign limit reached — schedule it for later instead.');

            return;
        }
        $campaign->update(['status' => 'draft', 'stats' => array_diff_key($campaign->stats ?? [], ['error' => true])]);
        $this->queueSend($campaign);
    }

    /** Sends after the response is out, so the seller isn't waiting on hundreds of emails. */
    private function queueSend(MarketingCampaign $campaign): void
    {
        $reach = app(CampaignSender::class)->reachable($campaign->seller_id, $campaign->audience['type'] ?? 'all_customers', $campaign->channel)->count();
        $id = $campaign->id;
        dispatch(fn () => app(CampaignSender::class)->send(MarketingCampaign::findOrFail($id)))->afterResponse();
        $this->dispatch('toast', message: "Sending to {$reach} ".\Illuminate\Support\Str::plural('person', $reach).' — stats update as people open and click.');
    }

    public function testSend(CampaignSender $sender): void
    {
        $this->validate(['body' => 'required', 'subject' => 'required_if:channel,email']);
        $store = auth()->user()->sellerProfile?->business_name ?? 'the store';
        $rendered = $sender->render(
            new MarketingCampaign(['channel' => $this->channel, 'subject' => $this->subject, 'preview' => $this->preview, 'body' => $this->body]),
            auth()->user(), $store, 'test-'.\Illuminate\Support\Str::random(20)
        );

        \Illuminate\Support\Facades\Mail::to(auth()->user()->email)->send(
            new \App\Mail\CampaignMail($rendered['subject'].' [TEST]', $rendered['preview'], $rendered['body'], null, $store)
        );
        $this->dispatch('toast', message: 'Test sent to '.auth()->user()->email);
    }

    private function owned(int $id): MarketingCampaign
    {
        return MarketingCampaign::where('seller_id', auth()->id())->findOrFail($id);
    }

    public function render()
    {
        return view('livewire.seller.campaigns', [
            'campaigns' => MarketingCampaign::where('seller_id', auth()->id())->latest()->get(),
            'audiences' => AudienceResolver::TYPES,
            'smsAvailable' => CampaignSender::smsAvailable(),
            'sentThisWeek' => app(CampaignSender::class)->sentThisWeek(auth()->id()),
            'weeklyLimit' => (int) config('marketplace.marketing.campaigns_per_week', 3),
            'products' => Product::where('seller_id', auth()->id())->where('status', 'active')->orderBy('title')->get(['id', 'title']),
        ]);
    }
}
