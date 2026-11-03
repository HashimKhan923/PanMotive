<?php

namespace App\Livewire\Seller;

use App\Models\MarketingAutomation;
use App\Services\Marketing\MarketingAutomationService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.seller')]
class Automations extends Component
{
    public ?string $editing = null;

    public bool $isActive = false;
    public int $delayHours = 4;
    public string $subject = '';
    public string $body = '';
    public int $lapsedDays = 90;

    public function edit(string $trigger): void
    {
        abort_unless(array_key_exists($trigger, MarketingAutomation::TRIGGERS), 404);

        $a = $this->record($trigger);
        $defaults = MarketingAutomation::DEFAULT_COPY[$trigger];

        $this->editing = $trigger;
        $this->isActive = (bool) $a->is_active;
        $this->delayHours = (int) ($a->delay_hours ?: $defaults['delay_hours']);
        $this->subject = $a->subject ?: $defaults['subject'];
        $this->body = $a->body ?: $defaults['body'];
        $this->lapsedDays = (int) ($a->settings['lapsed_days'] ?? 90);
    }

    public function save(): void
    {
        $this->validate([
            'delayHours' => 'required|integer|min:1|max:336',
            'subject' => 'required|string|max:150',
            'body' => 'required|string|max:5000',
            'lapsedDays' => 'required|integer|min:14|max:365',
        ]);

        $this->record($this->editing)->update([
            'is_active' => $this->isActive,
            'delay_hours' => $this->delayHours,
            'subject' => $this->subject,
            'body' => $this->body,
            'settings' => ['lapsed_days' => $this->lapsedDays],
        ]);

        $this->editing = null;
        $this->dispatch('toast', message: 'Automation saved');
    }

    public function toggle(string $trigger): void
    {
        $a = $this->record($trigger);
        $a->update(['is_active' => ! $a->is_active]);
        $this->dispatch('toast', message: $a->is_active ? 'Automation switched on' : 'Automation paused');
    }

    public function runNow(MarketingAutomationService $service): void
    {
        abort_unless(\App\Support\SellerContext::can('marketing'), 403);
        $sent = $service->run(auth()->id());
        $this->dispatch('toast', message: $sent > 0 ? "Sent {$sent} message(s)." : 'Nothing due right now.');
    }

    private function record(string $trigger): MarketingAutomation
    {
        $defaults = MarketingAutomation::DEFAULT_COPY[$trigger];

        return MarketingAutomation::firstOrCreate(
            ['seller_id' => auth()->id(), 'trigger' => $trigger],
            [
                'channel' => 'email',
                'delay_hours' => $defaults['delay_hours'],
                'subject' => $defaults['subject'],
                'body' => $defaults['body'],
            ]
        );
    }

    public function render()
    {
        $existing = MarketingAutomation::where('seller_id', auth()->id())->get()->keyBy('trigger');

        $rows = collect(MarketingAutomation::TRIGGERS)->map(function ($label, $trigger) use ($existing) {
            $a = $existing->get($trigger);

            return [
                'trigger' => $trigger,
                'label' => $label,
                'active' => (bool) $a?->is_active,
                'stats' => $a?->stats ?? ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'orders' => 0, 'revenue' => 0],
            ];
        })->values();

        return view('livewire.seller.automations', ['rows' => $rows]);
    }
}
