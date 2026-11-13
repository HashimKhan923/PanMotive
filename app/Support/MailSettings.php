<?php

namespace App\Support;

/** The addresses every email uses, in one place, with sensible fall-backs. */
class MailSettings
{
    /** Shown in email footers and on the site. The admin's Content setting wins when filled in. */
    public static function supportEmail(): string
    {
        return (string) (config('marketplace.platform.support_email') ?: config('mail.reply_to.address') ?: 'support@panmotive.com');
    }

    /** Where owner alerts go (failed backups, failed emails). */
    public static function alertsEmail(): string
    {
        return (string) (config('mail.alerts_to') ?: self::supportEmail());
    }

    /** The real postal address, or null while it's still the made-up sample one. */
    public static function postalAddress(): ?string
    {
        $address = trim((string) config('marketplace.marketing.postal_address'));

        return $address === '' || str_contains($address, '2100 Commerce St') ? null : $address;
    }

    /** True when emails really leave the server (not just written to the log). */
    public static function sending(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array', null, ''], true);
    }

    /** True when campaigns go through a service built for bulk email (not Gmail). */
    public static function bulkReady(): bool
    {
        $mailer = config('marketplace.marketing.campaign_mailer') ?: config('mail.default');

        return in_array($mailer, ['postmark', 'ses', 'ses-v2', 'resend', 'mailgun'], true);
    }

    public static function mailerLabel(): string
    {
        return match ((string) config('mail.default')) {
            'log' => 'Log file only (not sent)',
            'array' => 'Test mode (not sent)',
            'smtp' => 'SMTP ('.config('mail.mailers.smtp.host').')',
            'postmark' => 'Postmark',
            'resend' => 'Resend',
            'ses', 'ses-v2' => 'Amazon SES',
            'mailgun' => 'Mailgun',
            default => (string) config('mail.default'),
        };
    }
}
