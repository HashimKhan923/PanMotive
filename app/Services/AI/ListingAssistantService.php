<?php

namespace App\Services\AI;

use App\Models\Category;
use App\Models\Product;

class ListingAssistantService
{
    public function __construct(private AiClient $ai) {}

    /**
     * @return array{title: string, description: string, bullet_points: array<int, string>}
     */
    public function generateListingDraft(string $partNumber, ?int $categoryId = null): array
    {
        $categoryName = $categoryId ? Category::find($categoryId)?->name : null;

        $result = $this->ai->completeJson(
            system: 'You are an automotive parts cataloging expert. Given a part number and optional category, '
                .'produce an SEO-friendly product listing. Return JSON with keys: title (string, under 70 chars, '
                .'includes part number), description (HTML string, 2-3 short paragraphs covering fit/function/quality), '
                .'bullet_points (array of 4-5 short strings highlighting specs/benefits). '
                .'Do not invent a specific vehicle fitment you are not given — describe function generically.',
            prompt: "Part number: {$partNumber}".($categoryName ? "\nCategory: {$categoryName}" : ''),
            maxTokens: 800,
        );

        return [
            'title' => $result['title'] ?? '',
            'description' => $result['description'] ?? '',
            'bullet_points' => $result['bullet_points'] ?? [],
        ];
    }

    /**
     * Competitive pricing band from the category median. Median is computed in
     * PHP (DB-agnostic) — no native Eloquent median()/window function needed.
     *
     * @return array{suggested_min: float, suggested_max: float, category_median: float}|null
     */
    public function suggestPrice(int $categoryId, string $condition): ?array
    {
        $prices = Product::where('category_id', $categoryId)
            ->where('condition', $condition)
            ->where('status', 'active')
            ->pluck('price')
            ->map(fn ($p) => (float) $p)
            ->sort()
            ->values();

        if ($prices->isEmpty()) {
            return null;
        }

        $mid = intdiv($prices->count(), 2);
        $median = $prices->count() % 2
            ? $prices[$mid]
            : ($prices[$mid - 1] + $prices[$mid]) / 2;

        return [
            'suggested_min' => round($median * 0.9, 2),
            'suggested_max' => round($median * 1.1, 2),
            'category_median' => round($median, 2),
        ];
    }
}
