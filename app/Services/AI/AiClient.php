<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

/**
 * Single place every AI call goes through — provider swaps, timeouts, and
 * rate-limit discipline live here. Supports Anthropic (Claude) and OpenAI over
 * raw HTTP (thin wrapper on purpose). Set AI_PROVIDER=openai|anthropic, or leave
 * it 'auto' to prefer whichever key is configured. With no key the call returns
 * '' and every feature degrades gracefully to its non-AI fallback.
 */
class AiClient
{
    public function complete(string $system, string $prompt, int $maxTokens = 1000, ?string $jsonSchema = null): string
    {
        return match ($this->provider()) {
            'openai' => $this->openai($system, $prompt, $maxTokens),
            'anthropic' => $this->anthropic($system, $prompt, $maxTokens),
            default => '',
        };
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

        // Tolerate a stray ```json fence if the model adds one.
        $raw = trim(preg_replace('/^```(?:json)?|```$/m', '', $raw));

        try {
            return json_decode($raw, true, flags: JSON_THROW_ON_ERROR) ?? [];
        } catch (JsonException) {
            report(new RuntimeException('AI returned invalid JSON: '.$raw));

            return [];
        }
    }

    public function isConfigured(): bool
    {
        return $this->provider() !== null;
    }

    /** Resolve the active provider, or null when nothing is configured. */
    private function provider(): ?string
    {
        $choice = config('services.ai.provider', 'auto');

        if ($choice === 'openai') {
            return config('services.openai.key') ? 'openai' : null;
        }
        if ($choice === 'anthropic') {
            return config('services.anthropic.key') ? 'anthropic' : null;
        }

        // auto
        return match (true) {
            (bool) config('services.openai.key') => 'openai',
            (bool) config('services.anthropic.key') => 'anthropic',
            default => null,
        };
    }

    private function anthropic(string $system, string $prompt, int $maxTokens): string
    {
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
            report(new RuntimeException('Anthropic request failed: '.$response->body()));

            return '';
        }

        return collect($response->json('content'))->firstWhere('type', 'text')['text'] ?? '';
    }

    private function openai(string $system, string $prompt, int $maxTokens): string
    {
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(30)
            ->post(config('services.openai.base').'/chat/completions', [
                'model' => config('services.openai.model'),
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->failed()) {
            report(new RuntimeException('OpenAI request failed: '.$response->body()));

            return '';
        }

        return (string) $response->json('choices.0.message.content', '');
    }
}
