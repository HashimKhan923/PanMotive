<?php

namespace App\Livewire\Storefront;

use App\Models\AdminAlert;
use App\Notifications\AccountNotice;
use App\Services\AI\AiClient;
use App\Support\MailSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The help bubble on every shop page: an AI assistant for general questions, and a real hand-off to the
 * support mailbox when the shopper asks for a person (an email with the conversation + an admin alert).
 */
class SupportChat extends Component
{
    /** Kept server-side only: the browser can't rewrite the conversation (that used to crash the page). */
    /** @var array<int, array{role: string, content: string}> */
    #[Locked]
    public array $messages = [];

    public string $input = '';

    #[Locked]
    public bool $thinking = false;

    /** Showing the "talk to a person" form. */
    #[Locked]
    public bool $handoff = false;

    #[Locked]
    public bool $handedOff = false;

    public string $email = '';

    protected string $systemPrompt = 'You are Panmotive\'s customer support assistant for an automotive parts marketplace. '
        .'Help with order status, return policy (30-day returns, buyer-initiated via Order page), shipping questions, '
        .'and general fitment guidance. If asked something requiring account-specific data you don\'t have access to, '
        .'or anything about payments/disputes, tell the customer to tap "Talk to a person" and stop there. '
        .'Keep responses under 4 sentences.';

    public function mount(): void
    {
        $this->email = (string) (auth()->user()?->email ?? '');
    }

    public function send(AiClient $ai): void
    {
        $text = Str::limit(trim($this->input), 1000, '');
        if ($text === '') {
            return;
        }

        // Cost guard: the AI call is a paid external request.
        $key = 'ai-chat:'.(auth()->id() ?: request()->ip());
        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->say('You\'re sending messages too quickly. Give it a minute.');

            return;
        }
        RateLimiter::hit($key, 60);

        $this->messages[] = ['role' => 'user', 'content' => $text];
        $this->input = '';

        if (preg_match('/\b(human|person|agent|someone real|real person|representative)\b/i', $text)) {
            $this->askForPerson();

            return;
        }

        $history = collect($this->messages)->take(-12)->map(fn ($m) => "{$m['role']}: {$m['content']}")->implode("\n");
        $reply = $ai->complete($this->systemPrompt, $history, maxTokens: 300)
            ?: "I'm having trouble answering right now. Tap \"Talk to a person\" and our team will email you.";

        $this->say($reply);
    }

    public function askForPerson(): void
    {
        if ($this->handedOff) {
            $this->say('Your request is already with our support team. They\'ll reply by email.');

            return;
        }
        $this->handoff = true;
        $this->say('Sure. Leave your email and our support team will reply there, usually within one business day.');
    }

    public function requestHuman(): void
    {
        if ($this->handedOff) {
            return;
        }
        $this->validate(['email' => 'required|email:rfc|max:190'], ['email.required' => 'Add your email so we can reply.', 'email.email' => 'That email doesn\'t look right.']);

        $key = 'support-handoff:'.(auth()->id() ?: request()->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('email', 'We already have your request. Please wait for our reply.');

            return;
        }
        RateLimiter::hit($key, 3600);

        $who = auth()->user();
        $transcript = collect($this->messages)
            ->map(fn ($m) => ($m['role'] === 'user' ? 'Shopper' : 'Assistant').': '.$m['content'])
            ->implode("\n\n");
        $body = "A shopper asked to talk to a person.\n\n"
            ."Reply to: {$this->email}".($who ? " (signed in as {$who->name}, user #{$who->id})" : ' (guest)')."\n"
            .'Page: '.Str::limit((string) request()->header('Referer', url()->previous()), 200)."\n\n"
            ."Conversation:\n\n".($transcript ?: '(no messages)');

        Notification::route('mail', MailSettings::supportEmail())
            ->notify(new AccountNotice('Support request from '.$this->email, $body));
        $lastAsk = (string) (collect($this->messages)->where('role', 'user')->last()['content'] ?? '');
        AdminAlert::create(['type' => 'support_request', 'message' => Str::limit('Support request from '.$this->email.': '.$lastAsk, 250)]);

        $this->handoff = false;
        $this->handedOff = true;
        $this->say("Thanks. Our support team has your message and will reply to {$this->email}.");
    }

    private function say(string $text): void
    {
        $this->messages[] = ['role' => 'assistant', 'content' => $text];
        $this->messages = array_slice($this->messages, -40);
    }

    public function render()
    {
        return view('livewire.storefront.support-chat');
    }
}
