<?php

namespace Tests\Feature;

use App\Application\Blocks\BlockActionResult;
use App\Application\Blocks\CreateBlockAction;
use App\Application\Blocks\ReleaseBlockAction;
use App\Application\Holds\HoldActor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class BlockApplicationActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_action_owns_success_exact_side_effects_replay_conflict_and_actor(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-08T00:00:00Z'));
        [$organizationId, $boatId] = $this->inventory('Fictional Direct Block', 15, 20);
        $input = $this->blockInput($boatId, 'FICTIONAL-DIRECT-BLOCK-001');
        $action = app(CreateBlockAction::class);

        $created = $action->execute($organizationId, $input, 'direct-block-key', HoldActor::operatorUser(201));
        $replayed = $action->execute($organizationId, $input, 'direct-block-key', HoldActor::operatorUser(201));
        $conflict = $action->execute(
            $organizationId,
            [...$input, 'ends_at' => '2026-09-22T13:00:00Z'],
            'direct-block-key',
            HoldActor::operatorUser(201),
        );

        $this->assertSame(201, $created->status);
        $this->assertTrue($created->changed);
        $this->assertSame($created->payload, $replayed->payload);
        $this->assertFalse($replayed->changed);
        $this->assertSame(409, $conflict->status);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict->payload['code']);
        $this->assertTrue($conflict->payload['manual_action_required']);
        $blockId = (int) $created->payload['block_id'];
        $this->assertDatabaseHas('blocks', [
            'id' => $blockId,
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'status' => 'ACTIVE',
            'reason_code' => 'MAINTENANCE',
            'reason' => 'Fictional scheduled service',
            'business_start' => '2026-09-22 10:00:00',
            'business_end' => '2026-09-22 12:00:00',
            'occupied_start' => '2026-09-22 10:00:00',
            'occupied_end' => '2026-09-22 12:00:00',
        ]);
        $this->assertDatabaseHas('allocations', [
            'block_id' => $blockId,
            'allocation_type' => 'BLOCKED',
            'status' => 'ACTIVE',
            'service_date' => '2026-09-22',
            'service_start' => '2026-09-22 10:00:00',
            'service_end' => '2026-09-22 12:00:00',
            'occupied_start' => '2026-09-22 10:00:00',
            'occupied_end' => '2026-09-22 12:00:00',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'operator_user',
            'actor_id' => 201,
            'action' => 'resource.blocked',
            'object_type' => 'block',
            'object_id' => $blockId,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'resource.blocked.v1',
            'aggregate_type' => 'block',
            'aggregate_id' => $blockId,
            'inventory_revision' => 1,
        ]);
        $event = json_decode((string) DB::table('outbox_events')->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('2026-09-22T10:00:00Z', $event['occupied_start']);
        $this->assertSame('2026-09-22T12:00:00Z', $event['occupied_end']);
        $this->assertArrayNotHasKey('reason', $event);
        $this->assertDatabaseCount('blocks', 1);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
        $this->assertSame(1, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_create_action_isolates_organizations_and_rejects_unavailable_overlap_without_partial_writes(): void
    {
        [$organizationA, $boatA] = $this->inventory('Fictional Scope A');
        [$organizationB, $boatB] = $this->inventory('Fictional Scope B');
        $action = app(CreateBlockAction::class);
        $foreign = $action->execute(
            $organizationA,
            $this->blockInput($boatB, 'FICTIONAL-FOREIGN-BLOCK-001'),
            'foreign-block-key',
            HoldActor::apiClient(202),
        );
        DB::table('allocations')->insert([
            'organization_id' => $organizationA,
            'boat_id' => $boatA,
            'allocation_type' => 'BOOKING',
            'status' => 'ACTIVE',
            'business_start' => '2026-09-22 11:00:00',
            'business_end' => '2026-09-22 13:00:00',
            'occupied_start' => '2026-09-22 11:00:00',
            'occupied_end' => '2026-09-22 13:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $overlap = $action->execute(
            $organizationA,
            $this->blockInput($boatA, 'FICTIONAL-OVERLAP-BLOCK-001'),
            'overlap-block-key',
            HoldActor::apiClient(202),
        );

        $this->assertSame(403, $foreign->status);
        $this->assertSame('AUTHORIZATION_FAILED', $foreign->payload['code']);
        $this->assertSame(409, $overlap->status);
        $this->assertSame('SLOT_UNAVAILABLE', $overlap->payload['code']);
        $this->assertFalse($overlap->payload['retryable']);
        $this->assertFalse($overlap->payload['manual_action_required']);
        $this->assertDatabaseCount('blocks', 0);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $organizationA)->value('inventory_revision'));
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $organizationB)->value('inventory_revision'));
    }

    public function test_release_action_owns_success_replay_conflict_terminal_scope_and_actor(): void
    {
        [$organizationId, $boatId] = $this->inventory('Fictional Direct Release');
        [$foreignOrganization] = $this->inventory('Fictional Foreign Release');
        $created = app(CreateBlockAction::class)->execute(
            $organizationId,
            $this->blockInput($boatId, 'FICTIONAL-DIRECT-RELEASE-001'),
            'release-create-key',
            HoldActor::apiClient(203),
        );
        $blockId = (int) $created->payload['block_id'];
        $action = app(ReleaseBlockAction::class);
        $input = ['external_reference' => 'FICTIONAL-DIRECT-RELEASE-001', 'reason' => 'Fictional service complete'];

        $foreign = $action->execute($foreignOrganization, $blockId, $input, 'foreign-release-key', HoldActor::operatorUser(204));
        $released = $action->execute($organizationId, $blockId, $input, 'direct-release-key', HoldActor::operatorUser(204));
        $replayed = $action->execute($organizationId, $blockId, $input, 'direct-release-key', HoldActor::operatorUser(204));
        $conflict = $action->execute($organizationId, $blockId, [...$input, 'reason' => 'Fictional changed reason'], 'direct-release-key', HoldActor::operatorUser(204));
        $terminal = $action->execute($organizationId, $blockId, $input, 'terminal-release-key', HoldActor::operatorUser(204));

        $this->assertSame('AUTHORIZATION_FAILED', $foreign->payload['code']);
        $this->assertSame(200, $released->status);
        $this->assertTrue($released->changed);
        $this->assertSame($released->payload, $replayed->payload);
        $this->assertFalse($replayed->changed);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict->payload['code']);
        $this->assertSame('INVALID_TRANSITION', $terminal->payload['code']);
        $this->assertDatabaseHas('blocks', ['id' => $blockId, 'status' => 'RELEASED', 'released_at' => now()]);
        $this->assertDatabaseHas('allocations', ['block_id' => $blockId, 'status' => 'RELEASED']);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'operator_user',
            'actor_id' => 204,
            'action' => 'resource.unblocked',
            'reason' => 'Fictional service complete',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'resource.unblocked.v1',
            'aggregate_id' => $blockId,
            'inventory_revision' => 2,
        ]);
        $this->assertDatabaseCount('blocks', 1);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertDatabaseCount('idempotency_keys', 2);
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
        $this->assertSame(0, DB::table('idempotency_keys')->where('organization_id', $foreignOrganization)->count());
    }

    public function test_internal_api_adapters_only_authorize_validate_build_actor_delegate_and_translate(): void
    {
        [$organizationId, $boatId] = $this->inventory('Fictional Block Adapter');
        $token = $this->apiClient($organizationId, 205);
        $createInput = $this->blockInput($boatId, 'FICTIONAL-ADAPTER-BLOCK-001');
        $actor = fn (HoldActor $value): bool => $value->type === 'api_client' && $value->id === 205;
        $create = Mockery::mock(CreateBlockAction::class);
        $create->shouldReceive('execute')->once()
            ->with($organizationId, $createInput, 'adapter-block-key', Mockery::on($actor))
            ->andReturn(new BlockActionResult(201, ['code' => 'SHARED_BLOCK_CREATE_PATH']));
        $this->app->instance(CreateBlockAction::class, $create);

        $this->withToken($token)->withHeader('Idempotency-Key', 'adapter-block-key')
            ->postJson('/api/internal/v1/blocks', $createInput)
            ->assertCreated()->assertExactJson(['code' => 'SHARED_BLOCK_CREATE_PATH']);

        $releaseInput = ['external_reference' => 'FICTIONAL-ADAPTER-BLOCK-001', 'reason' => 'Fictional adapter release'];
        $release = Mockery::mock(ReleaseBlockAction::class);
        $release->shouldReceive('execute')->once()
            ->with($organizationId, 777, $releaseInput, 'adapter-release-key', Mockery::on($actor))
            ->andReturn(new BlockActionResult(200, ['code' => 'SHARED_BLOCK_RELEASE_PATH']));
        $this->app->instance(ReleaseBlockAction::class, $release);

        $this->withToken($token)->withHeader('Idempotency-Key', 'adapter-release-key')
            ->postJson('/api/internal/v1/blocks/777:release', $releaseInput)
            ->assertOk()->assertExactJson(['code' => 'SHARED_BLOCK_RELEASE_PATH']);
        $this->assertDatabaseCount('blocks', 0);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    /** @return array{int, int} */
    private function inventory(string $name, int $bufferBefore = 0, int $bufferAfter = 0): array
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
            'buffer_before_minutes' => $bufferBefore,
            'buffer_after_minutes' => $bufferAfter,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organizationId, $boatId];
    }

    /** @return array<string, mixed> */
    private function blockInput(int $boatId, string $externalReference): array
    {
        return [
            'external_reference' => $externalReference,
            'boat_id' => $boatId,
            'starts_at' => '2026-09-22T10:00:00Z',
            'ends_at' => '2026-09-22T12:00:00Z',
            'reason_code' => 'MAINTENANCE',
            'reason' => 'Fictional scheduled service',
        ];
    }

    private function apiClient(int $organizationId, int $id): string
    {
        $token = Str::random(48);
        DB::table('api_clients')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'name' => 'Fictional Block Adapter Client',
            'token_hash' => hash('sha256', $token),
            'scopes' => json_encode(['operations.write'], JSON_THROW_ON_ERROR),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
