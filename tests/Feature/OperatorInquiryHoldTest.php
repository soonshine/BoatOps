<?php

namespace Tests\Feature;

use App\Application\Holds\CreateInquiryHoldAction;
use App\Application\Holds\HoldActor;
use App\Application\Holds\OrganizationHoldTtlPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorInquiryHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_and_null_policy_fail_closed_visibly_without_writes(): void
    {
        $c = $this->context();
        $id = $this->inquiry($c, 'FICTIONAL-POLICY-MISSING');
        $this->actingAs($c['user']);
        $this->get('/operator/inquiries/'.$id)->assertOk()->assertSee('OWNER_DECISION_REQUIRED')
            ->assertDontSee('name="expires_at"', false)->assertDontSee('name="ttl"', false);

        foreach (['missing', 'null'] as $case) {
            if ($case === 'null') {
                DB::table('organization_settings')->insert(['organization_id' => $c['organization_id'], 'key' => OrganizationHoldTtlPolicy::KEY, 'value' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->post("/operator/inquiries/{$id}/hold", ['idempotency_key' => (string) Str::uuid()])
                ->assertStatus(303)->assertSessionHasErrors('hold');
        }

        $this->assertDatabaseHas('inquiries', ['id' => $id, 'hold_id' => null, 'status' => 'INQUIRY']);
        foreach (['holds', 'allocations', 'idempotency_keys', 'audit_logs', 'outbox_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $c['organization_id'])->value('inventory_revision'));
    }

    public function test_server_expiry_link_actor_side_effects_replay_and_payload_conflict(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:00:00Z'));
        $c = $this->context();
        $this->policy($c['organization_id'], 37);
        $id = $this->inquiry($c, 'FICTIONAL-HOLD-SUCCESS');
        $other = $this->inquiry($c, 'FICTIONAL-HOLD-OTHER', '2026-09-02');
        $key = (string) Str::uuid();
        $this->actingAs($c['user']);
        $this->post("/operator/inquiries/{$id}/hold", ['idempotency_key' => $key])->assertStatus(303);
        $hold = DB::table('holds')->first();

        $this->assertSame('2026-08-10 00:37:00', $hold->expires_at);
        $this->assertDatabaseHas('inquiries', ['id' => $id, 'hold_id' => $hold->id, 'status' => 'INQUIRY']);
        $this->assertDatabaseCount('holds', 1);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('idempotency_keys', 2);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user', 'actor_id' => $c['user']->id, 'action' => 'hold.created', 'object_id' => $hold->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user', 'actor_id' => $c['user']->id, 'action' => 'INQUIRY_HOLD_LINKED', 'object_id' => $id]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'hold.created.v1', 'inventory_revision' => 1]);
        $this->assertSame(1, (int) DB::table('organizations')->where('id', $c['organization_id'])->value('inventory_revision'));

        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:12:00Z'));
        $this->post("/operator/inquiries/{$id}/hold", ['idempotency_key' => $key])->assertStatus(303);
        $this->assertDatabaseCount('holds', 1);
        $this->assertSame('2026-08-10 00:37:00', DB::table('holds')->value('expires_at'));
        $this->post("/operator/inquiries/{$id}/hold", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303)->assertSessionHasErrors('hold');
        $this->assertDatabaseCount('holds', 1);
        $this->assertDatabaseCount('idempotency_keys', 2);
        $conflict = app(CreateInquiryHoldAction::class)->execute($c['organization_id'], $other, $key, HoldActor::operatorUser($c['user']->id));
        $this->assertSame(409, $conflict->status);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict->payload['code']);
        $this->get("/operator/inquiries/{$id}")->assertOk()->assertSee('Linked HOLD')->assertSee('ACTIVE')->assertSee('2026-08-10 00:37:00');
    }

    public function test_overlap_and_incomplete_are_atomic(): void
    {
        $c = $this->context();
        $this->policy($c['organization_id'], 43);
        $overlap = $this->inquiry($c, 'FICTIONAL-OVERLAP');
        $incomplete = $this->inquiry($c, 'FICTIONAL-INCOMPLETE', null, false);
        DB::table('allocations')->insert([
            'organization_id' => $c['organization_id'], 'boat_id' => $c['boat_id'], 'allocation_type' => 'BLOCKED', 'status' => 'ACTIVE',
            'business_start' => '2026-09-01 01:00:00', 'business_end' => '2026-09-01 05:00:00',
            'occupied_start' => '2026-09-01 01:00:00', 'occupied_end' => '2026-09-01 05:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($c['user']);
        foreach ([$overlap, $incomplete] as $id) {
            $this->post("/operator/inquiries/{$id}/hold", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303)->assertSessionHasErrors('hold');
        }
        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('allocations', 1);
        foreach (['idempotency_keys', 'audit_logs', 'outbox_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, DB::table('inquiries')->whereNotNull('hold_id')->count());
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $c['organization_id'])->value('inventory_revision'));
    }

    public function test_permission_and_organization_isolation_are_non_disclosing(): void
    {
        $allowed = $this->context();
        $foreign = $this->context();
        $denied = $this->context(false);
        $foreignId = $this->inquiry($foreign, 'FICTIONAL-FOREIGN');
        $deniedId = $this->inquiry($denied, 'FICTIONAL-DENIED');
        $this->actingAs($allowed['user']);
        $this->post("/operator/inquiries/{$foreignId}/hold", ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->post("/operator/inquiries/{$foreignId}/hold/release", ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->actingAs($denied['user']);
        $this->post("/operator/inquiries/{$deniedId}/hold", ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->post("/operator/inquiries/{$deniedId}/hold/release", ['idempotency_key' => (string) Str::uuid()])->assertForbidden();

        $result = app(CreateInquiryHoldAction::class)->execute($allowed['organization_id'], $foreignId, (string) Str::uuid(), HoldActor::operatorUser($allowed['user']->id));
        $this->assertSame(403, $result->status);
        $this->assertSame('AUTHORIZATION_FAILED', $result->payload['code']);
        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_release_success_replay_terminal_and_kernel_side_effects(): void
    {
        $c = $this->context();
        $this->policy($c['organization_id'], 41);
        $id = $this->inquiry($c, 'FICTIONAL-RELEASE');
        $this->actingAs($c['user']);
        $this->post("/operator/inquiries/{$id}/hold", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303);
        $holdId = (int) DB::table('inquiries')->where('id', $id)->value('hold_id');
        $key = (string) Str::uuid();
        $this->post("/operator/inquiries/{$id}/hold/release", ['idempotency_key' => $key])->assertStatus(303);
        $this->post("/operator/inquiries/{$id}/hold/release", ['idempotency_key' => $key])->assertStatus(303);
        $this->assertDatabaseHas('holds', ['id' => $holdId, 'status' => 'RELEASED']);
        $this->assertDatabaseHas('allocations', ['hold_id' => $holdId, 'status' => 'RELEASED']);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user', 'actor_id' => $c['user']->id, 'action' => 'hold.released', 'object_id' => $holdId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'inventory.revision.changed.v1', 'inventory_revision' => 2]);
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $c['organization_id'])->value('inventory_revision'));
        $this->assertDatabaseCount('idempotency_keys', 3);
        $this->post("/operator/inquiries/{$id}/hold/release", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303)->assertSessionHasErrors('hold');
        $this->assertDatabaseCount('idempotency_keys', 3);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'hold.released')->count());
        $this->get("/operator/inquiries/{$id}")->assertOk()->assertSee('RELEASED');
    }

    private function context(bool $booking = true): array
    {
        $oid = DB::table('organizations')->insertGetId(['name' => 'Fictional '.Str::random(8), 'timezone' => 'Asia/Bangkok', 'inventory_revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::create(['name' => 'Fictional Operator', 'email' => Str::random(8).'@example.test', 'password' => Hash::make('fictional-password')]);
        DB::table('operator_memberships')->insert(['organization_id' => $oid, 'user_id' => $user->id, 'status' => 'ACTIVE', 'can_calendar_read' => true, 'can_booking_workflow' => $booking, 'can_block' => false, 'created_at' => now(), 'updated_at' => now()]);
        $boat = DB::table('boats')->insertGetId(['organization_id' => $oid, 'name' => 'Fictional Resource', 'status' => 'ACTIVE', 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $template = DB::table('trip_templates')->insertGetId(['organization_id' => $oid, 'code' => 'FICTIONAL-'.Str::upper(Str::random(6)), 'name' => 'Fictional Product', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $slot = DB::table('slot_offerings')->insertGetId([
            'organization_id' => $oid, 'kind' => 'PRESET', 'code' => 'FICTIONAL_SLOT_'.Str::upper(Str::random(6)), 'name' => 'Fictional Slot', 'status' => 'ACTIVE',
            'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO', 'service_start_time' => '08:00:00', 'service_end_time' => '12:00:00', 'duration_minutes' => 240,
            'additional_buffer_before_minutes' => 0, 'additional_buffer_after_minutes' => 0, 'applies_to_all_boats' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['organization_id' => $oid, 'user' => $user, 'boat_id' => $boat, 'template_id' => $template, 'slot_id' => $slot];
    }

    private function inquiry(array $c, string $reference, ?string $date = '2026-09-01', bool $complete = true): int
    {
        return DB::table('inquiries')->insertGetId([
            'organization_id' => $c['organization_id'], 'reference' => $reference, 'status' => 'INQUIRY',
            'boat_id' => $complete ? $c['boat_id'] : null, 'trip_template_id' => $complete ? $c['template_id'] : null,
            'slot_offering_id' => $complete ? $c['slot_id'] : null, 'service_date' => $date, 'notes' => null,
            'created_by_user_id' => $c['user']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function policy(int $organizationId, int $minutes): void
    {
        DB::table('organization_settings')->insert(['organization_id' => $organizationId, 'key' => OrganizationHoldTtlPolicy::KEY, 'value' => (string) $minutes, 'created_at' => now(), 'updated_at' => now()]);
    }
}
