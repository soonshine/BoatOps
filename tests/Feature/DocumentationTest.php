<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_local_alpha_homepage_identifies_scope_and_release_state(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('BoatOps Community')
            ->assertSee('0.0.1 local alpha')
            ->assertSee('NOT DEPLOYED')
            ->assertSee('API 契约文档');
    }

    public function test_bundled_api_documentation_is_reachable(): void
    {
        $response = $this->get('/api-docs');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertHeader('x-robots-tag', 'noindex, nofollow, noarchive');

        $file = $response->baseResponse->getFile();
        $this->assertSame(public_path('api-docs.html'), $file->getPathname());
        $this->assertStringContainsString(
            'BoatOps Inventory Provider API',
            file_get_contents($file->getPathname()),
        );
    }

    public function test_bundled_operations_api_documentation_is_reachable(): void
    {
        $response = $this->get('/operations-api-docs');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertHeader('x-robots-tag', 'noindex, nofollow, noarchive');

        $file = $response->baseResponse->getFile();
        $this->assertSame(public_path('operations-api-docs.html'), $file->getPathname());
        $this->assertStringContainsString(
            'BoatOps Internal Operations API',
            file_get_contents($file->getPathname()),
        );
    }
}
