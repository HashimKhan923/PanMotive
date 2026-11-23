<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Services\AI\AiClient;
use App\Services\Seller\CategoryResolver;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CategoryResolverAiTest extends TestCase
{
    use RefreshDatabase;

    private const HINT = '2025, 2025 camry, 9th gen camry, cam-le, featured camry, new arrival, normal, toyota';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
    }

    private function resolver(array|\Closure $reply): CategoryResolver
    {
        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('isConfigured')->andReturn(true);
        $ai->shouldReceive('completeJson')->andReturnUsing(is_array($reply) ? fn () => $reply : $reply);

        return new CategoryResolver($ai);
    }

    public function test_the_ai_answers_by_number_not_by_echoing_long_tags_back(): void
    {
        $slug = Category::where('slug', 'tail-lights')->value('slug');
        $prompt = null;
        $out = $this->resolver(function ($system, $p) use (&$prompt, $slug) {
            $prompt = $p;

            return ['1' => $slug];
        })->resolveMany([self::HINT]);

        $this->assertStringContainsString('1. '.self::HINT, $prompt);
        $this->assertSame(Category::where('slug', 'tail-lights')->value('id'), $out[self::HINT] ?? null);
    }

    public function test_a_failed_reply_is_not_remembered_as_no_match(): void
    {
        $this->resolver([])->resolveMany([self::HINT]);            // the AI reply broke: nothing comes back

        $out = $this->resolver(['1' => 'tail-lights'])->resolveMany([self::HINT]);
        $this->assertArrayHasKey(self::HINT, $out, 'the next import must ask again');
    }

    public function test_a_real_no_match_is_remembered(): void
    {
        $this->resolver(['1' => null])->resolveMany([self::HINT]);

        $this->assertSame([], $this->resolver(['1' => 'tail-lights'])->resolveMany([self::HINT]));
    }
}
