<?php

namespace App\Application\Blocks;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BlockActionSupport
{
    /** @param array<string, mixed> $value */
    private function canonicalHash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $value
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

    private function replay(int $organizationId, string $operation, string $idempotencyKey, string $requestHash): ?BlockActionResult
    {
        $existing = DB::table('idempotency_keys')
            ->where('organization_id', $organizationId)
            ->where('operation', $operation)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $existing) {
            return null;
        }

        if (! hash_equals($existing->request_hash, $requestHash)) {
            return $this->error('IDEMPOTENCY_CONFLICT', 'The idempotency key was used with another payload.', 409, true);
        }

        return new BlockActionResult(
            (int) $existing->response_status,
            json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function error(string $code, string $message, int $status, bool $manualActionRequired = false): BlockActionResult
    {
        return new BlockActionResult($status, [
            'request_id' => (string) Str::uuid(),
            'code' => $code,
            'retryable' => false,
            'manual_action_required' => $manualActionRequired,
            'message' => $message,
        ]);
    }

    private function inventoryIntegrityError(): BlockActionResult
    {
        return $this->error(
            'INVENTORY_INTEGRITY_FAILED',
            'Inventory linkage is inconsistent and requires manual action.',
            409,
            true,
        );
    }

    private function allocationMatchesBlock(?object $allocation, object $block): bool
    {
        if (! $allocation
            || (int) $allocation->organization_id !== (int) $block->organization_id
            || (int) $allocation->boat_id !== (int) $block->boat_id
            || $allocation->allocation_type !== 'BLOCKED'
            || $allocation->status !== 'ACTIVE'
            || (int) $allocation->block_id !== (int) $block->id
            || $allocation->hold_id !== null
            || $allocation->booking_id !== null) {
            return false;
        }

        foreach (['business_start', 'business_end', 'occupied_start', 'occupied_end'] as $field) {
            if (! CarbonImmutable::parse((string) $allocation->{$field}, 'UTC')->utc()->equalTo(
                CarbonImmutable::parse((string) $block->{$field}, 'UTC')->utc(),
            )) {
                return false;
            }
        }

        return true;
    }
}
