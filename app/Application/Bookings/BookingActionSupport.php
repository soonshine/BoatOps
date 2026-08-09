<?php

namespace App\Application\Bookings;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BookingActionSupport
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

    private function replay(int $organizationId, string $operation, string $idempotencyKey, string $requestHash): ?BookingActionResult
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

        return new BookingActionResult(
            (int) $existing->response_status,
            json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function error(string $code, string $message, int $status, bool $manualActionRequired = false): BookingActionResult
    {
        return new BookingActionResult($status, [
            'request_id' => (string) Str::uuid(),
            'code' => $code,
            'retryable' => false,
            'manual_action_required' => $manualActionRequired,
            'message' => $message,
        ]);
    }

    private function inventoryIntegrityError(): BookingActionResult
    {
        return $this->error(
            'INVENTORY_INTEGRITY_FAILED',
            'Inventory linkage is inconsistent and requires manual action.',
            409,
            true,
        );
    }

    private function allocationMatchesBooking(?object $allocation, object $booking): bool
    {
        if (! $allocation
            || (int) $allocation->organization_id !== (int) $booking->organization_id
            || (int) $allocation->boat_id !== (int) $booking->boat_id
            || $allocation->allocation_type !== 'BOOKING'
            || $allocation->status !== 'ACTIVE'
            || (int) $allocation->booking_id !== (int) $booking->id
            || (int) ($allocation->hold_id ?? 0) !== (int) ($booking->hold_id ?? 0)
            || $allocation->block_id !== null) {
            return false;
        }

        return $this->matchingInventoryIntervals($allocation, $booking);
    }

    private function tripMatchesBooking(?object $trip, object $booking): bool
    {
        return $trip
            && (int) $trip->organization_id === (int) $booking->organization_id
            && (int) $trip->booking_id === (int) $booking->id
            && (int) $trip->boat_id === (int) $booking->boat_id
            && (int) $trip->trip_template_id === (int) $booking->trip_template_id
            && $this->matchingTimestamp($trip->planned_start, $booking->business_start)
            && $this->matchingTimestamp($trip->planned_end, $booking->business_end);
    }

    private function matchingInventoryIntervals(object $allocation, object $record): bool
    {
        foreach (['service_start', 'service_end', 'business_start', 'business_end', 'occupied_start', 'occupied_end'] as $field) {
            if (! $this->matchingTimestamp($allocation->{$field}, $record->{$field})) {
                return false;
            }
        }

        foreach (['service_date', 'slot_offering_id', 'custom_slot_instance_id'] as $field) {
            $allocationValue = $field === 'service_date'
                ? ($allocation->{$field} === null ? null : (string) $allocation->{$field})
                : ($allocation->{$field} === null ? null : (int) $allocation->{$field});
            $recordValue = $field === 'service_date'
                ? ($record->{$field} === null ? null : (string) $record->{$field})
                : ($record->{$field} === null ? null : (int) $record->{$field});
            if ($allocationValue !== $recordValue) {
                return false;
            }
        }

        return true;
    }

    private function matchingTimestamp(mixed $left, mixed $right): bool
    {
        return CarbonImmutable::parse((string) $left, 'UTC')->utc()->equalTo(
            CarbonImmutable::parse((string) $right, 'UTC')->utc(),
        );
    }

    /** @return array<string, int|string> */
    private function slotResponseFromRecord(object $record): array
    {
        $serviceStart = CarbonImmutable::parse((string) ($record->service_start ?? $record->business_start), 'UTC')->utc();
        $serviceEnd = CarbonImmutable::parse((string) ($record->service_end ?? $record->business_end), 'UTC')->utc();
        $occupiedStart = CarbonImmutable::parse((string) $record->occupied_start, 'UTC')->utc();
        $occupiedEnd = CarbonImmutable::parse((string) $record->occupied_end, 'UTC')->utc();
        $values = [
            'service_start' => $serviceStart->format('Y-m-d\TH:i:s\Z'),
            'service_end' => $serviceEnd->format('Y-m-d\TH:i:s\Z'),
            'occupied_start' => $occupiedStart->format('Y-m-d\TH:i:s\Z'),
            'occupied_end' => $occupiedEnd->format('Y-m-d\TH:i:s\Z'),
        ];
        if ($record->service_date !== null) {
            $values['service_date'] = (string) $record->service_date;
        }
        if ($record->slot_offering_id !== null) {
            $values['slot_offering_id'] = (int) $record->slot_offering_id;
        }
        if ($record->custom_slot_instance_id !== null) {
            $values['custom_slot_instance_id'] = (int) $record->custom_slot_instance_id;
        }
        if ($record->slot_code_snapshot !== null) {
            $values['slot_code'] = (string) $record->slot_code_snapshot;
        }

        return $values;
    }
}
