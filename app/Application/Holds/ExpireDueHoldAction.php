<?php

namespace App\Application\Holds;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpireDueHoldAction
{
    use HoldActionSupport;

    public function execute(int $holdId, CarbonImmutable $asOf, HoldActor $actor, ?int $trustedOrganizationId = null, ?HoldIdempotencyContext $idempotency = null): HoldActionResult
    {
        $candidateQuery = DB::table('holds')->where('id', $holdId);
        if ($trustedOrganizationId !== null) {
            $candidateQuery->where('organization_id', $trustedOrganizationId);
        }
        $candidate = $candidateQuery->first();
        if (! $candidate) {
            return $trustedOrganizationId === null
                ? $this->notExpired()
                : $this->error('AUTHORIZATION_FAILED', 'The requested HOLD is not accessible.', 403);
        }

        return DB::transaction(function () use ($candidate, $holdId, $asOf, $actor, $trustedOrganizationId, $idempotency): HoldActionResult {
            DB::table('organizations')->where('id', $candidate->organization_id)->lockForUpdate()->first();
            $holdQuery = DB::table('holds')->where('id', $holdId);
            if ($trustedOrganizationId !== null) {
                $holdQuery->where('organization_id', $trustedOrganizationId);
            }
            $hold = $holdQuery->lockForUpdate()->first();
            if (! $hold) {
                return $trustedOrganizationId === null
                    ? $this->notExpired()
                    : $this->error('AUTHORIZATION_FAILED', 'The requested HOLD is not accessible.', 403);
            }
            if ($hold->status !== 'ACTIVE' || CarbonImmutable::parse($hold->expires_at)->utc()->greaterThan($asOf)) {
                return $this->notExpired();
            }

            $now = $asOf->utc();
            DB::table('holds')->where('id', $hold->id)->update(['status' => 'EXPIRED', 'updated_at' => $now]);
            DB::table('allocations')->where('hold_id', $hold->id)->where('status', 'ACTIVE')
                ->update(['status' => 'EXPIRED', 'updated_at' => $now]);
            DB::table('organizations')->where('id', $hold->organization_id)->increment('inventory_revision');
            $revision = (int) DB::table('organizations')->where('id', $hold->organization_id)->value('inventory_revision');
            $occurredAt = $now->format('Y-m-d\TH:i:s\Z');
            $eventPayload = [
                'event_id' => (string) Str::uuid(),
                'event_type' => 'hold.expired.v1',
                'event_version' => 1,
                'occurred_at' => $occurredAt,
                'organization_id' => $hold->organization_id,
                'aggregate_type' => 'hold',
                'aggregate_id' => $hold->id,
                'inventory_revision' => $revision,
                'external_reference' => $hold->external_reference,
                'status' => 'EXPIRED',
            ];
            DB::table('outbox_events')->insert([
                'event_id' => $eventPayload['event_id'],
                'organization_id' => $hold->organization_id,
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
                'organization_id' => $hold->organization_id,
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'action' => 'hold.expired',
                'object_type' => 'hold',
                'object_id' => $hold->id,
                'before_values' => json_encode(['status' => 'ACTIVE'], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['status' => 'EXPIRED'], JSON_THROW_ON_ERROR),
                'reason' => $actor->type === 'system' ? 'HOLD_TTL_ELAPSED' : null,
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
            if ($idempotency !== null) {
                DB::table('idempotency_keys')->insert([
                    'organization_id' => $hold->organization_id,
                    'operation' => $idempotency->operation,
                    'idempotency_key' => $idempotency->key,
                    'request_hash' => $idempotency->requestHash,
                    'response_status' => 409,
                    'response_body' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return new HoldActionResult(409, $payload, true);
        }, 3);
    }

    private function notExpired(): HoldActionResult
    {
        return new HoldActionResult(200, ['code' => 'HOLD_NOT_EXPIRED']);
    }
}
