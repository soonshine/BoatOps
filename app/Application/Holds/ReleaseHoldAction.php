<?php

namespace App\Application\Holds;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReleaseHoldAction
{
    use HoldActionSupport;

    /** @param array{external_reference: string} $input */
    public function execute(int $organizationId, int $holdId, array $input, string $idempotencyKey, HoldActor $actor): HoldActionResult
    {
        $operation = 'releaseHold:'.$holdId;
        $requestHash = $this->canonicalHash($input);
        $existing = $this->replay($organizationId, $operation, $idempotencyKey, $requestHash);
        if ($existing) {
            return $existing;
        }

        $organization = DB::table('organizations')->find($organizationId);
        $hold = DB::table('holds')->where('organization_id', $organizationId)->where('id', $holdId)->first();
        if (! $organization || ! $hold) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested HOLD is not accessible.', 403);
        }
        if (! hash_equals($hold->external_reference, $input['external_reference'])) {
            return $this->error('VALIDATION_FAILED', 'The external reference does not match the HOLD.', 422);
        }

        return DB::transaction(function () use ($organization, $holdId, $input, $idempotencyKey, $actor, $operation, $requestHash): HoldActionResult {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replay((int) $organization->id, $operation, $idempotencyKey, $requestHash);
            if ($replayed) {
                return $replayed;
            }

            $lockedHold = DB::table('holds')->where('organization_id', $organization->id)
                ->where('id', $holdId)->lockForUpdate()->first();
            if (! $lockedHold) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested HOLD is not accessible.', 403);
            }
            if (! hash_equals($lockedHold->external_reference, $input['external_reference'])) {
                return $this->error('VALIDATION_FAILED', 'The external reference does not match the HOLD.', 422);
            }
            if ($lockedHold->status !== 'ACTIVE') {
                return $this->error('INVALID_TRANSITION', 'Only an active HOLD can be released.', 409);
            }
            $allocation = DB::table('allocations')->where('id', $lockedHold->allocation_id)->lockForUpdate()->first();
            if (! $this->allocationMatchesHold($allocation, $lockedHold)) {
                return $this->inventoryIntegrityError();
            }

            $now = now()->utc();
            DB::table('holds')->where('id', $lockedHold->id)->update(['status' => 'RELEASED', 'updated_at' => $now]);
            DB::table('allocations')->where('id', $allocation->id)->update(['status' => 'RELEASED', 'updated_at' => $now]);
            DB::table('organizations')->where('id', $organization->id)->increment('inventory_revision');
            $revision = (int) DB::table('organizations')->where('id', $organization->id)->value('inventory_revision');
            $occurredAt = $now->format('Y-m-d\TH:i:s\Z');
            $payload = [
                'request_id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'organization_id' => $organization->id,
                'hold_id' => $lockedHold->id,
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
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'action' => 'hold.released',
                'object_type' => 'hold',
                'object_id' => $lockedHold->id,
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

            return new HoldActionResult(200, $payload, true);
        }, 3);
    }
}
