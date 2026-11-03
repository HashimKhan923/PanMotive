<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LivewireScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_load_livewire_from_an_address_nginx_passes_to_laravel(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<script src="[^"]*/livewire/script\?id=#', $html);
        $this->assertStringNotContainsString('livewire.min.js', $html);
        $this->assertStringNotContainsString('livewire/livewire.js', $html);

        $response = $this->get('/livewire/script')->assertOk();
        $this->assertStringContainsString('javascript', $response->headers->get('Content-Type'));
    }
}
