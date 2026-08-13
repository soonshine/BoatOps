<?php

namespace App\Pilot;

use App\Application\Holds\OrganizationHoldTtlPolicy;
use App\Models\User;
use App\Services\SlotCatalog\SlotCatalogService;
use App\Services\SlotCatalog\SlotCompatibilityService;
use Illuminate\Support\Facades\DB;

final class PilotProvisioningService
{
    public function __construct(
        private readonly SlotCatalogService $slotCatalog,
        private readonly SlotCompatibilityService $slotCompatibility,
    ) {}

    /** @return 'CREATED'|'UNCHANGED' */
    public function provision(PilotManifest $manifest, ?string $operatorPassword): string
    {
        return DB::transaction(function () use ($manifest, $operatorPassword): string {
            $created = false;
            $organizationId = $this->organization($manifest, $created);
            $boatIds = $this->boats($organizationId, $manifest, $created);
            $this->tripTemplates($organizationId, $manifest, $created);
            $slotIds = $this->slots($organizationId, $manifest, $boatIds, $created);
            $this->compatibility($organizationId, $manifest, $slotIds, $created);
            $this->holdTtl($organizationId, $manifest, $created);
            $userId = $this->operator($manifest, $operatorPassword, $created);
            $this->membership($organizationId, $userId, $manifest, $created);

            return $created ? 'CREATED' : 'UNCHANGED';
        }, 3);
    }

    private function organization(PilotManifest $manifest, bool &$created): int
    {
        $rows = DB::table('organizations')
            ->where('name', $manifest->organizationName)
            ->lockForUpdate()
            ->get();

        if ($rows->count() > 1) {
            $this->drift("organization identity is ambiguous: {$manifest->organizationName}");
        }

        $organization = $rows->first();

        if ($organization === null) {
            $created = true;

            return (int) DB::table('organizations')->insertGetId([
                'name' => $manifest->organizationName,
                'timezone' => $manifest->organizationTimezone,
                'inventory_revision' => 0,
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);
        }

        if ((string) $organization->timezone !== $manifest->organizationTimezone) {
            $this->drift("organization timezone differs for {$manifest->organizationName}");
        }

        return (int) $organization->id;
    }

    /** @return array<string, int> */
    private function boats(int $organizationId, PilotManifest $manifest, bool &$created): array
    {
        $ids = [];

        foreach ($manifest->boats as $boat) {
            $rows = DB::table('boats')
                ->where('organization_id', $organizationId)
                ->where('name', $boat['name'])
                ->lockForUpdate()
                ->get();

            if ($rows->count() > 1) {
                $this->drift("boat identity is ambiguous: {$boat['name']}");
            }

            $existing = $rows->first();

            if ($existing === null) {
                $created = true;
                $ids[$boat['name']] = (int) DB::table('boats')->insertGetId([
                    'organization_id' => $organizationId,
                    'name' => $boat['name'],
                    'status' => 'ACTIVE',
                    'buffer_before_minutes' => $boat['buffer_before_minutes'],
                    'buffer_after_minutes' => $boat['buffer_after_minutes'],
                    'created_at' => now()->utc(),
                    'updated_at' => now()->utc(),
                ]);

                continue;
            }

            if (
                (string) $existing->status !== 'ACTIVE'
                || (int) $existing->buffer_before_minutes !== $boat['buffer_before_minutes']
                || (int) $existing->buffer_after_minutes !== $boat['buffer_after_minutes']
            ) {
                $this->drift("boat governed values differ: {$boat['name']}");
            }

            $ids[$boat['name']] = (int) $existing->id;
        }

        return $ids;
    }

    private function tripTemplates(int $organizationId, PilotManifest $manifest, bool &$created): void
    {
        foreach ($manifest->tripTemplates as $template) {
            $existing = DB::table('trip_templates')
                ->where('organization_id', $organizationId)
                ->where('code', $template['code'])
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $created = true;
                DB::table('trip_templates')->insert([
                    'organization_id' => $organizationId,
                    'code' => $template['code'],
                    'name' => $template['name'],
                    'status' => 'ACTIVE',
                    'created_at' => now()->utc(),
                    'updated_at' => now()->utc(),
                ]);

                continue;
            }

            if ((string) $existing->name !== $template['name'] || (string) $existing->status !== 'ACTIVE') {
                $this->drift("trip template governed values differ: {$template['code']}");
            }
        }
    }

