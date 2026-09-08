<?php

namespace App\Services\Search;

use App\Services\AI\AiClient;

class SemanticFallbackService
{
    public function __construct(private AiClient $ai) {}

    public function rewriteQuery(string $originalQuery): ?string
    {
        $response = $this->ai->complete(
            system: 'You rewrite vague automotive-parts search queries into 2-4 concrete search keywords a parts '
                .'catalog would recognize. Respond with ONLY the keywords, nothing else. If the query is already '
                .'concrete, return it unchanged.',
            prompt: $originalQuery,
            maxTokens: 30,
        );

        return trim($response) ?: null;
    }
}
