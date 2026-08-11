<?php

namespace Tests\Feature;

use App\Application\Pilot\PilotProvisioningManifest;
use App\Application\Pilot\ProvisionPilot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class PilotProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_only_checks_manifest_without_writes_or_operator_secret(): void
    {
        $receipt = app(ProvisionPilot::class)->execute($this->manifest(), validateOnly: true);

        $this->assertSame('VALIDATED', $receipt['status']);
        $this->assertSame(0, $receipt['writes']);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_provision_is_transactional_secret_injected_and_exact_rerun_is_unchanged(): void
    {
        $service = app(ProvisionPilot::class);
        $manifest = $this->manifest();

        $receipt = $service->execute($manifest, 'fictional-secret-12345');
        $this->assertSame('PROVISIONED', $receipt['status']);
        $this->assertGreaterThan(0, $receipt['writes']);
        $this->assertSame($manifest->sha256(), $receipt['manifest_sha256']);
        $this->assertStringNotContainsString('synthetic.operator@example.invalid', json_encode($receipt, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('fictional-secret-12345', json_encode($receipt, JSON_THROW_ON_ERROR));

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('boats', 1);
        $this->assertDatabaseCount('trip_templates', 1);
        $this->assertDatabaseCount('slot_offerings', 2);
        $this->assertDatabaseCount('slot_offering_boats', 2);
        $this->assertDatabaseCount('slot_compatibility_rules', 1);
        $this->assertDatabaseCount('organization_settings', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('operator_memberships', 1);

        $user = DB::table('users')->where('email', 'synthetic.operator@example.invalid')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('fictional-secret-12345', $user->password));
        $this->assertDatabaseHas('operator_memberships', [
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'can_calendar_read' => true,
            'can_booking_workflow' => true,
            'can_block' => true,
        ]);

        $second = $service->execute($manifest, 'different-secret-is-ignored');
        $this->assertSame('UNCHANGED', $second['status']);
        $this->assertSame(0, $second['writes']);
        $this->assertTrue(Hash::check('fictional-secret-12345', DB::table('users')->where('id', $user->id)->value('password')));
    }

    public function test_configuration_drift_fails_closed_and_rolls_back_partial_writes(): void
    {
        $data = $this->manifestData();
        $now = now()->utc();
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => $data['organization']['name'],
            'timezone' => $data['organization']['timezone'],
            'inventory_revision' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('trip_templates')->insert([
            'organization_id' => $organizationId,
            'code' => 'SYNTH_CHARTER',
            'name' => 'Drifted Name',
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            app(ProvisionPilot::class)->execute($this->manifest(), 'fictional-secret-12345');
            $this->fail('Expected CONFIGURATION_DRIFT.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('CONFIGURATION_DRIFT:', $e->getMessage());
        }

        $this->assertDatabaseCount('boats', 0);
        $this->assertDatabaseCount('slot_offerings', 0);
        $this->assertDatabaseHas('trip_templates', ['name' => 'Drifted Name']);
    }

    public function test_first_provision_requires_separate_operator_secret(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MISSING_OPERATOR_SECRET:');

        app(ProvisionPilot::class)->execute($this->manifest());
    }

    public function test_manifest_rejects_secrets_unknown_references_and_cross_midnight_slots(): void
    {
        $withSecret = $this->manifestData();
        $withSecret['operator']['password'] = 'forbidden';
        $this->assertManifestInvalid($withSecret, 'secret field');

        $unknownBoat = $this->manifestData();
        $unknownBoat['slots'][0]['applicable_boats'] = ['Unknown Vessel'];
        $this->assertManifestInvalid($unknownBoat, 'unknown boat');

        $crossMidnight = $this->manifestData();
        $crossMidnight['slots'][0]['service_start'] = '23:30';
        $crossMidnight['slots'][0]['service_end'] = '00:30';
        $this->assertManifestInvalid($crossMidnight, 'crosses midnight');
    }

    public function test_demo_flags_fail_closed(): void
    {
        config()->set('demo_site.enabled', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DEMO_ENABLED:');
        app(ProvisionPilot::class)->execute($this->manifest(), 'fictional-secret-12345');
    }

    public function test_artisan_command_validate_and_apply(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'boatops-pilot-');
        $this->assertNotFalse($path);
        file_put_contents($path, json_encode($this->manifestData(), JSON_THROW_ON_ERROR));

        try {
            $this->artisan('pilot:provision', ['manifest' => $path, '--validate' => true])
                ->expectsOutputToContain('"status": "VALIDATED"')
                ->assertExitCode(0);
            $this->assertDatabaseCount('organizations', 0);

            putenv(ProvisionPilot::OPERATOR_PASSWORD_ENV.'=fictional-command-secret');
            $this->artisan('pilot:provision', ['manifest' => $path])
                ->expectsOutputToContain('"status": "PROVISIONED"')
                ->assertExitCode(0);
            $this->assertDatabaseCount('organizations', 1);
        } finally {
            putenv(ProvisionPilot::OPERATOR_PASSWORD_ENV);
            @unlink($path);
        }
    }

    private function manifest(): PilotProvisioningManifest
    {
        return PilotProvisioningManifest::fromArray($this->manifestData());
    }

    /** @return array<string, mixed> */
    private function manifestData(): array
    {
        return [
            'version' => 'v1',
            'organization' => [
                'name' => 'Synthetic BoatOps Pilot Lab',
                'timezone' => 'Asia/Bangkok',
            ],
            'boats' => [[
                'name' => 'Synthetic Vessel One',
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'safe_max_party_size_or_sop_limit' => 'Synthetic smoke only',
            ]],
            'trip_templates' => [[
                'code' => 'SYNTH_CHARTER',
                'name' => 'Synthetic Private Charter',
            ]],
            'slots' => [
                [
                    'identity' => 'SYNTH_SMOKE_AM',
                    'name' => 'Synthetic Smoke AM',
                    'service_start' => '08:00',
                    'service_end' => '09:00',
                    'additional_buffer_before_minutes' => 0,
                    'additional_buffer_after_minutes' => 0,
                    'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
                    'applicable_boats' => ['Synthetic Vessel One'],
                ],
                [
                    'identity' => 'SYNTH_BLOCK_PM',
                    'name' => 'Synthetic Block PM',
                    'service_start' => '20:00',
                    'service_end' => '21:00',
                    'additional_buffer_before_minutes' => 0,
                    'additional_buffer_after_minutes' => 0,
                    'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
                    'applicable_boats' => ['Synthetic Vessel One'],
                ],
            ],
            'compatibility' => [[
                'slot_a' => 'SYNTH_SMOKE_AM',
                'slot_b' => 'SYNTH_BLOCK_PM',
                'policy' => 'ALLOW',
                'reason' => 'Fictional non-overlapping same-day smoke scenario',
            ]],
            'hold_ttl_minutes' => 30,
            'operator' => [
                'name' => 'Synthetic Pilot Operator',
                'email' => 'synthetic.operator@example.invalid',
                'organization_membership' => 'ACTIVE',
                'required_permissions' => [
                    'can_calendar_read' => true,
                    'can_booking_workflow' => true,
                    'can_block' => true,
                ],
            ],
            'pilot_service_boundary' => [
                'included' => ['Synthetic whole-vessel workflow'],
                'excluded' => ['Real customer data'],
            ],
            'product_to_slot_sop' => [[
                'product' => 'Synthetic Private Charter',
                'approved_slots' => ['SYNTH_SMOKE_AM', 'SYNTH_BLOCK_PM'],
            ]],
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertManifestInvalid(array $data, string $messageFragment): void
    {
        try {
            PilotProvisioningManifest::fromArray($data);
            $this->fail('Expected MANIFEST_INVALID.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('MANIFEST_INVALID:', $e->getMessage());
            $this->assertStringContainsString($messageFragment, $e->getMessage());
        }
    }
}
