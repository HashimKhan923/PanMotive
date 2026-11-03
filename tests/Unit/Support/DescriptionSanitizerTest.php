<?php

namespace Tests\Unit\Support;

use App\Support\Html\DescriptionSanitizer;
use Tests\TestCase;

class DescriptionSanitizerTest extends TestCase
{
    private function s(): DescriptionSanitizer
    {
        return new DescriptionSanitizer;
    }

    public function test_plain_text_passes_through_untouched(): void
    {
        $this->assertSame('Just plain text, no markup here.', $this->s()->sanitize('Just plain text, no markup here.'));
    }

    public function test_null_and_blank_return_null(): void
    {
        $this->assertNull($this->s()->sanitize(null));
        $this->assertNull($this->s()->sanitize('   '));
    }

    public function test_keeps_structural_tags(): void
    {
        $html = '<p>First <strong>bold</strong> para.</p><ul><li>One</li><li>Two</li></ul><h2>Heading</h2>';
        $out = $this->s()->sanitize($html);

        $this->assertStringContainsString('<p>First <strong>bold</strong> para.</p>', $out);
        $this->assertStringContainsString('<li>One</li>', $out);
        $this->assertStringContainsString('<h2>Heading</h2>', $out);
    }

    public function test_unwraps_disallowed_wrapper_tags_but_keeps_their_text(): void
    {
        $out = $this->s()->sanitize('<div><span>Hello</span> <figure>World</figure></div>');

        $this->assertSame('Hello World', $out);
    }

    public function test_strips_script_and_style_entirely(): void
    {
        $out = $this->s()->sanitize('<p>Safe</p><script>alert(1)</script><style>body{color:red}</style>');

        $this->assertStringContainsString('<p>Safe</p>', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringNotContainsString('color:red', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function test_strips_event_handler_and_style_attributes(): void
    {
        $out = $this->s()->sanitize('<p onclick="evil()" style="color:red" class="x">Hi</p>');

        $this->assertSame('<p>Hi</p>', $out);
    }

    public function test_keeps_safe_links_and_adds_rel_noopener(): void
    {
        $out = $this->s()->sanitize('<p><a href="https://example.com">link</a></p>');

        $this->assertStringContainsString('href="https://example.com"', $out);
        $this->assertStringContainsString('rel="noopener nofollow ugc"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
    }

    public function test_strips_javascript_href(): void
    {
        $out = $this->s()->sanitize('<p><a href="javascript:alert(1)">click</a></p>');

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('href=', $out);
    }

    public function test_removes_img_and_iframe_entirely(): void
    {
        $out = $this->s()->sanitize('<p>Text</p><img src="x.png" onerror="evil()"><iframe src="evil.com"></iframe>');

        $this->assertSame('<p>Text</p>', $out);
    }

    public function test_shopify_style_body_html_survives_with_structure(): void
    {
        $html = '<p>This part is <b>in transit</b>.</p>'
            .'<p>🔥 PRODUCT FEATURES ✅ Designed for 2018-2026 models ✅ Genuine carbon fiber</p>'
            .'<p>🚗 VEHICLE FITMENT This wing fits: 2018 | 2019 | 2020</p>';

        $out = $this->s()->sanitize($html);

        $this->assertSame(3, substr_count($out, '<p>'));
        $this->assertStringContainsString('<b>in transit</b>', $out);
        $this->assertStringContainsString('🔥 PRODUCT FEATURES', $out);
    }

    public function test_truncates_to_max_length_before_parsing(): void
    {
        $out = $this->s()->sanitize(str_repeat('a', 50), 10);

        $this->assertLessThanOrEqual(10, mb_strlen($out));
    }
}
