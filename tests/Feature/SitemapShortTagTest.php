<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * With PHP's short_open_tag On (common on production servers), a literal "<?xml" in a Blade file
 * is read as PHP code and the page dies with a 500. Run this suite once with
 * `php -d short_open_tag=1 vendor/bin/phpunit --filter=SitemapShortTagTest` to test the real thing
 * (`artisan test` starts a new PHP process, which drops the setting).
 */
class SitemapShortTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_template_has_no_literal_php_style_open_tag(): void
    {
        $source = file_get_contents(resource_path('views/sitemap.blade.php'));

        $this->assertStringNotContainsString('<?', $source);
    }

    public function test_sitemap_starts_with_the_xml_declaration(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $response->getContent());
        $this->assertNotFalse(simplexml_load_string($response->getContent()));
    }
}
