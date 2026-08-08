<?php

namespace Tests\Feature;

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

    public function test_create_action_rejects_foreign_resources_without_disclosure_or_partial_writes(): void
    {
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
