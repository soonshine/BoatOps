<?php

namespace App\Services\SlotCatalog;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SlotAvailabilityService
{
    public function __construct(private readonly SlotCompatibilityService $compatibility) {}

    /**
     * Compatibility is evaluated for identified slots on the same organization-local
     * service date. Physical occupied intervals are always evaluated for every active
     * allocation, including legacy bookings and operational blocks.
     *
     * @return array{available: bool, code: ?string, message: ?string}
     */
    public function decide(
        int $organizationId,
        int $boatId,
        ResolvedSlot $candidate,
        ?int $excludeAllocationId = null,
        bool $lockForUpdate = false,
    ): array {
        $query = DB::table('allocations')
            ->where('organization_id', $organizationId)
            ->where('boat_id', $boatId)
            ->where('status', 'ACTIVE')
            ->where(function ($query) use ($candidate): void {
                $query->where('service_date', $candidate->serviceDate)
                    ->orWhere(function ($query) use ($candidate): void {
                        $query->where('occupied_start', '<', $candidate->occupiedEnd)
                            ->where('occupied_end', '>', $candidate->occupiedStart);
                    });
            })
            ->orderBy('id');

        if ($excludeAllocationId !== null) {
            $query->where('id', '!=', $excludeAllocationId);
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $policies = $this->compatibility->policiesForOrganization($organizationId);
        $candidateIdentityIds = $candidate->compatibilityIdentityIds();

        foreach ($query->get() as $allocation) {
            $existingIdentityIds = $this->allocationIdentityIds($allocation);

            if (
                $candidateIdentityIds !== []
                && $existingIdentityIds !== []
                && $allocation->service_date !== null
                && (string) $allocation->service_date === $candidate->serviceDate
                && $this->compatibility->effectivePolicy(
                    $candidateIdentityIds,
                    $existingIdentityIds,
                    $policies,
                ) !== 'ALLOW'
            ) {
                return [
                    'available' => false,
                    'code' => 'SLOT_COMPATIBILITY_CONFLICT',
                    'message' => 'The selected slots cannot be combined on the same boat and service date.',
                ];
            }

            if ($this->occupiedIntervalsOverlap($candidate, $allocation)) {
                return [
                    'available' => false,
                    'code' => 'SLOT_UNAVAILABLE',
                    'message' => 'The requested slot is unavailable.',
                ];
            }
        }

        return ['available' => true, 'code' => null, 'message' => null];
    }

    /**
     * Derive one calendar candidate from a preloaded, organization-scoped allocation
     * snapshot. No database call occurs here, so a bounded calendar can evaluate all
     * boat/date candidates without N+1 queries.
     *
     * @param  iterable<object>  $allocations
     * @param  array<string, string>  $policies
     * @return array{
     *     available: bool,
     *     status: string,
     *     conflict_code: ?string,
     *     message: ?string,
     *     buffer_conflict: bool,
     *     direct_identity: bool,
     *     allocation: ?object
     * }
     */
    public function calendarDecision(
        ResolvedSlot $candidate,
        iterable $allocations,
        array $policies,
    ): array {
        $candidateIdentityIds = $candidate->compatibilityIdentityIds();
        $best = null;

        foreach ($allocations as $allocation) {
            $existingIdentityIds = $this->allocationIdentityIds($allocation);
            $sameServiceDate = $allocation->service_date !== null
                && (string) $allocation->service_date === $candidate->serviceDate;
            $directIdentity = $sameServiceDate
                && array_intersect($candidateIdentityIds, $existingIdentityIds) !== [];
            $physicalOverlap = $this->occupiedIntervalsOverlap($candidate, $allocation);
            $compatibilityConflict = ! $directIdentity
                && $sameServiceDate
                && $candidateIdentityIds !== []
                && $existingIdentityIds !== []
                && $this->compatibility->effectivePolicy(
                    $candidateIdentityIds,
                    $existingIdentityIds,
                    $policies,
                ) !== 'ALLOW';

            if (! $directIdentity && ! $physicalOverlap && ! $compatibilityConflict) {
                continue;
            }

            $authorityStatus = $this->calendarStatusForAllocation($allocation);
            $status = $compatibilityConflict
                ? 'UNAVAILABLE'
                : $authorityStatus;
            $conflictCode = $compatibilityConflict
                ? 'SLOT_COMPATIBILITY_CONFLICT'
                : 'SLOT_UNAVAILABLE';
            $rank = ($directIdentity ? 100 : ($physicalOverlap ? 50 : 10))
                + match ($authorityStatus) {
                    'BLOCKED' => 4,
                    'CONFIRMED' => 3,
                    'HELD' => 2,
                    default => 1,
                };
            $bufferConflict = $physicalOverlap
                && ! $this->serviceIntervalsOverlap($candidate, $allocation);
            $decision = [
                'available' => false,
                'status' => $status,
                'conflict_code' => $conflictCode,
                'message' => $conflictCode === 'SLOT_COMPATIBILITY_CONFLICT'
                    ? 'The selected slots cannot be combined on the same boat and service date.'
                    : 'The requested slot is unavailable.',
                'buffer_conflict' => $bufferConflict,
                'direct_identity' => $directIdentity,
                'allocation' => $allocation,
                'rank' => $rank,
            ];

            if ($best === null || $decision['rank'] > $best['rank']) {
                $best = $decision;
            }
        }

        if ($best === null) {
            return [
                'available' => true,
                'status' => 'AVAILABLE',
                'conflict_code' => null,
                'message' => null,
                'buffer_conflict' => false,
                'direct_identity' => false,
                'allocation' => null,
            ];
        }

        unset($best['rank']);

        return $best;
    }

    /**
     * @return list<int>
     */
    private function allocationIdentityIds(object $allocation): array
    {
        return array_values(array_unique(array_filter([
            $allocation->custom_slot_instance_id === null
                ? null
                : (int) $allocation->custom_slot_instance_id,
            $allocation->slot_offering_id === null
                ? null
                : (int) $allocation->slot_offering_id,
        ], static fn (?int $id): bool => $id !== null)));
    }

    private function occupiedIntervalsOverlap(ResolvedSlot $candidate, object $allocation): bool
    {
        $existingStart = CarbonImmutable::parse((string) $allocation->occupied_start, 'UTC')->utc();
        $existingEnd = CarbonImmutable::parse((string) $allocation->occupied_end, 'UTC')->utc();

        return $existingStart->lessThan($candidate->occupiedEnd)
            && $existingEnd->greaterThan($candidate->occupiedStart);
    }

    private function serviceIntervalsOverlap(ResolvedSlot $candidate, object $allocation): bool
    {
        $serviceStart = $allocation->service_start ?? $allocation->business_start;
        $serviceEnd = $allocation->service_end ?? $allocation->business_end;
        $existingStart = CarbonImmutable::parse((string) $serviceStart, 'UTC')->utc();
        $existingEnd = CarbonImmutable::parse((string) $serviceEnd, 'UTC')->utc();

        return $existingStart->lessThan($candidate->serviceEnd)
            && $existingEnd->greaterThan($candidate->serviceStart);
    }

    private function calendarStatusForAllocation(object $allocation): string
    {
        return match ((string) $allocation->allocation_type) {
            'HOLD', 'HELD' => 'HELD',
            'BOOKING', 'CONFIRMED' => 'CONFIRMED',
            'BLOCK', 'BLOCKED' => 'BLOCKED',
            default => 'UNAVAILABLE',
        };
    }
}
