<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/** A seller's marketing email (campaign or automation), sent by Pan Motive on their behalf. */
class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $preview,
        public string $bodyHtml,
        public ?string $unsubscribeUrl = null,
        public ?string $storeName = null,
        public bool $fromPlatform = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    /** RFC 8058 one-click unsubscribe — Gmail and Yahoo require it for bulk senders. */
    public function headers(): Headers
    {
        return new Headers(text: $this->unsubscribeUrl ? [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] : []);
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.campaign', [
                'preview' => $this->preview,
                'bodyHtml' => $this->bodyHtml,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'storeName' => $this->storeName,
                'fromPlatform' => $this->fromPlatform,
                'address' => config('marketplace.marketing.postal_address'),
            ])->render(),
        );
    }
}
