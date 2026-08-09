<?php

namespace App\Application\Holds;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait HoldActionSupport
{
    /** @param array<string, mixed> $value */
    private function canonicalHash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        ksort($value);

        return $value;
    }

    private function replay(
        int $organizationId,
        string $operation,
        string $idempotencyKey,
        string $requestHash,
    ): ?HoldActionResult {
        $existing = DB::table('idempotency_keys')
            ->where('organization_id', $organizationId)
            ->where('operation', $operation)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $existing) {
            return null;
        }

        if (! hash_equals($existing->request_hash, $requestHash)) {
            return $this->error(
                'IDEMPOTENCY_CONFLICT',
                'The idempotency key was used with another payload.',
                409,
                true,
            );
        }

        return new HoldActionResult(
            (int) $existing->response_status,
            json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function error(
        string $code,
        string $message,
        int $status,
        bool $manualActionRequired = false,
    ): HoldActionResult {
        return new HoldActionResult($status, [
            'request_id' => (string) Str::uuid(),
            'code' => $code,
            'retryable' => false,
            'manual_action_required' => $manualActionRequired,
            'message' => $message,
        ]);
    }

    private function inventoryIntegrityError(): HoldActionResult
    {
        return $this->error(
            'INVENTORY_INTEGRITY_FAILED',
            'Inventory linkage is inconsistent and requires manual action.',
            409,
            true,
        );
    }

    private function allocationMatchesHold(?object $allocation, object $hold): bool
    {
        if (! $allocation
            || (int) $allocation->organization_id !== (int) $hold->organization_id
            || (int) $allocation->boat_id !== (int) $hold->boat_id
            || $allocation->allocation_type !== 'HOLD'
            || $allocation->status !== 'ACTIVE'
            || (int) $allocation->hold_id !== (int) $hold->id
            || $allocation->booking_id !== null
            || $allocation->block_id !== null) {
            return false;
        }

        foreach (['service_start', 'service_end', 'business_start', 'business_end', 'occupied_start', 'occupied_end'] as $field) {
            if (! $this->matchingTimestamp($allocation->{$field}, $hold->{$field})) {
                return false;
            }
        }

        foreach (['service_date', 'slot_offering_id', 'custom_slot_instance_id'] as $field) {
            $allocationValue = $field === 'service_date'
                ? ($allocation->{$field} === null ? null : (string) $allocation->{$field})
                : ($allocation->{$field} === null ? null : (int) $allocation->{$field});
            $holdValue = $field === 'service_date'
                ? ($hold->{$field} === null ? null : (string) $hold->{$field})
                : ($hold->{$field} === null ? null : (int) $hold->{$field});
            if ($allocationValue !== $holdValue) {
                return false;
            }
        }

        return true;
    }

    private function matchingTimestamp(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }
        if ((is_string($left) && trim($left) === '') || (is_string($right) && trim($right) === '')) {
            return false;
        }

        return CarbonImmutable::parse((string) $left, 'UTC')->utc()->equalTo(
            CarbonImmutable::parse((string) $right, 'UTC')->utc(),
        );
    }
}
