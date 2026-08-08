<?php

namespace Tests\Feature;

use Database\Seeders\DemoSiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_demo_seeding_requires_every_explicit_public_seed_gate(): void
    {
        $originalEnvironment = $this->app->environment();
        $originalConfig = config('demo_site');
        $token = 'fictional-production-seed-gate-test-token';
        putenv('BOATOPS_DEMO_TOKEN='.$token);

        $invalidCases = [
            ['environment' => 'production', 'enabled' => false, 'mode' => 'public_read_only', 'allow' => true],
            ['environment' => 'production', 'enabled' => true, 'mode' => 'disabled', 'allow' => true],
            ['environment' => 'production', 'enabled' => true, 'mode' => 'public_read_only', 'allow' => false],
            ['environment' => 'staging', 'enabled' => true, 'mode' => 'public_read_only', 'allow' => true],
        ];

        try {
            foreach ($invalidCases as $case) {
                $this->app->detectEnvironment(fn (): string => $case['environment']);
                config([
                    'demo_site.enabled' => $case['enabled'],
                    'demo_site.mode' => $case['mode'],
                    'demo_site.allow_production_seed' => $case['allow'],
                ]);

                try {
                    $this->runDemoSiteSeeder();
                    $this->fail('The fictional production seeder accepted an incomplete gate set.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('one-time production seed flag', $exception->getMessage());
                }
            }
        } finally {
            $this->app->detectEnvironment(fn (): string => $originalEnvironment);
            config(['demo_site' => $originalConfig]);
            putenv('BOATOPS_DEMO_TOKEN');
        }

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_production_demo_seeding_is_allowed_only_with_all_public_seed_gates(): void
    {
        $originalEnvironment = $this->app->environment();
        $originalConfig = config('demo_site');
        $token = 'fictional-production-public-demo-token';
        putenv('BOATOPS_DEMO_TOKEN='.$token);

        try {
            $this->app->detectEnvironment(fn (): string => 'production');
            config([
                'demo_site.enabled' => true,
                'demo_site.mode' => 'public_read_only',
                'demo_site.allow_production_seed' => true,
            ]);

            $this->runDemoSiteSeeder();
        } finally {
            $this->app->detectEnvironment(fn (): string => $originalEnvironment);
            config(['demo_site' => $originalConfig]);
            putenv('BOATOPS_DEMO_TOKEN');
        }

        $this->assertDatabaseHas('organizations', ['name' => 'Fictional Andaman Charter Lab']);
        $this->assertDatabaseHas('api_clients', ['name' => 'Public Demo Reader']);
        $this->assertDatabaseCount('boats', 2);
    }

    public function test_demo_seeder_creates_idempotent_fictional_inventory_without_storing_plain_token(): void
    {
        $token = 'fictional-local-demo-token-for-test-only';
        putenv('BOATOPS_DEMO_TOKEN='.$token);
        try {
            $this->seed();
            $this->seed();
        } finally {
            putenv('BOATOPS_DEMO_TOKEN');
        }

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseHas('organizations', ['name' => 'Fictional Andaman Charter Lab', 'timezone' => 'Asia/Bangkok']);
        $this->assertDatabaseCount('boats', 2);
        $this->assertDatabaseHas('boats', ['name' => 'Plan A（虚构演示船）', 'buffer_before_minutes' => 30, 'buffer_after_minutes' => 30]);
        $this->assertDatabaseHas('boats', ['name' => 'Plan B（虚构演示船）', 'buffer_before_minutes' => 30, 'buffer_after_minutes' => 30]);
        $this->assertDatabaseCount('trip_templates', 1);
        $this->assertDatabaseHas('trip_templates', ['code' => 'DEMO-4H', 'name' => 'Fictional Four Hour Whole-Boat Charter']);
        $this->assertDatabaseCount('api_clients', 3);
        $this->assertDatabaseHas('api_clients', ['name' => 'Local Demo API Client', 'token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseHas('api_clients', ['name' => 'Local Demo Site Actor', 'token_hash' => hash('sha256', 'demo-site-actor:'.$token)]);
        $this->assertDatabaseHas('api_clients', ['name' => 'Public Demo Reader', 'token_hash' => hash('sha256', 'public-demo-reader:'.$token)]);
        $this->assertSame([
            'operations.write',
            'operations.finance.read',
            'operations.finance.write',
            'operations.schedule.read',
            'operations.schedule.write',
        ], json_decode((string) DB::table('api_clients')->where('name', 'Local Demo API Client')->value('scopes'), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame([
            'operations.finance.read',
            'operations.finance.write',
            'operations.schedule.read',
            'operations.schedule.write',
        ], json_decode((string) DB::table('api_clients')->where('name', 'Local Demo Site Actor')->value('scopes'), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame([
            'operations.finance.read',
            'operations.schedule.read',
        ], json_decode((string) DB::table('api_clients')->where('name', 'Public Demo Reader')->value('scopes'), true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($token, (string) DB::table('api_clients')->where('name', 'Local Demo Site Actor')->value('token_hash'));
        $this->assertStringNotContainsString($token, (string) DB::table('api_clients')->where('name', 'Public Demo Reader')->value('token_hash'));
        $this->assertDatabaseCount('allocations', 6);
        $this->assertDatabaseCount('holds', 1);
        $this->assertDatabaseCount('blocks', 1);
        $this->assertDatabaseCount('bookings', 4);
        $this->assertDatabaseCount('trips', 4);
        $this->assertDatabaseCount('slot_offerings', 8);
        $this->assertDatabaseCount('slot_compatibility_rules', 11);
        $this->assertDatabaseHas('slot_offerings', [
            'code' => 'DEMO_REUSABLE_DRAFT',
            'status' => 'DRAFT',
            'operating_time_status' => 'DEMO_DEFAULT_UNVERIFIED',
        ]);
        $this->assertDatabaseHas('slot_offerings', [
            'code' => 'DEMO_REUSABLE_RETIRED',
            'status' => 'RETIRED',
        ]);
        $this->assertDatabaseHas('slot_offerings', [
            'kind' => 'CUSTOM_INSTANCE',
            'service_start_time' => '12:00:00',
            'service_end_time' => '18:00:00',
            'duration_minutes' => 360,
            'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
        ]);
        $this->assertDatabaseCount('cash_accounts', 1);
        $this->assertDatabaseCount('expense_categories', 2);
        $this->assertDatabaseCount('items', 1);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('cash_postings', 1);
        $purchaseId = (int) DB::table('stock_movements')->where('movement_type', 'PURCHASE')->value('id');
        $this->assertDatabaseHas('cash_postings', [
            'source_type' => 'stock_movement', 'source_id' => $purchaseId,
            'posting_kind' => 'STOCK_PURCHASE', 'direction' => 'OUTFLOW',
            'amount_minor' => 120000, 'currency' => 'THB', 'status' => 'POSTED',
        ]);
        $this->assertDatabaseHas('stock_balances', ['location_key' => 'WAREHOUSE', 'quantity' => '80.000']);
        $this->assertSame(2, DB::table('stock_balances')->where('location_type', 'BOAT')->where('quantity', '20.000')->count());
        $this->assertDatabaseCount('audit_logs', 8);
        $this->assertSame(7, (int) DB::table('organizations')->value('inventory_revision'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'slot.retired')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('object_type', 'slot_compatibility_rule')->count());
    }

    private function runDemoSiteSeeder(): void
    {
        $seeder = $this->app->make(DemoSiteSeeder::class);
        $seeder->setContainer($this->app);
        $seeder();
    }
}
