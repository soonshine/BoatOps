<?php

namespace App\Application\Trips;

use App\Application\Holds\HoldActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReturnTripAction
{
    use TripActionSupport;

    /** @param array<string, mixed> $input */
    public function execute(int $organizationId, int $tripId, array $input, string $idempotencyKey, HoldActor $actor): TripActionResult
    {
        $operation = 'returnTrip:'.$tripId;
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
            if ($trip->status !== 'DEPARTED') {
                return $this->error('INVALID_TRANSITION', 'Only a departed trip can return.', 409);
            }

            $returnedAt = CarbonImmutable::parse($input['returned_at'])->utc();
            if (! $trip->actual_departed_at || $returnedAt->lessThan(CarbonImmutable::parse($trip->actual_departed_at)->utc())) {
                return $this->error('VALIDATION_FAILED', 'Return time cannot be before departure time.', 422);
            }
            $now = now()->utc();
            if ($returnedAt->greaterThan($now)) {
                return $this->error('VALIDATION_FAILED', 'Return time cannot be in the future.', 422);
            }

            DB::table('trips')->where('organization_id', $organization->id)->where('id', $trip->id)->update([
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
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
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

            return new TripActionResult(200, $payload, true);
        }, 3);
    }
}
