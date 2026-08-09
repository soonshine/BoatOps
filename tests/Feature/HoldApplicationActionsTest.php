<?php

namespace Tests\Feature;

use App\Application\Bookings\AmendBookingAction;
use App\Application\Bookings\BookingActionResult;
use App\Application\Bookings\CancelBookingAction;
use App\Application\Bookings\ConfirmBookingAction;
use App\Application\Holds\CreateHoldAction;
use App\Application\Holds\ExpireDueHoldAction;
use App\Application\Holds\ExpireDueHolds;
use App\Application\Holds\HoldActionResult;
use App\Application\Holds\HoldActor;
use App\Application\Holds\ReleaseHoldAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class HoldApplicationActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_action_owns_success_replay_conflict_and_side_effects(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Direct Create');
        $input = $this->holdInput($boatId, $templateId, 'DIRECT-CREATE-001');
        $action = app(CreateHoldAction::class);
        $actor = HoldActor::apiClient(81);
        $key = 'direct-create-key';

        $created = $action->execute($organizationId, $input, $key, $actor);
        $replayed = $action->execute($organizationId, $input, $key, $actor);
        $changed = $input;
        $changed['ends_at'] = '2026-09-01T13:00:00Z';
        $conflict = $action->execute($organizationId, $changed, $key, $actor);

        $this->assertSame(201, $created->status);
        $this->assertTrue($created->changed);
        $this->assertSame($created->payload, $replayed->payload);
        $this->assertFalse($replayed->changed);
        $this->assertSame(409, $conflict->status);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict->payload['code']);
        $this->assertTrue($conflict->payload['manual_action_required']);
        $this->assertDatabaseCount('holds', 1);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'api_client', 'actor_id' => 81, 'action' => 'hold.created']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'hold.created.v1', 'inventory_revision' => 1]);
        $this->assertSame(1, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_create_action_replays_stored_success_after_expiry_before_temporal_validation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Expired Replay');
        $input = $this->holdInput($boatId, $templateId, 'EXPIRED-REPLAY-001');
        $input['expires_at'] = '2026-08-01T00:01:00Z';
        $action = app(CreateHoldAction::class);

        $created = $action->execute($organizationId, $input, 'expired-replay-key', HoldActor::apiClient(810));
        $counts = collect(['holds', 'allocations', 'idempotency_keys', 'audit_logs', 'outbox_events'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $revision = (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision');
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:02:00Z'));

        $replayed = $action->execute($organizationId, $input, 'expired-replay-key', HoldActor::apiClient(810));
        $changed = $input;
        $changed['ends_at'] = '2026-09-01T13:00:00Z';
        $conflict = $action->execute($organizationId, $changed, 'expired-replay-key', HoldActor::apiClient(810));

        $this->assertSame(201, $created->status);
        $this->assertSame($created->status, $replayed->status);
        $this->assertSame($created->payload, $replayed->payload);
        $this->assertFalse($replayed->changed);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict->payload['code']);
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
        $this->assertSame($revision, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_create_action_rechecks_fresh_now_after_organization_lock_with_zero_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Lock Expiry');
        $input = $this->holdInput($boatId, $templateId, 'LOCK-EXPIRY-001');
        $input['expires_at'] = '2026-08-01T00:01:00Z';
        $advanced = false;
        DB::listen(function ($query) use (&$advanced): void {
            if (! $advanced && DB::transactionLevel() > 0 && str_contains(strtolower($query->sql), 'organizations')) {
                $advanced = true;
                $this->travelTo(CarbonImmutable::parse('2026-08-01T00:01:00Z'));
            }
        });

        $result = app(CreateHoldAction::class)->execute(
            $organizationId,
            $input,
            'lock-expiry-key',
            HoldActor::apiClient(811),
        );

        $this->assertTrue($advanced);
        $this->assertSame(422, $result->status);
        $this->assertSame('VALIDATION_FAILED', $result->payload['code']);
        $this->assertFalse($result->changed);
        foreach (['holds', 'allocations', 'idempotency_keys', 'audit_logs', 'outbox_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_create_action_rechecks_fresh_now_after_availability_locks_with_zero_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Availability Lock Expiry');
        $input = $this->holdInput($boatId, $templateId, 'AVAILABILITY-LOCK-EXPIRY-001');
        $input['expires_at'] = '2026-08-01T00:01:00Z';
        $advanced = false;
        DB::listen(function ($query) use (&$advanced): void {
            if (! $advanced && DB::transactionLevel() > 0 && str_contains(strtolower($query->sql), 'allocations')) {
                $advanced = true;
                $this->travelTo(CarbonImmutable::parse('2026-08-01T00:01:00Z'));
            }
        });

        $result = app(CreateHoldAction::class)->execute(
            $organizationId,
            $input,
            'availability-lock-expiry-key',
            HoldActor::apiClient(812),
        );

        $this->assertTrue($advanced);
        $this->assertSame(422, $result->status);
        $this->assertSame('VALIDATION_FAILED', $result->payload['code']);
        $this->assertFalse($result->changed);
        foreach (['holds', 'allocations', 'idempotency_keys', 'audit_logs', 'outbox_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_create_action_rejects_invalid_expired_and_equal_now_expiry_without_partial_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Invalid Direct Expiry');
        $action = app(CreateHoldAction::class);

        foreach (['not-a-date', '2026-07-31T23:59:59Z', '2026-08-01T00:00:00Z'] as $index => $expiresAt) {
            $input = $this->holdInput($boatId, $templateId, 'INVALID-EXPIRY-'.$index);
            $input['expires_at'] = $expiresAt;

            $result = $action->execute(
                $organizationId,
                $input,
                'invalid-expiry-key-'.$index,
                HoldActor::apiClient(82),
            );

            $this->assertSame(422, $result->status);
            $this->assertFalse($result->changed);
            $this->assertSame(
                ['code', 'retryable', 'manual_action_required', 'message'],
                array_keys(array_diff_key($result->payload, ['request_id' => true])),
            );
            $this->assertSame('VALIDATION_FAILED', $result->payload['code']);
            $this->assertFalse($result->payload['retryable']);
            $this->assertFalse($result->payload['manual_action_required']);
            $this->assertSame('The request payload is invalid.', $result->payload['message']);
        }

        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_create_action_rejects_foreign_resources_without_disclosure_or_partial_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationA] = $this->inventory('Fictional Scope A');
        [, $foreignBoat, $foreignTemplate] = $this->inventory('Fictional Scope B');

        $result = app(CreateHoldAction::class)->execute(
            $organizationA,
            $this->holdInput($foreignBoat, $foreignTemplate, 'FOREIGN-CREATE-001'),
            'foreign-create-key',
            HoldActor::apiClient(82),
        );

        $this->assertSame(403, $result->status);
        $this->assertSame('AUTHORIZATION_FAILED', $result->payload['code']);
        $this->assertSame('The requested inventory resource is not accessible.', $result->payload['message']);
        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $organizationA)->value('inventory_revision'));
    }

    public function test_create_action_normalizes_unavailable_overlap_without_partial_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Direct Overlap');
        DB::table('allocations')->insert([
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'allocation_type' => 'BLOCKED',
            'status' => 'ACTIVE',
            'business_start' => '2026-09-01 10:00:00',
            'business_end' => '2026-09-01 12:00:00',
            'occupied_start' => '2026-09-01 10:00:00',
            'occupied_end' => '2026-09-01 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(CreateHoldAction::class)->execute(
            $organizationId,
            $this->holdInput($boatId, $templateId, 'OVERLAP-CREATE-001'),
            'overlap-create-key',
            HoldActor::apiClient(83),
        );

        $this->assertSame(409, $result->status);
        $this->assertSame('SLOT_UNAVAILABLE', $result->payload['code']);
        $this->assertFalse($result->payload['retryable']);
        $this->assertFalse($result->payload['manual_action_required']);
        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('idempotency_keys', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_release_action_owns_success_replay_conflict_terminal_and_foreign_paths(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Direct Release');
        [$foreignOrganization] = $this->inventory('Fictional Foreign Release');
        $created = app(CreateHoldAction::class)->execute(
            $organizationId,
            $this->holdInput($boatId, $templateId, 'DIRECT-RELEASE-001'),
            'release-create-key',
            HoldActor::apiClient(84),
        );
        $holdId = (int) $created->payload['hold_id'];
        $action = app(ReleaseHoldAction::class);
        $input = ['external_reference' => 'DIRECT-RELEASE-001'];

        $released = $action->execute($organizationId, $holdId, $input, 'direct-release-key', HoldActor::apiClient(85));
        $replayed = $action->execute($organizationId, $holdId, $input, 'direct-release-key', HoldActor::apiClient(85));
        $conflict = $action->execute($organizationId, $holdId, ['external_reference' => 'CHANGED'], 'direct-release-key', HoldActor::apiClient(85));
        $terminal = $action->execute($organizationId, $holdId, $input, 'new-release-key', HoldActor::apiClient(85));
        $foreign = $action->execute($foreignOrganization, $holdId, $input, 'foreign-release-key', HoldActor::apiClient(86));

        $this->assertSame(200, $released->status);
        $this->assertTrue($released->changed);
        $this->assertSame($released->payload, $replayed->payload);
        $this->assertFalse($replayed->changed);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict->payload['code']);
        $this->assertSame('INVALID_TRANSITION', $terminal->payload['code']);
        $this->assertSame('AUTHORIZATION_FAILED', $foreign->payload['code']);
        $this->assertDatabaseHas('holds', ['id' => $holdId, 'status' => 'RELEASED']);
        $this->assertDatabaseHas('allocations', ['hold_id' => $holdId, 'status' => 'RELEASED']);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'api_client', 'actor_id' => 85, 'action' => 'hold.released']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'inventory.revision.changed.v1', 'inventory_revision' => 2]);
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
        $this->assertDatabaseCount('idempotency_keys', 2);
    }

    public function test_expiry_action_handles_due_not_due_repeated_and_foreign_holds_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Direct Expiry');
        [$foreignOrganization] = $this->inventory('Fictional Foreign Expiry');
        $input = $this->holdInput($boatId, $templateId, 'DIRECT-EXPIRY-001');
        $input['expires_at'] = '2026-08-01T00:05:00Z';
        $created = app(CreateHoldAction::class)->execute(
            $organizationId,
            $input,
            'expiry-create-key',
            HoldActor::apiClient(87),
        );
        $holdId = (int) $created->payload['hold_id'];
        $action = app(ExpireDueHoldAction::class);

        $notDue = $action->execute($holdId, CarbonImmutable::parse('2026-08-01T00:04:00Z'), HoldActor::system());
        $foreign = $action->execute($holdId, CarbonImmutable::parse('2026-08-01T00:06:00Z'), HoldActor::system(), $foreignOrganization);
        $due = $action->execute($holdId, CarbonImmutable::parse('2026-08-01T00:06:00Z'), HoldActor::system());
        $repeated = $action->execute($holdId, CarbonImmutable::parse('2026-08-01T00:07:00Z'), HoldActor::system());

        $this->assertSame('HOLD_NOT_EXPIRED', $notDue->payload['code']);
        $this->assertFalse($notDue->changed);
        $this->assertSame(403, $foreign->status);
        $this->assertSame('AUTHORIZATION_FAILED', $foreign->payload['code']);
        $this->assertSame(409, $due->status);
        $this->assertSame('HOLD_EXPIRED', $due->payload['code']);
        $this->assertTrue($due->changed);
        $this->assertSame('HOLD_NOT_EXPIRED', $repeated->payload['code']);
        $this->assertFalse($repeated->changed);
        $this->assertDatabaseHas('holds', ['id' => $holdId, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('allocations', ['hold_id' => $holdId, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'hold.expired',
            'reason' => 'HOLD_TTL_ELAPSED',
        ]);
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'hold.expired.v1')->count());
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_confirm_booking_action_owns_success_replay_conflict_and_exact_side_effects(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Direct Confirm');
        $created = app(CreateHoldAction::class)->execute(
            $organizationId,
            $this->holdInput($boatId, $templateId, 'DIRECT-CONFIRM-001'),
            'confirm-hold-key',
            HoldActor::apiClient(101),
        );
        $input = [
            'hold_id' => $created->payload['hold_id'],
            'external_reference' => 'DIRECT-CONFIRM-001',
            'rate_snapshot' => $this->rateSnapshot(),
        ];
        $action = app(ConfirmBookingAction::class);

        $confirmed = $action->execute($organizationId, $input, 'direct-confirm-key', HoldActor::operatorUser(102));
        $replayed = $action->execute($organizationId, $input, 'direct-confirm-key', HoldActor::operatorUser(102));
        $changed = $input;
        $changed['rate_snapshot']['selling_amount_minor'] = 54321;
        $conflict = $action->execute($organizationId, $changed, 'direct-confirm-key', HoldActor::operatorUser(102));
        $terminal = $action->execute($organizationId, $input, 'direct-confirm-new', HoldActor::operatorUser(102));

        $this->assertSame(201, $confirmed->status);
        $this->assertTrue($confirmed->changed);
        $this->assertSame($confirmed->payload, $replayed->payload);
        $this->assertFalse($replayed->changed);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict->payload['code']);
        $this->assertSame('INVALID_TRANSITION', $terminal->payload['code']);
        $bookingId = (int) $confirmed->payload['booking_id'];
        $this->assertDatabaseHas('holds', ['id' => $input['hold_id'], 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('allocations', ['hold_id' => $input['hold_id'], 'booking_id' => $bookingId, 'allocation_type' => 'BOOKING', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('trips', ['booking_id' => $bookingId, 'status' => 'PLANNED']);
        $this->assertDatabaseHas('rate_snapshots', ['booking_id' => $bookingId, 'source_reference' => 'FICTIONAL-RATE-001', 'selling_amount_minor' => 12345]);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user', 'actor_id' => 102, 'action' => 'booking.confirmed']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'booking.confirmed.v1', 'aggregate_id' => $bookingId, 'inventory_revision' => 2]);
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('trips', 1);
        $this->assertDatabaseCount('rate_snapshots', 1);
    }

    public function test_confirm_booking_action_preserves_expired_hold_and_cross_organization_behavior(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Expired Confirm');
        [$foreignOrganization] = $this->inventory('Fictional Foreign Confirm');
        $holdInput = $this->holdInput($boatId, $templateId, 'EXPIRED-CONFIRM-001');
        $holdInput['expires_at'] = '2026-08-01T00:05:00Z';
        $created = app(CreateHoldAction::class)->execute($organizationId, $holdInput, 'expired-hold-key', HoldActor::apiClient(103));
        $input = ['hold_id' => $created->payload['hold_id'], 'external_reference' => 'EXPIRED-CONFIRM-001', 'rate_snapshot' => $this->rateSnapshot()];
        $action = app(ConfirmBookingAction::class);

        $foreign = $action->execute($foreignOrganization, $input, 'foreign-confirm-key', HoldActor::operatorUser(104));
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:06:00Z'));
        $expired = $action->execute($organizationId, $input, 'expired-confirm-key', HoldActor::operatorUser(104));
        $replayed = $action->execute($organizationId, $input, 'expired-confirm-key', HoldActor::operatorUser(104));

        $this->assertSame(403, $foreign->status);
        $this->assertSame('AUTHORIZATION_FAILED', $foreign->payload['code']);
        $this->assertSame(409, $expired->status);
        $this->assertTrue($expired->changed);
        $this->assertSame('HOLD_EXPIRED', $expired->payload['code']);
        $this->assertSame($expired->payload, $replayed->payload);
        $this->assertFalse($replayed->changed);
        $this->assertDatabaseHas('holds', ['id' => $input['hold_id'], 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('allocations', ['hold_id' => $input['hold_id'], 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user', 'actor_id' => 104, 'action' => 'hold.expired']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'hold.expired.v1', 'inventory_revision' => 2]);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('trips', 0);
        $this->assertDatabaseCount('rate_snapshots', 0);
        $this->assertSame(0, DB::table('idempotency_keys')->where('organization_id', $foreignOrganization)->count());
    }

    public function test_amend_booking_action_preserves_allocation_overlap_atomicity_replay_and_audit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Direct Amend');
        $created = app(CreateHoldAction::class)->execute($organizationId, $this->holdInput($boatId, $templateId, 'DIRECT-AMEND-001'), 'amend-hold-key', HoldActor::apiClient(105));
        $confirmed = app(ConfirmBookingAction::class)->execute($organizationId, ['hold_id' => $created->payload['hold_id'], 'external_reference' => 'DIRECT-AMEND-001', 'rate_snapshot' => $this->rateSnapshot()], 'amend-confirm-key', HoldActor::apiClient(105));
        $bookingId = (int) $confirmed->payload['booking_id'];
        $input = ['external_reference' => 'DIRECT-AMEND-001', 'boat_id' => $boatId, 'trip_template_id' => $templateId, 'starts_at' => '2026-09-01T14:00:00Z', 'ends_at' => '2026-09-01T16:00:00Z'];
        $action = app(AmendBookingAction::class);

        $amended = $action->execute($organizationId, $bookingId, $input, 'direct-amend-key', HoldActor::operatorUser(106));
        $replayed = $action->execute($organizationId, $bookingId, $input, 'direct-amend-key', HoldActor::operatorUser(106));
        $changed = $input;
        $changed['ends_at'] = '2026-09-01T17:00:00Z';
        $conflict = $action->execute($organizationId, $bookingId, $changed, 'direct-amend-key', HoldActor::operatorUser(106));
        DB::table('allocations')->insert([
            'organization_id' => $organizationId, 'boat_id' => $boatId, 'allocation_type' => 'BLOCKED', 'status' => 'ACTIVE',
            'business_start' => '2026-09-01 18:00:00', 'business_end' => '2026-09-01 20:00:00',
            'occupied_start' => '2026-09-01 18:00:00', 'occupied_end' => '2026-09-01 20:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $blocked = [...$input, 'starts_at' => '2026-09-01T18:30:00Z', 'ends_at' => '2026-09-01T19:30:00Z'];
        $revision = (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision');
        $failed = $action->execute($organizationId, $bookingId, $blocked, 'blocked-amend-key', HoldActor::operatorUser(106));

        $this->assertSame(200, $amended->status);
        $this->assertTrue($amended->changed);
        $this->assertSame($amended->payload, $replayed->payload);
        $this->assertFalse($replayed->changed);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict->payload['code']);
        $this->assertSame('SLOT_UNAVAILABLE', $failed->payload['code']);
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'business_start' => '2026-09-01 14:00:00', 'business_end' => '2026-09-01 16:00:00']);
        $this->assertDatabaseHas('allocations', ['booking_id' => $bookingId, 'business_start' => '2026-09-01 14:00:00', 'business_end' => '2026-09-01 16:00:00', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('trips', ['booking_id' => $bookingId, 'planned_start' => '2026-09-01 14:00:00', 'planned_end' => '2026-09-01 16:00:00']);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user', 'actor_id' => 106, 'action' => 'booking.amended']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'booking.amended.v1', 'aggregate_id' => $bookingId, 'inventory_revision' => 3]);
        $this->assertSame($revision, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
        $this->assertSame(0, DB::table('idempotency_keys')->where('idempotency_key', 'blocked-amend-key')->count());
    }

    public function test_cancel_booking_action_owns_replay_conflict_terminal_and_organization_scope(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Direct Cancel');
        [$foreignOrganization] = $this->inventory('Fictional Foreign Cancel');
        $created = app(CreateHoldAction::class)->execute($organizationId, $this->holdInput($boatId, $templateId, 'DIRECT-CANCEL-001'), 'cancel-hold-key', HoldActor::apiClient(107));
        $confirmed = app(ConfirmBookingAction::class)->execute($organizationId, ['hold_id' => $created->payload['hold_id'], 'external_reference' => 'DIRECT-CANCEL-001', 'rate_snapshot' => $this->rateSnapshot()], 'cancel-confirm-key', HoldActor::apiClient(107));
        $bookingId = (int) $confirmed->payload['booking_id'];
        $input = ['external_reference' => 'DIRECT-CANCEL-001', 'reason' => 'FICTIONAL_CUSTOMER_REQUEST'];
        $action = app(CancelBookingAction::class);

        $foreign = $action->execute($foreignOrganization, $bookingId, $input, 'foreign-cancel-key', HoldActor::operatorUser(108));
        $cancelled = $action->execute($organizationId, $bookingId, $input, 'direct-cancel-key', HoldActor::operatorUser(108));
        $replayed = $action->execute($organizationId, $bookingId, $input, 'direct-cancel-key', HoldActor::operatorUser(108));
        $conflict = $action->execute($organizationId, $bookingId, [...$input, 'reason' => 'FICTIONAL_CHANGED_REASON'], 'direct-cancel-key', HoldActor::operatorUser(108));
        $terminal = $action->execute($organizationId, $bookingId, $input, 'direct-cancel-new', HoldActor::operatorUser(108));

        $this->assertSame('AUTHORIZATION_FAILED', $foreign->payload['code']);
        $this->assertSame(200, $cancelled->status);
        $this->assertTrue($cancelled->changed);
        $this->assertSame($cancelled->payload, $replayed->payload);
        $this->assertFalse($replayed->changed);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict->payload['code']);
        $this->assertSame('INVALID_TRANSITION', $terminal->payload['code']);
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('allocations', ['booking_id' => $bookingId, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('trips', ['booking_id' => $bookingId, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user', 'actor_id' => 108, 'action' => 'booking.cancelled', 'reason' => 'FICTIONAL_CUSTOMER_REQUEST']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'booking.cancelled.v1', 'aggregate_id' => $bookingId, 'inventory_revision' => 3]);
        $this->assertSame(3, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
        $this->assertSame(0, DB::table('idempotency_keys')->where('organization_id', $foreignOrganization)->count());
    }

    public function test_expiry_coordinator_drains_successive_batches_in_one_execute_call(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Batched Expiry');
        $holdIds = [];

        foreach ([0, 1, 2] as $index) {
            $input = $this->holdInput($boatId, $templateId, 'BATCHED-EXPIRY-'.$index);
            $input['starts_at'] = sprintf('2026-09-01T%02d:00:00Z', 10 + ($index * 3));
            $input['ends_at'] = sprintf('2026-09-01T%02d:00:00Z', 12 + ($index * 3));
            $input['expires_at'] = '2026-08-01T00:05:00Z';
            $created = app(CreateHoldAction::class)->execute(
                $organizationId,
                $input,
                'batched-expiry-create-'.$index,
                HoldActor::apiClient(88),
            );
            $this->assertSame(201, $created->status);
            $holdIds[] = (int) $created->payload['hold_id'];
        }

        $expiredCount = app(ExpireDueHolds::class)->execute(
            CarbonImmutable::parse('2026-08-01T00:06:00Z'),
            2,
        );

        $this->assertSame(3, $expiredCount);
        $this->assertSame(3, DB::table('holds')->whereIn('id', $holdIds)->where('status', 'EXPIRED')->count());
        $this->assertSame(3, DB::table('allocations')->whereIn('hold_id', $holdIds)->where('status', 'EXPIRED')->count());
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'hold.expired')->whereIn('object_id', $holdIds)->count());
        $this->assertSame(3, DB::table('outbox_events')->where('event_type', 'hold.expired.v1')->whereIn('aggregate_id', $holdIds)->count());
        $this->assertSame([4, 5, 6], DB::table('outbox_events')->where('event_type', 'hold.expired.v1')->orderBy('inventory_revision')->pluck('inventory_revision')->map(fn ($revision): int => (int) $revision)->all());
        $this->assertSame(6, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_api_create_and_release_adapters_translate_shared_action_results(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Adapter');
        $token = $this->apiClient($organizationId, 91);
        $input = $this->holdInput($boatId, $templateId, 'ADAPTER-CREATE-001');
        $create = Mockery::mock(CreateHoldAction::class);
        $create->shouldReceive('execute')->once()
            ->with($organizationId, $input, 'adapter-create-key', Mockery::on(fn (HoldActor $actor): bool => $actor->type === 'api_client' && $actor->id === 91))
            ->andReturn(new HoldActionResult(201, ['code' => 'SHARED_CREATE_PATH']));
        $this->app->instance(CreateHoldAction::class, $create);

        $this->withToken($token)->withHeader('Idempotency-Key', 'adapter-create-key')
            ->postJson('/api/v1/holds', $input)
            ->assertCreated()->assertExactJson(['code' => 'SHARED_CREATE_PATH']);

        $release = Mockery::mock(ReleaseHoldAction::class);
        $release->shouldReceive('execute')->once()
            ->with($organizationId, 777, ['external_reference' => 'ADAPTER-RELEASE-001'], 'adapter-release-key', Mockery::on(fn (HoldActor $actor): bool => $actor->type === 'api_client' && $actor->id === 91))
            ->andReturn(new HoldActionResult(200, ['code' => 'SHARED_RELEASE_PATH']));
        $this->app->instance(ReleaseHoldAction::class, $release);

        $this->withToken($token)->withHeader('Idempotency-Key', 'adapter-release-key')
            ->postJson('/api/v1/holds/777:release', ['external_reference' => 'ADAPTER-RELEASE-001'])
            ->assertOk()->assertExactJson(['code' => 'SHARED_RELEASE_PATH']);
        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('allocations', 0);
    }

    public function test_api_create_hold_replay_with_past_expiry_reaches_shared_action(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional API Expired Replay');
        $token = $this->apiClient($organizationId, 912);
        $input = $this->holdInput($boatId, $templateId, 'API-EXPIRED-REPLAY-001');
        $input['expires_at'] = '2026-08-01T00:01:00Z';

        $created = $this->withToken($token)->withHeader('Idempotency-Key', 'api-expired-replay-key')
            ->postJson('/api/v1/holds', $input)->assertCreated()->json();
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:02:00Z'));
        $replayed = $this->withToken($token)->withHeader('Idempotency-Key', 'api-expired-replay-key')
            ->postJson('/api/v1/holds', $input)->assertCreated()->json();

        $this->assertSame($created, $replayed);
        $this->assertDatabaseCount('holds', 1);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_api_booking_adapters_only_validate_delegate_and_translate_action_results(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Booking Adapters');
        $token = $this->apiClient($organizationId, 109);
        $actor = fn (HoldActor $value): bool => $value->type === 'api_client' && $value->id === 109;
        $confirmInput = ['hold_id' => 777, 'external_reference' => 'ADAPTER-CONFIRM-001', 'rate_snapshot' => $this->rateSnapshot()];
        $confirm = Mockery::mock(ConfirmBookingAction::class);
        $confirm->shouldReceive('execute')->once()->with($organizationId, $confirmInput, 'adapter-confirm-key', Mockery::on($actor))
            ->andReturn(new BookingActionResult(201, ['code' => 'SHARED_CONFIRM_PATH']));
        $this->app->instance(ConfirmBookingAction::class, $confirm);
        $this->withToken($token)->withHeader('Idempotency-Key', 'adapter-confirm-key')
            ->postJson('/api/v1/bookings:confirm', $confirmInput)
            ->assertCreated()->assertExactJson(['code' => 'SHARED_CONFIRM_PATH']);

        $amendInput = ['external_reference' => 'ADAPTER-AMEND-001', 'boat_id' => $boatId, 'trip_template_id' => $templateId, 'starts_at' => '2026-09-01T14:00:00Z', 'ends_at' => '2026-09-01T16:00:00Z'];
        $amend = Mockery::mock(AmendBookingAction::class);
        $amend->shouldReceive('execute')->once()->with($organizationId, 778, $amendInput, 'adapter-amend-key', Mockery::on($actor))
            ->andReturn(new BookingActionResult(200, ['code' => 'SHARED_AMEND_PATH']));
        $this->app->instance(AmendBookingAction::class, $amend);
        $this->withToken($token)->withHeader('Idempotency-Key', 'adapter-amend-key')
            ->postJson('/api/v1/bookings/778:amend', $amendInput)
            ->assertOk()->assertExactJson(['code' => 'SHARED_AMEND_PATH']);

        $cancelInput = ['external_reference' => 'ADAPTER-CANCEL-001', 'reason' => 'FICTIONAL_REASON'];
        $cancel = Mockery::mock(CancelBookingAction::class);
        $cancel->shouldReceive('execute')->once()->with($organizationId, 779, $cancelInput, 'adapter-cancel-key', Mockery::on($actor))
            ->andReturn(new BookingActionResult(200, ['code' => 'SHARED_CANCEL_PATH']));
        $this->app->instance(CancelBookingAction::class, $cancel);
        $this->withToken($token)->withHeader('Idempotency-Key', 'adapter-cancel-key')
            ->postJson('/api/v1/bookings/779:cancel', $cancelInput)
            ->assertOk()->assertExactJson(['code' => 'SHARED_CANCEL_PATH']);

        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('trips', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_expire_command_delegates_to_bounded_shared_coordinator(): void
    {
        $coordinator = Mockery::mock(ExpireDueHolds::class);
        $coordinator->shouldReceive('execute')->once()
            ->with(Mockery::on(fn ($asOf): bool => $asOf instanceof CarbonImmutable))
            ->andReturn(3);
        $this->app->instance(ExpireDueHolds::class, $coordinator);

        $this->artisan('holds:expire')->expectsOutput('Expired 3 HOLD(s).')->assertSuccessful();
    }

    /** @return array{int, int, int} */
    private function inventory(string $name): array
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => $name,
            'timezone' => 'UTC',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $boatId = DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $name.' Vessel',
            'status' => 'ACTIVE',
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $templateId = DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'FICTIONAL-'.Str::upper(Str::random(8)),
            'name' => $name.' Trip',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organizationId, $boatId, $templateId];
    }

    /** @return array<string, mixed> */
    private function holdInput(int $boatId, int $templateId, string $externalReference): array
    {
        return [
            'external_reference' => $externalReference,
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-01T10:00:00Z',
            'ends_at' => '2026-09-01T12:00:00Z',
            'expires_at' => '2026-08-01T00:20:00Z',
        ];
    }

    /** @return array<string, mixed> */
    private function rateSnapshot(): array
    {
        return [
            'source_reference' => 'FICTIONAL-RATE-001',
            'currency' => 'USD',
            'selling_amount_minor' => 12345,
            'tax_amount_minor' => 0,
            'commission_amount_minor' => 0,
            'quoted_at' => '2026-07-31T23:00:00Z',
            'valid_until' => '2026-08-02T00:00:00Z',
        ];
    }

    private function apiClient(int $organizationId, int $id): string
    {
        $token = Str::random(48);
        DB::table('api_clients')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'name' => 'Fictional Adapter Client',
            'token_hash' => hash('sha256', $token),
            'scopes' => json_encode([], JSON_THROW_ON_ERROR),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
