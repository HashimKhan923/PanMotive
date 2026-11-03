<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Html\DescriptionFormatter;
use App\Support\Html\DescriptionSanitizer;
use Illuminate\Console\Command;

/**
 * One-off backfill: restructures existing plain-text, emoji-marker product
 * descriptions (imported before DescriptionFormatter existed) into real
 * HTML — same transform ProductCsvImporter now applies automatically going
 * forward. Safe to re-run: only touches rows that are still plain text.
 */
class ReformatProductDescriptions extends Command
{
    protected $signature = 'products:reformat-descriptions {--dry-run}';

    protected $description = 'Restructure plain-text emoji-marker product descriptions into real HTML';

    public function handle(DescriptionFormatter $formatter, DescriptionSanitizer $sanitizer): int
    {
        $dry = (bool) $this->option('dry-run');
        $updated = 0;

        Product::query()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->chunkById(200, function ($products) use ($formatter, $sanitizer, $dry, &$updated) {
                foreach ($products as $product) {
                    $structured = $formatter->format($product->description);
                    if ($structured === null) {
                        continue; // already HTML, or not the emoji-marker convention.
                    }

                    $clean = $sanitizer->sanitize($structured, 8000);
                    $this->line(($dry ? '[dry-run] ' : '').'#'.$product->id.' '.$product->name);
                    if (! $dry) {
                        $product->forceFill(['description' => $clean])->saveQuietly();
                    }
                    $updated++;
                }
            });

        $this->info(($dry ? 'Would update ' : 'Updated ').$updated.' product description(s).');

        return self::SUCCESS;
    }
}
