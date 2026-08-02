<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $token = getenv('BOATOPS_DEMO_TOKEN');

        if (! is_string($token) || strlen($token) < 24) {
            throw new RuntimeException('BOATOPS_DEMO_TOKEN must be set to at least 24 characters for local demo seeding.');
        }

        DB::transaction(function () use ($token): void {
            $now = now()->utc();

            DB::table('organizations')->updateOrInsert(
                ['name' => 'Fictional Andaman Charter Lab'],
                [
                    'timezone' => 'Asia/Bangkok',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            $organizationId = (int) DB::table('organizations')
                ->where('name', 'Fictional Andaman Charter Lab')
                ->value('id');

            DB::table('boats')->updateOrInsert(
                [
                    'organization_id' => $organizationId,
                    'name' => 'Demo Coral One',
                ],
                [
                    'status' => 'ACTIVE',
                    'buffer_before_minutes' => 30,
                    'buffer_after_minutes' => 30,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            DB::table('trip_templates')->updateOrInsert(
                [
                    'organization_id' => $organizationId,
                    'code' => 'DEMO-4H',
                ],
                [
                    'name' => 'Fictional Four Hour Charter',
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            DB::table('api_clients')->updateOrInsert(
                [
                    'organization_id' => $organizationId,
                    'name' => 'Local Demo API Client',
                ],
                [
                    'token_hash' => hash('sha256', $token),
                    'scopes' => json_encode(['operations.write'], JSON_THROW_ON_ERROR),
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        });
    }
}
