<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Holds\CreateHoldAction;
use App\Application\Holds\ExpireDueHoldAction;
use App\Application\Holds\HoldActionResult;
use App\Application\Holds\HoldActor;
use App\Application\Holds\HoldIdempotencyContext;
use App\Application\Holds\ReleaseHoldAction;
use App\Exceptions\SlotCatalogException;
use App\Http\Controllers\Controller;
use App\Services\SlotCatalog\SlotAvailabilityService;
use App\Services\SlotCatalog\SlotIntervalResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryCommandController extends Controller
{
    public function __construct(
        private readonly SlotIntervalResolver $slotResolver,
        private readonly SlotAvailabilityService $slotAvailability,
        private readonly CreateHoldAction $createHold,
        private readonly ReleaseHoldAction $releaseHold,
        private readonly ExpireDueHoldAction $expireDueHold,
    ) {}

    public function createHold(Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'boat_id' => ['required', 'integer'],
            'trip_template_id' => ['required', 'integer'],
            'slot_offering_id' => ['nullable', 'integer', 'min:1', 'prohibits:custom_slot_instance_id'],
            'custom_slot_instance_id' => ['nullable', 'integer', 'min:1', 'prohibits:slot_offering_id'],
            'service_date' => ['nullable', 'date_format:Y-m-d', 'required_with:slot_offering_id'],
            'starts_at' => ['nullable', 'date', 'required_without_all:slot_offering_id,custom_slot_instance_id'],
            'ends_at' => [
                'nullable',
                'date',
                'after:starts_at',
                'required_with:starts_at',
                'required_without_all:slot_offering_id,custom_slot_instance_id',
            ],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);
        $organization = $request->attributes->get('organization');
        $result = $this->createHold->execute(
            (int) $organization->id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return $this->actionResponse($result);
    }

    public function releaseHold(Request $request, int $id): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
        ]);
        $organization = $request->attributes->get('organization');
        $result = $this->releaseHold->execute(
            (int) $organization->id,
            $id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return $this->actionResponse($result);
    }

    public function confirmBooking(Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'hold_id' => ['required', 'integer'],
            'external_reference' => ['required', 'string', 'max:255'],
            'rate_snapshot' => ['required', 'array'],
            'rate_snapshot.source_reference' => ['required', 'string', 'max:255'],
            'rate_snapshot.currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'rate_snapshot.selling_amount_minor' => ['required', 'integer', 'min:0'],
            'rate_snapshot.tax_amount_minor' => ['required', 'integer', 'min:0'],
            'rate_snapshot.commission_amount_minor' => ['required', 'integer', 'min:0'],
            'rate_snapshot.fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'rate_snapshot.fx_base_currency' => ['nullable', 'required_with:rate_snapshot.fx_rate', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'rate_snapshot.fx_quote_currency' => ['nullable', 'required_with:rate_snapshot.fx_rate', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'rate_snapshot.quoted_at' => ['required', 'date'],
            'rate_snapshot.valid_until' => ['nullable', 'date', 'after:rate_snapshot.quoted_at'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = 'confirmBooking';
        $requestHash = hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));
        $existing = DB::table('idempotency_keys')
            ->where('organization_id', $organization->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                return $this->error('IDEMPOTENCY_CONFLICT', 'The idempotency key was used with another payload.', 409, true);
            }

            return response()->json(json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR), $existing->response_status);
        }

        $hold = DB::table('holds')
            ->where('organization_id', $organization->id)
            ->where('id', $input['hold_id'])
            ->first();

        if (! $hold) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested HOLD is not accessible.', 403);
        }

        if (! hash_equals($hold->external_reference, $input['external_reference'])) {
            return $this->error('VALIDATION_FAILED', 'The external reference does not match the HOLD.', 422);
        }

        return DB::transaction(function () use (
            $request,
            $organization,
            $hold,
            $input,
            $idempotencyKey,
            $operation,
            $requestHash,
        ): JsonResponse {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replayIdempotency(
                $organization->id,
                $operation,
                $idempotencyKey,
                $requestHash,
            );

            if ($replayed) {
                return $replayed;
            }

            $lockedHold = DB::table('holds')->where('id', $hold->id)->lockForUpdate()->first();

            if ($lockedHold->status !== 'ACTIVE') {
                return $this->error('INVALID_TRANSITION', 'Only an active HOLD can be confirmed.', 409);
            }

            $expiry = $this->expireDueHold->execute(
                (int) $lockedHold->id,
                CarbonImmutable::now('UTC'),
                HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
                (int) $organization->id,
                new HoldIdempotencyContext($operation, $idempotencyKey, $requestHash),
            );

            if ($expiry->changed) {
                return $this->actionResponse($expiry);
            }

            $now = now()->utc();
            $rateSnapshot = $input['rate_snapshot'];
            $quotedAt = CarbonImmutable::parse($rateSnapshot['quoted_at'])->utc();
            $validUntil = isset($rateSnapshot['valid_until'])
                ? CarbonImmutable::parse($rateSnapshot['valid_until'])->utc()
                : null;

            if ($quotedAt->greaterThan($now)) {
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
                'canonical_hash' => hash('sha256', json_encode($this->canonicalize($rateSnapshot), JSON_THROW_ON_ERROR)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('bookings')->where('id', $bookingId)->update([
                'rate_snapshot_id' => $rateSnapshotId,
                'updated_at' => $now,
            ]);
            DB::table('holds')->where('id', $hold->id)->update([
                'status' => 'CONFIRMED',
                'updated_at' => $now,
            ]);
            DB::table('allocations')->where('id', $hold->allocation_id)->update([
                'allocation_type' => 'BOOKING',
                'booking_id' => $bookingId,
                'updated_at' => $now,
            ]);
            $tripId = DB::table('trips')->insertGetId([
                'organization_id' => $organization->id,
                'booking_id' => $bookingId,
                'boat_id' => $hold->boat_id,
                'trip_template_id' => $hold->trip_template_id,
                'status' => 'PLANNED',
                'planned_start' => $hold->business_start,
                'planned_end' => $hold->business_end,
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
                'actor_type' => 'api_client',
                'actor_id' => $request->attributes->get('api_client_id'),
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

            return response()->json($payload, 201);
        }, 3);
    }

    public function amendBooking(Request $request, int $id): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'boat_id' => ['required', 'integer'],
            'trip_template_id' => ['required', 'integer'],
            'slot_offering_id' => ['nullable', 'integer', 'min:1', 'prohibits:custom_slot_instance_id'],
            'custom_slot_instance_id' => ['nullable', 'integer', 'min:1', 'prohibits:slot_offering_id'],
            'service_date' => ['nullable', 'date_format:Y-m-d', 'required_with:slot_offering_id'],
            'starts_at' => ['nullable', 'date', 'required_without_all:slot_offering_id,custom_slot_instance_id'],
            'ends_at' => [
                'nullable',
                'date',
                'after:starts_at',
                'required_with:starts_at',
                'required_without_all:slot_offering_id,custom_slot_instance_id',
            ],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = 'amendBooking:'.$id;
        $requestHash = hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));
        $existing = DB::table('idempotency_keys')
            ->where('organization_id', $organization->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                return $this->error('IDEMPOTENCY_CONFLICT', 'The idempotency key was used with another payload.', 409, true);
            }

            return response()->json(json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR), $existing->response_status);
        }

        $booking = DB::table('bookings')
            ->where('organization_id', $organization->id)
            ->where('id', $id)
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
            return $this->slotError($exception);
        }

        try {
            return DB::transaction(function () use (
                $request,
                $organization,
                $booking,
                $input,
                $idempotencyKey,
                $operation,
                $requestHash,
                $slot,
            ): JsonResponse {
                DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
                $replayed = $this->replayIdempotency(
                    $organization->id,
                    $operation,
                    $idempotencyKey,
                    $requestHash,
                );

                if ($replayed) {
                    return $replayed;
                }

                $lockedBooking = DB::table('bookings')->where('id', $booking->id)->lockForUpdate()->first();
                $allocation = DB::table('allocations')->where('id', $lockedBooking->allocation_id)->lockForUpdate()->first();

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
                    'actor_type' => 'api_client',
                    'actor_id' => $request->attributes->get('api_client_id'),
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

                return response()->json($payload);
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'allocations_no_active_overlap')) {
                return $this->error('SLOT_UNAVAILABLE', 'The requested slot is unavailable.', 409);
            }

            throw $exception;
        }
    }

    public function cancelBooking(Request $request, int $id): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = 'cancelBooking:'.$id;
        $requestHash = hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));
        $existing = DB::table('idempotency_keys')
            ->where('organization_id', $organization->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                return $this->error('IDEMPOTENCY_CONFLICT', 'The idempotency key was used with another payload.', 409, true);
            }

            return response()->json(json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR), $existing->response_status);
        }

        $booking = DB::table('bookings')
            ->where('organization_id', $organization->id)
            ->where('id', $id)
            ->first();

        if (! $booking) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested booking is not accessible.', 403);
        }

        if (! hash_equals($booking->external_reference, $input['external_reference'])) {
            return $this->error('VALIDATION_FAILED', 'The external reference does not match the booking.', 422);
        }

        return DB::transaction(function () use (
            $request,
            $organization,
            $booking,
            $input,
            $idempotencyKey,
            $operation,
            $requestHash,
        ): JsonResponse {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replayIdempotency(
                $organization->id,
                $operation,
                $idempotencyKey,
                $requestHash,
            );

            if ($replayed) {
                return $replayed;
            }

            $lockedBooking = DB::table('bookings')->where('id', $booking->id)->lockForUpdate()->first();

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
                'actor_type' => 'api_client',
                'actor_id' => $request->attributes->get('api_client_id'),
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

            return response()->json($payload);
        }, 3);
    }

    private function actionResponse(HoldActionResult $result): JsonResponse
    {
        return response()->json($result->payload, $result->status);
    }

    private function replayIdempotency(
        int $organizationId,
        string $operation,
        string $idempotencyKey,
        string $requestHash,
    ): ?JsonResponse {
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

        return response()->json(
            json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR),
            $existing->response_status,
        );
    }

    private function slotError(SlotCatalogException $exception): JsonResponse
    {
        return $this->error(
            $exception->errorCode,
            $exception->getMessage(),
            $exception->httpStatus,
            $exception->manualActionRequired,
        );
    }

    /**
     * Return the interval and selected identity frozen on an existing HOLD.
     *
     * @return array<string, int|string>
     */
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

    private function error(
        string $code,
        string $message,
        int $status,
        bool $manualActionRequired = false,
    ): JsonResponse {
        return response()->json([
            'request_id' => (string) Str::uuid(),
            'code' => $code,
            'retryable' => false,
            'manual_action_required' => $manualActionRequired,
            'message' => $message,
        ], $status);
    }

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
}
