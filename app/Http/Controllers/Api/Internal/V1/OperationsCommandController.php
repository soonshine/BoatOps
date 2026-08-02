<?php

namespace App\Http\Controllers\Api\Internal\V1;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsCommandController extends Controller
{
    public function createBlock(Request $request): JsonResponse
    {
        if (! in_array('operations.write', $request->attributes->get('api_client_scopes', []), true)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'boat_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason_code' => ['required', 'in:MAINTENANCE,WEATHER,OWNER_USE,MANUAL'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = 'createBlock';
        $requestHash = hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));
        $businessStart = CarbonImmutable::parse($input['starts_at'])->utc();
        $businessEnd = CarbonImmutable::parse($input['ends_at'])->utc();

        try {
            return DB::transaction(function () use ($request, $organization, $input, $idempotencyKey, $operation, $requestHash, $businessStart, $businessEnd): JsonResponse {
                DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
                $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

                if ($replayed) {
                    return $replayed;
                }

                $boatExists = DB::table('boats')
                    ->where('organization_id', $organization->id)
                    ->where('status', 'ACTIVE')
                    ->where('id', $input['boat_id'])
                    ->exists();

                if (! $boatExists) {
                    return $this->error('AUTHORIZATION_FAILED', 'The requested inventory resource is not accessible.', 403);
                }

                if (DB::table('blocks')->where('organization_id', $organization->id)->where('external_reference', $input['external_reference'])->exists()) {
                    return $this->error('DUPLICATE_EXTERNAL_REFERENCE', 'The external reference already exists.', 409, true);
                }

                $overlapExists = DB::table('allocations')
                    ->where('organization_id', $organization->id)
                    ->where('boat_id', $input['boat_id'])
                    ->where('status', 'ACTIVE')
                    ->where('occupied_start', '<', $businessEnd)
                    ->where('occupied_end', '>', $businessStart)
                    ->lockForUpdate()
                    ->exists();

                if ($overlapExists) {
                    return $this->error('SLOT_UNAVAILABLE', 'The requested slot is unavailable.', 409);
                }

                $now = now()->utc();
                $blockId = DB::table('blocks')->insertGetId([
                    'organization_id' => $organization->id,
                    'boat_id' => $input['boat_id'],
                    'external_reference' => $input['external_reference'],
                    'status' => 'ACTIVE',
                    'reason_code' => $input['reason_code'],
                    'reason' => $input['reason'] ?? null,
                    'business_start' => $businessStart,
                    'business_end' => $businessEnd,
                    'occupied_start' => $businessStart,
                    'occupied_end' => $businessEnd,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $allocationId = DB::table('allocations')->insertGetId([
                    'organization_id' => $organization->id,
                    'boat_id' => $input['boat_id'],
                    'allocation_type' => 'BLOCKED',
                    'status' => 'ACTIVE',
                    'business_start' => $businessStart,
                    'business_end' => $businessEnd,
                    'occupied_start' => $businessStart,
                    'occupied_end' => $businessEnd,
                    'block_id' => $blockId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('blocks')->where('id', $blockId)->update(['allocation_id' => $allocationId]);
                DB::table('organizations')->where('id', $organization->id)->increment('inventory_revision');
                $revision = (int) DB::table('organizations')->where('id', $organization->id)->value('inventory_revision');
                $occurredAt = $now->format('Y-m-d\TH:i:s\Z');
                $payload = [
                    'request_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'organization_id' => $organization->id,
                    'block_id' => $blockId,
                    'external_reference' => $input['external_reference'],
                    'status' => 'ACTIVE',
                    'code' => 'RESOURCE_BLOCKED',
                    'inventory_revision' => $revision,
                    'occurred_at' => $occurredAt,
                    'business_timezone' => $organization->timezone,
                ];
                $eventPayload = [
                    'event_id' => (string) Str::uuid(),
                    'event_type' => 'resource.blocked.v1',
                    'event_version' => 1,
                    'occurred_at' => $occurredAt,
                    'organization_id' => $organization->id,
                    'aggregate_type' => 'block',
                    'aggregate_id' => $blockId,
                    'boat_id' => $input['boat_id'],
                    'inventory_revision' => $revision,
                    'external_reference' => $input['external_reference'],
                    'status' => 'ACTIVE',
                    'occupied_start' => $businessStart->format('Y-m-d\TH:i:s\Z'),
                    'occupied_end' => $businessEnd->format('Y-m-d\TH:i:s\Z'),
                ];
                DB::table('outbox_events')->insert([
                    'event_id' => $eventPayload['event_id'],
                    'organization_id' => $organization->id,
                    'event_type' => $eventPayload['event_type'],
                    'aggregate_type' => 'block',
                    'aggregate_id' => $blockId,
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
                    'action' => 'resource.blocked',
                    'object_type' => 'block',
                    'object_id' => $blockId,
                    'after_values' => json_encode([
                        'status' => 'ACTIVE',
                        'boat_id' => $input['boat_id'],
                        'reason_code' => $input['reason_code'],
                        'reason' => $input['reason'] ?? null,
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

    public function prepareTrip(Request $request, int $id): JsonResponse
    {
        if (! in_array('operations.write', $request->attributes->get('api_client_scopes', []), true)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'crew' => ['required', 'array', 'min:1', 'max:20'],
            'crew.*.external_reference' => ['required', 'string', 'max:255', 'distinct'],
            'crew.*.display_name' => ['required', 'string', 'max:255'],
            'crew.*.role' => ['required', 'string', 'max:100'],
            'crew.*.duty' => ['required', 'string', 'max:100'],
            'checklist' => ['required', 'array', 'min:1', 'max:100'],
            'checklist.*.code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9_-]+$/', 'distinct'],
            'checklist.*.label' => ['required', 'string', 'max:255'],
            'checklist.*.required' => ['required', 'boolean'],
            'checklist.*.completed' => ['required', 'boolean'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = 'prepareTrip:'.$id;
        $requestHash = hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $organization, $id, $input, $idempotencyKey, $operation, $requestHash): JsonResponse {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

            if ($replayed) {
                return $replayed;
            }

            $trip = DB::table('trips')->where('organization_id', $organization->id)->where('id', $id)->lockForUpdate()->first();

            if (! $trip) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested trip is not accessible.', 403);
            }

            if ($trip->status !== 'PLANNED') {
                return $this->error('INVALID_TRANSITION', 'Only a planned trip can be prepared.', 409);
            }

            $now = now()->utc();
            DB::table('crew_assignments')->where('trip_id', $trip->id)->delete();
            DB::table('trip_checklists')->where('trip_id', $trip->id)->delete();

            foreach ($input['crew'] as $crewInput) {
                $crewMember = DB::table('crew_members')
                    ->where('organization_id', $organization->id)
                    ->where('external_reference', $crewInput['external_reference'])
                    ->first();
                $crewValues = [
                    'display_name' => $crewInput['display_name'],
                    'role' => $crewInput['role'],
                    'status' => 'ACTIVE',
                    'updated_at' => $now,
                ];

                if ($crewMember) {
                    DB::table('crew_members')->where('id', $crewMember->id)->update($crewValues);
                    $crewMemberId = $crewMember->id;
                } else {
                    $crewMemberId = DB::table('crew_members')->insertGetId($crewValues + [
                        'organization_id' => $organization->id,
                        'external_reference' => $crewInput['external_reference'],
                        'created_at' => $now,
                    ]);
                }

                DB::table('crew_assignments')->insert([
                    'organization_id' => $organization->id,
                    'trip_id' => $trip->id,
                    'crew_member_id' => $crewMemberId,
                    'duty' => $crewInput['duty'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($input['checklist'] as $checkInput) {
                DB::table('trip_checklists')->insert([
                    'organization_id' => $organization->id,
                    'trip_id' => $trip->id,
                    'code' => $checkInput['code'],
                    'label' => $checkInput['label'],
                    'required' => $checkInput['required'],
                    'completed' => $checkInput['completed'],
                    'completed_at' => $checkInput['completed'] ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $requiredCount = collect($input['checklist'])->where('required', true)->count();
            $incompleteRequiredCount = collect($input['checklist'])->where('required', true)->where('completed', false)->count();
            $payload = [
                'request_id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'organization_id' => $organization->id,
                'trip_id' => $trip->id,
                'status' => 'PLANNED',
                'code' => 'TRIP_PREPARED',
                'crew_count' => count($input['crew']),
                'required_checklist_count' => $requiredCount,
                'incomplete_required_count' => $incompleteRequiredCount,
                'occurred_at' => $now->format('Y-m-d\TH:i:s\Z'),
                'business_timezone' => $organization->timezone,
            ];
            DB::table('audit_logs')->insert([
                'organization_id' => $organization->id,
                'actor_type' => 'api_client',
                'actor_id' => $request->attributes->get('api_client_id'),
                'action' => 'trip.prepared',
                'object_type' => 'trip',
                'object_id' => $trip->id,
                'after_values' => json_encode([
                    'crew_count' => count($input['crew']),
                    'required_checklist_count' => $requiredCount,
                    'incomplete_required_count' => $incompleteRequiredCount,
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
    }

    public function departTrip(Request $request, int $id): JsonResponse
    {
        if (! in_array('operations.write', $request->attributes->get('api_client_scopes', []), true)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'departed_at' => ['required', 'date'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = 'departTrip:'.$id;
        $requestHash = hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $organization, $id, $input, $idempotencyKey, $operation, $requestHash): JsonResponse {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

            if ($replayed) {
                return $replayed;
            }

            $trip = DB::table('trips')->where('organization_id', $organization->id)->where('id', $id)->lockForUpdate()->first();

            if (! $trip) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested trip is not accessible.', 403);
            }

            if ($trip->status !== 'PLANNED') {
                return $this->error('INVALID_TRANSITION', 'Only a planned trip can depart.', 409);
            }

            $crewCount = DB::table('crew_assignments')->where('organization_id', $organization->id)->where('trip_id', $trip->id)->count();
            $requiredChecklistCount = DB::table('trip_checklists')->where('organization_id', $organization->id)->where('trip_id', $trip->id)->where('required', true)->count();
            $incompleteRequiredCount = DB::table('trip_checklists')->where('organization_id', $organization->id)->where('trip_id', $trip->id)->where('required', true)->where('completed', false)->count();

            if ($crewCount < 1 || $requiredChecklistCount < 1 || $incompleteRequiredCount > 0) {
                return $this->error('TRIP_NOT_READY', 'Crew and all required checklist items must be complete before departure.', 409, true);
            }

            $departedAt = CarbonImmutable::parse($input['departed_at'])->utc();
            $now = now()->utc();
            DB::table('trips')->where('id', $trip->id)->update([
                'status' => 'DEPARTED',
                'actual_departed_at' => $departedAt,
                'updated_at' => $now,
            ]);
            $payload = [
                'request_id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'organization_id' => $organization->id,
                'trip_id' => $trip->id,
                'status' => 'DEPARTED',
                'code' => 'TRIP_DEPARTED',
                'departed_at' => $departedAt->format('Y-m-d\TH:i:s\Z'),
                'occurred_at' => $now->format('Y-m-d\TH:i:s\Z'),
                'business_timezone' => $organization->timezone,
            ];
            DB::table('audit_logs')->insert([
                'organization_id' => $organization->id,
                'actor_type' => 'api_client',
                'actor_id' => $request->attributes->get('api_client_id'),
                'action' => 'trip.departed',
                'object_type' => 'trip',
                'object_id' => $trip->id,
                'before_values' => json_encode(['status' => 'PLANNED'], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['status' => 'DEPARTED', 'actual_departed_at' => $payload['departed_at']], JSON_THROW_ON_ERROR),
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

    public function returnTrip(Request $request, int $id): JsonResponse
    {
        if (! in_array('operations.write', $request->attributes->get('api_client_scopes', []), true)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate(['returned_at' => ['required', 'date']]);
        $organization = $request->attributes->get('organization');
        $operation = 'returnTrip:'.$id;
        $requestHash = hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $organization, $id, $input, $idempotencyKey, $operation, $requestHash): JsonResponse {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

            if ($replayed) {
                return $replayed;
            }

            $trip = DB::table('trips')->where('organization_id', $organization->id)->where('id', $id)->lockForUpdate()->first();

            if (! $trip) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested trip is not accessible.', 403);
            }

            if ($trip->status !== 'DEPARTED') {
                return $this->error('INVALID_TRANSITION', 'Only a departed trip can return.', 409);
            }

            $returnedAt = CarbonImmutable::parse($input['returned_at'])->utc();

            if (! $trip->actual_departed_at || $returnedAt->lessThan(CarbonImmutable::parse($trip->actual_departed_at)->utc())) {
                return $this->error('VALIDATION_FAILED', 'Return time cannot be before departure time.', 422);
            }

            $now = now()->utc();
            DB::table('trips')->where('id', $trip->id)->update([
                'status' => 'RETURNED',
                'actual_returned_at' => $returnedAt,
                'updated_at' => $now,
            ]);
            $payload = [
                'request_id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'organization_id' => $organization->id,
                'trip_id' => $trip->id,
                'status' => 'RETURNED',
                'code' => 'TRIP_RETURNED',
                'returned_at' => $returnedAt->format('Y-m-d\TH:i:s\Z'),
                'occurred_at' => $now->format('Y-m-d\TH:i:s\Z'),
                'business_timezone' => $organization->timezone,
            ];
            DB::table('audit_logs')->insert([
                'organization_id' => $organization->id,
                'actor_type' => 'api_client',
                'actor_id' => $request->attributes->get('api_client_id'),
                'action' => 'trip.returned',
                'object_type' => 'trip',
                'object_id' => $trip->id,
                'before_values' => json_encode(['status' => 'DEPARTED'], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['status' => 'RETURNED', 'actual_returned_at' => $payload['returned_at']], JSON_THROW_ON_ERROR),
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

    public function completeTrip(Request $request, int $id): JsonResponse
    {
        if (! in_array('operations.write', $request->attributes->get('api_client_scopes', []), true)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $organization = $request->attributes->get('organization');
        $operation = 'completeTrip:'.$id;
        $requestHash = hash('sha256', '{}');

        return DB::transaction(function () use ($request, $organization, $id, $idempotencyKey, $operation, $requestHash): JsonResponse {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

            if ($replayed) {
                return $replayed;
            }

            $trip = DB::table('trips')->where('organization_id', $organization->id)->where('id', $id)->lockForUpdate()->first();

            if (! $trip) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested trip is not accessible.', 403);
            }

            if ($trip->status !== 'RETURNED') {
                return $this->error('INVALID_TRANSITION', 'Only a returned trip can be completed.', 409);
            }

            $booking = DB::table('bookings')->where('organization_id', $organization->id)->where('id', $trip->booking_id)->lockForUpdate()->first();
            $allocation = $booking
                ? DB::table('allocations')->where('organization_id', $organization->id)->where('id', $booking->allocation_id)->lockForUpdate()->first()
                : null;

            if (! $booking || $booking->status !== 'CONFIRMED' || ! $allocation || $allocation->status !== 'ACTIVE') {
                return $this->error('INVALID_TRANSITION', 'The trip booking must still own active inventory.', 409, true);
            }

            $now = now()->utc();
            DB::table('trips')->where('id', $trip->id)->update([
                'status' => 'COMPLETED',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('bookings')->where('id', $booking->id)->update([
                'status' => 'COMPLETED',
                'updated_at' => $now,
            ]);
            DB::table('allocations')->where('id', $allocation->id)->update([
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
                'actor_type' => 'api_client',
                'actor_id' => $request->attributes->get('api_client_id'),
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

            return response()->json($payload);
        }, 3);
    }

    public function releaseBlock(Request $request, int $id): JsonResponse
    {
        if (! in_array('operations.write', $request->attributes->get('api_client_scopes', []), true)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = 'releaseBlock:'.$id;
        $requestHash = hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $organization, $id, $input, $idempotencyKey, $operation, $requestHash): JsonResponse {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

            if ($replayed) {
                return $replayed;
            }

            $block = DB::table('blocks')
                ->where('organization_id', $organization->id)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $block) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested block is not accessible.', 403);
            }

            if (! hash_equals($block->external_reference, $input['external_reference'])) {
                return $this->error('VALIDATION_FAILED', 'The external reference does not match the block.', 422);
            }

            if ($block->status !== 'ACTIVE') {
                return $this->error('INVALID_TRANSITION', 'Only an active block can be released.', 409);
            }

            $now = now()->utc();
            DB::table('blocks')->where('id', $block->id)->update([
                'status' => 'RELEASED',
                'released_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('allocations')->where('block_id', $block->id)->where('status', 'ACTIVE')->update([
                'status' => 'RELEASED',
                'updated_at' => $now,
            ]);
            DB::table('organizations')->where('id', $organization->id)->increment('inventory_revision');
            $revision = (int) DB::table('organizations')->where('id', $organization->id)->value('inventory_revision');
            $occurredAt = $now->format('Y-m-d\TH:i:s\Z');
            $payload = [
                'request_id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'organization_id' => $organization->id,
                'block_id' => $block->id,
                'external_reference' => $input['external_reference'],
                'status' => 'RELEASED',
                'code' => 'RESOURCE_UNBLOCKED',
                'inventory_revision' => $revision,
                'occurred_at' => $occurredAt,
                'business_timezone' => $organization->timezone,
            ];
            $eventPayload = [
                'event_id' => (string) Str::uuid(),
                'event_type' => 'resource.unblocked.v1',
                'event_version' => 1,
                'occurred_at' => $occurredAt,
                'organization_id' => $organization->id,
                'aggregate_type' => 'block',
                'aggregate_id' => $block->id,
                'boat_id' => $block->boat_id,
                'inventory_revision' => $revision,
                'external_reference' => $block->external_reference,
                'status' => 'RELEASED',
            ];
            DB::table('outbox_events')->insert([
                'event_id' => $eventPayload['event_id'],
                'organization_id' => $organization->id,
                'event_type' => $eventPayload['event_type'],
                'aggregate_type' => 'block',
                'aggregate_id' => $block->id,
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
                'action' => 'resource.unblocked',
                'object_type' => 'block',
                'object_id' => $block->id,
                'before_values' => json_encode(['status' => 'ACTIVE'], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['status' => 'RELEASED'], JSON_THROW_ON_ERROR),
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

    private function replayIdempotency(int $organizationId, string $operation, string $idempotencyKey, string $requestHash): ?JsonResponse
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

        return response()->json(json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR), $existing->response_status);
    }

    private function error(string $code, string $message, int $status, bool $manualActionRequired = false): JsonResponse
    {
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
