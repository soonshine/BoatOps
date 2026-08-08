<?php

namespace App\Application\Blocks;

use App\Application\Holds\HoldActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReleaseBlockAction
{
    use BlockActionSupport;

    /** @param array<string, mixed> $input */
    public function execute(int $organizationId, int $blockId, array $input, string $idempotencyKey, HoldActor $actor): BlockActionResult
    {
        $operation = 'releaseBlock:'.$blockId;
        $requestHash = $this->canonicalHash($input);
        $existing = $this->replay($organizationId, $operation, $idempotencyKey, $requestHash);

        if ($existing) {
            return $existing;
        }

        $organization = DB::table('organizations')->find($organizationId);
        $block = DB::table('blocks')->where('organization_id', $organizationId)->where('id', $blockId)->first();

        if (! $organization || ! $block) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested block is not accessible.', 403);
        }

        if (! hash_equals($block->external_reference, $input['external_reference'])) {
            return $this->error('VALIDATION_FAILED', 'The external reference does not match the block.', 422);
        }

        return DB::transaction(function () use ($organization, $blockId, $input, $idempotencyKey, $actor, $operation, $requestHash): BlockActionResult {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replay((int) $organization->id, $operation, $idempotencyKey, $requestHash);

            if ($replayed) {
                return $replayed;
            }

            $lockedBlock = DB::table('blocks')->where('organization_id', $organization->id)
                ->where('id', $blockId)->lockForUpdate()->first();

            if (! $lockedBlock) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested block is not accessible.', 403);
            }

            if (! hash_equals($lockedBlock->external_reference, $input['external_reference'])) {
                return $this->error('VALIDATION_FAILED', 'The external reference does not match the block.', 422);
            }

            if ($lockedBlock->status !== 'ACTIVE') {
                return $this->error('INVALID_TRANSITION', 'Only an active block can be released.', 409);
            }

            $now = now()->utc();
            DB::table('blocks')->where('organization_id', $organization->id)->where('id', $lockedBlock->id)->update([
                'status' => 'RELEASED',
                'released_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('allocations')->where('organization_id', $organization->id)
                ->where('block_id', $lockedBlock->id)->where('status', 'ACTIVE')->update([
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
                'block_id' => $lockedBlock->id,
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
                'aggregate_id' => $lockedBlock->id,
                'boat_id' => $lockedBlock->boat_id,
                'inventory_revision' => $revision,
                'external_reference' => $lockedBlock->external_reference,
                'status' => 'RELEASED',
            ];
            DB::table('outbox_events')->insert([
                'event_id' => $eventPayload['event_id'],
                'organization_id' => $organization->id,
                'event_type' => $eventPayload['event_type'],
                'aggregate_type' => 'block',
                'aggregate_id' => $lockedBlock->id,
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
                'action' => 'resource.unblocked',
                'object_type' => 'block',
                'object_id' => $lockedBlock->id,
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

            return new BlockActionResult(200, $payload, true);
        }, 3);
    }
}