    /**
     * @param  array<string, int>  $boatIds
     * @return array<string, int>
     */
    private function slots(int $organizationId, PilotManifest $manifest, array $boatIds, bool &$created): array
    {
        $ids = [];

        foreach ($manifest->slots as $slot) {
            $expectedBoatIds = array_map(static fn (string $name): int => $boatIds[$name], $slot['applicable_boats']);
            sort($expectedBoatIds);
            $existing = DB::table('slot_offerings')
                ->where('organization_id', $organizationId)
                ->where('code', $slot['code'])
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $created = true;
                $ids[$slot['code']] = $this->slotCatalog->createReusableOffering(
                    $organizationId,
                    [
                        'code' => $slot['code'],
                        'name' => $slot['name'],
                        'status' => 'ACTIVE',
                        'operating_time_status' => $slot['operating_time_status'],
                        'service_start_time' => $slot['service_start_time'],
                        'service_end_time' => $slot['service_end_time'],
                        'duration_minutes' => $slot['duration_minutes'],
                        'additional_buffer_before_minutes' => 0,
                        'additional_buffer_after_minutes' => 0,
                        'applies_to_all_boats' => false,
                    ],
                    $expectedBoatIds,
                );

                continue;
            }

            $actualBoatIds = DB::table('slot_offering_boats')
                ->where('organization_id', $organizationId)
                ->where('slot_offering_id', $existing->id)
                ->orderBy('boat_id')
                ->pluck('boat_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if (
                (string) $existing->kind !== 'CUSTOM_TEMPLATE'
                || (string) $existing->name !== $slot['name']
                || (string) $existing->status !== 'ACTIVE'
                || (string) $existing->operating_time_status !== $slot['operating_time_status']
                || $this->time((string) $existing->service_start_time) !== $slot['service_start_time']
                || $this->time((string) $existing->service_end_time) !== $slot['service_end_time']
                || (int) $existing->duration_minutes !== $slot['duration_minutes']
                || (int) $existing->additional_buffer_before_minutes !== 0
                || (int) $existing->additional_buffer_after_minutes !== 0
                || (bool) $existing->applies_to_all_boats
                || $existing->service_date !== null
                || $existing->valid_from !== null
                || $existing->valid_until !== null
                || $actualBoatIds !== $expectedBoatIds
            ) {
                $this->drift("slot governed values differ: {$slot['code']}");
            }

            $ids[$slot['code']] = (int) $existing->id;
        }

        return $ids;
    }

    /** @param  array<string, int>  $slotIds */
    private function compatibility(int $organizationId, PilotManifest $manifest, array $slotIds, bool &$created): void
    {
        foreach ($manifest->compatibility as $rule) {
            $firstId = $slotIds[$rule['first_slot_code']];
            $secondId = $slotIds[$rule['second_slot_code']];
            $pairIds = [$firstId, $secondId];
            sort($pairIds);
            $pairKey = implode(':', $pairIds);
            $existing = DB::table('slot_compatibility_rules')
                ->where('organization_id', $organizationId)
                ->where('pair_key', $pairKey)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $created = true;
                $this->slotCompatibility->setRule(
                    $organizationId,
                    $firstId,
                    $secondId,
                    $rule['policy'],
                    reason: $rule['reason'],
                );

                continue;
            }

            if (
                (int) $existing->first_slot_offering_id !== $pairIds[0]
                || (int) $existing->second_slot_offering_id !== $pairIds[1]
                || (string) $existing->policy !== $rule['policy']
                || $existing->reason !== $rule['reason']
            ) {
                $this->drift("slot compatibility governed values differ: {$rule['first_slot_code']}:{$rule['second_slot_code']}");
            }
        }
    }

    private function holdTtl(int $organizationId, PilotManifest $manifest, bool &$created): void
    {
        $setting = DB::table('organization_settings')
            ->where('organization_id', $organizationId)
            ->where('key', OrganizationHoldTtlPolicy::KEY)
            ->lockForUpdate()
            ->first();

        if ($setting === null) {
            $created = true;
            DB::table('organization_settings')->insert([
                'organization_id' => $organizationId,
                'key' => OrganizationHoldTtlPolicy::KEY,
                'value' => (string) $manifest->holdTtlMinutes,
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);

            return;
        }

        if ((string) $setting->value !== (string) $manifest->holdTtlMinutes) {
            $this->drift('HOLD TTL governed value differs.');
        }
    }

    private function operator(PilotManifest $manifest, ?string $operatorPassword, bool &$created): int
    {
        $users = User::query()
            ->whereRaw('LOWER(email) = ?', [$manifest->operatorEmail])
            ->lockForUpdate()
            ->get();

        if ($users->count() > 1) {
            $this->drift("operator identity is ambiguous: {$manifest->operatorEmail}");
        }

        $user = $users->first();

        if ($user === null) {
            if ($operatorPassword === null || mb_strlen($operatorPassword) < 12) {
                throw PilotProvisioningException::invalidManifest(
                    'BOATOPS_PILOT_OPERATOR_PASSWORD must be set to at least 12 characters when creating the operator.',
                );
            }

            $created = true;
            $user = User::create([
                'name' => $manifest->operatorName,
                'email' => $manifest->operatorEmail,
                'password' => $operatorPassword,
            ]);
        } elseif ((string) $user->name !== $manifest->operatorName) {
            $this->drift("operator governed values differ: {$manifest->operatorEmail}");
        }

        return (int) $user->id;
    }

    private function membership(int $organizationId, int $userId, PilotManifest $manifest, bool &$created): void
    {
        $membership = DB::table('operator_memberships')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            $created = true;
            DB::table('operator_memberships')->insert([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'status' => 'ACTIVE',
                'can_calendar_read' => $manifest->operatorPermissions['can_calendar_read'],
                'can_booking_workflow' => $manifest->operatorPermissions['can_booking_workflow'],
                'can_block' => $manifest->operatorPermissions['can_block'],
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);

            return;
        }

        if (
            (int) $membership->organization_id !== $organizationId
            || (string) $membership->status !== 'ACTIVE'
            || (bool) $membership->can_calendar_read !== $manifest->operatorPermissions['can_calendar_read']
            || (bool) $membership->can_booking_workflow !== $manifest->operatorPermissions['can_booking_workflow']
            || (bool) $membership->can_block !== $manifest->operatorPermissions['can_block']
        ) {
            $this->drift("operator membership governed values differ: {$manifest->operatorEmail}");
        }
    }

    private function time(string $value): string
    {
        return substr($value, 0, 8);
    }

    private function drift(string $message): never
    {
        throw PilotProvisioningException::configurationDrift($message);
    }
}
