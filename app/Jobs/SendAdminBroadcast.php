<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\AdminBroadcast;
use App\Models\User;
use App\Services\Marketing\MarketingConsent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendAdminBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Never retried: a second attempt would email people twice. Failures are counted instead. */
    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $broadcastId) {}

    public function handle(MarketingConsent $consent): void
    {
        $broadcast = AdminBroadcast::find($this->broadcastId);
        if (! $broadcast || $broadcast->status !== 'queued') {
            return;
        }

        $broadcast->update(['status' => 'sending']);
        $sent = 0;
        $failed = 0;

        AdminBroadcast::audienceQuery($broadcast->audience, $broadcast->kind)->chunkById(200, function ($users) use ($broadcast, $consent, &$sent, &$failed) {
            /** @var User $user */
            foreach ($users as $user) {
                try {
                    Mail::to($user->email)->send(new CampaignMail(
                        $broadcast->subject,
                        (string) $broadcast->preview,
                        (string) $broadcast->body,
                        $broadcast->kind === 'marketing' ? $consent->unsubscribeUrl($user, null) : null,
                        'Panmotive',
                        true,
                    ));
                    $sent++;
                } catch (\Throwable $e) {
                    report($e);
                    $failed++;
                }
            }
            $broadcast->update(['sent_count' => $sent, 'failed_count' => $failed]);
        });

        $broadcast->update([
            'status' => $sent === 0 && $failed > 0 ? 'failed' : 'sent',
            'recipients' => $sent + $failed, 'sent_count' => $sent, 'failed_count' => $failed, 'sent_at' => now(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        AdminBroadcast::whereKey($this->broadcastId)->update(['status' => 'failed']);
    }
}
