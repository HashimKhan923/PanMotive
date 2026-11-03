<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Services\Marketing\Social\SocialPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishSocialPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $postId) {}

    public function handle(SocialPublisher $publisher): void
    {
        $post = SocialPost::with('targets')->find($this->postId);

        if ($post && in_array($post->status, ['scheduled', 'draft', 'publishing', 'failed', 'partial'], true)) {
            $publisher->publish($post);
        }
    }
}
