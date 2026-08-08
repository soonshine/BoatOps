<?php

namespace App\Http\Controllers\Api\Internal\V1;

use App\Application\Blocks\CreateBlockAction;
use App\Application\Blocks\ReleaseBlockAction;
use App\Application\Holds\HoldActor;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsCommandController extends Controller
{
    public function __construct(
        private readonly CreateBlockAction $createBlockAction,
        private readonly ReleaseBlockAction $releaseBlockAction,
    ) {}

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
        $result = $this->createBlockAction->execute(
            (int) $organization->id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return response()->json($result->payload, $result->status);
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
        $result = $this->releaseBlockAction->execute(
            (int) $organization->id,
            $id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return response()->json($result->payload, $result->status);
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
