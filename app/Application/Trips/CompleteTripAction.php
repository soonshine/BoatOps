<?php

namespace App\Application\Trips;

use App\Application\Holds\HoldActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CompleteTripAction
{
    use TripActionSupport;

    public function execute(int $organizationId, int $tripId, string $idempotencyKey, HoldActor $actor): TripActionResult
    {
        $operation = 'completeTrip:'.$tripId;
        $requestHash = hash('sha256', '{}');
        $existing = $this->replay($organizationId, $operation, $idempotencyKey, $requestHash);
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($organizationId, $tripId, $idempotencyKey, $actor, $operation, $requestHash): TripActionResult {
            $organization = DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();
            if (! $organization) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested trip is not accessible.', 403);
            }
            $replayed = $this->replay((int) $organization->id, $operation, $idempotencyKey, $requestHash);
            if ($replayed) {
                return $replayed;
            }

            $trip = DB::table('trips')
                ->where('organization_id', $organization->id)
                ->where('id', $tripId)
                ->lockForUpdate()
                ->first();
            if (! $trip) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested trip is not accessible.', 403);
            }
            if ($trip->status !== 'RETURNED') {
                return $this->error('INVALID_TRANSITION', 'Only a returned trip can be completed.', 409);
            }

            $now = now()->utc();
            if (! $trip->actual_returned_at) {
                return $this->error('VALIDATION_FAILED', 'A valid return time is required before completion.', 422);
            }
            $actualReturnedAt = CarbonImmutable::parse($trip->actual_returned_at)->utc();
            if ($actualReturnedAt->greaterThan($now)) {
                return $this->error('VALIDATION_FAILED', 'A trip with a future return time cannot be completed.', 422);
            }

            $booking = DB::table('bookings')
                ->where('organization_id', $organization->id)
                ->where('id', $trip->booking_id)
                ->lockForUpdate()
                ->first();
            $allocation = $booking
                ? DB::table('allocations')->where('organization_id', $organization->id)->where('id', $booking->allocation_id)->lockForUpdate()->first()
                : null;
            if (! $booking || $booking->status !== 'CONFIRMED' || ! $allocation || $allocation->status !== 'ACTIVE') {
                return $this->error('INVALID_TRANSITION', 'The trip booking must still own active inventory.', 409, true);
            }

            DB::table('trips')->where('organization_id', $organization->id)->where('id', $trip->id)->update([
                'status' => 'COMPLETED',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('bookings')->where('organization_id', $organization->id)->where('id', $booking->id)->update([
                'status' => 'COMPLETED',
                'updated_at' => $now,
            ]);
            DB::table('allocations')->where('organization_id', $organization->id)->where('id', $allocation->id)->update([
                'status' => 'COMPLETED',
                'updated_at' => $now,
            ]);
            DB::table('organizations')->where('id', $organization->id)->increment('inventory_revision');
            $revision = (int) DB::table('organizations')->where('id', $organization->id)->value('inventory_revision');
            $occurredAt = $now->format('Y-m-d\TH:i:s\Z');
            $payload = [
                'request_id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'organization_id' => $organization->id,
                'trip_id' => $trip->id,
                'booking_id' => $booking->id,
                'status' => 'COMPLETED',
                'code' => 'TRIP_COMPLETED',
                'inventory_revision' => $revision,
                'occurred_at' => $occurredAt,
                'business_timezone' => $organization->timezone,
            ];
            $eventPayload = [
                'event_id' => (string) Str::uuid(),
                'event_type' => 'trip.completed.v1',
                'event_version' => 1,
                'occurred_at' => $occurredAt,
                'organization_id' => $organization->id,
                'aggregate_type' => 'trip',
                'aggregate_id' => $trip->id,
                'trip_id' => $trip->id,
                'booking_id' => $booking->id,
                'boat_id' => $trip->boat_id,
                'inventory_revision' => $revision,
                'status' => 'COMPLETED',
            ];
            DB::table('outbox_events')->insert([
                'event_id' => $eventPayload['event_id'],
                'organization_id' => $organization->id,
                'event_type' => $eventPayload['event_type'],
                'aggregate_type' => 'trip',
                'aggregate_id' => $trip->id,
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
                'action' => 'trip.completed',
                'object_type' => 'trip',
                'object_id' => $trip->id,
                'before_values' => json_encode(['status' => 'RETURNED'], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['status' => 'COMPLETED', 'booking_status' => 'COMPLETED'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('idempotency_keys')->insert([
                'organization_id' => $organization->id,
                'operation' => $operation,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'response_status' => 200,
                'response_body' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return new TripActionResult(200, $payload, true);
        }, 3);
    }
}
