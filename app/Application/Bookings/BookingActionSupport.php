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
