<?php

namespace App\Services\Search;

use App\Services\AI\AiClient;
use Illuminate\Support\Facades\Cache;

class SemanticFallbackService
{
    public function __construct(private AiClient $ai) {}

    public function rewriteQuery(string $originalQuery): ?string
    {
        $key = 'search.rewrite.'.md5(mb_strtolower(trim($originalQuery)));

        return Cache::remember($key, now()->addDay(), function () use ($originalQuery) {
            try {
                $response = $this->ai->complete(
                    system: 'You rewrite vague automotive-parts search queries into 2-4 concrete search keywords a parts '
                        .'catalog would recognize. Respond with ONLY the keywords, nothing else. If the query is already '
                        .'concrete, return it unchanged.',
                    prompt: $originalQuery,
                    maxTokens: 30,
                );
            } catch (\Throwable $e) {
                report($e);

                return '';
            }

            return trim((string) $response);
        }) ?: null;
    }
}
