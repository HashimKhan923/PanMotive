<?php

namespace App\Services\AI;

use App\Models\Product;

class SeoGeneratorService
{
    public function __construct(private AiClient $ai) {}

    /**
     * @return array{meta_title: string, meta_description: string, structured_data: array<string, mixed>}
     */
    public function generateForProduct(Product $product): array
    {
        $result = $this->ai->completeJson(
            system: 'You write SEO meta tags for e-commerce product pages selling automotive parts. '
                .'Return JSON: meta_title (under 60 chars, include brand/part if relevant), '
                .'meta_description (under 155 chars, action-oriented, no keyword stuffing).',
            prompt: "Product title: {$product->title}\nCategory: {$product->category?->name}\nBrand: {$product->brand?->name}",
            maxTokens: 200,
        );

        $structuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->title,
            'sku' => $product->sku,
            'brand' => ['@type' => 'Brand', 'name' => $product->brand?->name],
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'USD',
                'price' => (string) ($product->sale_price ?? $product->price),
                'availability' => $product->quantity > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            ],
        ];

        return [
            'meta_title' => $result['meta_title'] ?? $product->title,
            'meta_description' => $result['meta_description'] ?? '',
            'structured_data' => $structuredData,
        ];
    }
}
