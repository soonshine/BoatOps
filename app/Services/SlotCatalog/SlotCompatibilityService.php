<?php

namespace App\Services\SlotCatalog;

use App\Exceptions\SlotCatalogException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SlotCompatibilityService
{
    public function setRule(
        int $organizationId,
        int $firstSlotOfferingId,
        int $secondSlotOfferingId,
        string $policy,
        ?int $actorApiClientId = null,
        ?string $reason = null,
    ): int {
        $policy = strtoupper($policy);
        $reason = $reason === null ? null : trim($reason);

        if (! in_array($policy, ['ALLOW', 'DENY'], true)) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'Slot compatibility policy must be ALLOW or DENY.', 422);
        }

        if ($reason !== null && mb_strlen($reason) > 500) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'The compatibility reason is too long.', 422);
        }

        if ($firstSlotOfferingId === $secondSlotOfferingId) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'A slot compatibility rule requires two different slots.', 422);
        }

        $slotCount = DB::table('slot_offerings')
            ->where('organization_id', $organizationId)
            ->whereIn('id', [$firstSlotOfferingId, $secondSlotOfferingId])
            ->count();

        if ($slotCount !== 2) {
            throw new SlotCatalogException(
                'AUTHORIZATION_FAILED',
                'The requested slot compatibility pair is not accessible.',
                403,
            );
        }

        [$firstId, $secondId] = $this->canonicalPair($firstSlotOfferingId, $secondSlotOfferingId);
        $pairKey = $this->pairKey($firstId, $secondId);

        return DB::transaction(function () use (
            $organizationId,
            $firstId,
            $secondId,
            $pairKey,
            $policy,
            $actorApiClientId,
            $reason,
        ): int {
            if (! DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first()) {
                throw new SlotCatalogException('AUTHORIZATION_FAILED', 'The organization is not accessible.', 403);
            }

            $existing = DB::table('slot_compatibility_rules')
                ->where('organization_id', $organizationId)
                ->where('pair_key', $pairKey)
                ->lockForUpdate()
                ->first();
            $now = now()->utc();
            $before = $existing === null ? null : [
                'policy' => $existing->policy,
                'reason' => $existing->reason,
            ];

            if ($existing) {
                DB::table('slot_compatibility_rules')->where('id', $existing->id)->update([
                    'first_slot_offering_id' => $firstId,
                    'second_slot_offering_id' => $secondId,
                    'policy' => $policy,
                    'reason' => $reason,
                    'updated_by_api_client_id' => $actorApiClientId,
                    'updated_at' => $now,
                ]);
                $ruleId = (int) $existing->id;
                $action = 'slot.compatibility.updated';
            } else {
                $ruleId = DB::table('slot_compatibility_rules')->insertGetId([
                    'organization_id' => $organizationId,
                    'first_slot_offering_id' => $firstId,
                    'second_slot_offering_id' => $secondId,
                    'pair_key' => $pairKey,
                    'policy' => $policy,
                    'reason' => $reason,
                    'created_by_api_client_id' => $actorApiClientId,
                    'updated_by_api_client_id' => $actorApiClientId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $action = 'slot.compatibility.created';
            }

            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId,
                'actor_type' => $actorApiClientId === null ? 'system' : 'api_client',
                'actor_id' => $actorApiClientId,
                'action' => $action,
                'object_type' => 'slot_compatibility_rule',
                'object_id' => $ruleId,
                'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after_values' => json_encode([
                    'first_slot_offering_id' => $firstId,
                    'second_slot_offering_id' => $secondId,
                    'policy' => $policy,
                    'reason' => $reason,
                ], JSON_THROW_ON_ERROR),
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('organizations')->where('id', $organizationId)->increment('inventory_revision');

            return $ruleId;
        }, 3);
    }

    public function policyBetween(int $organizationId, int $firstSlotOfferingId, int $secondSlotOfferingId): ?string
    {
        if ($firstSlotOfferingId === $secondSlotOfferingId) {
            return null;
        }

        [$firstId, $secondId] = $this->canonicalPair($firstSlotOfferingId, $secondSlotOfferingId);

        return DB::table('slot_compatibility_rules')
            ->where('organization_id', $organizationId)
            ->where('pair_key', $this->pairKey($firstId, $secondId))
            ->value('policy');
    }

    /**
     * @return array<string, string>
     */
    public function policiesForOrganization(int $organizationId): array
    {
        return DB::table('slot_compatibility_rules')
            ->where('organization_id', $organizationId)
            ->pluck('policy', 'pair_key')
            ->mapWithKeys(static fn (mixed $policy, mixed $key): array => [(string) $key => (string) $policy])
            ->all();
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    public function listRules(int $organizationId): array
    {
        return DB::table('slot_compatibility_rules as rules')
            ->join('slot_offerings as first_slot', function ($join): void {
                $join->on('first_slot.id', '=', 'rules.first_slot_offering_id')
                    ->on('first_slot.organization_id', '=', 'rules.organization_id');
            })
            ->join('slot_offerings as second_slot', function ($join): void {
                $join->on('second_slot.id', '=', 'rules.second_slot_offering_id')
                    ->on('second_slot.organization_id', '=', 'rules.organization_id');
            })
            ->where('rules.organization_id', $organizationId)
            ->orderBy('first_slot.code')
            ->orderBy('second_slot.code')
            ->get([
                'rules.id',
                'rules.pair_key',
                'rules.first_slot_offering_id',
                'rules.second_slot_offering_id',
                'rules.policy',
                'rules.reason',
                'first_slot.code as first_slot_code',
                'first_slot.name as first_slot_name',
                'second_slot.code as second_slot_code',
                'second_slot.name as second_slot_name',
                'rules.updated_at',
            ])
            ->map(static fn (object $rule): array => [
                'id' => (int) $rule->id,
                'pair_key' => (string) $rule->pair_key,
                'first_slot_offering_id' => (int) $rule->first_slot_offering_id,
                'first_slot_code' => (string) $rule->first_slot_code,
                'first_slot_name' => (string) $rule->first_slot_name,
                'second_slot_offering_id' => (int) $rule->second_slot_offering_id,
                'second_slot_code' => (string) $rule->second_slot_code,
                'second_slot_name' => (string) $rule->second_slot_name,
                'policy' => (string) $rule->policy,
                'reason' => $rule->reason === null ? null : (string) $rule->reason,
                'updated_at' => CarbonImmutable::parse((string) $rule->updated_at, 'UTC')
                    ->utc()
                    ->format('Y-m-d\TH:i:s\Z'),
            ])
            ->all();
    }

    /**
     * @param  list<int>  $candidateIdentityIds
     * @param  list<int>  $existingIdentityIds
     * @param  array<string, string>  $policies
     */
    public function effectivePolicy(
        array $candidateIdentityIds,
        array $existingIdentityIds,
        array $policies,
    ): string {
        foreach ($candidateIdentityIds as $candidateIdentityId) {
            foreach ($existingIdentityIds as $existingIdentityId) {
                if ($candidateIdentityId === $existingIdentityId) {
                    return 'DENY';
                }

                [$firstId, $secondId] = $this->canonicalPair($candidateIdentityId, $existingIdentityId);
                $policy = $policies[$this->pairKey($firstId, $secondId)] ?? null;

                if ($policy !== null) {
                    return $policy;
                }
            }
        }

        // Fail closed for every unidentified pair. One-time custom slots remain
        // mutually exclusive until one canonical, auditable ALLOW rule exists.
        return 'DENY';
    }

    /**
     * @return array{int, int}
     */
    private function canonicalPair(int $firstSlotOfferingId, int $secondSlotOfferingId): array
    {
        return $firstSlotOfferingId < $secondSlotOfferingId
            ? [$firstSlotOfferingId, $secondSlotOfferingId]
            : [$secondSlotOfferingId, $firstSlotOfferingId];
    }

    private function pairKey(int $firstSlotOfferingId, int $secondSlotOfferingId): string
    {
        return $firstSlotOfferingId.':'.$secondSlotOfferingId;
    }
}
