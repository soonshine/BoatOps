<?php

namespace Tests\Feature;

use Database\Seeders\OperatorDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class D1OperatorDemoModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_isolated_operator_demo_keeps_api_and_public_demo_writes_closed(): void
    {
        $environment = $this->app->environment();

        try {
            $this->app->detectEnvironment(fn () => 'production');
            $this->configureIsolatedOperatorDemo();

            $this->get('/operator/login')->assertOk();
            $this->get('/api/v1/inventory/revision')->assertNotFound();
            $this->post('/demo/fuel')->assertStatus(405)->assertHeader('Allow', 'GET');
        } finally {
            $this->app->detectEnvironment(fn () => $environment);
        }
    }

    public function test_isolated_operator_demo_fails_closed_without_sqlite(): void
    {
        $environment = $this->app->environment();

        try {
            $this->app->detectEnvironment(fn () => 'production');
            $this->configureIsolatedOperatorDemo();
            config(['database.default' => 'pgsql']);

            $this->get('/operator/login')->assertNotFound();
            $this->post('/operator/login')->assertNotFound();
        } finally {
            $this->app->detectEnvironment(fn () => $environment);
        }
    }

    public function test_fictional_operator_seeder_is_idempotent_and_login_works(): void
    {
        $environment = $this->app->environment();
        $oldEmail = getenv('BOATOPS_DEMO_OPERATOR_EMAIL');
        $oldPassword = getenv('BOATOPS_DEMO_OPERATOR_PASSWORD');

        try {
            $this->app->detectEnvironment(fn () => 'production');
            $this->configureIsolatedOperatorDemo();
            putenv('BOATOPS_DEMO_OPERATOR_EMAIL=operator-d1@example.test');
            putenv('BOATOPS_DEMO_OPERATOR_PASSWORD=fictional-d1-password-123456789');

            $organizationId = DB::table('organizations')->insertGetId([
                'name' => config('demo_site.organization_name'),
                'timezone' => 'Asia/Bangkok',
                'inventory_revision' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(OperatorDemoSeeder::class)->run();
            app(OperatorDemoSeeder::class)->run();

            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseHas('users', [
                'name' => 'Fictional Demo Operator',
                'email' => 'operator-d1@example.test',
            ]);
            $this->assertDatabaseHas('operator_memberships', [
                'organization_id' => $organizationId,
                'status' => 'ACTIVE',
                'can_calendar_read' => true,
                'can_booking_workflow' => true,
                'can_block' => true,
            ]);

            $this->post('/operator/login', [
                'email' => 'operator-d1@example.test',
                'password' => 'fictional-d1-password-123456789',
            ])->assertRedirect('/operator/calendar');
            $this->get('/operator/calendar')->assertOk();
        } finally {
            $this->restoreEnvironmentVariable('BOATOPS_DEMO_OPERATOR_EMAIL', $oldEmail);
            $this->restoreEnvironmentVariable('BOATOPS_DEMO_OPERATOR_PASSWORD', $oldPassword);
            $this->app->detectEnvironment(fn () => $environment);
        }
    }

    public function test_fictional_operator_seeder_rejects_non_reserved_email(): void
    {
        $environment = $this->app->environment();
        $oldEmail = getenv('BOATOPS_DEMO_OPERATOR_EMAIL');
        $oldPassword = getenv('BOATOPS_DEMO_OPERATOR_PASSWORD');

        try {
            $this->app->detectEnvironment(fn () => 'production');
            $this->configureIsolatedOperatorDemo();
            putenv('BOATOPS_DEMO_OPERATOR_EMAIL=operator@example.com');
            putenv('BOATOPS_DEMO_OPERATOR_PASSWORD=fictional-d1-password-123456789');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('reserved @example.test domain');
            app(OperatorDemoSeeder::class)->run();
        } finally {
            $this->restoreEnvironmentVariable('BOATOPS_DEMO_OPERATOR_EMAIL', $oldEmail);
            $this->restoreEnvironmentVariable('BOATOPS_DEMO_OPERATOR_PASSWORD', $oldPassword);
            $this->app->detectEnvironment(fn () => $environment);
        }
    }

    private function configureIsolatedOperatorDemo(): void
    {
        config([
            'demo_site.enabled' => true,
            'demo_site.mode' => 'isolated_operator_demo',
            'demo_site.isolated_dataset' => true,
            'database.default' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'cache.default' => 'file',
            'cache.limiter' => 'file',
            'session.driver' => 'file',
            'queue.default' => 'sync',
        ]);
    }

    private function restoreEnvironmentVariable(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name.'='.$value);
    }
}
