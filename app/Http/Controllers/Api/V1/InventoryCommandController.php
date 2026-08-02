<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryCommandController extends Controller
{
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
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = 'createHold';
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

        $boat = DB::table('boats')
            ->where('organization_id', $organization->id)
            ->where('status', 'ACTIVE')
            ->find($input['boat_id']);
        $templateExists = DB::table('trip_templates')
            ->where('organization_id', $organization->id)
            ->where('status', 'ACTIVE')
            ->where('id', $input['trip_template_id'])
            ->exists();

        if (! $boat || ! $templateExists) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested inventory resource is not accessible.', 403);
        }

        $businessStart = CarbonImmutable::parse($input['starts_at'])->utc();
        $businessEnd = CarbonImmutable::parse($input['ends_at'])->utc();
        $occupiedStart = $businessStart->subMinutes($boat->buffer_before_minutes);
        $occupiedEnd = $businessEnd->addMinutes($boat->buffer_after_minutes);
        $expiresAt = CarbonImmutable::parse($input['expires_at'])->utc();

        try {
            return DB::transaction(function () use (
                $request,
                $organization,
                $input,
                $idempotencyKey,
                $operation,
                $requestHash,
                $businessStart,
                $businessEnd,
                $occupiedStart,
                $occupiedEnd,
                $expiresAt,
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

                if (DB::table('holds')
                    ->where('organization_id', $organization->id)
                    ->where('external_reference', $input['external_reference'])
                    ->exists()) {
                    return $this->error(
                        'DUPLICATE_EXTERNAL_REFERENCE',
                        'The external reference already exists.',
                        409,
                        true,
                    );
                }

                $overlapExists = DB::table('allocations')
                    ->where('organization_id', $organization->id)
                    ->where('boat_id', $input['boat_id'])
                    ->where('status', 'ACTIVE')
                    ->where('occupied_start', '<', $occupiedEnd)
                    ->where('occupied_end', '>', $occupiedStart)
                    ->lockForUpdate()
                    ->exists();

                if ($overlapExists) {
                    return $this->error('SLOT_UNAVAILABLE', 'The requested slot is unavailable.', 409);
                }

                $now = now()->utc();
                $holdId = DB::table('holds')->insertGetId([
                    'organization_id' => $organization->id,
                    'boat_id' => $input['boat_id'],
                    'trip_template_id' => $input['trip_template_id'],
                    'external_reference' => $input['external_reference'],
                    'status' => 'ACTIVE',
                    'business_start' => $businessStart,
                    'business_end' => $businessEnd,
                    'occupied_start' => $occupiedStart,
                    'occupied_end' => $occupiedEnd,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $allocationId = DB::table('allocations')->insertGetId([
                    'organization_id' => $organization->id,
                    'boat_id' => $input['boat_id'],
                    'allocation_type' => 'HOLD',
                    'status' => 'ACTIVE',
                    'business_start' => $businessStart,
                    'business_end' => $businessEnd,
                    'occupied_start' => $occupiedStart,
                    'occupied_end' => $occupiedEnd,
                    'hold_id' => $holdId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('holds')->where('id', $holdId)->update(['allocation_id' => $allocationId]);
                DB::table('organizations')->where('id', $organization->id)->increment('inventory_revision');
                $revision = (int) DB::table('organizations')->where('id', $organization->id)->value('inventory_revision');
                $requestId = (string) Str::uuid();
                $occurredAt = $now->format('Y-m-d\TH:i:s\Z');
                $payload = [
                    'request_id' => $requestId,
                    'idempotency_key' => $idempotencyKey,
                    'organization_id' => $organization->id,
                    'hold_id' => $holdId,
                    'external_reference' => $input['external_reference'],
                    'status' => 'ACTIVE',
                    'code' => 'HOLD_CREATED',
                    'inventory_revision' => $revision,
                    'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
                    'occurred_at' => $occurredAt,
                    'business_timezone' => $organization->timezone,
                ];
                $eventPayload = [
                    'event_id' => (string) Str::uuid(),
                    'event_type' => 'hold.created.v1',
                    'event_version' => 1,
                    'occurred_at' => $occurredAt,
                    'organization_id' => $organization->id,
                    'aggregate_type' => 'hold',
                    'aggregate_id' => $holdId,
                    'inventory_revision' => $revision,
                    'external_reference' => $input['external_reference'],
                    'status' => 'ACTIVE',
                ];
                DB::table('outbox_events')->insert([
                    'event_id' => $eventPayload['event_id'],
                    'organization_id' => $organization->id,
                    'event_type' => $eventPayload['event_type'],
                    'aggregate_type' => 'hold',
                    'aggregate_id' => $holdId,
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
                    'action' => 'hold.created',
                    'object_type' => 'hold',
                    'object_id' => $holdId,
                    'after_values' => json_encode([
                        'status' => 'ACTIVE',
                        'boat_id' => $input['boat_id'],
                        'business_start' => $businessStart->format('Y-m-d\TH:i:s\Z'),
                        'business_end' => $businessEnd->format('Y-m-d\TH:i:s\Z'),
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
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'allocations_no_active_overlap')) {
                return $this->error('SLOT_UNAVAILABLE', 'The requested slot is unavailable.', 409);
            }

            throw $exception;
        }
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
        $operation = 'releaseHold:'.$id;
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
            ->where('id', $id)
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
                return $this->error('INVALID_TRANSITION', 'Only an active HOLD can be released.', 409);
            }

            $now = now()->utc();
            DB::table('holds')->where('id', $hold->id)->update([
                'status' => 'RELEASED',
                'updated_at' => $now,
            ]);
            DB::table('allocations')->where('hold_id', $hold->id)->where('status', 'ACTIVE')->update([
                'status' => 'RELEASED',
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
                'hold_id' => $hold->id,
                'external_reference' => $input['external_reference'],
                'status' => 'RELEASED',
                'code' => 'HOLD_RELEASED',
                'inventory_revision' => $revision,
                'occurred_at' => $occurredAt,
                'business_timezone' => $organization->timezone,
            ];
            $eventPayload = [
                'event_id' => (string) Str::uuid(),
                'event_type' => 'inventory.revision.changed.v1',
                'event_version' => 1,
                'occurred_at' => $occurredAt,
                'organization_id' => $organization->id,
                'aggregate_type' => 'inventory',
                'aggregate_id' => $organization->id,
                'inventory_revision' => $revision,
                'external_reference' => $input['external_reference'],
                'status' => 'RELEASED',
            ];
            DB::table('outbox_events')->insert([
                'event_id' => $eventPayload['event_id'],
                'organization_id' => $organization->id,
                'event_type' => $eventPayload['event_type'],
                'aggregate_type' => 'inventory',
                'aggregate_id' => $organization->id,
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
                'action' => 'hold.released',
                'object_type' => 'hold',
                'object_id' => $hold->id,
                'before_values' => json_encode(['status' => 'ACTIVE'], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['status' => 'RELEASED'], JSON_THROW_ON_ERROR),
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

            if (CarbonImmutable::parse($lockedHold->expires_at)->utc()->lessThanOrEqualTo(now()->utc())) {
                return $this->expireHold($request, $organization, $lockedHold, $idempotencyKey, $operation, $requestHash);
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
                'hold_id' => $hold->id,
                'boat_id' => $hold->boat_id,
                'trip_template_id' => $hold->trip_template_id,
                'external_reference' => $input['external_reference'],
                'status' => 'CONFIRMED',
                'business_start' => $hold->business_start,
                'business_end' => $hold->business_end,
                'allocation_id' => $hold->allocation_id,
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
                    'hold_id' => $hold->id,
                    'trip_id' => $tripId,
                    'rate_snapshot_id' => $rateSnapshotId,
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
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
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

        $businessStart = CarbonImmutable::parse($input['starts_at'])->utc();
        $businessEnd = CarbonImmutable::parse($input['ends_at'])->utc();
        $occupiedStart = $businessStart->subMinutes($boat->buffer_before_minutes);
        $occupiedEnd = $businessEnd->addMinutes($boat->buffer_after_minutes);

        try {
            return DB::transaction(function () use (
                $request,
                $organization,
                $booking,
                $input,
                $idempotencyKey,
                $operation,
                $requestHash,
                $businessStart,
                $businessEnd,
                $occupiedStart,
                $occupiedEnd,
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

                $overlapExists = DB::table('allocations')
                    ->where('organization_id', $organization->id)
                    ->where('boat_id', $input['boat_id'])
                    ->where('status', 'ACTIVE')
                    ->where('id', '!=', $allocation->id)
                    ->where('occupied_start', '<', $occupiedEnd)
                    ->where('occupied_end', '>', $occupiedStart)
                    ->lockForUpdate()
                    ->exists();

                if ($overlapExists) {
                    return $this->error('SLOT_UNAVAILABLE', 'The requested slot is unavailable.', 409);
                }

                $now = now()->utc();
                DB::table('allocations')->where('id', $allocation->id)->update([
                    'boat_id' => $input['boat_id'],
                    'business_start' => $businessStart,
                    'business_end' => $businessEnd,
                    'occupied_start' => $occupiedStart,
                    'occupied_end' => $occupiedEnd,
                    'updated_at' => $now,
                ]);
                DB::table('bookings')->where('id', $booking->id)->update([
                    'boat_id' => $input['boat_id'],
                    'trip_template_id' => $input['trip_template_id'],
                    'business_start' => $businessStart,
                    'business_end' => $businessEnd,
                    'updated_at' => $now,
                ]);
                DB::table('trips')->where('booking_id', $booking->id)->update([
                    'boat_id' => $input['boat_id'],
                    'trip_template_id' => $input['trip_template_id'],
                    'planned_start' => $businessStart,
                    'planned_end' => $businessEnd,
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
                        'business_start' => $lockedBooking->business_start,
                        'business_end' => $lockedBooking->business_end,
                    ], JSON_THROW_ON_ERROR),
                    'after_values' => json_encode([
                        'boat_id' => $input['boat_id'],
                        'business_start' => $businessStart->format('Y-m-d\TH:i:s\Z'),
                        'business_end' => $businessEnd->format('Y-m-d\TH:i:s\Z'),
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

    private function expireHold(
        Request $request,
        object $organization,
        object $hold,
        string $idempotencyKey,
        string $operation,
        string $requestHash,
    ): JsonResponse {
        $now = now()->utc();
        DB::table('holds')->where('id', $hold->id)->update([
            'status' => 'EXPIRED',
            'updated_at' => $now,
        ]);
        DB::table('allocations')->where('hold_id', $hold->id)->where('status', 'ACTIVE')->update([
            'status' => 'EXPIRED',
            'updated_at' => $now,
        ]);
        DB::table('organizations')->where('id', $organization->id)->increment('inventory_revision');
        $revision = (int) DB::table('organizations')->where('id', $organization->id)->value('inventory_revision');
        $occurredAt = $now->format('Y-m-d\TH:i:s\Z');
        $eventPayload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'hold.expired.v1',
            'event_version' => 1,
            'occurred_at' => $occurredAt,
            'organization_id' => $organization->id,
            'aggregate_type' => 'hold',
            'aggregate_id' => $hold->id,
            'inventory_revision' => $revision,
            'external_reference' => $hold->external_reference,
            'status' => 'EXPIRED',
        ];
        DB::table('outbox_events')->insert([
            'event_id' => $eventPayload['event_id'],
            'organization_id' => $organization->id,
            'event_type' => $eventPayload['event_type'],
            'aggregate_type' => 'hold',
            'aggregate_id' => $hold->id,
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
            'action' => 'hold.expired',
            'object_type' => 'hold',
            'object_id' => $hold->id,
            'before_values' => json_encode(['status' => 'ACTIVE'], JSON_THROW_ON_ERROR),
            'after_values' => json_encode(['status' => 'EXPIRED'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $payload = [
            'request_id' => (string) Str::uuid(),
            'code' => 'HOLD_EXPIRED',
            'retryable' => false,
            'manual_action_required' => true,
            'message' => 'The HOLD has expired.',
        ];
        DB::table('idempotency_keys')->insert([
            'organization_id' => $organization->id,
            'operation' => $operation,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'response_status' => 409,
            'response_body' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json($payload, 409);
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
