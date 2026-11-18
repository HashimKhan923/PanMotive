<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-time (and safe to re-run) copy of the public files on this server into the cloud
 * "public" disk (Cloudflare R2). Only images and PDFs are copied; hidden files and anything
 * else are skipped. Files already there with the same size are left alone.
 */
class CopyPublicFilesToCloud extends Command
{
    protected $signature = 'storage:copy-public-to-cloud {--dry-run : List what would be copied without copying}';

    protected $description = 'Copy public files (photos, logos, banners) from this server to the cloud public disk';

    private const ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'pdf', 'avif'];

    public function handle(): int
    {
        if (config('filesystems.disks.public.driver') === 'local') {
            $this->error('The public disk is still on this server (MEDIA_DRIVER is not r2). Nothing to copy to.');

            return self::FAILURE;
        }

        $from = Storage::disk('public-local');
        $to = Storage::disk('public');
        $copied = $same = $skipped = $failed = 0;

        foreach ($from->allFiles() as $file) {
            $name = basename($file);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (str_starts_with($name, '.') || str_contains($file, '/.') || ! in_array($ext, self::ALLOWED, true)) {
                $skipped++;
                $this->line("skip  $file");

                continue;
            }
            if ($to->exists($file) && $to->size($file) === $from->size($file)) {
                $same++;

                continue;
            }
            if ($this->option('dry-run')) {
                $copied++;
                $this->line("would copy  $file");

                continue;
            }
            $stream = $from->readStream($file);
            $ok = $stream && $to->writeStream($file, $stream, ['ContentType' => $from->mimeType($file) ?: 'application/octet-stream']);
            is_resource($stream) && fclose($stream);
            $ok ? $copied++ : $failed++;
            if (! $ok) {
                $this->warn("FAILED  $file");
            }
        }

        $this->info(($this->option('dry-run') ? 'Would copy' : 'Copied')." $copied, already there $same, skipped $skipped, failed $failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
