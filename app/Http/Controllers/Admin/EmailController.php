<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\EmailPreviews;
use App\Support\MailSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/** Every email the platform sends: who it's from, a live preview of each one, and a test send. */
class EmailController extends Controller
{
    public function index()
    {
        return view('admin.emails.index', [
            'catalog' => collect(EmailPreviews::catalog())->groupBy('group', preserveKeys: true),
            'settings' => [
                'Sent from' => config('mail.from.name').' <'.config('mail.from.address').'>',
                'Replies go to' => config('mail.reply_to.address'),
                'Support address on the site' => MailSettings::supportEmail(),
                'Owner alerts go to' => MailSettings::alertsEmail(),
                'Sending service' => MailSettings::mailerLabel(),
            ],
            'sending' => MailSettings::sending(),
            'postal' => MailSettings::postalAddress(),
        ]);
    }

    /** The email itself, shown inside an iframe on the index page. */
    public function preview(string $key)
    {
        return response(EmailPreviews::render($key)['html'])
            ->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'; frame-ancestors 'self'")
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test(Request $request, string $key)
    {
        $email = EmailPreviews::render($key);
        $to = $request->user()->email;

        try {
            Mail::html($email['html'], fn ($m) => $m->to($to)->subject('[Test] '.$email['subject']));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'The test email could not be sent: '.\Illuminate\Support\Str::limit($e->getMessage(), 160));
        }

        return back()->with('success', MailSettings::sending()
            ? "Test email sent to {$to}."
            : 'Test email created, but the site is set to write emails to a log file, so nothing was delivered. Connect an email service first.');
    }
}
