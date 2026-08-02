<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_idempotent_fictional_inventory_without_storing_plain_token(): void
    {
        $token = 'fictional-local-demo-token-for-test-only';
        putenv("BOATOPS_DEMO_TOKEN={$token}");
        $_ENV['BOATOPS_DEMO_TOKEN'] = $token;
        $_SERVER['BOATOPS_DEMO_TOKEN'] = $token;

        try {
            $this->seed();
            $this->seed();
        } finally {
            putenv('BOATOPS_DEMO_TOKEN');
            unset($_ENV['BOATOPS_DEMO_TOKEN'], $_SERVER['BOATOPS_DEMO_TOKEN']);
        }

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseHas('organizations', [
            'name' => 'Fictional Andaman Charter Lab',
            'timezone' => 'Asia/Bangkok',
        ]);
        $this->assertDatabaseCount('boats', 1);
        $this->assertDatabaseHas('boats', [
            'name' => 'Demo Coral One',
            'buffer_before_minutes' => 30,
            'buffer_after_minutes' => 30,
        ]);
        $this->assertDatabaseCount('trip_templates', 1);
        $this->assertDatabaseHas('trip_templates', [
            'code' => 'DEMO-4H',
            'name' => 'Fictional Four Hour Charter',
        ]);
        $this->assertDatabaseCount('api_clients', 1);
        $this->assertDatabaseHas('api_clients', [
            'name' => 'Local Demo API Client',
            'token_hash' => hash('sha256', $token),
        ]);
        $this->assertSame(
            ['operations.write'],
            json_decode((string) DB::table('api_clients')->value('scopes'), true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            $token,
            (string) DB::table('api_clients')->value('token_hash'),
        );
    }
}
