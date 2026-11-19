<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Safety net for the photo bucket: removes photo folders that no photo record points to any
 * more (e.g. a listing deleted while its photos were still downloading).
 *
 * The media library keeps each photo in a folder named after its id ("123/…"). Every other
 * upload (banners, campaign images, messages…) lives in a named folder, so only all-digit
 * folders are ever looked at. A record is saved before its files are written, so a folder
 * without a record is never a photo that is still being added.
 */
class SweepOrphanedMedia extends Command
{
    protected $signature = 'media:sweep-orphans {--dry-run : Only list what would be removed}';

    protected $description = 'Remove photo folders on the media disk that no photo record uses';

    public function handle(): int
    {
        $live = DB::table('media')->pluck('id')->map(fn ($id) => (string) $id)->flip();
        $dry = (bool) $this->option('dry-run');

        foreach (array_unique(['public', 'public-local']) as $name) {
            $disk = Storage::disk($name);
            $removed = 0;
            foreach ($disk->directories('') as $dir) {
                if (! ctype_digit($dir) || isset($live[$dir])) {
                    continue;
                }
                $dry ? $this->line("would remove {$name}/{$dir}") : $disk->deleteDirectory($dir);
                $removed++;
            }
            $this->info(($dry ? 'Would remove' : 'Removed')." {$removed} unused photo folder(s) on {$name}.");
        }

        return self::SUCCESS;
    }
}
