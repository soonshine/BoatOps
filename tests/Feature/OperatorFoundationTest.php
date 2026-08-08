<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_invalid_inactive_regeneration_and_logout(): void
    {
        $c = $this->context();
        $this->get('/operator/login');
        $old = session()->getId();
        $this->post('/operator/login', ['email' => $c['user']->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/operator/login', ['email' => $c['user']->email, 'password' => 'fictional-password'])->assertRedirect('/operator/calendar');
        $this->assertNotSame($old, session()->getId());
        $this->post('/operator/logout')->assertRedirect('/operator/login');
        $this->assertGuest();
        DB::table('operator_memberships')->where('user_id', $c['user']->id)->update(['status' => 'INACTIVE']);
        $this->post('/operator/login', ['email' => $c['user']->email, 'password' => 'fictional-password'])->assertSessionHasErrors('email');
    }

    public function test_login_is_rate_limited_and_valid_login_still_succeeds(): void
    {
        $limited = $this->context();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/operator/login', ['email' => $limited['user']->email, 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }

        $this->post('/operator/login', ['email' => $limited['user']->email, 'password' => 'wrong'])
            ->assertStatus(429);

        $valid = $this->context();
        $this->post('/operator/login', ['email' => $valid['user']->email, 'password' => 'fictional-password'])
            ->assertRedirect('/operator/calendar');
        $this->assertAuthenticatedAs($valid['user']);
    }

    public function test_inactive_membership_session_can_recover_and_logout(): void
    {
        $loginRecovery = $this->context();
        $this->actingAs($loginRecovery['user'])->withSession(['_token' => 'fictional-inactive-token']);
        DB::table('operator_memberships')->where('user_id', $loginRecovery['user']->id)->update(['status' => 'INACTIVE']);

        $this->get('/operator/login')->assertOk()->assertViewIs('operator.login');
        $this->assertGuest();
        $this->assertNotSame('fictional-inactive-token', session()->token());

        DB::table('operator_memberships')->where('user_id', $loginRecovery['user']->id)->update(['status' => 'ACTIVE']);
        $this->post('/operator/login', ['email' => $loginRecovery['user']->email, 'password' => 'fictional-password'])
            ->assertRedirect('/operator/calendar');

        $logoutRecovery = $this->context();
        $this->actingAs($logoutRecovery['user'])->withSession(['_token' => 'fictional-inactive-logout-token']);
        DB::table('operator_memberships')->where('user_id', $logoutRecovery['user']->id)->update(['status' => 'INACTIVE']);

        $this->post('/operator/logout')->assertRedirect('/operator/login');
        $this->assertGuest();
        $this->assertNotSame('fictional-inactive-logout-token', session()->token());
    }

    public function test_removed_membership_session_can_recover_and_logout_and_guest_logout_is_harmless(): void
    {
        $loginRecovery = $this->context();
        $this->actingAs($loginRecovery['user'])->withSession(['_token' => 'fictional-removed-token']);
        DB::table('operator_memberships')->where('user_id', $loginRecovery['user']->id)->delete();

        $this->get('/operator/login')->assertOk()->assertViewIs('operator.login');
        $this->assertGuest();
        $this->assertNotSame('fictional-removed-token', session()->token());

        $logoutRecovery = $this->context();
        $this->actingAs($logoutRecovery['user'])->withSession(['_token' => 'fictional-removed-logout-token']);
        DB::table('operator_memberships')->where('user_id', $logoutRecovery['user']->id)->delete();

        $this->post('/operator/logout')->assertRedirect('/operator/login');
        $this->assertGuest();
        $this->assertNotSame('fictional-removed-logout-token', session()->token());

        $this->post('/operator/logout')->assertRedirect('/operator/login');
        $this->assertGuest();
    }

    public function test_inactive_removed_user_and_permissions_fail_closed(): void
    {
        $c = $this->context(false, false, false);
        foreach (['calendar_read', 'booking_workflow', 'block'] as $p) {
            Route::middleware('web')->get('/permission-'.$p, fn () => response('ok'))->middleware('operator.membership:'.$p);
        }$this->actingAs($c['user']);
        foreach (['calendar_read', 'booking_workflow', 'block'] as $p) {
            $this->get('/permission-'.$p)->assertForbidden();
        }DB::table('operator_memberships')->where('user_id', $c['user']->id)->update(['status' => 'INACTIVE']);
        $this->get('/operator/calendar')->assertForbidden();
        $c = $this->context();
        $this->actingAs($c['user']);
        $c['user']->delete();
        $this->get('/operator/calendar')->assertForbidden();
    }

    public function test_calendar_exact_ranges_and_scope(): void
    {
        $a = $this->context();
        $b = $this->context();
        $this->actingAs($a['user']);
        $r = $this->get('/operator/calendar?from=2026-09-01&range=7')->assertOk();
        $cal = $r->viewData('calendar');
        $this->assertCount(1, $cal['boats']);
        $this->assertCount(7, $cal['boats'][0]['dates']);
        $this->assertSame($a['boat_id'], $cal['boats'][0]['boat_id']);
        $this->assertSame('AVAILABLE', $cal['boats'][0]['dates'][0]['slots'][0]['status']);
        $r = $this->get('/operator/calendar?from=2026-09-01&range=30')->assertOk();
        $this->assertCount(30, $r->viewData('calendar')['boats'][0]['dates']);
        $this->assertSame([$a['product_id']], $r->viewData('products')->pluck('id')->map(fn ($x) => (int) $x)->all());
        $this->get('/operator/calendar?range=7&boat_id='.$b['boat_id'])->assertNotFound();
    }

    public function test_inquiry_idempotency_audit_and_no_inventory_mutation(): void
    {
        $c = $this->context();
        $this->actingAs($c['user']);
        $key = (string) Str::uuid();
        $p = $this->payload($c, $key);
        $rev = DB::table('organizations')->where('id', $c['organization_id'])->value('inventory_revision');
        $first = $this->post('/operator/inquiries', $p)->assertStatus(303);
        $inquiryId = (int) DB::table('inquiries')->value('id');
        $target = route('operator.inquiries.show', $inquiryId);
        $first->assertHeader('Location', $target);
        $replay = $this->post('/operator/inquiries', array_reverse($p, true))->assertStatus(303);
        $replay->assertHeader('Location', $target);
        $this->assertDatabaseHas('idempotency_keys', [
            'organization_id' => $c['organization_id'],
            'operation' => 'operator.inquiries.create',
            'idempotency_key' => $key,
            'response_status' => 303,
        ]);
        $this->assertDatabaseCount('inquiries', 1);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user', 'actor_id' => $c['user']->id, 'action' => 'INQUIRY_CREATED']);
        foreach (['allocations', 'holds', 'bookings', 'blocks'] as $t) {
            $this->assertDatabaseCount($t, 0);
        }$this->assertSame($rev, DB::table('organizations')->where('id', $c['organization_id'])->value('inventory_revision'));
        $this->post('/operator/inquiries', [...$p, 'notes' => 'changed'])->assertConflict();
        DB::table('operator_memberships')->where('user_id', $c['user']->id)->update(['status' => 'INACTIVE']);
        $this->post('/operator/inquiries', $p)->assertForbidden();
    }

    public function test_inquiry_reference_is_neutral_safe_and_bounded(): void
    {
        $c = $this->context();
        $this->actingAs($c['user']);
        $accepted = $this->payload($c, (string) Str::uuid());
        $accepted['reference'] = 'SAMPLE-NEUTRAL_001.test';

        $this->post('/operator/inquiries', $accepted)->assertStatus(303);
        $this->assertDatabaseHas('inquiries', ['reference' => 'SAMPLE-NEUTRAL_001.test']);

        foreach (['SAMPLE INQUIRY', 'SAMPLE/INQUIRY', '-SAMPLE', str_repeat('S', 101)] as $reference) {
            $payload = $this->payload($c, (string) Str::uuid());
            $payload['reference'] = $reference;
            $this->post('/operator/inquiries', $payload)->assertSessionHasErrors('reference');
        }
    }

    public function test_foreign_ids_are_non_disclosing(): void
    {
        $a = $this->context();
        $b = $this->context();
        $this->actingAs($a['user']);
        $p = $this->payload($a, (string) Str::uuid());
        $this->post('/operator/inquiries', [...$p, 'boat_id' => $b['boat_id']])->assertNotFound();
        $this->post('/operator/inquiries', [...$p, 'idempotency_key' => (string) Str::uuid(), 'trip_template_id' => $b['product_id']])->assertNotFound();
        $this->post('/operator/inquiries', [...$p, 'idempotency_key' => (string) Str::uuid(), 'slot_offering_id' => $b['slot_id']])->assertNotFound();
        $id = DB::table('inquiries')->insertGetId(['organization_id' => $b['organization_id'], 'reference' => 'FICTIONAL-FOREIGN', 'status' => 'INQUIRY', 'created_by_user_id' => $b['user']->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/operator/inquiries/'.$id)->assertNotFound();
    }

    public function test_public_demo_operator_gets_404_and_non_get_405(): void
    {
        $env = $this->app->environment();
        try {
            $this->app->detectEnvironment(fn () => 'production');
            config(['demo_site.mode' => 'public_read_only', 'demo_site.isolated_dataset' => true, 'database.default' => 'sqlite', 'database.connections.sqlite.url' => null, 'cache.default' => 'file', 'cache.limiter' => 'file', 'session.driver' => 'file', 'queue.default' => 'sync']);
            foreach (['/operator/login', '/operator/calendar', '/operator/inquiries', '/operator/inquiries/create', '/operator/inquiries/9', '/operator/inquiries/9/hold', '/operator/inquiries/9/hold/release', '/operator/inquiries/9/holds/8/confirm', '/operator/inquiries/9/bookings/7/amend', '/operator/inquiries/9/bookings/7/cancel'] as $p) {
                $this->get($p)->assertNotFound();
            }$this->get('/api/v1/inventory/revision')->assertNotFound();
            foreach (['/operator/login', '/operator/inquiries/9/hold', '/operator/inquiries/9/hold/release', '/operator/inquiries/9/holds/8/confirm', '/operator/inquiries/9/bookings/7/amend', '/operator/inquiries/9/bookings/7/cancel'] as $path) {
                $this->post($path)->assertStatus(405)->assertHeader('Allow', 'GET');
            }
        } finally {
            $this->app->detectEnvironment(fn () => $env);
        }
    }

    private function context(bool $read = true, bool $booking = true, bool $block = true): array
    {
        $oid = DB::table('organizations')->insertGetId(['name' => 'Fictional '.Str::random(5), 'timezone' => 'Asia/Bangkok', 'inventory_revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $u = User::create(['name' => 'Fictional Operator', 'email' => Str::random(8).'@example.test', 'password' => Hash::make('fictional-password')]);
        DB::table('operator_memberships')->insert(['organization_id' => $oid, 'user_id' => $u->id, 'status' => 'ACTIVE', 'can_calendar_read' => $read, 'can_booking_workflow' => $booking, 'can_block' => $block, 'created_at' => now(), 'updated_at' => now()]);
        $boat = DB::table('boats')->insertGetId(['organization_id' => $oid, 'name' => 'Fictional Resource', 'status' => 'ACTIVE', 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $product = DB::table('trip_templates')->insertGetId(['organization_id' => $oid, 'code' => 'FICTIONAL-'.Str::upper(Str::random(4)), 'name' => 'Fictional Product', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $slot = DB::table('slot_offerings')->insertGetId(['organization_id' => $oid, 'kind' => 'PRESET', 'code' => 'FICTIONAL_SLOT_'.Str::upper(Str::random(4)), 'name' => 'Fictional Slot', 'status' => 'ACTIVE', 'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO', 'service_start_time' => '08:00:00', 'service_end_time' => '12:00:00', 'duration_minutes' => 240, 'additional_buffer_before_minutes' => 0, 'additional_buffer_after_minutes' => 0, 'applies_to_all_boats' => true, 'created_at' => now(), 'updated_at' => now()]);

        return ['organization_id' => $oid, 'user' => $u, 'boat_id' => $boat, 'product_id' => $product, 'slot_id' => $slot];
    }

    private function payload(array $c, string $key): array
    {
        return ['idempotency_key' => $key, 'reference' => 'SAMPLE-INQUIRY-001', 'boat_id' => $c['boat_id'], 'trip_template_id' => $c['product_id'], 'slot_offering_id' => $c['slot_id'], 'service_date' => '2026-09-01', 'notes' => 'Neutral fictional note'];
    }
}
