<?php

namespace Tests\Unit\Support;

use App\Support\Html\DescriptionFormatter;
use Tests\TestCase;

class DescriptionFormatterTest extends TestCase
{
    private function f(): DescriptionFormatter
    {
        return new DescriptionFormatter;
    }

    public function test_leaves_plain_text_with_no_emoji_markers_alone(): void
    {
        $this->assertNull($this->f()->format('Just a normal sentence about a part.'));
    }

    public function test_leaves_text_that_already_has_real_html_alone(): void
    {
        $this->assertNull($this->f()->format('<p>Already <strong>formatted</strong>.</p>'));
    }

    public function test_turns_bare_emoji_caps_heading_into_h3_even_with_nothing_trailing_in_segment(): void
    {
        $raw = 'Intro sentence here. 🔥 PRODUCT FEATURES ✅ First feature ✅ Second feature';
        $out = $this->f()->format($raw);

        $this->assertStringContainsString('<h3>Product Features</h3>', $out);
        $this->assertStringContainsString('<li>First feature</li>', $out);
        $this->assertStringContainsString('<li>Second feature</li>', $out);
        $this->assertStringNotContainsString('<li>Product Features</li>', $out);
    }

    public function test_turns_emoji_caps_heading_with_trailing_prose_into_heading_plus_paragraph(): void
    {
        $raw = 'Intro. 🔥 FEATURES ✅ One feature 🚗 VEHICLE FITMENT This fits the following years: 2020, 2021, 2022.';
        $out = $this->f()->format($raw);

        $this->assertStringContainsString('<h3>Vehicle Fitment</h3>', $out);
        $this->assertStringContainsString('<p>This fits the following years: 2020, 2021, 2022.</p>', $out);
    }

    public function test_bolds_a_label_value_bullet(): void
    {
        $raw = 'Intro sentence. 🚗 Fitment: Toyota Camry 2025 ⚡ Install: ~1 hour';
        $out = $this->f()->format($raw);

        $this->assertStringContainsString('<li><strong>Fitment:</strong> Toyota Camry 2025</li>', $out);
        $this->assertStringContainsString('<li><strong>Install:</strong> ~1 hour</li>', $out);
    }

    public function test_wraps_warning_glyph_in_blockquote_including_with_variation_selector(): void
    {
        $raw = 'Intro sentence here for context. 🔥 FEATURES ✅ Aluminum body ⚠️ Not compatible with AWD models.';
        $out = $this->f()->format($raw);

        $this->assertStringContainsString('<blockquote>Not compatible with AWD models.</blockquote>', $out);
    }

    public function test_requires_at_least_two_markers_before_restructuring(): void
    {
        $this->assertNull($this->f()->format('Just one marker here 🔥 and nothing else.'));
    }
}
