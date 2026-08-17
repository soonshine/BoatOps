<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_finance_reversal_local_candidate_document_exists_with_unreleased_boundaries(): void
    {
        $document = file_get_contents(base_path('docs/releases/0.0.3-finance-reversals-local-candidate.md'));

        $this->assertIsString($document);
        $this->assertStringContainsString('LOCAL_WORKTREE / NOT_DEPLOYED / NOT_RELEASED', $document);
        $this->assertStringContainsString('18 operations endpoints', $document);
        $this->assertStringContainsString('PostgreSQL', $document);
        $this->assertStringContainsString('NOT_RUN', $document);
    }

    public function test_operator_calendar_local_candidate_has_demo_and_operating_time_boundaries(): void
    {
        $document = file_get_contents(base_path('docs/releases/0.0.6-operator-calendar-local-candidate.md'));

        $this->assertIsString($document);
        $this->assertStringContainsString('LOCAL_WORKTREE / NOT_DEPLOYED / NOT_RELEASED', $document);
        $this->assertStringContainsString('DEMO DATA ONLY', $document);
        $this->assertStringContainsString('OPERATING SLOT TIMES NOT FROZEN', $document);
        $this->assertStringContainsString('演示默认档期；真实起止时间和周转缓冲尚未冻结。', $document);
        $this->assertStringContainsString('FICTIONAL_VALIDATION_SCENARIO', $document);
        $this->assertStringContainsString('CUSTOM_INSTANCE', $document);
        $this->assertStringContainsString('not modify the reusable `FULL_DAY_6H` preset', $document);
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
        $this->assertStringContainsString('1.0.0-alpha.5', file_get_contents($file->getPathname()));
        $this->assertStringContainsString('recordFuelLog', file_get_contents($file->getPathname()));
        $this->assertStringContainsString('getStockBalances', file_get_contents($file->getPathname()));
        $this->assertStringContainsString('getScheduleCalendar', file_get_contents($file->getPathname()));
        $this->assertStringContainsString('createCustomSlotInstance', file_get_contents($file->getPathname()));
    }
}
