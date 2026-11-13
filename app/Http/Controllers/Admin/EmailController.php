<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Support\EmailPreviews;
use App\Support\EmailTemplates;
use App\Support\MailSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/** Every email the platform sends: who it's from, a live preview, a test send, and editing its words. */
class EmailController extends Controller
{
    use AdminTools;

    public function index()
    {
        return view('admin.emails.index', [
            'catalog' => collect(EmailPreviews::catalog())->groupBy('group', preserveKeys: true),
            'edited' => array_keys(EmailTemplates::saved()),
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

    /** The email itself, shown inside an iframe. POST shows unsaved changes from the editor. */
    public function preview(Request $request, string $key)
    {
        $draft = $request->isMethod('post') && EmailTemplates::editable($key) ? $this->draftFrom($request, $key) : null;

        return response(EmailPreviews::render($key, $draft)['html'])
            ->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'; frame-ancestors 'self'")
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }

    public function edit(string $key)
    {
        abort_unless(EmailTemplates::editable($key), 404);
        $default = EmailTemplates::defaults()[$key];
        $saved = EmailTemplates::saved()[$key] ?? [];

        return view('admin.emails.edit', [
            'key' => $key,
            'info' => EmailPreviews::catalog()[$key],
            'default' => $default,
            'values' => collect(EmailTemplates::FIELDS)->mapWithKeys(fn ($f) => [$f => old($f, $saved[$f] ?? $default[$f] ?? '')])->all(),
            'template' => EmailTemplate::with('editor:id,name')->firstWhere('key', $key),
        ]);
    }

    public function update(Request $request, string $key)
    {
        abort_unless(EmailTemplates::editable($key), 404);
        $draft = $this->draftFrom($request, $key);
        $default = EmailTemplates::defaults()[$key];

        // Store only what differs from the default, so later improvements to defaults still arrive.
        $changes = collect($draft)->map(fn ($v, $f) => $v === ($default[$f] ?? '') ? null : $v)->all();

        if (collect($changes)->filter(fn ($v) => $v !== null)->isEmpty()) {
            EmailTemplate::where('key', $key)->delete();
        } else {
            EmailTemplate::updateOrCreate(['key' => $key], $changes + ['updated_by' => $request->user()->id]);
        }
        EmailTemplates::forget();
        $this->audit('Edited email wording', EmailTemplate::firstWhere('key', $key), ['email' => $key]);

        return redirect()->route('admin.emails.edit', $key)->with('success', 'Saved. New emails use these words from now on.');
    }

    public function reset(string $key)
    {
        abort_unless(EmailTemplates::editable($key), 404);
        EmailTemplate::where('key', $key)->delete();
        EmailTemplates::forget();
        $this->audit('Reset email wording to default', null, ['email' => $key]);

        return redirect()->route('admin.emails.edit', $key)->with('success', 'Back to the default wording.');
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

    /** Validate the editor's fields; unknown {placeholders} are refused so a typo can't reach customers. */
    private function draftFrom(Request $request, string $key): array
    {
        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'preheader' => 'nullable|string|max:255',
            'greeting' => 'nullable|string|max:120',
            'intro' => 'nullable|string|max:3000',
            'button' => 'nullable|string|max:60',
            'outro' => 'nullable|string|max:3000',
        ]);

        $draft = collect(EmailTemplates::FIELDS)->mapWithKeys(fn ($f) => [$f => trim(str_replace("\r\n", "\n", (string) ($data[$f] ?? '')))])->all();

        $errors = [];
        foreach ($draft as $field => $text) {
            if ($unknown = EmailTemplates::unknownPlaceholders($key, $text)) {
                $errors[$field] = 'Unknown placeholder: {'.implode('}, {', $unknown).'}. Use one from the list on the right.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $draft;
    }
}
