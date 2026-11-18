<?php

namespace App\Jobs;

use App\Models\Product;
use App\Support\WebpImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Downloads an imported product's photos on the queue, one job per product, so a big import
 * finishes quickly and a slow photo host can never hold it up or cost a product its photos.
 */
class FetchProductImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // dead links are skipped inside; retrying would add duplicate photos

    public int $timeout = 900;

    private const MAX_PER_PRODUCT = 10;

    private const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * @param  array<int,string>  $urls  every distinct photo for the product
     * @param  array<string,string>  $variantPhotos  variant label => the URL of its own photo
     */
    public function __construct(
        public int $productId,
        public array $urls,
        public array $variantPhotos = [],
    ) {}

    public function handle(): void
    {
        set_time_limit(0);

        $product = Product::find($this->productId);
        if (! $product) {
            return;
        }

        $mediaIds = [];
        foreach (array_slice($this->urls, 0, self::MAX_PER_PRODUCT) as $url) {
            $download = null;
            $webp = null;
            try {
                // Download, then store as WebP like every other product photo.
                $body = Http::timeout(30)->get($url)->throw()->body();
                if (strlen($body) > self::MAX_BYTES) {
                    throw new \RuntimeException('Photo is larger than 15 MB.');
                }
                $download = tempnam(sys_get_temp_dir(), 'pm-dl-');
                file_put_contents($download, $body);
                unset($body);
                $webp = WebpImage::fromFile($download);
                $name = WebpImage::fileName(basename((string) parse_url($url, PHP_URL_PATH)));
                $mediaIds[$url] = $product->addMedia($webp)->usingFileName($name)->toMediaCollection('images')->id;
            } catch (\Throwable $e) {
                // One dead link shouldn't cost the product its other photos.
                Log::warning('Import could not fetch a product photo', ['product' => $product->id, 'url' => $url, 'error' => $e->getMessage()]);
            } finally {
                $download && @unlink($download);
                $webp && is_file($webp) && @unlink($webp);
            }
        }

        foreach ($this->variantPhotos as $label => $url) {
            if (isset($mediaIds[$url])) {
                $product->variants()->where('label', $label)->update(['media_id' => $mediaIds[$url]]);
            }
        }
    }
}
