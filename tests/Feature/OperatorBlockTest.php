<?php

namespace Tests\Feature;

use App\Application\Blocks\BlockActionResult;
use App\Application\Blocks\CreateBlockAction;
use App\Application\Blocks\ReleaseBlockAction;
use App\Application\Holds\HoldActor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OperatorBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_page_labels_timezone_manual_weather_policy_and_active_release_only(): void
    {
        $a = $this->context('America/New_York');
        $b = $this->context('Pacific/Auckland');
        $active = $this->block($a, 'ACTIVE', 'WEATHER');
        $released = $this->block($a, 'RELEASED', 'OWNER_USE');
        $this->block($b, 'ACTIVE', 'MANUAL');
        $response = $this->actingAs($a['user'])->get('/operator/blocks')->assertOk();
        $response->assertSee('America/New_York')->assertSee('WEATHER is a manual reason label only.')
            ->assertSee('OWNER_DECISION_REQUIRED')->assertSee('This page exposes no automated weather rule.')
            ->assertSee('Fictional Resource America/New_York')->assertDontSee('Fictional Resource Pacific/Auckland')
            ->assertSee(route('operator.blocks.release', $active), false)
            ->assertDontSee(route('operator.blocks.release', $released), false)
            ->assertSee('2026-09-22 06:00 EDT')->assertSee('2026-09-22 08:00 EDT');
        $this->assertMatchesRegularExpression('/name="idempotency_key" value="[0-9a-f-]{36}"/', $response->getContent());
        $this->assertStringNotContainsString('Asia/Bangkok', $response->getContent());
        $this->assertStringNotContainsString('<script', strtolower($response->getContent()));
    }

    public function test_create_converts_non_bangkok_local_time_and_preserves_action_effects_replay_conflict(): void
    {
        $c = $this->context('America/New_York');
        $key = (string) Str::uuid();
        $payload = $this->payload($c, $key);
        $this->actingAs($c['user'])->post('/operator/blocks', $payload)->assertStatus(303);
        $this->post('/operator/blocks', $payload)->assertStatus(303);
        $this->post('/operator/blocks', [...$payload, 'ends_at_local' => '2026-09-22T13:00'])
            ->assertStatus(303)->assertSessionHasErrors(['block' => 'The idempotency key was used with another payload.']);
        $block = DB::table('blocks')->sole();
        $this->assertSame('2026-09-22 14:00:00', $block->business_start);
        $this->assertSame('2026-09-22 16:00:00', $block->business_end);
        $this->assertSame($block->business_start, $block->occupied_start);
        $this->assertSame($block->business_end, $block->occupied_end);
        $this->assertDatabaseHas('allocations', ['block_id' => $block->id, 'allocation_type' => 'BLOCKED', 'status' => 'ACTIVE', 'service_start' => '2026-09-22 14:00:00', 'service_end' => '2026-09-22 16:00:00', 'occupied_start' => '2026-09-22 14:00:00', 'occupied_end' => '2026-09-22 16:00:00']);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user', 'actor_id' => $c['user']->id, 'action' => 'resource.blocked']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'resource.blocked.v1', 'aggregate_id' => $block->id, 'inventory_revision' => 1]);
        $this->assertDatabaseHas('idempotency_keys', ['operation' => 'createBlock', 'idempotency_key' => $key, 'response_status' => 201]);
        foreach (['blocks', 'allocations', 'audit_logs', 'outbox_events', 'idempotency_keys'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertSame(1, (int) DB::table('organizations')->where('id', $c['organization_id'])->value('inventory_revision'));
    }

    public function test_overlap_is_unavailable_atomic(): void
    {
        $c = $this->context('UTC');
        DB::table('allocations')->insert(['organization_id' => $c['organization_id'], 'boat_id' => $c['boat_id'], 'allocation_type' => 'BOOKING', 'status' => 'ACTIVE', 'business_start' => '2026-09-22 10:30:00', 'business_end' => '2026-09-22 13:00:00', 'occupied_start' => '2026-09-22 10:30:00', 'occupied_end' => '2026-09-22 13:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($c['user'])->post('/operator/blocks', $this->payload($c, (string) Str::uuid()))
            ->assertStatus(303)->assertSessionHasErrors(['block' => 'The requested slot is unavailable.']);
        $this->assertDatabaseCount('blocks', 0);
        $this->assertDatabaseCount('allocations', 1);
        foreach (['audit_logs', 'outbox_events', 'idempotency_keys'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $c['organization_id'])->value('inventory_revision'));
    }

    public function test_release_success_replay_terminal_and_shared_effects(): void
    {
        $c = $this->context('UTC');
        $this->actingAs($c['user'])->post('/operator/blocks', $this->payload($c, (string) Str::uuid()))->assertStatus(303);
        $block = DB::table('blocks')->sole();
        $key = (string) Str::uuid();
        $release = ['idempotency_key' => $key, 'reason' => 'Fictional maintenance complete'];
        $this->post('/operator/blocks/'.$block->id.'/release', $release)->assertStatus(303);
        $this->post('/operator/blocks/'.$block->id.'/release', $release)->assertStatus(303);
        $this->post('/operator/blocks/'.$block->id.'/release', ['idempotency_key' => (string) Str::uuid()])
            ->assertStatus(303)->assertSessionHasErrors(['release' => 'Only an active block can be released.']);
        $this->assertDatabaseHas('blocks', ['id' => $block->id, 'status' => 'RELEASED']);
        $this->assertDatabaseHas('allocations', ['block_id' => $block->id, 'status' => 'RELEASED']);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user', 'actor_id' => $c['user']->id, 'action' => 'resource.unblocked', 'reason' => 'Fictional maintenance complete']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'resource.unblocked.v1', 'aggregate_id' => $block->id, 'inventory_revision' => 2]);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertDatabaseCount('idempotency_keys', 2);
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $c['organization_id'])->value('inventory_revision'));
    }

    public function test_permission_and_foreign_ids_fail_closed_without_disclosure(): void
    {
        $allowed = $this->context('UTC');
        $foreign = $this->context('UTC');
        $denied = $this->context('UTC', false);
        $foreignBlock = $this->block($foreign);
        $this->actingAs($denied['user'])->get('/operator/blocks')->assertForbidden();
        $this->post('/operator/blocks', $this->payload($denied, (string) Str::uuid()))->assertForbidden();
        $this->post('/operator/blocks/1/release', ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($allowed['user']);
        $this->post('/operator/blocks', [...$this->payload($allowed, (string) Str::uuid()), 'boat_id' => $foreign['boat_id']])->assertNotFound();
        $this->post('/operator/blocks/'.$foreignBlock.'/release', ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->get('/operator/blocks/'.$foreignBlock.'/release')->assertMethodNotAllowed();
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_controller_formats_and_delegates_without_duplicate_mutation_code(): void
    {
        $c = $this->context('Pacific/Auckland');
        $key = (string) Str::uuid();
        $actor = fn (HoldActor $value): bool => $value->type === 'operator_user' && $value->id === $c['user']->id;
        $create = Mockery::mock(CreateBlockAction::class);
        $create->shouldReceive('execute')->once()->with($c['organization_id'], [
            'external_reference' => 'FICTIONAL-OPERATOR-BLOCK-001',
            'boat_id' => $c['boat_id'],
            'starts_at' => '2026-09-21T22:00:00Z',
            'ends_at' => '2026-09-22T00:00:00Z',
            'reason_code' => 'MAINTENANCE',
            'reason' => 'Fictional scheduled service',
        ], $key, Mockery::on($actor))->andReturn(new BlockActionResult(201, ['code' => 'SHARED_CREATE']));
        $this->app->instance(CreateBlockAction::class, $create);
        $this->actingAs($c['user'])->post('/operator/blocks', $this->payload($c, $key))->assertStatus(303);

        $blockId = $this->block($c);
        $releaseKey = (string) Str::uuid();
        $release = Mockery::mock(ReleaseBlockAction::class);
        $release->shouldReceive('execute')->once()->with(
            $c['organization_id'], $blockId,
            ['external_reference' => 'FICTIONAL-BLOCK-'.$blockId, 'reason' => 'Fictional release'],
            $releaseKey, Mockery::on($actor),
        )->andReturn(new BlockActionResult(200, ['code' => 'SHARED_RELEASE']));
        $this->app->instance(ReleaseBlockAction::class, $release);
        $this->post('/operator/blocks/'.$blockId.'/release', ['idempotency_key' => $releaseKey, 'reason' => 'Fictional release'])->assertStatus(303);

        $source = file_get_contents(app_path('Http/Controllers/Operator/BlockController.php'));
        foreach (['DB::transaction', "DB::table('allocations')", 'lockForUpdate', 'inventory_revision', 'outbox_events'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    private function context(string $timezone, bool $canBlock = true): array
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Fictional '.$timezone, 'timezone' => $timezone, 'inventory_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::create(['name' => 'Fictional Block Operator', 'email' => Str::random(8).'@example.test', 'password' => Hash::make('fictional-password')]);
        DB::table('operator_memberships')->insert([
            'organization_id' => $organizationId, 'user_id' => $user->id, 'status' => 'ACTIVE',
            'can_calendar_read' => true, 'can_booking_workflow' => true, 'can_block' => $canBlock,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $boatId = DB::table('boats')->insertGetId([
            'organization_id' => $organizationId, 'name' => 'Fictional Resource '.$timezone,
            'status' => 'ACTIVE', 'buffer_before_minutes' => 27, 'buffer_after_minutes' => 31,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['organization_id' => $organizationId, 'user' => $user, 'boat_id' => $boatId];
    }

    private function payload(array $context, string $key): array
    {
        return [
            'idempotency_key' => $key,
            'external_reference' => 'FICTIONAL-OPERATOR-BLOCK-001',
            'boat_id' => $context['boat_id'],
            'starts_at_local' => '2026-09-22T10:00',
            'ends_at_local' => '2026-09-22T12:00',
            'reason_code' => 'MAINTENANCE',
            'reason' => 'Fictional scheduled service',
        ];
    }

    private function block(array $context, string $status = 'ACTIVE', string $reasonCode = 'MAINTENANCE'): int
    {
        $id = DB::table('blocks')->count() + 1;

        return DB::table('blocks')->insertGetId([
            'organization_id' => $context['organization_id'], 'boat_id' => $context['boat_id'],
            'external_reference' => 'FICTIONAL-BLOCK-'.$id, 'status' => $status,
            'reason_code' => $reasonCode, 'reason' => 'Fictional reason',
            'business_start' => '2026-09-22 10:00:00', 'business_end' => '2026-09-22 12:00:00',
            'occupied_start' => '2026-09-22 10:00:00', 'occupied_end' => '2026-09-22 12:00:00',
            'released_at' => $status === 'ACTIVE' ? null : now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
