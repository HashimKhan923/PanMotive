<?php

namespace Tests\Feature\Admin;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Notifications\WelcomeToPanmotive;
use App\Support\EmailPreviews;
use App\Support\EmailTemplates;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Admins can change the words in emails, safely: only words, placeholders checked, reset any time. */
class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
        $this->admin = $this->withTwoFactor($this->admin);
    }

    private function fields(array $over = []): array
    {
        $d = EmailTemplates::defaults()['order_confirmation'];

        return array_merge(array_intersect_key($d, array_flip(EmailTemplates::FIELDS)), $over);
    }

    public function test_every_previewed_email_except_admin_notices_is_editable_and_renders_its_defaults(): void
    {
        $missing = array_diff(array_keys(EmailPreviews::catalog()), array_keys(EmailTemplates::defaults()));
        $this->assertSame(['account_notice'], array_values($missing)); // its words are typed per message

        foreach (array_keys(EmailTemplates::defaults()) as $key) {
            $html = EmailPreviews::render($key)['html'];
            $this->assertStringNotContainsString('{', strip_tags(preg_replace('/<style.*?<\/style>/s', '', $html)), "unfilled placeholder in {$key}");
        }
    }

    public function test_admin_edits_words_and_new_emails_use_them(): void
    {
        $this->actingAs($this->admin)->get(route('admin.emails.edit', 'order_confirmation'))->assertOk()
            ->assertSee('Order confirmed: {order_number}')->assertSee('{order_total}');

        $this->actingAs($this->admin)->put(route('admin.emails.update', 'order_confirmation'), $this->fields([
            'subject' => 'Thanks! Order {order_number} is confirmed',
            'intro' => "Your parts are booked.\n\nWe’ll keep you posted, {first_name}.",
        ]))->assertRedirect(route('admin.emails.edit', 'order_confirmation'))->assertSessionHas('success');

        // Only the changed fields are stored; the rest keep following the defaults.
        $row = EmailTemplate::firstWhere('key', 'order_confirmation');
        $this->assertSame('Thanks! Order {order_number} is confirmed', $row->subject);
        $this->assertNull($row->button);

        $words = EmailTemplates::get('order_confirmation', ['order_number' => 'PM-1', 'first_name' => 'Kevin']);
        $this->assertSame('Thanks! Order PM-1 is confirmed', $words['subject']);
        $this->assertSame(['Your parts are booked.', 'We’ll keep you posted, Kevin.'], EmailTemplates::paragraphs($words['intro']));

        $html = EmailPreviews::render('order_confirmation')['html'];
        $this->assertStringContainsString('Your parts are booked.', $html);
        $this->assertStringContainsString('View your order', $html); // untouched default button

        $this->actingAs($this->admin)->get(route('admin.emails.index'))->assertSee('Edited');

        // Reset brings the defaults back.
        $this->actingAs($this->admin)->delete(route('admin.emails.reset', 'order_confirmation'))->assertRedirect();
        $this->assertDatabaseCount('email_templates', 0);
        $this->assertSame('Order confirmed: PM-1', EmailTemplates::get('order_confirmation', ['order_number' => 'PM-1'])['subject']);
    }

    public function test_unknown_placeholders_and_a_missing_subject_are_refused(): void
    {
        $this->actingAs($this->admin)->from(route('admin.emails.edit', 'welcome'))
            ->put(route('admin.emails.update', 'welcome'), ['subject' => 'Hi {frist_name}', 'intro' => 'Welcome {order_number}'])
            ->assertSessionHasErrors(['subject', 'intro']);
        $this->actingAs($this->admin)->put(route('admin.emails.update', 'welcome'), ['subject' => ''])->assertSessionHasErrors('subject');
        $this->assertDatabaseCount('email_templates', 0);
    }

    public function test_words_are_escaped_and_the_live_preview_shows_unsaved_changes(): void
    {
        $this->actingAs($this->admin)->put(route('admin.emails.update', 'welcome'), [
            'subject' => 'Welcome', 'intro' => '<script>alert(1)</script> Glad you’re here', 'button' => 'Go',
        ])->assertRedirect();
        $html = (string) (new WelcomeToPanmotive)->toMail($this->admin)->render();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Glad you’re here', $html);

        $this->actingAs($this->admin)->post(route('admin.emails.preview', 'welcome'), ['subject' => 'Draft', 'intro' => 'Not saved yet'])
            ->assertOk()->assertSee('Not saved yet');
        $this->assertSame('<script>alert(1)</script> Glad you’re here', EmailTemplate::firstWhere('key', 'welcome')->intro); // draft didn't save
    }

    public function test_only_admins_can_edit(): void
    {
        $seller = User::factory()->create();
        $seller->assignRole('seller');
        $this->actingAs($this->withTwoFactor($seller))->get(route('admin.emails.edit', 'welcome'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.emails.edit', 'account_notice'))->assertNotFound();
    }
}
