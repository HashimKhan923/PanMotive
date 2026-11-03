<?php

namespace App\Livewire\Storefront;

use App\Services\AI\AiClient;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class SupportChat extends Component
{
    /** @var array<int, array{role: string, content: string}> */
    public array $messages = [];

    public string $input = '';

    public bool $thinking = false;

    protected string $systemPrompt = 'You are Pan Motive\'s customer support assistant for an automotive parts marketplace. '
        .'Help with order status, return policy (30-day returns, buyer-initiated via Order page), shipping questions, '
        .'and general fitment guidance. If asked something requiring account-specific data you don\'t have access to, '
        .'or anything about payments/disputes, tell the customer to say "connect me to a human" and stop there. '
        .'Keep responses under 4 sentences.';

    public function send(AiClient $ai): void
    {
        if (! trim($this->input)) {
            return;
        }

        // Cost guard — the AI call is a paid external request.
        $key = 'ai-chat:'.(auth()->id() ?: request()->ip());
        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->messages[] = ['role' => 'assistant', 'content' => 'You\'re sending messages too quickly — give it a minute.'];

            return;
        }
        RateLimiter::hit($key, 60);

        $this->messages[] = ['role' => 'user', 'content' => $this->input];
        $userMessage = $this->input;
        $this->input = '';
        $this->thinking = true;

        $history = collect($this->messages)->map(fn ($m) => "{$m['role']}: {$m['content']}")->implode("\n");

        $reply = $ai->complete($this->systemPrompt, $history, maxTokens: 300)
            ?: "I'm having trouble reaching support right now — please email ".(config('marketplace.platform.support_email') ?: 'support@panmotive.test').'.';

        if (str_contains(strtolower($reply), 'connect me to a human') || str_contains(strtolower($userMessage), 'human')) {
            $reply .= "\n\nI've flagged this for a support agent — they'll follow up by email.";
            // Phase 7 notification system creates the actual support ticket here.
        }

        $this->messages[] = ['role' => 'assistant', 'content' => $reply];
        $this->thinking = false;
    }

    public function render()
    {
        return view('livewire.storefront.support-chat');
    }
}
