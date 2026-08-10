<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorTripDeskTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_list_uses_organization_timezone_is_isolated_and_minimizes_pii(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T18:00:00Z'));
        $allowed = $this->context();
        $included = $this->trip($allowed, 'FICTIONAL-LOCAL-MIDNIGHT', '2026-08-10 17:00:00', dossier: true);
        $this->trip($allowed, 'FICTIONAL-PREVIOUS-DAY', '2026-08-10 16:59:59');
        $this->trip($allowed, 'FICTIONAL-NEXT-DAY', '2026-08-11 17:00:00');
        $foreign = $this->context();
        $this->trip($foreign, 'FICTIONAL-FOREIGN-TRIP', '2026-08-10 18:00:00');
        $this->actingAs($allowed['user']);

        $response = $this->get('/operator/trips')->assertOk()
            ->assertSee('FICTIONAL-LOCAL-MIDNIGHT')
            ->assertSee('Fictional Contact')
            ->assertDontSee('FICTIONAL-PREVIOUS-DAY')
            ->assertDontSee('FICTIONAL-NEXT-DAY')
            ->assertDontSee('FICTIONAL-FOREIGN-TRIP')
            ->assertDontSee('contact-secret@example.test')
            ->assertDontSee('Fictional private service note')
            ->assertDontSee('Fictional private internal note');
        $this->assertSame(50, $response->viewData('trips')->perPage());
        $this->assertSame([(int) $included['trip_id']], $response->viewData('trips')->pluck('id')->map(fn ($id) => (int) $id)->all());

        $this->get('/operator/trips?date=2026-08-10')->assertOk()
            ->assertSee('FICTIONAL-PREVIOUS-DAY')
            ->assertDontSee('FICTIONAL-LOCAL-MIDNIGHT');
    }

    public function test_trip_list_uses_bounded_fifty_row_pagination(): void
    {
        $context = $this->context();
        $start = CarbonImmutable::parse('2026-08-10T17:00:00Z');
        for ($index = 1; $index <= 51; $index++) {
            $this->trip(
                $context,
                'FICTIONAL-PAGE-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                $start->addMinutes($index)->format('Y-m-d H:i:s'),
            );
        }
        $this->actingAs($context['user']);

        $first = $this->get('/operator/trips?date=2026-08-11')->assertOk()->viewData('trips');
        $second = $this->get('/operator/trips?date=2026-08-11&page=2')->assertOk()->viewData('trips');
        $this->assertSame(51, $first->total());
        $this->assertCount(50, $first->items());
        $this->assertSame(2, $first->lastPage());
        $this->assertCount(1, $second->items());
    }

    public function test_detail_shows_dossier_readiness_links_and_status_specific_controls(): void
    {
        $allowed = $this->context();
        $record = $this->trip($allowed, 'FICTIONAL-DETAIL', '2026-08-10 20:00:00', dossier: true);
        $this->ready($allowed, $record['trip_id']);
        $foreign = $this->context();
        $foreignRecord = $this->trip($foreign, 'FICTIONAL-FOREIGN-DETAIL', '2026-08-10 20:00:00');
        $this->actingAs($allowed['user']);

        $this->get('/operator/trips/'.$record['trip_id'])->assertOk()
            ->assertSee('FICTIONAL-DETAIL')
            ->assertSee($allowed['boat_name'])
            ->assertSee($allowed['product_name'])
            ->assertSee('contact-secret@example.test')
            ->assertSee('Fictional private service note')
            ->assertSee('FICTIONAL-CREW-DETAIL')
            ->assertSee('SAFETY_DETAIL')
            ->assertSee('Open Booking')
            ->assertSee('Save preparation')
            ->assertSee('Depart Trip')
            ->assertDontSee('Return Trip')
            ->assertDontSee('Complete Trip');

        DB::table('trips')->where('id', $record['trip_id'])->update([
            'status' => 'DEPARTED',
            'actual_departed_at' => '2026-08-10 20:00:00',
        ]);
        $this->get('/operator/trips/'.$record['trip_id'])->assertOk()
            ->assertSee('Return Trip')
            ->assertDontSee('Save preparation')
            ->assertDontSee('Depart Trip')
            ->assertDontSee('Complete Trip');

        DB::table('trips')->where('id', $record['trip_id'])->update([
            'status' => 'RETURNED',
            'actual_returned_at' => '2026-08-10 21:00:00',
        ]);
        $this->get('/operator/trips/'.$record['trip_id'])->assertOk()
            ->assertSee('Complete Trip')
            ->assertDontSee('Return Trip');
        $this->get('/operator/trips/'.$foreignRecord['trip_id'])->assertNotFound();

        foreach ([
            'prepare' => $this->preparePayload((string) Str::uuid()),
            'depart' => ['idempotency_key' => (string) Str::uuid(), 'departed_at' => '2026-08-11T03:00'],
            'return' => ['idempotency_key' => (string) Str::uuid(), 'returned_at' => '2026-08-11T04:00'],
            'complete' => ['idempotency_key' => (string) Str::uuid()],
        ] as $operation => $payload) {
            $this->post('/operator/trips/'.$foreignRecord['trip_id'].'/'.$operation, $payload)->assertNotFound();
        }

        $denied = $this->context(false);
        $deniedTrip = $this->trip($denied, 'FICTIONAL-DENIED-TRIP', '2026-08-10 20:00:00');
        $this->actingAs($denied['user']);
        $this->get('/operator/trips')->assertForbidden();
        $this->get('/operator/trips/'.$deniedTrip['trip_id'])->assertForbidden();
        $this->post('/operator/trips/'.$deniedTrip['trip_id'].'/complete', ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_dynamic_prepare_rows_use_independent_monotonic_indexes_without_silent_data_loss(): void
    {
        $context = $this->context();
        $record = $this->trip($context, 'FICTIONAL-ROW-INDEXES', '2026-08-10 20:00:00');
        $this->actingAs($context['user']);
        $initialCrew = [];
        $initialChecklist = [];
        foreach ([0, 1, 2] as $index) {
            $initialCrew[$index] = [
                'external_reference' => 'FICTIONAL-INITIAL-CREW-'.$index,
                'display_name' => 'Fictional Initial Crew '.$index,
                'role' => 'CREW',
                'duty' => 'DUTY_'.$index,
            ];
            $initialChecklist[$index] = [
                'code' => 'INITIAL_CHECK_'.$index,
                'label' => 'Fictional initial check '.$index,
                'required' => '1',
                'completed' => '1',
            ];
        }

        $page = $this->withSession(['_old_input' => [
            'crew' => $initialCrew,
            'checklist' => $initialChecklist,
        ]])->get('/operator/trips/'.$record['trip_id'])->assertOk();
        $content = $page->getContent();
        foreach ([0, 1, 2] as $index) {
            $this->assertStringContainsString('name="crew['.$index.'][external_reference]"', $content);
            $this->assertStringContainsString('name="checklist['.$index.'][code]"', $content);
        }
        $this->assertStringContainsString("crew: nextRowIndex('crew')", $content);
        $this->assertStringContainsString("checklist: nextRowIndex('checklist')", $content);
        $this->assertStringContainsString('Math.max(...indexes) + 1', $content);
        $this->assertStringContainsString('const index = nextRowIndexes[type];', $content);
        $this->assertStringContainsString('nextRowIndexes[type] += 1;', $content);
        $this->assertStringNotContainsString("container.querySelectorAll('[data-row]').length", $content);

        $finalCrew = [];
        $finalChecklist = [];
        foreach ([0, 2, 3] as $index) {
            $finalCrew[$index] = [
                'external_reference' => 'FICTIONAL-SUBMITTED-CREW-'.$index,
                'display_name' => 'Fictional Submitted Crew '.$index,
                'role' => 'CREW',
                'duty' => 'DUTY_'.$index,
            ];
            $finalChecklist[$index] = [
                'code' => 'SUBMITTED_CHECK_'.$index,
                'label' => 'Fictional submitted check '.$index,
                'required' => '1',
                'completed' => '1',
            ];
        }
        $key = (string) Str::uuid();
        $this->post('/operator/trips/'.$record['trip_id'].'/prepare', [
            'idempotency_key' => $key,
            'crew' => $finalCrew,
            'checklist' => $finalChecklist,
        ])->assertStatus(303)->assertSessionHasNoErrors();

        $this->assertSame(3, DB::table('crew_assignments')->where('trip_id', $record['trip_id'])->count());
        $this->assertSame(3, DB::table('trip_checklists')->where('trip_id', $record['trip_id'])->count());
        foreach ([0, 2, 3] as $index) {
            $this->assertDatabaseHas('crew_members', [
                'organization_id' => $context['organization_id'],
                'external_reference' => 'FICTIONAL-SUBMITTED-CREW-'.$index,
            ]);
            $this->assertDatabaseHas('trip_checklists', [
                'organization_id' => $context['organization_id'],
                'trip_id' => $record['trip_id'],
                'code' => 'SUBMITTED_CHECK_'.$index,
            ]);
        }
        $this->assertDatabaseHas('idempotency_keys', [
            'organization_id' => $context['organization_id'],
            'operation' => 'prepareTrip:'.$record['trip_id'],
            'idempotency_key' => $key,
        ]);
        $this->assertDatabaseHas('trips', ['id' => $record['trip_id'], 'status' => 'PLANNED']);
    }

    public function test_operator_workflow_shares_actions_enforces_time_safety_and_preserves_exact_side_effects(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T10:00:00Z'));
        $context = $this->context();
        $record = $this->trip($context, 'FICTIONAL-WORKFLOW', '2026-08-10 10:00:00');
        $this->actingAs($context['user']);
        $tripPath = '/operator/trips/'.$record['trip_id'];

        $this->post($tripPath.'/depart', [
            'idempotency_key' => (string) Str::uuid(),
            'departed_at' => '2026-08-10T17:00',
        ])->assertStatus(303)->assertSessionHasErrors('trip');
        $this->assertDatabaseHas('trips', ['id' => $record['trip_id'], 'status' => 'PLANNED']);

        $prepareKey = (string) Str::uuid();
        $prepare = $this->preparePayload($prepareKey);
        $this->post($tripPath.'/prepare', $prepare)->assertStatus(303)->assertSessionHasNoErrors();
        $this->post($tripPath.'/prepare', $prepare)->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('crew_assignments', 1);
        $this->assertDatabaseCount('trip_checklists', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'trip.prepared')->count());
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'operator_user',
            'actor_id' => $context['user']->id,
            'action' => 'trip.prepared',
        ]);
        $conflict = $prepare;
        $conflict['checklist'][0]['label'] = 'Changed fictional intent';
        $this->post($tripPath.'/prepare', $conflict)->assertStatus(303)->assertSessionHasErrors('trip');
        $this->assertDatabaseCount('trip_checklists', 1);

        $this->post($tripPath.'/depart', [
            'idempotency_key' => (string) Str::uuid(),
            'departed_at' => '2026-08-10T17:01',
        ])->assertStatus(303)->assertSessionHasErrors('trip');
        $this->assertDatabaseHas('trips', ['id' => $record['trip_id'], 'status' => 'PLANNED']);
        $this->post($tripPath.'/depart', [
            'idempotency_key' => (string) Str::uuid(),
            'departed_at' => '2026-08-10T17:00',
        ])->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('trips', [
            'id' => $record['trip_id'],
            'status' => 'DEPARTED',
            'actual_departed_at' => '2026-08-10 10:00:00',
        ]);

        $this->post($tripPath.'/return', [
            'idempotency_key' => (string) Str::uuid(),
            'returned_at' => '2026-08-10T16:59',
        ])->assertStatus(303)->assertSessionHasErrors('trip');
        $this->post($tripPath.'/return', [
            'idempotency_key' => (string) Str::uuid(),
            'returned_at' => '2026-08-10T17:01',
        ])->assertStatus(303)->assertSessionHasErrors('trip');
        $this->post($tripPath.'/return', [
            'idempotency_key' => (string) Str::uuid(),
            'returned_at' => '2026-08-10T17:00',
        ])->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('trips', [
            'id' => $record['trip_id'],
            'status' => 'RETURNED',
            'actual_returned_at' => '2026-08-10 10:00:00',
        ]);

        $completeKey = (string) Str::uuid();
        $complete = ['idempotency_key' => $completeKey];
        $this->post($tripPath.'/complete', $complete)->assertStatus(303)->assertSessionHasNoErrors();
        $this->post($tripPath.'/complete', $complete)->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('trips', ['id' => $record['trip_id'], 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('bookings', ['id' => $record['booking_id'], 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('allocations', ['id' => $record['allocation_id'], 'status' => 'COMPLETED']);
        $this->assertSame(1, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'trip.completed.v1')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'trip.completed')->count());
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'operator_user',
            'actor_id' => $context['user']->id,
            'action' => 'trip.completed',
        ]);
        $completedAt = CarbonImmutable::parse((string) DB::table('trips')->where('id', $record['trip_id'])->value('completed_at'), 'UTC');
        $returnedAt = CarbonImmutable::parse((string) DB::table('trips')->where('id', $record['trip_id'])->value('actual_returned_at'), 'UTC');
        $this->assertTrue($completedAt->greaterThanOrEqualTo($returnedAt));
    }

    private function context(bool $bookingPermission = true): array
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Fictional Trip Desk '.Str::random(6),
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::create([
            'name' => 'Fictional Trip Operator',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('fictional-password'),
        ]);
        DB::table('operator_memberships')->insert([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'can_calendar_read' => false,
            'can_booking_workflow' => $bookingPermission,
            'can_block' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $boatName = 'Fictional Trip Boat '.Str::random(4);
        $productName = 'Fictional Trip Product '.Str::random(4);
        $boatId = DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $boatName,
            'status' => 'ACTIVE',
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $templateId = DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'FICTIONAL-'.Str::upper(Str::random(8)),
            'name' => $productName,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('organizationId', 'user', 'boatId', 'templateId', 'boatName', 'productName') + [
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'template_id' => $templateId,
            'boat_name' => $boatName,
            'product_name' => $productName,
        ];
    }

    private function trip(array $context, string $reference, string $start, string $status = 'PLANNED', bool $dossier = false): array
    {
        $end = CarbonImmutable::parse($start, 'UTC')->addHour()->format('Y-m-d H:i:s');
        $active = $status === 'COMPLETED' ? 'COMPLETED' : 'ACTIVE';
        $allocationId = DB::table('allocations')->insertGetId([
            'organization_id' => $context['organization_id'],
            'boat_id' => $context['boat_id'],
            'allocation_type' => 'BOOKING',
            'status' => $active,
            'business_start' => $start,
            'business_end' => $end,
            'occupied_start' => $start,
            'occupied_end' => $end,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $holdId = null;
        if ($dossier) {
            $holdId = DB::table('holds')->insertGetId([
                'organization_id' => $context['organization_id'],
                'boat_id' => $context['boat_id'],
                'trip_template_id' => $context['template_id'],
                'external_reference' => $reference.'-HOLD',
                'status' => 'CONFIRMED',
                'business_start' => $start,
                'business_end' => $end,
                'occupied_start' => $start,
                'occupied_end' => $end,
                'expires_at' => '2026-12-31 00:00:00',
                'allocation_id' => $allocationId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('inquiries')->insert([
                'organization_id' => $context['organization_id'],
                'reference' => $reference.'-INQUIRY',
                'status' => 'INQUIRY',
                'boat_id' => $context['boat_id'],
                'trip_template_id' => $context['template_id'],
                'service_date' => CarbonImmutable::parse($start, 'UTC')->setTimezone('Asia/Bangkok')->format('Y-m-d'),
                'created_by_user_id' => $context['user']->id,
                'hold_id' => $holdId,
                'contact_name' => 'Fictional Contact',
                'contact_method' => 'EMAIL',
                'contact_value' => 'contact-secret@example.test',
                'party_size' => 4,
                'meeting_point' => 'Fictional Pier',
                'service_location' => 'Fictional Bay',
                'sales_source' => 'DIRECT',
                'service_notes' => 'Fictional private service note',
                'internal_notes' => 'Fictional private internal note',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $bookingId = DB::table('bookings')->insertGetId([
            'organization_id' => $context['organization_id'],
            'hold_id' => $holdId,
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['template_id'],
            'external_reference' => $reference,
            'status' => $status === 'COMPLETED' ? 'COMPLETED' : 'CONFIRMED',
            'business_start' => $start,
            'business_end' => $end,
            'allocation_id' => $allocationId,
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('allocations')->where('id', $allocationId)->update([
            'hold_id' => $holdId,
            'booking_id' => $bookingId,
        ]);
        $tripId = DB::table('trips')->insertGetId([
            'organization_id' => $context['organization_id'],
            'booking_id' => $bookingId,
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['template_id'],
            'status' => $status,
            'planned_start' => $start,
            'planned_end' => $end,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('allocationId', 'bookingId', 'tripId') + [
            'allocation_id' => $allocationId,
            'booking_id' => $bookingId,
            'trip_id' => $tripId,
        ];
    }

    private function ready(array $context, int $tripId): void
    {
        $crewMemberId = DB::table('crew_members')->insertGetId([
            'organization_id' => $context['organization_id'],
            'external_reference' => 'FICTIONAL-CREW-DETAIL',
            'display_name' => 'Fictional Detail Captain',
            'role' => 'CAPTAIN',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('crew_assignments')->insert([
            'organization_id' => $context['organization_id'],
            'trip_id' => $tripId,
            'crew_member_id' => $crewMemberId,
            'duty' => 'CAPTAIN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('trip_checklists')->insert([
            'organization_id' => $context['organization_id'],
            'trip_id' => $tripId,
            'code' => 'SAFETY_DETAIL',
            'label' => 'Fictional detail safety check',
            'required' => true,
            'completed' => true,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function preparePayload(string $key): array
    {
        return [
            'idempotency_key' => $key,
            'crew' => [[
                'external_reference' => 'FICTIONAL-WEB-CREW',
                'display_name' => 'Fictional Web Captain',
                'role' => 'CAPTAIN',
                'duty' => 'CAPTAIN',
            ]],
            'checklist' => [[
                'code' => 'WEB_READY',
                'label' => 'Fictional web readiness check',
                'required' => '1',
                'completed' => '1',
            ]],
        ];
    }
}
