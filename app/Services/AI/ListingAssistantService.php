<?php

namespace App\Services\AI;

use App\Models\Category;

/**
 * Skeleton — a real LLM call lands in Phase 6. For now this returns a
 * deterministic draft so the "Generate with AI" button is functional end to end.
 */
class ListingAssistantService
{
    /**
     * @return array{title: string, description: string, bullet_points: array<int, string>}
     */
    public function generateListingDraft(string $partNumber, ?int $categoryId = null): array
    {
        $category = $categoryId ? Category::find($categoryId)?->name : null;
        $noun = $category ? rtrim($category, 's') : 'Part';

        return [
            'title' => trim("{$noun} — {$partNumber}"),
            'description' => "Replacement {$noun} {$partNumber}"
                .($category ? " for the {$category} system." : '.')
                .' Verify fitment against your vehicle before purchase.',
            'bullet_points' => [
                'Direct-fit replacement',
                'Meets or exceeds OE specifications',
                "Cross-reference: {$partNumber}",
            ],
        ];
    }
}
