<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppPageTest extends TestCase
{
    private string $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->page = tempnam(sys_get_temp_dir(), 'doc-page');
        file_put_contents($this->page, '<!DOCTYPE html><title>Documentator</title>');
        config(['documentator.app_page' => $this->page]);
    }

    protected function tearDown(): void
    {
        @unlink($this->page);
        parent::tearDown();
    }

    public function test_the_built_page_is_served_with_a_strict_content_security_policy(): void
    {
        $response = $this->get('/')->assertOk()->assertSee('Documentator', false);

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
    }

    public function test_index_html_is_the_same_page(): void
    {
        $this->get('/index.html')->assertOk()->assertSee('Documentator', false);
    }

    public function test_an_unbuilt_page_says_how_to_build_it(): void
    {
        config(['documentator.app_page' => $this->page.'.missing']);

        $this->get('/')->assertStatus(503)->assertSee('npm run build');
    }

    public function test_the_page_source_has_no_inline_script_and_is_wired_for_the_build(): void
    {
        $source = file_get_contents(base_path('resources/app/index.html'));

        $this->assertStringContainsString('<!-- documentator:head -->', $source);
        $this->assertStringContainsString('<!-- documentator:app -->', $source);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $source);
        $this->assertDoesNotMatchRegularExpression('/\son(click|submit|load)=/i', $source);
    }
}
