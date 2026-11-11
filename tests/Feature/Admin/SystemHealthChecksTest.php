<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Heartbeat;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SystemHealthChecksTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();

        $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pm-heartbeats-'.uniqid();
        Heartbeat::$directory = $this->folder;

        config(['queue.default' => 'redis']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        Heartbeat::$directory = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{ok: bool, detail: string} */
    private function row(string $label): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $checks = $this->actingAs($this->withTwoFactor($admin))->get(route('admin.system.index'))->assertOk()->viewData('checks');

        return collect($checks)->firstWhere('label', $label);
    }

    private function launchRow(string $label): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $launch = $this->actingAs($this->withTwoFactor($admin))->get(route('admin.system.index'))->assertOk()->viewData('launch');

        return collect($launch)->firstWhere(0, $label);
    }

    private function storageIsSetUp(): void
    {
        config(['filesystems.disks.s3-backups.key' => 'key', 'filesystems.disks.s3-backups.bucket' => 'bucket']);
    }

    public function test_worker_shows_a_problem_until_a_real_worker_has_been_seen(): void
    {
        $row = $this->row('Background worker');
        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('Not running', $row['detail']);

        event(new Looping('redis', 'default'));

        $row = $this->row('Background worker');
        $this->assertTrue($row['ok']);
        $this->assertStringContainsString('Last ran', $row['detail']);
    }

    public function test_worker_that_has_stopped_shows_a_problem_again(): void
    {
        event(new Looping('redis', 'default'));

        Carbon::setTestNow(now()->addMinutes(16));

        $row = $this->row('Background worker');
        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('Stopped', $row['detail']);
    }

    public function test_worker_is_not_needed_when_jobs_run_straight_away(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertTrue($this->row('Background worker')['ok']);
    }

    public function test_scheduler_row_follows_the_real_scheduled_heartbeat(): void
    {
        $this->assertFalse($this->row('Scheduler')['ok']);

        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'system-heartbeat');
        $this->assertNotNull($event, 'The heartbeat must be on the schedule.');
        $this->assertSame('* * * * *', $event->expression);
        $event->run(app());

        $this->assertTrue($this->row('Scheduler')['ok']);

        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertFalse($this->row('Scheduler')['ok']);
    }

    public function test_clear_caches_does_not_make_a_healthy_worker_look_dead(): void
    {
        event(new Looping('redis', 'default'));

        Artisan::call('cache:clear');

        $this->assertTrue($this->row('Background worker')['ok']);
    }

    public function test_backups_need_storage_then_a_recent_successful_run(): void
    {
        config(['filesystems.disks.s3-backups.key' => null, 'filesystems.disks.s3-backups.bucket' => null]);
        $row = $this->row('Backups');
        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('not set up', $row['detail']);

        $this->storageIsSetUp();
        $row = $this->row('Backups');
        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('no backup has finished', $row['detail']);

        event(new BackupWasSuccessful(\Mockery::mock(BackupDestination::class)));
        $row = $this->row('Backups');
        $this->assertTrue($row['ok']);
        $this->assertStringContainsString('Last backup', $row['detail']);

        Carbon::setTestNow(now()->addHours(49));
        $this->assertFalse($this->row('Backups')['ok']);
    }

    public function test_a_failed_backup_after_a_good_one_shows_a_problem(): void
    {
        $this->storageIsSetUp();
        event(new BackupWasSuccessful(\Mockery::mock(BackupDestination::class)));

        Carbon::setTestNow(now()->addHour());
        event(new BackupHasFailed(new \Exception('disk full')));

        $row = $this->row('Backups');
        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('failed', $row['detail']);

        Carbon::setTestNow(now()->addHour());
        event(new BackupWasSuccessful(\Mockery::mock(BackupDestination::class)));
        $this->assertTrue($this->row('Backups')['ok']);
    }

    public function test_stripe_row_says_not_set_up_then_waiting_then_arrived(): void
    {
        config(['services.stripe.webhook_secret' => null]);
        $row = $this->row('Stripe webhooks arriving');
        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('not set up', $row['detail']);

        $secret = 'whsec_test';
        config(['services.stripe.webhook_secret' => $secret]);
        $row = $this->row('Stripe webhooks arriving');
        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('Cloudflare', $row['detail']);

        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'ping', 'data' => ['object' => []]]);

        // A forged call must not turn the row green.
        $this->call('POST', route('webhooks.stripe'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=forged', 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
        $this->assertFalse($this->row('Stripe webhooks arriving')['ok']);

        $time = time();
        $signature = hash_hmac('sha256', "{$time}.{$payload}", $secret);
        $this->call('POST', route('webhooks.stripe'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}", 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

        $row = $this->row('Stripe webhooks arriving');
        $this->assertTrue($row['ok']);
        $this->assertStringContainsString('Last arrived', $row['detail']);

        // A quiet shop isn't a fault.
        Carbon::setTestNow(now()->addDays(20));
        $this->assertTrue($this->row('Stripe webhooks arriving')['ok']);
    }

    public function test_easypost_row_only_turns_green_for_a_signed_webhook(): void
    {
        config(['services.easypost.webhook_secret' => null]);
        $this->assertStringContainsString('not set up', $this->row('EasyPost webhooks arriving')['detail']);

        $secret = 'easypost-test-secret';
        config(['services.easypost.webhook_secret' => $secret]);
        $this->assertStringContainsString('Cloudflare', $this->row('EasyPost webhooks arriving')['detail']);

        $body = json_encode(['description' => 'tracker.updated', 'result' => ['tracking_code' => 'NOPE']]);

        // The fake call that used to turn this row green.
        $this->call('POST', route('webhooks.easypost'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $this->assertFalse($this->row('EasyPost webhooks arriving')['ok']);

        $signature = 'hmac-sha256-hex='.hash_hmac('sha256', $body, $secret);
        $this->call('POST', route('webhooks.easypost'), [], [], [], ['HTTP_X_HMAC_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertTrue($this->row('EasyPost webhooks arriving')['ok']);
    }

    public function test_postal_address_row_catches_the_placeholder_but_accepts_a_real_one(): void
    {
        // The real default from config, so this breaks if the placeholder is ever reworded.
        $this->assertStringContainsString('2100 Commerce St', (string) config('marketplace.marketing.postal_address'));
        $this->assertFalse($this->launchRow('Real postal address in marketing emails')[1]);

        config(['marketplace.marketing.postal_address' => '']);
        $this->assertFalse($this->launchRow('Real postal address in marketing emails')[1]);

        config(['marketplace.marketing.postal_address' => 'Acme Parts LLC, 1 Real Road, Austin, TX 78701']);
        $this->assertTrue($this->launchRow('Real postal address in marketing emails')[1]);
    }

    public function test_the_screen_shows_the_new_rows(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($this->withTwoFactor($admin))->get(route('admin.system.index'))->assertOk()
            ->assertSee('Background worker')->assertSee('Scheduler')->assertSee('Backups')
            ->assertSee('Stripe webhooks arriving')->assertSee('EasyPost webhooks arriving')
            ->assertSee('Real postal address in marketing emails')
            ->assertSee('Postmark email')->assertSee('Connect Postmark for site emails')
            ->assertDontSee('all running');
    }

    public function test_postmark_row_needs_both_the_driver_and_the_token(): void
    {
        config(['mail.default' => 'log', 'services.postmark.key' => null]);
        $this->assertFalse($this->launchRow('Connect Postmark for site emails')[1]);

        config(['services.postmark.key' => 'server-token']);
        $this->assertFalse($this->launchRow('Connect Postmark for site emails')[1]); // token alone isn't enough

        config(['mail.default' => 'postmark']);
        $this->assertTrue($this->launchRow('Connect Postmark for site emails')[1]);
    }
}
