<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandContrastTest extends TestCase
{
    private function tokens(string $selector): array
    {
        $css = file_get_contents(resource_path('css/app.css'));
        preg_match('/'.preg_quote($selector, '/').'\s*\{(.*?)\}/s', $css, $block);
        preg_match_all('/--c-([a-z0-9-]+):\s*(\d+) (\d+) (\d+)/', $block[1] ?? '', $rows, PREG_SET_ORDER);

        $tokens = [];
        foreach ($rows as $row) {
            $tokens[$row[1]] = [(int) $row[2], (int) $row[3], (int) $row[4]];
        }

        return $tokens;
    }

    private function ratio(array $a, array $b): float
    {
        $luminance = function (array $rgb): float {
            [$r, $g, $b] = array_map(function (int $v): float {
                $c = $v / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }, $rgb);

            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };
        [$hi, $lo] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** The pairs from the guide's contrast matrix, read from the live tokens. */
    public function test_light_tokens_meet_the_brand_contrast_matrix(): void
    {
        $t = $this->tokens(':root');

        foreach ([
            ['accent-fg', 'accent', 4.64], ['accent-fg', 'accent-hover', 5.65], ['accent-fg', 'accent-pressed', 6.52],
            ['fg', 'surface', 17.65], ['fg', 'surface-2', 16.32],
            ['muted', 'surface', 5.80], ['muted', 'surface-2', 5.37], ['input', 'surface', 4.97],
            ['success', 'success-bg', 4.82], ['warning', 'warning-bg', 5.83], ['error', 'error-bg', 5.68],
        ] as [$text, $on, $expected]) {
            $this->assertEqualsWithDelta($expected, $this->ratio($t[$text], $t[$on]), 0.02, "{$text} on {$on}");
        }

        // Small red text must stay readable on both white and the neutral surface.
        $this->assertGreaterThanOrEqual(4.5, $this->ratio($t['accent-ink'], $t['surface']));
        $this->assertGreaterThanOrEqual(4.5, $this->ratio($t['accent-ink'], $t['surface-2']));
    }

    public function test_dark_tokens_keep_text_readable_and_never_use_red_text(): void
    {
        $light = $this->tokens(':root');
        $dark = array_merge($light, $this->tokens('.dark, .bg-brand, .from-brand'));

        foreach ([$light['fg'], $dark['surface'], $dark['surface-2']] as $background) {
            foreach (['fg', 'muted', 'accent-ink', 'success', 'warning', 'error'] as $text) {
                $this->assertGreaterThanOrEqual(4.5, $this->ratio($dark[$text], $background), $text.' on dark');
            }
        }

        $this->assertSame([255, 255, 255], $dark['accent-ink']);
        $this->assertGreaterThanOrEqual(3.0, $this->ratio($dark['input'], $dark['surface']), 'input boundary');
    }
}
