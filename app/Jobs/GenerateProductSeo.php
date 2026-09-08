<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\AI\SeoGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateProductSeo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $productId) {}

    public function handle(SeoGeneratorService $seo): void
    {
        if (! config('services.anthropic.key')) {
            return; // no key — leave meta as-is rather than blanking it
        }

        $product = Product::find($this->productId);

        if (! $product) {
            return;
        }

        $data = $seo->generateForProduct($product);

        $product->update([
            'meta_title' => $data['meta_title'],
            'meta_description' => $data['meta_description'],
            'structured_data' => $data['structured_data'],
        ]);
    }
}
