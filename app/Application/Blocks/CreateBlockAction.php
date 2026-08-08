<?php

namespace App\Application\Blocks;

use App\Application\Holds\HoldActor;
use App\Exceptions\SlotCatalogException;
use App\Services\SlotCatalog\SlotAvailabilityService;
use App\Services\SlotCatalog\SlotIntervalResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateBlockAction
{
    use BlockActionSupport;

    public function __construct(
        private readonly SlotIntervalResolver $slotResolver,
        private readonly SlotAvailabilityService $slotAvailability,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(int $organizationId, array $input, string $idempotencyKey, HoldActor $actor): BlockActionResult
    {
        $operation = 'createBlock';
        $requestHash = $this->canonicalHash($input);
        $existing = $this->replay($organizationId, $operation, $idempotencyKey, $requestHash);

        if ($existing) {
            return $existing;
        }

        $organization = DB::table('organizations')->find($organizationId);
        $boat = DB::table('boats')->where('organization_id', $organizationId)
            ->where('status', 'ACTIVE')->find($input['boat_id']);

        if (! $organization || ! $boat) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested inventory resource is not accessible.', 403);
        }

        try {
            $slot = $this->slotResolver->resolve($organization, $boat, $input, applyBoatBuffers: false);
        } catch (SlotCatalogException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus, $exception->manualActionRequired);
        }

        try {
            return DB::transaction(function () use ($organization, $input, $idempotencyKey, $actor, $operation, $requestHash, $slot): BlockActionResult {
                DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
                $replayed = $this->replay((int) $organization->id, $operation, $idempotencyKey, $requestHash);

                if ($replayed) {
                    return $replayed;
                }

                if (DB::table('blocks')->where('organization_id', $organization->id)
                    ->where('external_reference', $input['external_reference'])->exists()) {
                    return $this->error('DUPLICATE_EXTERNAL_REFERENCE', 'The external reference already exists.', 409, true);
                }

                $decision = $this->slotAvailability->decide(
                    (int) $organization->id,
                    (int) $input['boat_id'],
                    $slot,
                    lockForUpdate: true,
                );

                if (! $decision['available']) {
                    return $this->error($decision['code'], $decision['message'], 409);
                }

                $now = now()->utc();
                $blockId = DB::table('blocks')->insertGetId([
                    'organization_id' => $organization->id,
                    'boat_id' => $input['boat_id'],
                    'external_reference' => $input['external_reference'],
                    'status' => 'ACTIVE',
                    'reason_code' => $input['reason_code'],
                    'reason' => $input['reason'] ?? null,
                    'business_start' => $slot->serviceStart,
                    'business_end' => $slot->serviceEnd,
                    'occupied_start' => $slot->occupiedStart,
                    'occupied_end' => $slot->occupiedEnd,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $allocationId = DB::table('allocations')->insertGetId([
                    'organization_id' => $organization->id,
                    'boat_id' => $input['boat_id'],
                    'allocation_type' => 'BLOCKED',
                    'status' => 'ACTIVE',
                    ...$slot->databaseValues(),
                    'block_id' => $blockId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('blocks')->where('organization_id', $organization->id)->where('id', $blockId)
                    ->update(['allocation_id' => $allocationId]);
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
                    'occupied_start' => $slot->occupiedStart->format('Y-m-d\TH:i:s\Z'),
                    'occupied_end' => $slot->occupiedEnd->format('Y-m-d\TH:i:s\Z'),
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
                    'actor_type' => $actor->type,
                    'actor_id' => $actor->id,
                    'action' => 'resource.blocked',
                    'object_type' => 'block',
                    'object_id' => $blockId,
                    'after_values' => json_encode([
                        'status' => 'ACTIVE',
                        'boat_id' => $input['boat_id'],
                        'reason_code' => $input['reason_code'],
                        'reason' => $input['reason'] ?? null,
                        'business_start' => $slot->serviceStart->format('Y-m-d\TH:i:s\Z'),
                        'business_end' => $slot->serviceEnd->format('Y-m-d\TH:i:s\Z'),
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

                return new BlockActionResult(201, $payload, true);
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'allocations_no_active_overlap')) {
                return $this->error('SLOT_UNAVAILABLE', 'The requested slot is unavailable.', 409);
            }

            throw $exception;
        }
    }
}
