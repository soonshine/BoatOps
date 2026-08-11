<?php

namespace App\Application\Trips;

use App\Application\Holds\HoldActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PrepareTripAction
{
    use TripActionSupport;

    /** @param array<string, mixed> $input */
    public function execute(int $organizationId, int $tripId, array $input, string $idempotencyKey, HoldActor $actor): TripActionResult
    {
        $operation = 'prepareTrip:'.$tripId;
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
                return $this->error('INVALID_TRANSITION', 'Only a planned trip can be prepared.', 409);
            }

            $now = now()->utc();
            DB::table('crew_assignments')
                ->where('organization_id', $organization->id)
                ->where('trip_id', $trip->id)
                ->delete();
            DB::table('trip_checklists')
                ->where('organization_id', $organization->id)
                ->where('trip_id', $trip->id)
                ->delete();

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
                    DB::table('crew_members')
                        ->where('organization_id', $organization->id)
                        ->where('id', $crewMember->id)
                        ->update($crewValues);
                    $crewMemberId = (int) $crewMember->id;
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
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
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

            return new TripActionResult(200, $payload, true);
        }, 3);
    }
}
