<?php

namespace App\Application\Holds;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CreateInquiryHoldAction
{
    use HoldActionSupport;

    private const OPERATION = 'operator.inquiries.createHold';

    public function __construct(
        private readonly CreateHoldAction $createHold,
        private readonly OrganizationHoldTtlPolicy $ttlPolicy,
    ) {}

    public function execute(
        int $organizationId,
        int $inquiryId,
        string $idempotencyKey,
        HoldActor $actor,
    ): HoldActionResult {
        $request = ['inquiry_id' => $inquiryId];
        $requestHash = $this->canonicalHash($request);
        $existing = $this->replay($organizationId, self::OPERATION, $idempotencyKey, $requestHash);

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($organizationId, $inquiryId, $idempotencyKey, $actor, $requestHash): HoldActionResult {
            $organization = DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();

            if (! $organization) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested inquiry is not accessible.', 403);
            }

            $replayed = $this->replay($organizationId, self::OPERATION, $idempotencyKey, $requestHash);
            if ($replayed) {
                return $replayed;
            }

            $inquiry = DB::table('inquiries')
                ->where('organization_id', $organizationId)
                ->where('id', $inquiryId)
                ->lockForUpdate()
                ->first();

            if (! $inquiry) {
                return $this->error('AUTHORIZATION_FAILED', 'The requested inquiry is not accessible.', 403);
            }
            if ($inquiry->hold_id !== null) {
                return $this->error('HOLD_ALREADY_LINKED', 'This inquiry already has a linked HOLD.', 409, true);
            }
            if ($inquiry->boat_id === null || $inquiry->trip_template_id === null
                || $inquiry->slot_offering_id === null || $inquiry->service_date === null) {
                return $this->error('INQUIRY_INCOMPLETE', 'Boat, product, slot, and service date are required before creating a HOLD.', 422);
            }

            $ttlMinutes = $this->ttlPolicy->minutes($organizationId);
            if ($ttlMinutes === null) {
                return $this->error(
                    'HOLD_TTL_POLICY_UNCONFIGURED',
                    'HOLD creation is unavailable because the organization HOLD TTL policy is not configured.',
                    409,
                    true,
                );
            }

            $expiresAt = CarbonImmutable::now('UTC')->addMinutes($ttlMinutes);
            $holdResult = $this->createHold->execute($organizationId, [
                'external_reference' => (string) $inquiry->reference,
                'boat_id' => (int) $inquiry->boat_id,
                'trip_template_id' => (int) $inquiry->trip_template_id,
                'slot_offering_id' => (int) $inquiry->slot_offering_id,
                'service_date' => (string) $inquiry->service_date,
                'expires_at' => $expiresAt->format("Y-m-d\TH:i:s\Z"),
            ], 'operator-inquiry:'.$idempotencyKey, $actor);

            if ($holdResult->status !== 201) {
                return $holdResult;
            }

            $holdId = (int) $holdResult->payload['hold_id'];
            $now = now()->utc();
            DB::table('inquiries')->where('id', $inquiry->id)->update([
                'hold_id' => $holdId,
                'updated_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId,
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'action' => 'INQUIRY_HOLD_LINKED',
                'object_type' => 'inquiry',
                'object_id' => $inquiry->id,
                'before_values' => json_encode(['hold_id' => null], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['hold_id' => $holdId], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('idempotency_keys')->insert([
                'organization_id' => $organizationId,
                'operation' => self::OPERATION,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'response_status' => 201,
                'response_body' => json_encode($holdResult->payload, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return new HoldActionResult(201, $holdResult->payload, true);
        }, 3);
    }
}
