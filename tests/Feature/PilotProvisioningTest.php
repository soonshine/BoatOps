<?php

namespace Tests\Feature;

use App\Models\User;
use App\Pilot\PilotManifest;
use App\Pilot\PilotProvisioningException;
use App\Pilot\PilotProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PilotProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_manifest_creates_once_and_exact_rerun_is_unchanged(): void
    {
        $service = app(PilotProvisioningService::class);
        $manifest = PilotManifest::fromArray($this->manifest());

        $this->assertSame('CREATED', $service->provision($manifest, 'synthetic-password'));
        $timestamps = [
            'organization' => DB::table('organizations')->value('updated_at'),
            'boat' => DB::table('boats')->value('updated_at'),
            'template' => DB::table('trip_templates')->value('updated_at'),
            'slot' => DB::table('slot_offerings')->value('updated_at'),
            'setting' => DB::table('organization_settings')->value('updated_at'),
            'user' => DB::table('users')->value('updated_at'),
            'membership' => DB::table('operator_memberships')->value('updated_at'),
        ];
        $counts = $this->provisionedCounts();

        $this->assertSame('UNCHANGED', $service->provision($manifest, 'different-password-is-ignored'));
        $this->assertSame($counts, $this->provisionedCounts());
        $this->assertSame($timestamps, [
            'organization' => DB::table('organizations')->value('updated_at'),
            'boat' => DB::table('boats')->value('updated_at'),
            'template' => DB::table('trip_templates')->value('updated_at'),
            'slot' => DB::table('slot_offerings')->value('updated_at'),
            'setting' => DB::table('organization_settings')->value('updated_at'),
            'user' => DB::table('users')->value('updated_at'),
            'membership' => DB::table('operator_memberships')->value('updated_at'),
        ]);
    }

    public function test_governed_value_drift_fails_closed_without_writes(): void
    {
        $service = app(PilotProvisioningService::class);
        $base = $this->manifest();
        $this->assertSame('CREATED', $service->provision(PilotManifest::fromArray($base), 'synthetic-password'));
        $counts = $this->provisionedCounts();

        $drifts = [];
        $boatDrift = $base;
        $boatDrift['boats'][0]['buffer_before_minutes'] = 45;
        $drifts['boat buffer'] = $boatDrift;
        $slotDrift = $base;
        $slotDrift['slots'][0]['service_end_time'] = '13:00:00';
        $slotDrift['slots'][0]['duration_minutes'] = 300;
        $drifts['slot time'] = $slotDrift;
        $ttlDrift = $base;
        $ttlDrift['hold_ttl_minutes'] = 45;
        $drifts['HOLD TTL'] = $ttlDrift;
        $timezoneDrift = $base;
        $timezoneDrift['organization']['timezone'] = 'UTC';
        $drifts['organization timezone'] = $timezoneDrift;
        $membershipDrift = $base;
        $membershipDrift['operator']['permissions']['can_block'] = false;
        $drifts['operator membership'] = $membershipDrift;

        foreach ($drifts as $label => $drift) {
            try {
                $service->provision(PilotManifest::fromArray($drift), 'synthetic-password');
                $this->fail("Expected {$label} drift to fail.");
            } catch (PilotProvisioningException $exception) {
                $this->assertSame('CONFIGURATION_DRIFT', $exception->errorCode, $label);
            }

            $this->assertSame($counts, $this->provisionedCounts(), $label);
        }
    }

    public function test_invalid_reference_and_cross_midnight_slot_fail_before_writes(): void
    {
        $invalidReference = $this->manifest();
        $invalidReference['slots'][0]['applicable_boats'] = ['Unknown Vessel'];
        $this->assertInvalidManifest($invalidReference);
        $this->assertSame([], $this->provisionedCounts());

        $crossMidnight = $this->manifest();
        $crossMidnight['slots'][0]['service_start_time'] = '23:00:00';
        $crossMidnight['slots'][0]['service_end_time'] = '03:00:00';
        $this->assertInvalidManifest($crossMidnight);
        $this->assertSame([], $this->provisionedCounts());
    }

    public function test_late_configuration_drift_rolls_back_every_partial_write(): void
    {
        User::create([
            'name' => 'Different Existing Operator',
            'email' => 'synthetic.operator@example.test',
            'password' => 'existing-password',
        ]);

        try {
            app(PilotProvisioningService::class)->provision(
                PilotManifest::fromArray($this->manifest()),
                'synthetic-password',
            );
            $this->fail('Expected late operator drift to fail.');
        } catch (PilotProvisioningException $exception) {
            $this->assertSame('CONFIGURATION_DRIFT', $exception->errorCode);
        }

        $this->assertSame(1, DB::table('users')->count());
        foreach (['organizations', 'boats', 'trip_templates', 'slot_offerings', 'slot_offering_boats', 'organization_settings', 'operator_memberships', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_same_service_date_compatibility_is_created_and_rerun_is_unchanged(): void
    {
        $manifest = $this->manifest();
        $manifest['slots'][] = [
            'code' => 'SYNTH_PM_4H',
            'name' => 'Synthetic Afternoon Four Hours',
            'service_start_time' => '13:00:00',
            'service_end_time' => '17:00:00',
            'duration_minutes' => 240,
            'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
            'applicable_boats' => ['Synthetic Vessel 01'],
        ];
        $manifest['compatibility'][] = [
            'first_slot_code' => 'SYNTH_AM_4H',
            'second_slot_code' => 'SYNTH_PM_4H',
            'policy' => 'ALLOW',
            'reason' => 'SYNTHETIC_SAME_SERVICE_DATE_VALIDATION',
        ];
        $parsed = PilotManifest::fromArray($manifest);
        $service = app(PilotProvisioningService::class);

        $this->assertSame('CREATED', $service->provision($parsed, 'synthetic-password'));
        $this->assertDatabaseHas('slot_compatibility_rules', [
            'policy' => 'ALLOW',
            'reason' => 'SYNTHETIC_SAME_SERVICE_DATE_VALIDATION',
        ]);
        $this->assertSame('UNCHANGED', $service->provision($parsed, 'synthetic-password'));
        $this->assertSame(1, DB::table('slot_compatibility_rules')->count());
    }

    public function test_artisan_command_provisions_the_example_manifest(): void
    {
        putenv('BOATOPS_PILOT_OPERATOR_PASSWORD=synthetic-password');

        try {
            $this->artisan('pilot:provision', [
                'manifest' => base_path('examples/pilot/synthetic-pilot-v1.json'),
                '--validate' => true,
            ])->expectsOutputToContain('CREATED')->assertSuccessful();
            $this->artisan('pilot:provision', [
                'manifest' => base_path('examples/pilot/synthetic-pilot-v1.json'),
                '--validate' => true,
            ])->expectsOutputToContain('UNCHANGED')->assertSuccessful();
        } finally {
            putenv('BOATOPS_PILOT_OPERATOR_PASSWORD');
        }
    }

    /** @param  array<string, mixed>  $manifest */
    private function assertInvalidManifest(array $manifest): void
    {
        try {
            PilotManifest::fromArray($manifest);
            $this->fail('Expected manifest validation to fail.');
        } catch (PilotProvisioningException $exception) {
            $this->assertSame('INVALID_MANIFEST', $exception->errorCode);
        }
    }

    /** @return array<string, int> */
    private function provisionedCounts(): array
    {
        $counts = [];

        foreach (['organizations', 'boats', 'trip_templates', 'slot_offerings', 'slot_offering_boats', 'slot_compatibility_rules', 'organization_settings', 'users', 'operator_memberships', 'audit_logs'] as $table) {
            $count = DB::table($table)->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return [
            'version' => 1,
            'organization' => [
                'name' => 'Synthetic BoatOps Pilot',
                'timezone' => 'Asia/Bangkok',
            ],
            'boats' => [[
                'name' => 'Synthetic Vessel 01',
                'buffer_before_minutes' => 30,
                'buffer_after_minutes' => 30,
            ]],
            'trip_templates' => [[
                'code' => 'SYNTH-4H',
                'name' => 'Synthetic Four Hour Charter',
            ]],
            'slots' => [[
                'code' => 'SYNTH_AM_4H',
                'name' => 'Synthetic Morning Four Hours',
                'service_start_time' => '08:00:00',
                'service_end_time' => '12:00:00',
                'duration_minutes' => 240,
                'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
                'applicable_boats' => ['Synthetic Vessel 01'],
            ]],
            'compatibility' => [],
            'hold_ttl_minutes' => 30,
            'operator' => [
                'name' => 'Synthetic Operator',
                'email' => 'synthetic.operator@example.test',
                'permissions' => [
                    'can_calendar_read' => true,
                    'can_booking_workflow' => true,
                    'can_block' => true,
                ],
            ],
        ];
    }
}
