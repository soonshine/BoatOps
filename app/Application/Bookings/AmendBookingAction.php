<?php

namespace App\Application\Bookings;

use App\Application\Holds\HoldActor;
use App\Exceptions\SlotCatalogException;
use App\Services\SlotCatalog\SlotAvailabilityService;
use App\Services\SlotCatalog\SlotIntervalResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AmendBookingAction
{
    use BookingActionSupport;

    public function __construct(
        private readonly SlotIntervalResolver $slotResolver,
        private readonly SlotAvailabilityService $slotAvailability,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(int $organizationId, int $bookingId, array $input, string $idempotencyKey, HoldActor $actor): BookingActionResult
    {
        $operation = 'amendBooking:'.$bookingId;
        $requestHash = $this->canonicalHash($input);
        $existing = $this->replay($organizationId, $operation, $idempotencyKey, $requestHash);
        if ($existing) {
            return $existing;
        }
        $organization = DB::table('organizations')->find($organizationId);
        if (! $organization) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested booking or inventory resource is not accessible.', 403);
        }

        $booking = DB::table('bookings')
            ->where('organization_id', $organization->id)
            ->where('id', $bookingId)
            ->first();
        $boat = DB::table('boats')
            ->where('organization_id', $organization->id)
            ->where('status', 'ACTIVE')
            ->find($input['boat_id']);
        $templateExists = DB::table('trip_templates')
            ->where('organization_id', $organization->id)
            ->where('status', 'ACTIVE')
            ->where('id', $input['trip_template_id'])
            ->exists();

        if (! $booking || ! $boat || ! $templateExists) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested booking or inventory resource is not accessible.', 403);
        }

        if (! hash_equals($booking->external_reference, $input['external_reference'])) {
            return $this->error('VALIDATION_FAILED', 'The external reference does not match the booking.', 422);
        }

        try {
            $slot = $this->slotResolver->resolve($organization, $boat, $input);
        } catch (SlotCatalogException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus, $exception->manualActionRequired);
        }

        try {
            return DB::transaction(function () use (
                $organization,
                $actor,
                $booking,
                $input,
                $idempotencyKey,
                $operation,
                $requestHash,
                $slot,
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
                $allocation = DB::table('allocations')->where('organization_id', $organization->id)->where('id', $lockedBooking->allocation_id)->lockForUpdate()->first();

                if ($lockedBooking->status !== 'CONFIRMED' || ! $allocation || $allocation->status !== 'ACTIVE') {
                    return $this->error('INVALID_TRANSITION', 'Only a confirmed booking with active inventory can be amended.', 409);
                }

                $decision = $this->slotAvailability->decide(
                    (int) $organization->id,
                    (int) $input['boat_id'],
                    $slot,
                    excludeAllocationId: (int) $allocation->id,
                    lockForUpdate: true,
                );

                if (! $decision['available']) {
                    return $this->error($decision['code'], $decision['message'], 409);
                }

                $now = now()->utc();
                DB::table('allocations')->where('id', $allocation->id)->update([
                    'boat_id' => $input['boat_id'],
                    ...$slot->databaseValues(),
                    'updated_at' => $now,
                ]);
                DB::table('bookings')->where('id', $booking->id)->update([
                    'boat_id' => $input['boat_id'],
                    'trip_template_id' => $input['trip_template_id'],
                    ...$slot->databaseValues(),
                    'updated_at' => $now,
                ]);
                DB::table('trips')->where('booking_id', $booking->id)->update([
                    'boat_id' => $input['boat_id'],
                    'trip_template_id' => $input['trip_template_id'],
                    'planned_start' => $slot->serviceStart,
                    'planned_end' => $slot->serviceEnd,
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
                    'status' => 'CONFIRMED',
                    'code' => 'BOOKING_AMENDED',
                    'inventory_revision' => $revision,
                    ...$slot->responseValues(),
                    'occurred_at' => $occurredAt,
                    'business_timezone' => $organization->timezone,
                ];
                $eventPayload = [
                    'event_id' => (string) Str::uuid(),
                    'event_type' => 'booking.amended.v1',
                    'event_version' => 1,
                    'occurred_at' => $occurredAt,
                    'organization_id' => $organization->id,
                    'aggregate_type' => 'booking',
                    'aggregate_id' => $booking->id,
                    'inventory_revision' => $revision,
                    'external_reference' => $input['external_reference'],
                    'status' => 'CONFIRMED',
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
                    'action' => 'booking.amended',
                    'object_type' => 'booking',
                    'object_id' => $booking->id,
                    'before_values' => json_encode([
                        'boat_id' => $allocation->boat_id,
                        'service_date' => $lockedBooking->service_date,
                        'business_start' => $lockedBooking->business_start,
                        'business_end' => $lockedBooking->business_end,
                        'slot_offering_id' => $lockedBooking->slot_offering_id,
                        'custom_slot_instance_id' => $lockedBooking->custom_slot_instance_id,
                    ], JSON_THROW_ON_ERROR),
                    'after_values' => json_encode([
                        'boat_id' => $input['boat_id'],
                        'service_date' => $slot->serviceDate,
                        'business_start' => $slot->serviceStart->format('Y-m-d\TH:i:s\Z'),
                        'business_end' => $slot->serviceEnd->format('Y-m-d\TH:i:s\Z'),
                        'slot_offering_id' => $slot->slotOfferingId,
                        'custom_slot_instance_id' => $slot->customSlotInstanceId,
                    ], JSON_THROW_ON_ERROR),
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
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'allocations_no_active_overlap')) {
                return $this->error('SLOT_UNAVAILABLE', 'The requested slot is unavailable.', 409);
            }

            throw $exception;
        }
    }
}
