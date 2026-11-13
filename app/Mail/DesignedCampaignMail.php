<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * A seller's designed campaign, already rendered for one person. Sent from Panmotive as
 * "Store via Panmotive" (so it passes our SPF/DKIM), with replies going to the seller.
 */
class DesignedCampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
        public string $storeName,
        public ?string $replyToEmail = null,
        public ?string $unsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), \Illuminate\Support\Str::limit($this->storeName, 60, '').' via Panmotive'),
            replyTo: $this->replyToEmail ? [new Address($this->replyToEmail, $this->storeName)] : [],
            subject: $this->subjectLine,
        );
    }

    public function headers(): Headers
    {
        $text = $this->unsubscribeUrl ? [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] : [];
        // Postmark keeps marketing on its own "broadcast" stream, away from receipts.
        if ($stream = config('marketplace.marketing.postmark_stream')) {
            $text['X-PM-Message-Stream'] = $stream;
        }

        return new Headers(text: $text);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->bodyHtml);
    }
}
