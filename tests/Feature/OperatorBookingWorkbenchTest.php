<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorBookingWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_list_is_organization_scoped_permissioned_and_minimizes_pii(): void
    {
        $allowed = $this->context();
        $foreign = $this->context();
        $allowedBooking = $this->directBooking(
            $allowed,
            'FICTIONAL-ALLOWED-BOOKING',
            '2026-08-10T01:00:00Z',
            'CANCELLED',
            $this->dossier('ALLOWED'),
        );
        $this->directBooking(
            $foreign,
            'FICTIONAL-FOREIGN-BOOKING',
            '2026-08-10T01:00:00Z',
            'CANCELLED',
            $this->dossier('FOREIGN'),
        );

        $this->actingAs($allowed['user']);
        $response = $this->get('/operator/bookings?view=all')->assertOk()
            ->assertSee('Bookings')
            ->assertSee('FICTIONAL-ALLOWED-BOOKING')
            ->assertSee('Fictional Contact ALLOWED')
            ->assertDontSee('FICTIONAL-FOREIGN-BOOKING')
            ->assertDontSee('Fictional Contact FOREIGN')
            ->assertDontSee('fictional-private-allowed@example.test')
            ->assertDontSee('Fictional Internal Notes ALLOWED')
            ->assertDontSee('Fictional Service Notes ALLOWED');
        $this->assertSame([$allowedBooking['reference']], $this->references($response));

        $denied = $this->context('Asia/Bangkok', false);
        $this->actingAs($denied['user']);
        $this->get('/operator/bookings')->assertForbidden();
    }

    public function test_list_paginates_twenty_five_and_preserves_all_query_parameters(): void
    {
        $context = $this->context();
        for ($index = 1; $index <= 27; $index++) {
            $this->directBooking(
                $context,
                sprintf('FICTIONAL-PAGE-%02d', $index),
                '2026-08-09T18:00:00Z',
                'CANCELLED',
            );
        }
        $this->actingAs($context['user']);
        $query = 'view=all&date=2026-08-10&status=CANCELLED&q=fictional-page';
        $first = $this->get('/operator/bookings?'.$query)->assertOk();
        $paginator = $first->viewData('bookings');

        $this->assertCount(25, $paginator->items());
        $this->assertSame(27, $paginator->total());
        $this->assertSame(2, $paginator->lastPage());
        parse_str((string) parse_url($paginator->nextPageUrl(), PHP_URL_QUERY), $nextQuery);
        $this->assertSame('all', $nextQuery['view']);
        $this->assertSame('2026-08-10', $nextQuery['date']);
        $this->assertSame('CANCELLED', $nextQuery['status']);
        $this->assertSame('fictional-page', $nextQuery['q']);
        $this->assertSame('2', (string) $nextQuery['page']);

        $second = $this->get('/operator/bookings?'.$query.'&page=2')->assertOk();
        $this->assertCount(2, $second->viewData('bookings')->items());
    }

    public function test_today_uses_exact_asia_bangkok_local_boundaries_and_keeps_cancelled_visible(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-09T18:00:00Z'));
        $context = $this->context('Asia/Bangkok');
        $cases = [
            ['BEFORE-TODAY', '2026-08-09T16:59:59Z'],
            ['AT-TODAY-START', '2026-08-09T17:00:00Z'],
            ['BEFORE-NEXT-DAY', '2026-08-10T16:59:59Z'],
            ['AT-NEXT-DAY', '2026-08-10T17:00:00Z'],
        ];
        foreach ($cases as [$reference, $start]) {
            $boat = $this->boat($context['organization_id'], 'Fictional Boundary '.$reference);
            $this->directBooking($context, $reference, $start, 'CANCELLED', null, null, $boat);
        }

        $this->actingAs($context['user']);
        $response = $this->get('/operator/bookings')->assertOk();
        $this->assertSame(['AT-TODAY-START', 'BEFORE-NEXT-DAY'], $this->references($response));
        $response->assertSee('CANCELLED');
    }

    public function test_upcoming_uses_next_local_midnight_and_all_has_no_date_scope_with_stable_ordering(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-09T18:00:00Z'));
        $context = $this->context('Asia/Bangkok');
        $this->directBooking($context, 'TODAY-LAST', '2026-08-10T16:59:59Z', 'CANCELLED');
        $this->directBooking($context, 'UPCOMING-START', '2026-08-10T17:00:00Z', 'CANCELLED');
        $this->directBooking($context, 'UPCOMING-LATER', '2026-08-11T01:00:00Z', 'CANCELLED');

        $this->actingAs($context['user']);
        $upcoming = $this->get('/operator/bookings?view=upcoming')->assertOk();
        $this->assertSame(['UPCOMING-START', 'UPCOMING-LATER'], $this->references($upcoming));
        $upcoming->assertSee('CANCELLED');

        $older = $this->directBooking($context, 'ALL-OLDER', '2026-07-01T01:00:00Z', 'CANCELLED');
        $sameLow = $this->directBooking($context, 'ALL-SAME-LOW', '2026-12-01T01:00:00Z', 'CANCELLED');
        $sameHigh = $this->directBooking($context, 'ALL-SAME-HIGH', '2026-12-01T01:00:00Z', 'CANCELLED');
        $all = $this->get('/operator/bookings?view=all')->assertOk();
        $references = $this->references($all);
        $this->assertLessThan(array_search($sameLow['reference'], $references, true), array_search($sameHigh['reference'], $references, true));
        $this->assertLessThan(array_search($older['reference'], $references, true), array_search('TODAY-LAST', $references, true));
    }

    public function test_explicit_date_overrides_view_and_uses_organization_local_day(): void
    {
        $context = $this->context('Asia/Bangkok');
        foreach ([
            ['DATE-BEFORE', '2026-08-09T16:59:59Z'],
            ['DATE-START', '2026-08-09T17:00:00Z'],
            ['DATE-END-INCLUSIVE', '2026-08-10T16:59:59Z'],
            ['DATE-AFTER', '2026-08-10T17:00:00Z'],
        ] as [$reference, $start]) {
            $this->directBooking($context, $reference, $start, 'CANCELLED');
        }

        $this->actingAs($context['user']);
        $response = $this->get('/operator/bookings?view=upcoming&date=2026-08-10')->assertOk();
        $this->assertSame(['DATE-START', 'DATE-END-INCLUSIVE'], $this->references($response));
        $this->get('/operator/bookings?date=not-a-date')->assertRedirect()->assertSessionHasErrors('date');
        $this->get('/operator/bookings?view=unknown')->assertRedirect()->assertSessionHasErrors('view');
    }

    public function test_status_and_bounded_case_insensitive_search_filters_are_exact(): void
    {
        $context = $this->context();
        $confirmedBoat = $this->boat($context['organization_id'], 'Fictional Confirmed Search Boat');
        $confirmed = $this->directBooking(
            $context,
            'FICTIONAL-BOOKING-ALPHA',
            '2026-09-10T01:00:00Z',
            'CONFIRMED',
            [
                ...$this->dossier('NEEDLE'),
                'inquiry_reference' => 'FICTIONAL-INQUIRY-NEEDLE',
            ],
            null,
            $confirmedBoat,
        );
        $cancelled = $this->directBooking($context, 'FICTIONAL-BOOKING-CANCELLED', '2026-09-11T01:00:00Z', 'CANCELLED');
        $this->actingAs($context['user']);

        $this->assertSame([$confirmed['reference']], $this->references($this->get('/operator/bookings?view=all&status=CONFIRMED')->assertOk()));
        $this->assertSame([$cancelled['reference']], $this->references($this->get('/operator/bookings?view=all&status=CANCELLED')->assertOk()));
        $this->get('/operator/bookings?status=VOID')->assertRedirect()->assertSessionHasErrors('status');

        foreach (['booking-alpha', 'inquiry-needle', 'fictional contact needle'] as $query) {
            $this->assertSame(
                [$confirmed['reference']],
                $this->references($this->get('/operator/bookings?view=all&q='.urlencode($query))->assertOk()),
            );
        }
        $private = $this->get('/operator/bookings?view=all&q='.urlencode('fictional-private-needle@example.test'))->assertOk();
        $this->assertSame([], $this->references($private));
        $this->assertSame([], $this->references($this->get('/operator/bookings?view=all&q=%25')->assertOk()));
    }

    public function test_detail_displays_booking_existing_dossier_and_trip_summary_without_trip_controls(): void
    {
        $context = $this->context();
        $booking = $this->directBooking(
            $context,
            'FICTIONAL-DETAIL-BOOKING',
            '2026-09-10T01:00:00Z',
            'CONFIRMED',
            $this->dossier('DETAIL'),
        );
        $this->actingAs($context['user']);
        $response = $this->get('/operator/bookings/'.$booking['id'])->assertOk()
            ->assertSee('FICTIONAL-DETAIL-BOOKING')
            ->assertSee('Fictional Resource')
            ->assertSee('Fictional Product')
            ->assertSee('Fictional Contact DETAIL')
            ->assertSee('fictional-private-detail@example.test')
            ->assertSee('Party size: 7')
            ->assertSee('Fictional Meeting Point DETAIL')
            ->assertSee('Fictional Service Location DETAIL')
            ->assertSee('FICTIONAL_DIRECT')
            ->assertSee('FICTIONAL-AGENT-DETAIL')
            ->assertSee('Fictional Service Notes DETAIL')
            ->assertSee('Fictional Internal Notes DETAIL')
            ->assertSee('THB 250000 minor units')
            ->assertSee('Trip status: PLANNED')
            ->assertSee('Actual departed at: Not recorded')
            ->assertSee('Actual returned at: Not recorded')
            ->assertSee('Completed at: Not recorded')
            ->assertSee('View Inquiry / Edit Operational Dossier')
            ->assertSee(route('operator.inquiries.show', $booking['inquiry_id']), false)
            ->assertDontSee('<button>Prepare', false)
            ->assertDontSee('<button>Depart', false)
            ->assertDontSee('<button>Return', false)
            ->assertDontSee('<button>Complete', false);
        $response->assertSee(route('operator.bookings.amend', $booking['id']), false)
            ->assertSee(route('operator.bookings.cancel', $booking['id']), false);
    }

    public function test_booking_without_inquiry_is_listed_searchable_and_has_graceful_detail(): void
    {
        $context = $this->context();
        $booking = $this->directBooking($context, 'FICTIONAL-API-DIRECT-BOOKING', '2026-09-10T01:00:00Z');
        $this->assertNull($booking['inquiry_id']);
        $this->assertNull(DB::table('bookings')->where('id', $booking['id'])->value('hold_id'));

        $this->actingAs($context['user']);
        $this->get('/operator/bookings?view=all')->assertOk()->assertSee($booking['reference']);
        $this->get('/operator/bookings?view=all&q=api-direct')->assertOk()->assertSee($booking['reference']);
        $this->get('/operator/bookings/'.$booking['id'])->assertOk()
            ->assertSee('No Operator inquiry dossier linked.')
            ->assertSee('Trip status: PLANNED');
    }

    public function test_detail_and_mutations_are_non_disclosing_across_organizations_and_permissions(): void
    {
        $allowed = $this->context();
        $foreign = $this->context();
        $allowedBooking = $this->directBooking($allowed, 'FICTIONAL-OWN-BOOKING', '2026-09-10T01:00:00Z');
        $foreignBooking = $this->directBooking($foreign, 'FICTIONAL-FOREIGN-BOOKING', '2026-09-10T01:00:00Z');
        $this->actingAs($allowed['user']);
        $this->get('/operator/bookings/'.$foreignBooking['id'])->assertNotFound();
        $this->post('/operator/bookings/'.$foreignBooking['id'].'/amend', $this->amendPayload($allowed))->assertNotFound();
        $this->post('/operator/bookings/'.$foreignBooking['id'].'/cancel', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();

        foreach (['boat_id', 'trip_template_id', 'slot_offering_id'] as $field) {
            $payload = $this->amendPayload($allowed);
            $payload[$field] = match ($field) {
                'boat_id' => $foreign['boat_id'],
                'trip_template_id' => $foreign['template_id'],
                'slot_offering_id' => $foreign['slot_id'],
            };
            $this->post('/operator/bookings/'.$allowedBooking['id'].'/amend', $payload)->assertNotFound();
        }

        $denied = $this->context('Asia/Bangkok', false);
        $deniedBooking = $this->directBooking($denied, 'FICTIONAL-DENIED-BOOKING', '2026-09-10T01:00:00Z');
        $this->actingAs($denied['user']);
        $this->get('/operator/bookings/'.$deniedBooking['id'])->assertForbidden();
        $this->post('/operator/bookings/'.$deniedBooking['id'].'/amend', $this->amendPayload($denied))->assertForbidden();
        $this->post('/operator/bookings/'.$deniedBooking['id'].'/cancel', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
    }

    public function test_list_and_detail_gets_leave_all_operational_state_unchanged(): void
    {
        $context = $this->context();
        $booking = $this->directBooking(
            $context,
            'FICTIONAL-READ-INVARIANT',
            '2026-09-10T01:00:00Z',
            'CONFIRMED',
            $this->dossier('READ'),
        );
        $before = $this->operationalState($context['organization_id']);

        $this->actingAs($context['user']);
        $this->get('/operator/bookings?view=all&q=read-invariant')->assertOk();
        $this->get('/operator/bookings/'.$booking['id'])->assertOk();

        $this->assertSame($before, $this->operationalState($context['organization_id']));
    }

    public function test_booking_context_amend_and_cancel_direct_booking_reuse_authoritative_actions_and_idempotency(): void
    {
        $context = $this->context();
        $booking = $this->directBooking($context, 'FICTIONAL-API-MUTATION', '2026-09-10T01:00:00Z');
        $this->actingAs($context['user']);

        $amendKey = (string) Str::uuid();
        $amend = [
            ...$this->amendPayload($context),
            'idempotency_key' => $amendKey,
            'service_date' => '2026-09-11',
        ];
        $path = '/operator/bookings/'.$booking['id'].'/amend';
        $this->post($path, $amend)->assertStatus(303)->assertRedirect(route('operator.bookings.show', $booking['id']));
        $this->post($path, $amend)->assertStatus(303)->assertRedirect(route('operator.bookings.show', $booking['id']));

        $this->assertDatabaseHas('bookings', [
            'id' => $booking['id'],
            'hold_id' => null,
            'service_date' => '2026-09-11',
            'business_start' => '2026-09-11 01:00:00',
            'business_end' => '2026-09-11 05:00:00',
            'status' => 'CONFIRMED',
        ]);
        $this->assertDatabaseHas('allocations', [
            'booking_id' => $booking['id'],
            'service_date' => '2026-09-11',
            'business_start' => '2026-09-11 01:00:00',
            'business_end' => '2026-09-11 05:00:00',
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('trips', [
            'booking_id' => $booking['id'],
            'planned_start' => '2026-09-11 01:00:00',
            'planned_end' => '2026-09-11 05:00:00',
            'status' => 'PLANNED',
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'booking.amended')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'booking.amended.v1')->count());
        $this->assertSame(1, DB::table('idempotency_keys')->where('operation', 'amendBooking:'.$booking['id'])->count());
        $this->assertSame(1, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));

        $this->post($path, [...$amend, 'service_date' => '2026-09-12'])
            ->assertStatus(303)
            ->assertSessionHasErrors('booking');
        $this->assertDatabaseHas('bookings', ['id' => $booking['id'], 'service_date' => '2026-09-11']);

        $cancelKey = (string) Str::uuid();
        $cancel = [
            'idempotency_key' => $cancelKey,
            'reason' => 'Fictional direct booking cancellation',
        ];
        $cancelPath = '/operator/bookings/'.$booking['id'].'/cancel';
        $this->post($cancelPath, $cancel)->assertStatus(303)->assertRedirect(route('operator.bookings.show', $booking['id']));
        $this->post($cancelPath, $cancel)->assertStatus(303)->assertRedirect(route('operator.bookings.show', $booking['id']));
        $this->assertDatabaseHas('bookings', ['id' => $booking['id'], 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('allocations', ['booking_id' => $booking['id'], 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('trips', ['booking_id' => $booking['id'], 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $context['organization_id'],
            'actor_type' => 'operator_user',
            'actor_id' => $context['user']->id,
            'action' => 'booking.cancelled',
            'object_id' => $booking['id'],
            'reason' => $cancel['reason'],
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'booking.cancelled')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'booking.cancelled.v1')->count());
        $this->assertSame(1, DB::table('idempotency_keys')->where('operation', 'cancelBooking:'.$booking['id'])->count());
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));

        $this->post($cancelPath, [...$cancel, 'reason' => 'Fictional conflict reason'])
            ->assertStatus(303)
            ->assertSessionHasErrors('booking');
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));
    }

    public function test_http_adapters_do_not_add_a_second_mutation_engine_and_reads_are_paginated(): void
    {
        $workflow = file_get_contents(app_path('Http/Controllers/Operator/BookingWorkflowController.php'));
        $workbench = file_get_contents(app_path('Http/Controllers/Operator/BookingWorkbenchController.php'));

        $this->assertStringContainsString('$this->amendBooking->execute(', $workflow);
        $this->assertStringContainsString('$this->cancelBooking->execute(', $workflow);
        $this->assertStringNotContainsString('DB::transaction', $workflow);
        $this->assertStringNotContainsString('->insert(', $workflow);
        $this->assertStringNotContainsString('->update(', $workflow);
        $this->assertStringContainsString("->leftJoin('inquiries'", $workbench);
        $this->assertStringContainsString("->where('bookings.organization_id'", $workbench);
        $this->assertStringContainsString('->paginate(self::PER_PAGE)', $workbench);
        $this->assertStringNotContainsString('whereDate(', $workbench);
        $this->assertStringNotContainsString('DB::transaction', $workbench);
        $this->assertStringNotContainsString('->insert(', $workbench);
        $this->assertStringNotContainsString('->update(', $workbench);
        $this->assertStringNotContainsString('->delete(', $workbench);
    }

    /** @return array<string, mixed> */
    private function context(string $timezone = 'Asia/Bangkok', bool $bookingPermission = true): array
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Fictional Workbench Organization '.Str::random(8),
            'timezone' => $timezone,
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::create([
            'name' => 'Fictional Workbench Operator',
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('fictional-password'),
        ]);
        DB::table('operator_memberships')->insert([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'can_calendar_read' => true,
            'can_booking_workflow' => $bookingPermission,
            'can_block' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $boatId = $this->boat($organizationId, 'Fictional Resource');
        $templateId = DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'FICTIONAL-WORKBENCH-'.Str::upper(Str::random(6)),
            'name' => 'Fictional Product',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $slotId = DB::table('slot_offerings')->insertGetId([
            'organization_id' => $organizationId,
            'kind' => 'PRESET',
            'code' => 'FICTIONAL_WORKBENCH_'.Str::upper(Str::random(6)),
            'name' => 'Fictional Morning Slot',
            'status' => 'ACTIVE',
            'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
            'service_start_time' => '08:00:00',
            'service_end_time' => '12:00:00',
            'duration_minutes' => 240,
            'additional_buffer_before_minutes' => 0,
            'additional_buffer_after_minutes' => 0,
            'applies_to_all_boats' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'organization_id' => $organizationId,
            'timezone' => $timezone,
            'user' => $user,
            'boat_id' => $boatId,
            'template_id' => $templateId,
            'slot_id' => $slotId,
        ];
    }

    private function boat(int $organizationId, string $name): int
    {
        return DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $name,
            'status' => 'ACTIVE',
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $dossier
     * @return array{id: int, reference: string, inquiry_id: int|null, trip_id: int, allocation_id: int}
     */
    private function directBooking(
        array $context,
        string $reference,
        string $startUtc,
        string $status = 'CONFIRMED',
        ?array $dossier = null,
        ?string $tripStatus = null,
        ?int $boatId = null,
    ): array {
        $boatId ??= $context['boat_id'];
        $start = CarbonImmutable::parse($startUtc)->utc();
        $end = $start->addHours(4);
        $serviceDate = $start->setTimezone($context['timezone'])->toDateString();
        $allocationStatus = $status === 'CANCELLED' ? 'CANCELLED' : 'ACTIVE';
        $now = now()->utc();
        $interval = [
            'service_date' => $serviceDate,
            'service_start' => $start,
            'service_end' => $end,
            'business_start' => $start,
            'business_end' => $end,
            'occupied_start' => $start,
            'occupied_end' => $end,
            'slot_code_snapshot' => 'FICTIONAL_WORKBENCH_SLOT',
            'slot_name_snapshot' => 'Fictional Morning Slot',
            'slot_duration_minutes_snapshot' => 240,
        ];
        $allocationId = DB::table('allocations')->insertGetId([
            'organization_id' => $context['organization_id'],
            'boat_id' => $boatId,
            'slot_offering_id' => $context['slot_id'],
            'custom_slot_instance_id' => null,
            'allocation_type' => 'BOOKING',
            'status' => $allocationStatus,
            ...$interval,
            'hold_id' => null,
            'booking_id' => null,
            'block_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $holdId = null;
        if ($dossier !== null) {
            $holdId = DB::table('holds')->insertGetId([
                'organization_id' => $context['organization_id'],
                'boat_id' => $boatId,
                'trip_template_id' => $context['template_id'],
                'slot_offering_id' => $context['slot_id'],
                'custom_slot_instance_id' => null,
                'external_reference' => $reference,
                'status' => 'CONFIRMED',
                ...$interval,
                'expires_at' => $now->addHour(),
                'allocation_id' => $allocationId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $bookingId = DB::table('bookings')->insertGetId([
            'organization_id' => $context['organization_id'],
            'hold_id' => $holdId,
            'boat_id' => $boatId,
            'trip_template_id' => $context['template_id'],
            'slot_offering_id' => $context['slot_id'],
            'custom_slot_instance_id' => null,
            'external_reference' => $reference,
            'status' => $status,
            ...$interval,
            'allocation_id' => $allocationId,
            'rate_snapshot_id' => null,
            'confirmed_at' => $now,
            'cancelled_at' => $status === 'CANCELLED' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('allocations')->where('id', $allocationId)->update([
            'hold_id' => $holdId,
            'booking_id' => $bookingId,
        ]);
        $tripId = DB::table('trips')->insertGetId([
            'organization_id' => $context['organization_id'],
            'booking_id' => $bookingId,
            'boat_id' => $boatId,
            'trip_template_id' => $context['template_id'],
            'status' => $tripStatus ?? ($status === 'CANCELLED' ? 'CANCELLED' : 'PLANNED'),
            'planned_start' => $start,
            'planned_end' => $end,
            'actual_departed_at' => null,
            'actual_returned_at' => null,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $inquiryId = null;
        if ($dossier !== null) {
            $inquiryReference = $dossier['inquiry_reference'] ?? 'FICTIONAL-INQUIRY-'.$reference;
            unset($dossier['inquiry_reference']);
            $inquiryId = DB::table('inquiries')->insertGetId([
                'organization_id' => $context['organization_id'],
                'reference' => $inquiryReference,
                'status' => 'INQUIRY',
                'boat_id' => $boatId,
                'trip_template_id' => $context['template_id'],
                'slot_offering_id' => $context['slot_id'],
                'service_date' => $serviceDate,
                'notes' => null,
                ...array_fill_keys($this->dossierFields(), null),
                ...$dossier,
                'created_by_user_id' => $context['user']->id,
                'hold_id' => $holdId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'id' => $bookingId,
            'reference' => $reference,
            'inquiry_id' => $inquiryId,
            'trip_id' => $tripId,
            'allocation_id' => $allocationId,
        ];
    }

    /** @return array<string, mixed> */
    private function dossier(string $suffix): array
    {
        return [
            'contact_name' => "Fictional Contact {$suffix}",
            'contact_method' => 'EMAIL',
            'contact_value' => 'fictional-private-'.strtolower($suffix).'@example.test',
            'party_size' => 7,
            'meeting_point' => "Fictional Meeting Point {$suffix}",
            'service_location' => "Fictional Service Location {$suffix}",
            'sales_source' => 'FICTIONAL_DIRECT',
            'agent_reference' => "FICTIONAL-AGENT-{$suffix}",
            'service_notes' => "Fictional Service Notes {$suffix}",
            'internal_notes' => "Fictional Internal Notes {$suffix}",
            'selling_currency' => 'THB',
            'selling_amount_minor' => 250000,
        ];
    }

    /** @return list<string> */
    private function dossierFields(): array
    {
        return [
            'contact_name',
            'contact_method',
            'contact_value',
            'party_size',
            'meeting_point',
            'service_location',
            'sales_source',
            'agent_reference',
            'service_notes',
            'internal_notes',
            'selling_currency',
            'selling_amount_minor',
        ];
    }

    /** @return array<string, mixed> */
    private function amendPayload(array $context): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['template_id'],
            'slot_offering_id' => $context['slot_id'],
            'service_date' => '2026-09-11',
        ];
    }

    /** @return list<string> */
    private function references($response): array
    {
        return $response->viewData('bookings')->getCollection()
            ->pluck('external_reference')
            ->values()
            ->all();
    }

    private function operationalState(int $organizationId): string
    {
        $state = [
            'inventory_revision' => DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'),
        ];
        foreach (['allocations', 'holds', 'bookings', 'trips', 'audit_logs', 'idempotency_keys', 'outbox_events'] as $table) {
            $state[$table] = DB::table($table)
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->get();
        }

        return json_encode($state, JSON_THROW_ON_ERROR);
    }
}
