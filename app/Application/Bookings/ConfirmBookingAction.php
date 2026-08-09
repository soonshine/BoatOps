<?php

namespace App\Application\Bookings;

use App\Application\Holds\ExpireDueHoldAction;
use App\Application\Holds\HoldActor;
use App\Application\Holds\HoldIdempotencyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConfirmBookingAction
{
    use BookingActionSupport;

    public function __construct(private readonly ExpireDueHoldAction $expireDueHold) {}

    /** @param array<string, mixed> $input */
    public function execute(int $organizationId, array $input, string $idempotencyKey, HoldActor $actor): BookingActionResult
    {
        $operation = 'confirmBooking';
        $requestHash = $this->canonicalHash($input);
        $existing = $this->replay($organizationId, $operation, $idempotencyKey, $requestHash);
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use (
            $organizationId,
            $actor,
            $input,
            $idempotencyKey,
            $operation,
            $requestHash,
        ): BookingActionResult {
            $organization = DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();
            if (! $organization) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested HOLD is not accessible.', 403);
            }
            $replayed = $this->replay(
                $organization->id,
                $operation,
                $idempotencyKey,
                $requestHash,
            );

            if ($replayed) {
                return $replayed;
            }

            $lockedHold = DB::table('holds')->where('organization_id', $organization->id)
                ->where('id', $input['hold_id'])->lockForUpdate()->first();
            if (! $lockedHold) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested HOLD is not accessible.', 403);
            }
            if (! hash_equals($lockedHold->external_reference, $input['external_reference'])) {
                return $this->error('VALIDATION_FAILED', 'The external reference does not match the HOLD.', 422);
            }

            if ($lockedHold->status !== 'ACTIVE') {
                return $this->error('INVALID_TRANSITION', 'Only an active HOLD can be confirmed.', 409);
            }
            $allocation = DB::table('allocations')->where('id', $lockedHold->allocation_id)->lockForUpdate()->first();
            if (! $this->allocationMatchesHoldForConfirmation($allocation, $lockedHold)) {
                return $this->inventoryIntegrityError();
            }

            $expiry = $this->expireDueHold->execute(
                (int) $lockedHold->id,
                CarbonImmutable::now('UTC'),
                $actor,
                (int) $organization->id,
                new HoldIdempotencyContext($operation, $idempotencyKey, $requestHash),
            );

            if ($expiry->changed) {
                return new BookingActionResult($expiry->status, $expiry->payload, true);
            }

            $now = now()->utc();
            $rateSnapshot = $input['rate_snapshot'] ?? null;
            $quotedAt = $rateSnapshot === null
                ? null
                : CarbonImmutable::parse($rateSnapshot['quoted_at'])->utc();
            $validUntil = isset($rateSnapshot['valid_until'])
                ? CarbonImmutable::parse($rateSnapshot['valid_until'])->utc()
                : null;

            if ($quotedAt?->greaterThan($now)) {
                return $this->error('VALIDATION_FAILED', 'The rate quote time cannot be in the future.', 422);
            }

            if ($validUntil && $validUntil->lessThanOrEqualTo($now)) {
                return $this->error('RATE_CHANGED', 'The rate snapshot is no longer valid.', 409, true);
            }

            $bookingId = DB::table('bookings')->insertGetId([
                'organization_id' => $organization->id,
                'hold_id' => $lockedHold->id,
                'boat_id' => $lockedHold->boat_id,
                'trip_template_id' => $lockedHold->trip_template_id,
                'slot_offering_id' => $lockedHold->slot_offering_id,
                'custom_slot_instance_id' => $lockedHold->custom_slot_instance_id,
                'external_reference' => $input['external_reference'],
                'status' => 'CONFIRMED',
                'service_date' => $lockedHold->service_date,
                'service_start' => $lockedHold->service_start,
                'service_end' => $lockedHold->service_end,
                'business_start' => $lockedHold->business_start,
                'business_end' => $lockedHold->business_end,
                'occupied_start' => $lockedHold->occupied_start,
                'occupied_end' => $lockedHold->occupied_end,
                'slot_code_snapshot' => $lockedHold->slot_code_snapshot,
                'slot_name_snapshot' => $lockedHold->slot_name_snapshot,
                'slot_duration_minutes_snapshot' => $lockedHold->slot_duration_minutes_snapshot,
                'allocation_id' => $lockedHold->allocation_id,
                'confirmed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $rateSnapshotId = null;
            if ($rateSnapshot !== null) {
                $rateSnapshotId = DB::table('rate_snapshots')->insertGetId([
                    'organization_id' => $organization->id,
                    'booking_id' => $bookingId,
                    'schema_version' => 1,
                    'source_reference' => $rateSnapshot['source_reference'],
                    'currency' => $rateSnapshot['currency'],
                    'selling_amount_minor' => $rateSnapshot['selling_amount_minor'],
                    'tax_amount_minor' => $rateSnapshot['tax_amount_minor'],
                    'commission_amount_minor' => $rateSnapshot['commission_amount_minor'],
                    'fx_rate' => $rateSnapshot['fx_rate'] ?? null,
                    'fx_base_currency' => $rateSnapshot['fx_base_currency'] ?? null,
                    'fx_quote_currency' => $rateSnapshot['fx_quote_currency'] ?? null,
                    'quoted_at' => $quotedAt,
                    'valid_until' => $validUntil,
                    'canonical_hash' => $this->canonicalHash($rateSnapshot),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('bookings')->where('id', $bookingId)->update([
                    'rate_snapshot_id' => $rateSnapshotId,
                    'updated_at' => $now,
                ]);
            }
            DB::table('holds')->where('id', $lockedHold->id)->update([
                'status' => 'CONFIRMED',
                'updated_at' => $now,
            ]);
            DB::table('allocations')->where('id', $allocation->id)->update([
                'allocation_type' => 'BOOKING',
                'booking_id' => $bookingId,
                'updated_at' => $now,
            ]);
            $tripId = DB::table('trips')->insertGetId([
                'organization_id' => $organization->id,
                'booking_id' => $bookingId,
                'boat_id' => $lockedHold->boat_id,
                'trip_template_id' => $lockedHold->trip_template_id,
                'status' => 'PLANNED',
                'planned_start' => $lockedHold->business_start,
                'planned_end' => $lockedHold->business_end,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('organizations')->where('id', $organization->id)->increment('inventory_revision');
            $revision = (int) DB::table('organizations')->where('id', $organization->id)->value('inventory_revision');
            $requestId = (string) Str::uuid();
            $occurredAt = $now->format('Y-m-d\TH:i:s\Z');
            $payload = [
                'request_id' => $requestId,
                'idempotency_key' => $idempotencyKey,
                'organization_id' => $organization->id,
                'booking_id' => $bookingId,
                'trip_id' => $tripId,
                'external_reference' => $input['external_reference'],
                'status' => 'CONFIRMED',
                'code' => 'BOOKING_CONFIRMED',
                'inventory_revision' => $revision,
                ...$this->slotResponseFromRecord($lockedHold),
                'occurred_at' => $occurredAt,
                'business_timezone' => $organization->timezone,
            ];
            $eventPayload = [
                'event_id' => (string) Str::uuid(),
                'event_type' => 'booking.confirmed.v1',
                'event_version' => 1,
                'occurred_at' => $occurredAt,
                'organization_id' => $organization->id,
                'aggregate_type' => 'booking',
                'aggregate_id' => $bookingId,
                'inventory_revision' => $revision,
                'external_reference' => $input['external_reference'],
                'status' => 'CONFIRMED',
            ];
            DB::table('outbox_events')->insert([
                'event_id' => $eventPayload['event_id'],
                'organization_id' => $organization->id,
                'event_type' => $eventPayload['event_type'],
                'aggregate_type' => 'booking',
                'aggregate_id' => $bookingId,
                'inventory_revision' => $revision,
                'payload' => json_encode($eventPayload, JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'organization_id' => $organization->id,
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'action' => 'booking.confirmed',
                'object_type' => 'booking',
                'object_id' => $bookingId,
                'after_values' => json_encode([
                    'status' => 'CONFIRMED',
                    'hold_id' => $lockedHold->id,
                    'trip_id' => $tripId,
                    'rate_snapshot_id' => $rateSnapshotId,
                    'service_date' => $lockedHold->service_date,
                    'slot_offering_id' => $lockedHold->slot_offering_id,
                    'custom_slot_instance_id' => $lockedHold->custom_slot_instance_id,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('idempotency_keys')->insert([
                'organization_id' => $organization->id,
                'operation' => $operation,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'response_status' => 201,
                'response_body' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return new BookingActionResult(201, $payload, true);
        }, 3);
    }

    private function allocationMatchesHoldForConfirmation(?object $allocation, object $hold): bool
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

        return $this->matchingInventoryIntervals($allocation, $hold);
    }
}
