<?php

namespace App\Application\Bookings;

use App\Application\Holds\HoldActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CancelBookingAction
{
    use BookingActionSupport;

    /** @param array<string, mixed> $input */
    public function execute(int $organizationId, int $bookingId, array $input, string $idempotencyKey, HoldActor $actor): BookingActionResult
    {
        $operation = 'cancelBooking:'.$bookingId;
        $requestHash = $this->canonicalHash($input);
        $existing = $this->replay($organizationId, $operation, $idempotencyKey, $requestHash);
        if ($existing) {
            return $existing;
        }
        $organization = DB::table('organizations')->find($organizationId);
        if (! $organization) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested booking is not accessible.', 403);
        }

        $booking = DB::table('bookings')
            ->where('organization_id', $organization->id)
            ->where('id', $bookingId)
            ->first();

        if (! $booking) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested booking is not accessible.', 403);
        }

        if (! hash_equals($booking->external_reference, $input['external_reference'])) {
            return $this->error('VALIDATION_FAILED', 'The external reference does not match the booking.', 422);
        }

        return DB::transaction(function () use (
            $organization,
            $actor,
            $booking,
            $input,
            $idempotencyKey,
            $operation,
            $requestHash,
        ): BookingActionResult {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replay(
                $organization->id,
                $operation,
                $idempotencyKey,
                $requestHash,
            );

            if ($replayed) {
                return $replayed;
            }

            $lockedBooking = DB::table('bookings')->where('organization_id', $organization->id)->where('id', $booking->id)->lockForUpdate()->first();

            if ($lockedBooking->status !== 'CONFIRMED') {
                return $this->error('INVALID_TRANSITION', 'Only a confirmed booking can be cancelled.', 409);
            }

            $now = now()->utc();
            DB::table('bookings')->where('id', $booking->id)->update([
                'status' => 'CANCELLED',
                'cancelled_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('allocations')->where('booking_id', $booking->id)->where('status', 'ACTIVE')->update([
                'status' => 'CANCELLED',
                'updated_at' => $now,
            ]);
            DB::table('trips')->where('booking_id', $booking->id)->update([
                'status' => 'CANCELLED',
                'updated_at' => $now,
            ]);
            $tripId = (int) DB::table('trips')->where('booking_id', $booking->id)->value('id');
            DB::table('organizations')->where('id', $organization->id)->increment('inventory_revision');
            $revision = (int) DB::table('organizations')->where('id', $organization->id)->value('inventory_revision');
            $occurredAt = $now->format('Y-m-d\TH:i:s\Z');
            $payload = [
                'request_id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'organization_id' => $organization->id,
                'booking_id' => $booking->id,
                'trip_id' => $tripId,
                'external_reference' => $input['external_reference'],
                'status' => 'CANCELLED',
                'code' => 'BOOKING_CANCELLED',
                'inventory_revision' => $revision,
                'occurred_at' => $occurredAt,
                'business_timezone' => $organization->timezone,
            ];
            $eventPayload = [
                'event_id' => (string) Str::uuid(),
                'event_type' => 'booking.cancelled.v1',
                'event_version' => 1,
                'occurred_at' => $occurredAt,
                'organization_id' => $organization->id,
                'aggregate_type' => 'booking',
                'aggregate_id' => $booking->id,
                'inventory_revision' => $revision,
                'external_reference' => $input['external_reference'],
                'status' => 'CANCELLED',
                'reason' => $input['reason'] ?? null,
            ];
            DB::table('outbox_events')->insert([
                'event_id' => $eventPayload['event_id'],
                'organization_id' => $organization->id,
                'event_type' => $eventPayload['event_type'],
                'aggregate_type' => 'booking',
                'aggregate_id' => $booking->id,
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
                'action' => 'booking.cancelled',
                'object_type' => 'booking',
                'object_id' => $booking->id,
                'before_values' => json_encode(['status' => 'CONFIRMED'], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['status' => 'CANCELLED'], JSON_THROW_ON_ERROR),
                'reason' => $input['reason'] ?? null,
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

            return new BookingActionResult(200, $payload, true);
        }, 3);
    }
}
