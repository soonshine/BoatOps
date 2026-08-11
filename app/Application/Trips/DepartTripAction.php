<?php

namespace App\Application\Trips;

use App\Application\Holds\HoldActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DepartTripAction
{
    use TripActionSupport;

    /** @param array<string, mixed> $input */
    public function execute(int $organizationId, int $tripId, array $input, string $idempotencyKey, HoldActor $actor): TripActionResult
    {
        $operation = 'departTrip:'.$tripId;
        $requestHash = $this->canonicalHash($input);
        $existing = $this->replay($organizationId, $operation, $idempotencyKey, $requestHash);
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($organizationId, $tripId, $input, $idempotencyKey, $actor, $operation, $requestHash): TripActionResult {
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
            if ($departedAt->greaterThan($now)) {
                return $this->error('VALIDATION_FAILED', 'Departure time cannot be in the future.', 422);
            }

            DB::table('trips')->where('organization_id', $organization->id)->where('id', $trip->id)->update([
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
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
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

            return new TripActionResult(200, $payload, true);
        }, 3);
    }
}
