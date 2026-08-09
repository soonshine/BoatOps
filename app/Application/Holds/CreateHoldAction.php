<?php

namespace App\Application\Holds;

use App\Exceptions\SlotCatalogException;
use App\Services\SlotCatalog\SlotAvailabilityService;
use App\Services\SlotCatalog\SlotIntervalResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateHoldAction
{
    use HoldActionSupport;

    public function __construct(private readonly SlotIntervalResolver $slotResolver, private readonly SlotAvailabilityService $slotAvailability) {}

    /** @param array<string, mixed> $input */
    public function execute(int $organizationId, array $input, string $idempotencyKey, HoldActor $actor): HoldActionResult
    {
        $operation = 'createHold';
        $requestHash = $this->canonicalHash($input);
        $existing = $this->replay($organizationId, $operation, $idempotencyKey, $requestHash);
        if ($existing) {
            return $existing;
        }

        try {
            if (! isset($input['expires_at']) || ! is_string($input['expires_at'])) {
                throw new \InvalidArgumentException;
            }
            $expiresAt = CarbonImmutable::parse($input['expires_at'], 'UTC')->utc();
        } catch (\Throwable) {
            return $this->error('VALIDATION_FAILED', 'The request payload is invalid.', 422);
        }
        if (! $expiresAt->greaterThan(CarbonImmutable::now('UTC'))) {
            return $this->error('VALIDATION_FAILED', 'The request payload is invalid.', 422);
        }
        try {
            return DB::transaction(function () use ($organizationId, $input, $idempotencyKey, $actor, $operation, $requestHash, $expiresAt): HoldActionResult {
                $organization = DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();
                if (! $organization) {
                    return $this->error('AUTHORIZATION_FAILED', 'The requested inventory resource is not accessible.', 403);
                }
                $replayed = $this->replay((int) $organization->id, $operation, $idempotencyKey, $requestHash);
                if ($replayed) {
                    return $replayed;
                }
                if (! $expiresAt->greaterThan(CarbonImmutable::now('UTC'))) {
                    return $this->error('VALIDATION_FAILED', 'The request payload is invalid.', 422);
                }
                $boat = DB::table('boats')->where('organization_id', $organization->id)
                    ->where('status', 'ACTIVE')->where('id', $input['boat_id'])->lockForUpdate()->first();
                $template = DB::table('trip_templates')->where('organization_id', $organization->id)
                    ->where('status', 'ACTIVE')->where('id', $input['trip_template_id'])->lockForUpdate()->first();
                if (! $boat || ! $template) {
                    return $this->error('AUTHORIZATION_FAILED', 'The requested inventory resource is not accessible.', 403);
                }
                $this->lockSelectedSlot($organization->id, $boat->id, $input);
                try {
                    $slot = $this->slotResolver->resolve($organization, $boat, $input);
                } catch (SlotCatalogException $exception) {
                    return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus, $exception->manualActionRequired);
                }
                if (! $expiresAt->greaterThan(CarbonImmutable::now('UTC'))) {
                    return $this->error('VALIDATION_FAILED', 'The request payload is invalid.', 422);
                }
                if (DB::table('holds')->where('organization_id', $organization->id)
                    ->where('external_reference', $input['external_reference'])->exists()) {
                    return $this->error('DUPLICATE_EXTERNAL_REFERENCE', 'The external reference already exists.', 409, true);
                }
                $decision = $this->slotAvailability->decide((int) $organization->id, (int) $input['boat_id'], $slot, lockForUpdate: true);
                if (! $decision['available']) {
                    return $this->error($decision['code'], $decision['message'], 409);
                }
                if (! $expiresAt->greaterThan(CarbonImmutable::now('UTC'))) {
                    return $this->error('VALIDATION_FAILED', 'The request payload is invalid.', 422);
                }
                $now = now()->utc();
                $holdId = DB::table('holds')->insertGetId([
                    'organization_id' => $organization->id,
                    'boat_id' => $input['boat_id'],
                    'trip_template_id' => $input['trip_template_id'],
                    'external_reference' => $input['external_reference'],
                    'status' => 'ACTIVE',
                    ...$slot->databaseValues(),
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $allocationId = DB::table('allocations')->insertGetId([
                    'organization_id' => $organization->id,
                    'boat_id' => $input['boat_id'],
                    'allocation_type' => 'HOLD',
                    'status' => 'ACTIVE',
                    ...$slot->databaseValues(),
                    'hold_id' => $holdId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('holds')->where('id', $holdId)->update(['allocation_id' => $allocationId]);
                DB::table('organizations')->where('id', $organization->id)->increment('inventory_revision');
                $revision = (int) DB::table('organizations')->where('id', $organization->id)->value('inventory_revision');
                $occurredAt = $now->format('Y-m-d\TH:i:s\Z');
                $payload = [
                    'request_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'organization_id' => $organization->id,
                    'hold_id' => $holdId,
                    'external_reference' => $input['external_reference'],
                    'status' => 'ACTIVE',
                    'code' => 'HOLD_CREATED',
                    'inventory_revision' => $revision,
                    'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
                    ...$slot->responseValues(),
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
                    'actor_type' => $actor->type,
                    'actor_id' => $actor->id,
                    'action' => 'hold.created',
                    'object_type' => 'hold',
                    'object_id' => $holdId,
                    'after_values' => json_encode([
                        'status' => 'ACTIVE',
                        'boat_id' => $input['boat_id'],
                        'service_date' => $slot->serviceDate,
                        'business_start' => $slot->serviceStart->format('Y-m-d\TH:i:s\Z'),
                        'business_end' => $slot->serviceEnd->format('Y-m-d\TH:i:s\Z'),
                        'slot_offering_id' => $slot->slotOfferingId,
                        'custom_slot_instance_id' => $slot->customSlotInstanceId,
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

                return new HoldActionResult(201, $payload, true);
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'allocations_no_active_overlap')) {
                return $this->error('SLOT_UNAVAILABLE', 'The requested slot is unavailable.', 409);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $input */
    private function lockSelectedSlot(int $organizationId, int $boatId, array $input): void
    {
        $slotId = isset($input['custom_slot_instance_id'])
            ? (int) $input['custom_slot_instance_id']
            : (isset($input['slot_offering_id']) ? (int) $input['slot_offering_id'] : null);
        if ($slotId === null) {
            return;
        }

        $slot = DB::table('slot_offerings')->where('organization_id', $organizationId)
            ->where('id', $slotId)->lockForUpdate()->first();
        if ($slot && ! $slot->applies_to_all_boats) {
            DB::table('slot_offering_boats')->where('organization_id', $organizationId)
                ->where('slot_offering_id', $slotId)->where('boat_id', $boatId)
                ->lockForUpdate()->first();
        }
    }
}
