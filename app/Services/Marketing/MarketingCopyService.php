<?php

namespace App\Services\Marketing;

use App\Models\Product;
use App\Services\AI\AiClient;
use App\Services\Marketing\Social\PlatformRegistry;
use Illuminate\Support\Str;

/**
 * One place for AI-assisted marketing copy. Every method degrades to a decent
 * template when no AI key is configured, so the studio always produces output.
 */
class MarketingCopyService
{
    public function __construct(private AiClient $ai) {}

    public function socialCaption(?Product $product, string $platform, string $tone = 'friendly'): string
    {
        $cfg = PlatformRegistry::config($platform);
        $limit = $cfg['char_limit'] ?? 500;
        $useTags = $cfg['hashtags'] ?? false;

        $ctx = $product
            ? "Product: {$product->title}. Brand: ".($product->brand?->name ?? 'n/a').
              ". Price: \${$product->effectivePrice()}. Condition: {$product->condition}."
            : 'A general promotional post for an auto-parts store.';

        $ai = $this->ai->complete(
            system: "You write short, {$tone} social media captions for an automotive parts seller. "
                ."Target platform: ".PlatformRegistry::label($platform).". Hard limit {$limit} characters. "
                .($useTags ? 'End with 3-5 relevant hashtags.' : 'Do not use hashtags.')
                .' No emojis unless they add clarity. Plain text only.',
            prompt: $ctx.($product ? "\nInclude a soft call to action to shop the listing." : ''),
            maxTokens: 350,
        );

        if ($ai !== '') {
            return Str::limit(trim($ai), $limit, '');
        }

        // Fallback template.
        $base = $product
            ? "{$product->title} — now in stock".($product->isOnDeal() ? " at \${$product->sale_price} (was \${$product->price})" : " for \${$product->price}").". Fast shipping, buyer protection on every order."
            : "New arrivals just landed. Genuine and OEM-grade auto parts, matched to your vehicle.";

        if ($useTags) {
            $base .= "\n#autoparts #".Str::slug($product?->category?->name ?? 'carparts', '').' #diyauto #panmotive';
        }

        return Str::limit($base, $limit, '');
    }

    /** @return array{subject:string, preview:string, body:string} */
    public function emailCopy(string $goal, ?Product $product = null, ?string $storeName = null): array
    {
        $store = $storeName ?: 'our store';
        $ctx = $product ? "Featured product: {$product->title} at \${$product->effectivePrice()}." : '';

        $json = $this->ai->completeJson(
            system: "You are an email marketer for an automotive parts store ({$store}). Goal: {$goal}. "
                .'Return JSON: subject (<=60 chars), preview (<=90 chars), body (plain text, 90-160 words, '
                .'one clear CTA, friendly, no spammy caps). Use a {{shop_url}} placeholder for the link.',
            prompt: $goal.' '.$ctx,
            maxTokens: 500,
        );

        if (! empty($json['body'])) {
            return [
                'subject' => Str::limit($json['subject'] ?? 'A note from '.$store, 60, ''),
                'preview' => Str::limit($json['preview'] ?? '', 90, ''),
                'body' => trim($json['body']),
            ];
        }

        return [
            'subject' => match ($goal) {
                'win_back' => "We miss you at {$store}",
                'new_arrivals' => "Fresh stock just landed at {$store}",
                'sale' => "A little something off at {$store}",
                default => "News from {$store}",
            },
            'preview' => 'Parts matched to your vehicle, shipped fast.',
            'body' => "Hi there,\n\n".($product
                ? "We just restocked {$product->title} — \${$product->effectivePrice()} with buyer protection and quick dispatch.\n\n"
                : "We've added new parts to the store and refreshed a few prices.\n\n")
                ."Take a look: {{shop_url}}\n\nThanks for shopping with {$store}.",
        ];
    }

    /** @return array{headline:string, primary_text:string} */
    public function adCopy(Product $product, string $objective = 'sales'): array
    {
        $json = $this->ai->completeJson(
            system: 'Write one ad creative for an automotive part. Return JSON: headline (<=40 chars), '
                ."primary_text (<=125 chars). Objective: {$objective}. Punchy, benefit-led, no hype words.",
            prompt: "{$product->title}, \${$product->effectivePrice()}, {$product->condition} condition"
                .($product->brand ? ", brand {$product->brand->name}" : ''),
            maxTokens: 200,
        );

        return [
            'headline' => Str::limit($json['headline'] ?? Str::limit($product->title, 38, ''), 40, ''),
            'primary_text' => Str::limit($json['primary_text'] ?? "In stock now at \${$product->effectivePrice()} — fast shipping, buyer protection.", 125, ''),
        ];
    }
}
