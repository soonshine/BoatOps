<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $token = getenv('BOATOPS_DEMO_TOKEN');
        if (! is_string($token) || strlen($token) < 24) {
            throw new RuntimeException('BOATOPS_DEMO_TOKEN must be set to at least 24 characters for fictional demo seeding.');
        }

        $this->call(DemoSiteSeeder::class);
    }
}
