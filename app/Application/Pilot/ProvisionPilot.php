<?php

namespace App\Application\Pilot;

use App\Application\Holds\OrganizationHoldTtlPolicy;
use App\Services\SlotCatalog\SlotCatalogService;
use App\Services\SlotCatalog\SlotCompatibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class ProvisionPilot
{
    public const OPERATOR_PASSWORD_ENV = 'BOATOPS_PILOT_OPERATOR_PASSWORD';

    public function __construct(
        private readonly SlotCatalogService $slotCatalog,
        private readonly SlotCompatibilityService $slotCompatibility,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(
        PilotProvisioningManifest $manifest,
        ?string $operatorPassword = null,
        bool $validateOnly = false,
    ): array {
        $this->assertDemoDisabled();
        $data = $manifest->data();

        if ($validateOnly) {
            $this->validateExistingState($data);

            return $this->receipt('VALIDATED', $manifest, 0, $data);
        }

        $writes = DB::transaction(function () use ($data, $operatorPassword): int {
            return $this->apply($data, $operatorPassword);
        }, 3);

        return $this->receipt($writes === 0 ? 'UNCHANGED' : 'PROVISIONED', $manifest, $writes, $data);
    }

    /** @param array<string, mixed> $data */
    private function validateExistingState(array $data): void
    {
        $organization = $this->organizationByName($data['organization']['name']);
        if ($organization !== null) {
            $this->assertOrganizationMatches($organization, $data['organization']);
            $this->validateOrganizationConfiguration((int) $organization->id, $data);
        }

        $this->validateOperatorIdentity($organization === null ? null : (int) $organization->id, $data['operator']);
    }

    /** @param array<string, mixed> $data */
    private function apply(array $data, ?string $operatorPassword): int
    {
        $writes = 0;
        $organization = $this->organizationByName($data['organization']['name'], true);

        if ($organization === null) {
            $now = now()->utc();
            $organizationId = DB::table('organizations')->insertGetId([
                'name' => $data['organization']['name'],
                'timezone' => $data['organization']['timezone'],
                'inventory_revision' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $writes++;
        } else {
            $this->assertOrganizationMatches($organization, $data['organization']);
            $organizationId = (int) $organization->id;
        }

        $boatIds = [];
        foreach ($data['boats'] as $boat) {
            $rows = DB::table('boats')
                ->where('organization_id', $organizationId)
                ->where('name', $boat['name'])
                ->lockForUpdate()
                ->get();
            if ($rows->count() > 1) {
                $this->drift('duplicate boat identity '.$boat['name']);
            }
            $existing = $rows->first();
            if ($existing !== null) {
                $this->assertBoatMatches($existing, $boat);
                $boatIds[$boat['name']] = (int) $existing->id;
                continue;
            }

            $now = now()->utc();
            $boatIds[$boat['name']] = DB::table('boats')->insertGetId([
                'organization_id' => $organizationId,
                'name' => $boat['name'],
                'status' => 'ACTIVE',
                'buffer_before_minutes' => $boat['buffer_before_minutes'],
                'buffer_after_minutes' => $boat['buffer_after_minutes'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $writes++;
        }

        foreach ($data['trip_templates'] as $template) {
            $rows = DB::table('trip_templates')
                ->where('organization_id', $organizationId)
                ->where('code', $template['code'])
                ->lockForUpdate()
                ->get();
            if ($rows->count() > 1) {
                $this->drift('duplicate trip template identity '.$template['code']);
            }
            $existing = $rows->first();
            if ($existing !== null) {
                $this->assertTripTemplateMatches($existing, $template);
                continue;
            }
            $now = now()->utc();
            DB::table('trip_templates')->insert([
                'organization_id' => $organizationId,
                'code' => $template['code'],
                'name' => $template['name'],
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $writes++;
        }

        $slotIds = [];
        foreach ($data['slots'] as $slot) {
            $rows = DB::table('slot_offerings')
                ->where('organization_id', $organizationId)
                ->where('code', $slot['identity'])
                ->lockForUpdate()
                ->get();
            if ($rows->count() > 1) {
                $this->drift('duplicate slot identity '.$slot['identity']);
            }
            $existing = $rows->first();
            $expectedBoatIds = array_map(static fn (string $name): int => $boatIds[$name], $slot['applicable_boats']);
            sort($expectedBoatIds, SORT_NUMERIC);

            if ($existing !== null) {
                $this->assertSlotMatches($organizationId, $existing, $slot, $expectedBoatIds);
                $slotIds[$slot['identity']] = (int) $existing->id;
                continue;
            }

            $slotIds[$slot['identity']] = $this->slotCatalog->createReusableOffering(
                organizationId: $organizationId,
                attributes: [
                    'code' => $slot['identity'],
                    'name' => $slot['name'],
                    'status' => 'ACTIVE',
                    'operating_time_status' => $slot['operating_time_status'],
                    'service_start_time' => $slot['service_start'],
                    'service_end_time' => $slot['service_end'],
                    'duration_minutes' => $slot['duration_minutes'],
                    'additional_buffer_before_minutes' => $slot['additional_buffer_before_minutes'],
                    'additional_buffer_after_minutes' => $slot['additional_buffer_after_minutes'],
                    'applies_to_all_boats' => false,
                ],
                boatIds: $expectedBoatIds,
            );
            $writes++;
        }

        foreach ($data['compatibility'] as $rule) {
            $firstId = $slotIds[$rule['slot_a']];
            $secondId = $slotIds[$rule['slot_b']];
            [$firstId, $secondId] = $firstId < $secondId ? [$firstId, $secondId] : [$secondId, $firstId];
            $pairKey = $firstId.':'.$secondId;
            $existing = DB::table('slot_compatibility_rules')
                ->where('organization_id', $organizationId)
                ->where('pair_key', $pairKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ((string) $existing->policy !== $rule['policy'] || $this->nullableString($existing->reason) !== $rule['reason']) {
                    $this->drift('compatibility pair '.$rule['slot_a'].'/'.$rule['slot_b']);
                }
                continue;
            }
            $this->slotCompatibility->setRule(
                organizationId: $organizationId,
                firstSlotOfferingId: $firstId,
                secondSlotOfferingId: $secondId,
                policy: $rule['policy'],
                reason: $rule['reason'],
            );
            $writes++;
        }

        $setting = DB::table('organization_settings')
            ->where('organization_id', $organizationId)
            ->where('key', OrganizationHoldTtlPolicy::KEY)
            ->lockForUpdate()
            ->first();
        $ttl = (string) $data['hold_ttl_minutes'];
        if ($setting !== null) {
            if ((string) $setting->value !== $ttl) {
                $this->drift('HOLD TTL');
            }
        } else {
            $now = now()->utc();
            DB::table('organization_settings')->insert([
                'organization_id' => $organizationId,
                'key' => OrganizationHoldTtlPolicy::KEY,
                'value' => $ttl,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $writes++;
        }

        $userRows = DB::table('users')->where('email', $data['operator']['email'])->lockForUpdate()->get();
        if ($userRows->count() > 1) {
            $this->drift('duplicate Operator email');
        }
        $user = $userRows->first();
        if ($user !== null) {
            if ((string) $user->name !== $data['operator']['name']) {
                $this->drift('Operator identity');
            }
            $userId = (int) $user->id;
        } else {
            if (! is_string($operatorPassword) || mb_strlen($operatorPassword) < 12) {
                throw new RuntimeException('MISSING_OPERATOR_SECRET: set '.self::OPERATOR_PASSWORD_ENV.' to at least 12 characters for first provisioning.');
            }
            $now = now()->utc();
            $userId = DB::table('users')->insertGetId([
                'name' => $data['operator']['name'],
                'email' => $data['operator']['email'],
                'password' => Hash::make($operatorPassword),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $writes++;
        }

        $membership = DB::table('operator_memberships')->where('user_id', $userId)->lockForUpdate()->first();
        if ($membership !== null) {
            $this->assertMembershipMatches($membership, $organizationId, $data['operator']);
        } else {
            $permissions = $data['operator']['required_permissions'];
            $now = now()->utc();
            DB::table('operator_memberships')->insert([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'status' => 'ACTIVE',
                'can_calendar_read' => $permissions['can_calendar_read'],
                'can_booking_workflow' => $permissions['can_booking_workflow'],
                'can_block' => $permissions['can_block'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $writes++;
        }

        return $writes;
    }

    /** @param array<string, mixed> $data */
    private function validateOrganizationConfiguration(int $organizationId, array $data): void
    {
        $boatIds = [];
        foreach ($data['boats'] as $boat) {
            $rows = DB::table('boats')->where('organization_id', $organizationId)->where('name', $boat['name'])->get();
            if ($rows->count() > 1) {
                $this->drift('duplicate boat identity '.$boat['name']);
            }
            $existing = $rows->first();
            if ($existing !== null) {
                $this->assertBoatMatches($existing, $boat);
                $boatIds[$boat['name']] = (int) $existing->id;
            }
        }

        foreach ($data['trip_templates'] as $template) {
            $rows = DB::table('trip_templates')->where('organization_id', $organizationId)->where('code', $template['code'])->get();
            if ($rows->count() > 1) {
                $this->drift('duplicate trip template identity '.$template['code']);
            }
            if (($existing = $rows->first()) !== null) {
                $this->assertTripTemplateMatches($existing, $template);
            }
        }

        $slotIds = [];
        foreach ($data['slots'] as $slot) {
            $rows = DB::table('slot_offerings')->where('organization_id', $organizationId)->where('code', $slot['identity'])->get();
            if ($rows->count() > 1) {
                $this->drift('duplicate slot identity '.$slot['identity']);
            }
            $existing = $rows->first();
            if ($existing === null) {
                continue;
            }
            $expectedBoatIds = [];
            $canCompareBoats = true;
            foreach ($slot['applicable_boats'] as $boatName) {
                if (! isset($boatIds[$boatName])) {
                    $canCompareBoats = false;
                    break;
                }
                $expectedBoatIds[] = $boatIds[$boatName];
            }
            if ($canCompareBoats) {
                sort($expectedBoatIds, SORT_NUMERIC);
                $this->assertSlotMatches($organizationId, $existing, $slot, $expectedBoatIds);
            } else {
                $this->assertSlotFieldsMatch($existing, $slot);
            }
            $slotIds[$slot['identity']] = (int) $existing->id;
        }

        foreach ($data['compatibility'] as $rule) {
            if (! isset($slotIds[$rule['slot_a']], $slotIds[$rule['slot_b']])) {
                continue;
            }
            $ids = [$slotIds[$rule['slot_a']], $slotIds[$rule['slot_b']]];
            sort($ids, SORT_NUMERIC);
            $existing = DB::table('slot_compatibility_rules')
                ->where('organization_id', $organizationId)
                ->where('pair_key', $ids[0].':'.$ids[1])
                ->first();
            if ($existing !== null && ((string) $existing->policy !== $rule['policy'] || $this->nullableString($existing->reason) !== $rule['reason'])) {
                $this->drift('compatibility pair '.$rule['slot_a'].'/'.$rule['slot_b']);
            }
        }

        $setting = DB::table('organization_settings')
            ->where('organization_id', $organizationId)
            ->where('key', OrganizationHoldTtlPolicy::KEY)
            ->first();
        if ($setting !== null && (string) $setting->value !== (string) $data['hold_ttl_minutes']) {
            $this->drift('HOLD TTL');
        }
    }

    /** @param array<string, mixed> $operator */
    private function validateOperatorIdentity(?int $organizationId, array $operator): void
    {
        $rows = DB::table('users')->where('email', $operator['email'])->get();
        if ($rows->count() > 1) {
            $this->drift('duplicate Operator email');
        }
        $user = $rows->first();
        if ($user === null) {
            return;
        }
        if ((string) $user->name !== $operator['name']) {
            $this->drift('Operator identity');
        }
        $membership = DB::table('operator_memberships')->where('user_id', $user->id)->first();
        if ($membership === null) {
            return;
        }
        if ($organizationId === null) {
            $this->drift('Operator is already attached to another organization configuration');
        }
        $this->assertMembershipMatches($membership, $organizationId, $operator);
    }

    private function organizationByName(string $name, bool $lock = false): ?object
    {
        $query = DB::table('organizations')->where('name', $name);
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->get();
        if ($rows->count() > 1) {
            $this->drift('duplicate organization identity '.$name);
        }

        return $rows->first();
    }

    /** @param array<string, mixed> $expected */
    private function assertOrganizationMatches(object $existing, array $expected): void
    {
        if ((string) $existing->timezone !== $expected['timezone']) {
            $this->drift('organization timezone');
        }
    }

    /** @param array<string, mixed> $expected */
    private function assertBoatMatches(object $existing, array $expected): void
    {
        if (
            (string) $existing->status !== 'ACTIVE'
            || (int) $existing->buffer_before_minutes !== $expected['buffer_before_minutes']
            || (int) $existing->buffer_after_minutes !== $expected['buffer_after_minutes']
        ) {
            $this->drift('boat '.$expected['name']);
        }
    }

    /** @param array<string, mixed> $expected */
    private function assertTripTemplateMatches(object $existing, array $expected): void
    {
        if ((string) $existing->name !== $expected['name'] || (string) $existing->status !== 'ACTIVE') {
            $this->drift('trip template '.$expected['code']);
        }
    }

    /** @param array<string, mixed> $expected @param list<int> $expectedBoatIds */
    private function assertSlotMatches(int $organizationId, object $existing, array $expected, array $expectedBoatIds): void
    {
        $this->assertSlotFieldsMatch($existing, $expected);
        $actualBoatIds = DB::table('slot_offering_boats')
            ->where('organization_id', $organizationId)
            ->where('slot_offering_id', $existing->id)
            ->pluck('boat_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        sort($actualBoatIds, SORT_NUMERIC);
        if ($actualBoatIds !== $expectedBoatIds) {
            $this->drift('slot applicable boats '.$expected['identity']);
        }
    }

    /** @param array<string, mixed> $expected */
    private function assertSlotFieldsMatch(object $existing, array $expected): void
    {
        if (
            (string) $existing->kind !== 'CUSTOM_TEMPLATE'
            || (string) $existing->name !== $expected['name']
            || (string) $existing->status !== 'ACTIVE'
            || (string) $existing->operating_time_status !== $expected['operating_time_status']
            || $this->timeString($existing->service_start_time) !== $expected['service_start']
            || $this->timeString($existing->service_end_time) !== $expected['service_end']
            || (int) $existing->duration_minutes !== $expected['duration_minutes']
            || (int) $existing->additional_buffer_before_minutes !== $expected['additional_buffer_before_minutes']
            || (int) $existing->additional_buffer_after_minutes !== $expected['additional_buffer_after_minutes']
            || (bool) $existing->applies_to_all_boats !== false
        ) {
            $this->drift('slot '.$expected['identity']);
        }
    }

    /** @param array<string, mixed> $operator */
    private function assertMembershipMatches(object $membership, int $organizationId, array $operator): void
    {
        $permissions = $operator['required_permissions'];
        if (
            (int) $membership->organization_id !== $organizationId
            || (string) $membership->status !== 'ACTIVE'
            || (bool) $membership->can_calendar_read !== $permissions['can_calendar_read']
            || (bool) $membership->can_booking_workflow !== $permissions['can_booking_workflow']
            || (bool) $membership->can_block !== $permissions['can_block']
        ) {
            $this->drift('Operator membership or permissions');
        }
    }

    private function assertDemoDisabled(): void
    {
        if (
            (bool) config('demo_site.enabled', false)
            || (string) config('demo_site.mode', 'disabled') !== 'disabled'
            || (bool) config('demo_site.isolated_dataset', false)
            || (bool) config('demo_site.allow_production_seed', false)
        ) {
            throw new RuntimeException('DEMO_ENABLED: Pilot provisioning requires all Demo flags disabled.');
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function receipt(string $status, PilotProvisioningManifest $manifest, int $writes, array $data): array
    {
        return [
            'status' => $status,
            'manifest_version' => $data['version'],
            'manifest_sha256' => $manifest->sha256(),
            'writes' => $writes,
            'counts' => [
                'organizations' => 1,
                'boats' => count($data['boats']),
                'trip_templates' => count($data['trip_templates']),
                'slots' => count($data['slots']),
                'compatibility_rules' => count($data['compatibility']),
                'operators' => 1,
            ],
            'service_boundary_entries' => count($data['pilot_service_boundary']['included']) + count($data['pilot_service_boundary']['excluded']),
            'product_to_slot_sop_entries' => count($data['product_to_slot_sop']),
        ];
    }

    private function timeString(mixed $value): string
    {
        $value = (string) $value;

        return strlen($value) >= 8 ? substr($value, 0, 8) : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function drift(string $field): never
    {
        throw new RuntimeException('CONFIGURATION_DRIFT: '.$field.' differs from the manifest.');
    }
}
