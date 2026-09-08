<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

/**
 * Single place every AI call goes through — provider swaps, timeouts, and
 * rate-limit discipline live here. Uses the Anthropic Messages API over raw
 * HTTP (thin wrapper on purpose).
 */
class AiClient
{
    public function complete(string $system, string $prompt, int $maxTokens = 1000, ?string $jsonSchema = null): string
    {
        if (! config('services.anthropic.key')) {
            return ''; // no key configured — feature degrades gracefully to its fallback
        }

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($response->failed()) {
            report(new RuntimeException('AI request failed: '.$response->body()));

            return '';
        }

        return collect($response->json('content'))
            ->firstWhere('type', 'text')['text'] ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    public function completeJson(string $system, string $prompt, int $maxTokens = 1000): array
    {
        $raw = $this->complete(
            $system.' Respond ONLY with valid JSON, no markdown code fences, no preamble.',
            $prompt,
            $maxTokens,
        );

        if ($raw === '') {
            return [];
        }

        try {
            return json_decode($raw, true, flags: JSON_THROW_ON_ERROR) ?? [];
        } catch (JsonException) {
            report(new RuntimeException('AI returned invalid JSON: '.$raw));

            return [];
        }
    }
}
