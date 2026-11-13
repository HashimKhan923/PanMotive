<?php

namespace App\Notifications\Messages;

use App\Models\User;
use App\Support\EmailPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

/**
 * A MailMessage with Panmotive's building blocks: order lines, totals, fact boxes, a progress
 * bar and quotes, plus the hidden preview line, the right footer (buyer or seller) and, for
 * emails people may switch off, a one-click "stop these emails" link and header.
 */
class PanmotiveMail extends MailMessage
{
    /** The recipient's first name, for the {first_name} placeholder. */
    protected string $firstName = '';

    /** This email's words (from App\Support\EmailTemplates), once useWords() is called. */
    protected array $words = [];

    /** Unsaved wording from the admin editor, used only for its live preview. */
    public static ?array $draft = null;

    /** Start an email to this person, greeting them by first name. */
    public static function to(object $notifiable, ?string $name = null): static
    {
        $name ??= $notifiable instanceof User ? $notifiable->name : null;
        $first = trim(strtok((string) $name, ' ') ?: '');

        $mail = (new static)->greeting($first !== '' ? "Hi {$first}," : 'Hi there,');
        $mail->firstName = $first;

        return $mail;
    }

    /**
     * Use the admin-editable words for this email: subject, preview line and greeting now;
     * then intro(), button() and outro() place the rest where the email wants them.
     */
    public function useWords(string $key, array $vars = []): static
    {
        $vars = ['first_name' => $this->firstName !== '' ? $this->firstName : 'there'] + $vars;
        $this->words = \App\Support\EmailTemplates::get($key, $vars, static::$draft);

        $this->subject($this->words['subject']);
        if ($this->words['preheader'] !== '') {
            $this->preheader($this->words['preheader']);
        }
        if ($this->words['greeting'] !== '') {
            $this->greeting($this->words['greeting']);
        }

        return $this;
    }

    public function intro(): static
    {
        foreach (\App\Support\EmailTemplates::paragraphs($this->words['intro'] ?? '') as $paragraph) {
            $this->line($paragraph);
        }

        return $this;
    }

    /** The main button, with the template's label (falls back when the label is blank). */
    public function button(string $url, string $fallback = 'Open Panmotive'): static
    {
        return $this->action(($this->words['button'] ?? '') !== '' ? $this->words['button'] : $fallback, $url);
    }

    public function outro(): static
    {
        foreach (\App\Support\EmailTemplates::paragraphs($this->words['outro'] ?? '') as $paragraph) {
            $this->line($paragraph);
        }

        return $this;
    }

    public function preheader(string $text): static
    {
        $this->viewData['preheader'] = $text;

        return $this;
    }

    /** Seller emails get seller links in the footer. */
    public function forSeller(): static
    {
        $this->viewData['audience'] = 'seller';

        return $this;
    }

    /** One line in the footer saying why this email came. */
    public function reason(string $text): static
    {
        $this->viewData['reason'] = $text;

        return $this;
    }

    /** Mark this email as one the person can turn off, and give them the link to do it. */
    public function optional(object $notifiable, string $category): static
    {
        if (! $notifiable instanceof User) {
            return $this;
        }

        $url = EmailPreferences::stopUrl($notifiable, $category);
        $this->viewData['stopUrl'] = $url;
        $this->viewData['stopLabel'] = EmailPreferences::categories()[$category]['label'] ?? null;

        // Gmail and Yahoo show their own "Unsubscribe" button from these headers (RFC 8058).
        return $this->withSymfonyMessage(function (Email $message) use ($url) {
            $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$url.'>');
            $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }

    public function block(string $type, array $data): static
    {
        $this->viewData['blocks'][] = ['type' => $type, 'data' => $data];

        return $this;
    }

    /** @param  list<array{title: string, meta?: ?string, qty?: int, price?: string, image?: ?string, url?: ?string}>  $items */
    public function items(array $items, ?string $heading = null): static
    {
        return $this->block('items', ['items' => $items, 'heading' => $heading]);
    }

    /** @param  list<array{0: string, 1: string, 2?: bool, 3?: bool}>  $rows  label, value, bold total, green */
    public function totals(array $rows): static
    {
        return $this->block('totals', ['rows' => $rows]);
    }

    /** @param  array<string|int, string>  $rows  label => value, or plain lines */
    public function facts(array $rows, ?string $title = null): static
    {
        return $this->block('facts', ['rows' => array_filter($rows, fn ($v) => $v !== null && $v !== ''), 'title' => $title]);
    }

    public function progress(int $current, array $steps = ['Ordered', 'Shipped', 'Delivered']): static
    {
        return $this->block('progress', ['steps' => $steps, 'current' => $current]);
    }

    public function quote(string $text, ?string $by = null): static
    {
        return $this->block('quote', ['text' => $text, 'by' => $by]);
    }

    public static function money(float|int|string|null $amount): string
    {
        return '$'.number_format((float) $amount, 2);
    }
}
