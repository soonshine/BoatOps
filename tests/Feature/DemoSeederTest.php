<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_env_example_uses_fail_closed_isolation_and_approved_read_only_state_drivers(): void
    {
        $environment = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^BOATOPS_DEMO_SITE_ISOLATED_DATASET=false\r?$/m', $environment);
        $this->assertMatchesRegularExpression('/^CACHE_STORE=file\r?$/m', $environment);
        $this->assertMatchesRegularExpression('/^SESSION_DRIVER=file\r?$/m', $environment);
        $this->assertMatchesRegularExpression('/^QUEUE_CONNECTION=sync\r?$/m', $environment);
    }

    public function test_isolated_dataset_flag_defaults_to_false(): void
    {
        $this->assertFalse(config('demo_site.isolated_dataset'));
    }

    public function test_demo_security_boolean_environment_values_fail_closed_unless_literal_true(): void
    {
        Env::enablePutenv();
        $variables = [
            'BOATOPS_DEMO_SITE_ENABLED' => 'enabled',
            'BOATOPS_DEMO_SITE_ISOLATED_DATASET' => 'isolated_dataset',
            'BOATOPS_DEMO_SITE_ALLOW_PRODUCTION_SEED' => 'allow_production_seed',
        ];
        $original = [];
        foreach (array_keys($variables) as $variable) {
            $original[$variable] = getenv($variable);
        }

        try {
            foreach ($variables as $variable => $configKey) {
                foreach (['true' => true, 'false' => false, 'no' => false, 'typo' => false] as $raw => $expected) {
                    putenv($variable.'='.$raw);
                    $demoConfig = require base_path('config/demo_site.php');
                    $this->assertSame($expected, $demoConfig[$configKey], $variable.'='.$raw.' must not enable a fail-closed gate unexpectedly.');
                }
            }
        } finally {
            foreach ($original as $variable => $value) {
                $value === false ? putenv($variable) : putenv($variable.'='.$value);
            }
        }
    }

    public function test_production_demo_seeding_requires_every_explicit_public_seed_gate(): void
    {
        $originalEnvironment = $this->app->environment();
        $originalConfig = config('demo_site');
        $originalDatabaseDefault = config('database.default');
        $originalSqliteUrl = config('database.connections.sqlite.url');
        $token = 'fictional-production-seed-gate-test-token';
        putenv('BOATOPS_DEMO_TOKEN='.$token);

        $invalidCases = [
            ['environment' => 'production', 'enabled' => false, 'mode' => 'public_read_only', 'allow' => true, 'isolated' => true, 'database' => 'sqlite', 'url' => null],
            ['environment' => 'production', 'enabled' => true, 'mode' => 'disabled', 'allow' => true, 'isolated' => true, 'database' => 'sqlite', 'url' => null],
            ['environment' => 'production', 'enabled' => true, 'mode' => 'public_read_only', 'allow' => false, 'isolated' => true, 'database' => 'sqlite', 'url' => null],
            ['environment' => 'staging', 'enabled' => true, 'mode' => 'public_read_only', 'allow' => true, 'isolated' => true, 'database' => 'sqlite', 'url' => null],
            ['environment' => 'production', 'enabled' => true, 'mode' => 'public_read_only', 'allow' => true, 'isolated' => null, 'database' => 'sqlite', 'url' => null],
            ['environment' => 'production', 'enabled' => true, 'mode' => 'public_read_only', 'allow' => true, 'isolated' => false, 'database' => 'sqlite', 'url' => null],
            ['environment' => 'production', 'enabled' => true, 'mode' => 'public_read_only', 'allow' => true, 'isolated' => true, 'database' => 'pgsql', 'url' => null],
            ['environment' => 'production', 'enabled' => true, 'mode' => 'public_read_only', 'allow' => true, 'isolated' => true, 'database' => 'sqlite', 'url' => 'pgsql://fictional.invalid/boatops'],
            ['environment' => 'production', 'enabled' => true, 'mode' => 'public_read_only', 'allow' => true, 'isolated' => true, 'database' => 'sqlite', 'url' => 'sqlite:///tmp/demo.sqlite?read%5Bdriver%5D=pgsql&write%5Bdriver%5D=pgsql'],
        ];
        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        try {
            foreach ($invalidCases as $case) {
                $queryCount = 0;
                $this->app->detectEnvironment(fn (): string => $case['environment']);
                config([
                    'demo_site.enabled' => $case['enabled'],
                    'demo_site.mode' => $case['mode'],
                    'demo_site.allow_production_seed' => $case['allow'],
                    'database.default' => $case['database'],
                    'database.connections.sqlite.url' => $case['url'],
                ]);
                if ($case['isolated'] === null) {
                    $demoConfig = (array) config('demo_site');
                    unset($demoConfig['isolated_dataset']);
                    config(['demo_site' => $demoConfig]);
                } else {
                    config(['demo_site.isolated_dataset' => $case['isolated']]);
                }

                try {
                    $this->runDemoSiteSeeder();
                    $this->fail('The fictional production seeder accepted an incomplete gate set.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('one-time production seed flag', $exception->getMessage());
                }
                $this->assertSame(0, $queryCount, 'An invalid production Demo seed gate must fail before every database query.');
            }
        } finally {
            $this->app->detectEnvironment(fn (): string => $originalEnvironment);
            config(['demo_site' => $originalConfig]);
            config(['database.default' => $originalDatabaseDefault]);
            config(['database.connections.sqlite.url' => $originalSqliteUrl]);
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
                'demo_site.isolated_dataset' => true,
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

    public function test_production_demo_seeding_does_not_modify_unrelated_organization_slot_catalog(): void
    {
        $unrelatedOrganizationId = DB::table('organizations')->insertGetId([
            'name' => 'Unrelated Fictional Organization',
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $firstSlotId = DB::table('slot_offerings')->insertGetId([
            'organization_id' => $unrelatedOrganizationId,
            'template_slot_offering_id' => null,
            'kind' => 'CUSTOM',
            'code' => 'UNRELATED_SENTINEL_AM',
            'name' => 'Unrelated Sentinel Morning',
            'status' => 'ACTIVE',
            'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
            'service_date' => null,
            'service_start_time' => '06:00:00',
            'service_end_time' => '07:00:00',
            'duration_minutes' => 60,
            'additional_buffer_before_minutes' => 0,
            'additional_buffer_after_minutes' => 0,
            'valid_from' => null,
            'valid_until' => null,
            'applies_to_all_boats' => true,
            'created_by_api_client_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondSlotId = DB::table('slot_offerings')->insertGetId([
            'organization_id' => $unrelatedOrganizationId,
            'template_slot_offering_id' => null,
            'kind' => 'CUSTOM',
            'code' => 'UNRELATED_SENTINEL_PM',
            'name' => 'Unrelated Sentinel Afternoon',
            'status' => 'ACTIVE',
            'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
            'service_date' => null,
            'service_start_time' => '16:00:00',
            'service_end_time' => '17:00:00',
            'duration_minutes' => 60,
            'additional_buffer_before_minutes' => 0,
            'additional_buffer_after_minutes' => 0,
            'valid_from' => null,
            'valid_until' => null,
            'applies_to_all_boats' => true,
            'created_by_api_client_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('slot_compatibility_rules')->insert([
            'organization_id' => $unrelatedOrganizationId,
            'first_slot_offering_id' => $firstSlotId,
            'second_slot_offering_id' => $secondSlotId,
            'pair_key' => $firstSlotId.':'.$secondSlotId,
            'policy' => 'ALLOW',
            'reason' => 'UNRELATED_SENTINEL_RULE',
            'created_by_api_client_id' => null,
            'updated_by_api_client_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $offeringsBefore = $this->organizationRows('slot_offerings', $unrelatedOrganizationId);
        $rulesBefore = $this->organizationRows('slot_compatibility_rules', $unrelatedOrganizationId);
        $originalEnvironment = $this->app->environment();
        $originalConfig = config('demo_site');
        putenv('BOATOPS_DEMO_TOKEN=fictional-production-isolation-token');

        try {
            $this->app->detectEnvironment(fn (): string => 'production');
            config([
                'demo_site.enabled' => true,
                'demo_site.mode' => 'public_read_only',
                'demo_site.allow_production_seed' => true,
                'demo_site.isolated_dataset' => true,
            ]);
            $this->runDemoSiteSeeder();
            $this->runSeeder(DatabaseSeeder::class);
        } finally {
            $this->app->detectEnvironment(fn (): string => $originalEnvironment);
            config(['demo_site' => $originalConfig]);
            putenv('BOATOPS_DEMO_TOKEN');
        }

        $this->assertSame($offeringsBefore, $this->organizationRows('slot_offerings', $unrelatedOrganizationId));
        $this->assertSame($rulesBefore, $this->organizationRows('slot_compatibility_rules', $unrelatedOrganizationId));
        $this->assertCount(2, DB::table('slot_offerings')->where('organization_id', $unrelatedOrganizationId)->get());
        $this->assertCount(1, DB::table('slot_compatibility_rules')->where('organization_id', $unrelatedOrganizationId)->get());
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

    private function organizationRows(string $table, int $organizationId): string
    {
        return json_encode(
            DB::table($table)
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    private function runDemoSiteSeeder(): void
    {
        $this->runSeeder(DemoSiteSeeder::class);
    }

    private function runSeeder(string $seederClass): void
    {
        $seeder = $this->app->make($seederClass);
        $seeder->setContainer($this->app);
        $seeder();
    }
}
