<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SlotCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedOrganizations(DB::table('organizations')->orderBy('id')->pluck('id'));
    }

    public function runForOrganization(int $organizationId): void
    {
        $this->seedOrganizations([$organizationId]);
    }

    private function seedOrganizations(iterable $organizationIds): void
    {
        // These clock times are demonstration defaults only. They are not frozen
        // Ayany, Plan A, or Plan B operating rules.
        $presets = [
            [
                'code' => 'FULL_DAY_8H',
                'name' => 'Full Day 8 Hours',
                'service_start_time' => '09:00:00',
                'service_end_time' => '17:00:00',
                'duration_minutes' => 480,
            ],
            [
                'code' => 'FULL_DAY_6H',
                'name' => 'Full Day 6 Hours',
                'service_start_time' => '09:00:00',
                'service_end_time' => '15:00:00',
                'duration_minutes' => 360,
            ],
            [
                'code' => 'AM_4H',
                'name' => 'Morning 4 Hours',
                'service_start_time' => '08:00:00',
                'service_end_time' => '12:00:00',
                'duration_minutes' => 240,
            ],
            [
                'code' => 'PM_4H',
                'name' => 'Afternoon 4 Hours',
                'service_start_time' => '13:00:00',
                'service_end_time' => '17:00:00',
                'duration_minutes' => 240,
            ],
            [
                'code' => 'PM_2_5H',
                'name' => 'Afternoon 2.5 Hours',
                'service_start_time' => '14:30:00',
                'service_end_time' => '17:00:00',
                'duration_minutes' => 150,
            ],
        ];

        DB::transaction(function () use ($organizationIds, $presets): void {
            foreach ($organizationIds as $organizationId) {
                $slotIds = [];
                $now = now()->utc();

                foreach ($presets as $preset) {
                    $existingId = DB::table('slot_offerings')
                        ->where('organization_id', $organizationId)
                        ->where('code', $preset['code'])
                        ->value('id');

                    if ($existingId === null) {
                        $existingId = DB::table('slot_offerings')->insertGetId([
                            'organization_id' => $organizationId,
                            'template_slot_offering_id' => null,
                            'kind' => 'PRESET',
                            'code' => $preset['code'],
                            'name' => $preset['name'],
                            'status' => 'ACTIVE',
                            'operating_time_status' => 'DEMO_DEFAULT_UNVERIFIED',
                            'service_date' => null,
                            'service_start_time' => $preset['service_start_time'],
                            'service_end_time' => $preset['service_end_time'],
                            'duration_minutes' => $preset['duration_minutes'],
                            'additional_buffer_before_minutes' => 0,
                            'additional_buffer_after_minutes' => 0,
                            'valid_from' => null,
                            'valid_until' => null,
                            'applies_to_all_boats' => true,
                            'created_by_api_client_id' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    $slotIds[] = (int) $existingId;
                }

                for ($firstIndex = 0; $firstIndex < count($slotIds); $firstIndex++) {
                    for ($secondIndex = $firstIndex + 1; $secondIndex < count($slotIds); $secondIndex++) {
                        $firstId = min($slotIds[$firstIndex], $slotIds[$secondIndex]);
                        $secondId = max($slotIds[$firstIndex], $slotIds[$secondIndex]);
                        $pairKey = $firstId.':'.$secondId;

                        if (DB::table('slot_compatibility_rules')
                            ->where('organization_id', $organizationId)
                            ->where('pair_key', $pairKey)
                            ->exists()) {
                            continue;
                        }

                        DB::table('slot_compatibility_rules')->insert([
                            'organization_id' => $organizationId,
                            'first_slot_offering_id' => $firstId,
                            'second_slot_offering_id' => $secondId,
                            'pair_key' => $pairKey,
                            'policy' => 'DENY',
                            'reason' => 'SAFE_DEFAULT_PRESET_MATRIX',
                            'created_by_api_client_id' => null,
                            'updated_by_api_client_id' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        }, 3);
    }
}
