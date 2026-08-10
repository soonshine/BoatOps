<?php

namespace Tests\Feature;

use App\Application\Bookings\ConfirmBookingAction;
use App\Application\Holds\HoldActor;
use App\Application\Holds\OrganizationHoldTtlPolicy;
use App\Application\Trips\DepartTripAction;
use App\Application\Trips\PrepareTripAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorBookingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_confirm_is_unpriced_exact_idempotent_and_uses_shared_action_effects(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:00:00Z'));
        $context = $this->context();
        $inquiry = $this->inquiryWithHold($context, 'FICTIONAL-OPERATOR-CONFIRM');
        $hold = DB::table('holds')->first();
        $key = (string) Str::uuid();
        $page = $this->get("/operator/inquiries/{$inquiry}")->assertOk()->assertSee('Pricing and payment are outside G1')->assertSee('explicitly unpriced booking')->assertSee('not production-commercial ready')->assertSee('Confirm unpriced booking')->assertDontSee('name="rate_snapshot"', false)->assertSee('name="selling_currency"', false)->assertSee('name="selling_amount_minor"', false)->assertDontSee('name="tax_amount_minor"', false)->assertDontSee('name="commission_amount_minor"', false)->assertDontSee('name="customer"', false)->assertDontSee('name="order"', false);
        $this->assertMatchesRegularExpression('/name="idempotency_key" value="[0-9a-f-]{36}"/', $page->getContent());
        $path = "/operator/inquiries/{$inquiry}/holds/{$hold->id}/confirm";
        $this->post($path, ['idempotency_key' => $key])->assertStatus(303);
        $this->post($path, ['idempotency_key' => $key])->assertStatus(303);
        $booking = DB::table('bookings')->first();
        $trip = DB::table('trips')->first();
        $this->assertNotNull($booking);
        $this->assertNull($booking->rate_snapshot_id);
        $this->assertDatabaseCount('rate_snapshots', 0);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('trips', 1);
        $this->assertDatabaseHas('holds', ['id' => $hold->id, 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id,            'hold_id' => $hold->id,            'allocation_id' => $hold->allocation_id,            'status' => 'CONFIRMED',            'rate_snapshot_id' => null]);
        $this->assertDatabaseHas('allocations', ['id' => $hold->allocation_id,            'allocation_type' => 'BOOKING',            'booking_id' => $booking->id,            'status' => 'ACTIVE']);
        $this->assertDatabaseHas('trips', ['id' => $trip->id,            'booking_id' => $booking->id,            'status' => 'PLANNED']);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user',            'actor_id' => $context['user']->id,            'action' => 'booking.confirmed',            'object_id' => $booking->id]);
        $auditAfter = json_decode((string) DB::table('audit_logs')->where('action', 'booking.confirmed')->value('after_values'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('rate_snapshot_id', $auditAfter);
        $this->assertNull($auditAfter['rate_snapshot_id']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'booking.confirmed.v1',            'aggregate_id' => $booking->id,            'inventory_revision' => 2]);
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'booking.confirmed')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'booking.confirmed.v1')->count());
        $this->assertSame(1, DB::table('idempotency_keys')->where('operation', 'confirmBooking')->count());
        $conflict = app(ConfirmBookingAction::class)->execute($context['organization_id'], ['hold_id' => (int) $hold->id, 'external_reference' => 'FICTIONAL-CHANGED'], $key, HoldActor::operatorUser($context['user']->id));
        $this->assertSame(409, $conflict->status);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict->payload['code']);
        $this->get("/operator/inquiries/{$inquiry}")->assertOk()->assertSee('Associated booking')->assertSee('UNPRICED / NOT PRODUCTION-COMMERCIAL READY')->assertSee('PLANNED');
    }

    public function test_existing_provider_api_still_requires_and_stores_full_rate_snapshot(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:00:00Z'));
        $context = $this->context();
        $token = Str::random(48);
        DB::table('api_clients')->insert(['organization_id' => $context['organization_id'],            'name' => 'Fictional API Adapter',            'token_hash' => hash('sha256', $token),            'scopes' => json_encode([], JSON_THROW_ON_ERROR),            'active' => true,            'created_at' => now(),            'updated_at' => now()]);
        $hold = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/holds', ['external_reference' => 'FICTIONAL-API-RATE-COMPAT',                'boat_id' => $context['boat_id'],                'trip_template_id' => $context['template_id'],                'slot_offering_id' => $context['slot_id'],                'service_date' => '2026-09-10',                'expires_at' => '2026-08-10T00:30:00Z'])->assertCreated();
        $payload = ['hold_id' => $hold->json('hold_id'),            'external_reference' => 'FICTIONAL-API-RATE-COMPAT'];
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/bookings:confirm', $payload)->assertUnprocessable()->assertJson(['code' => 'VALIDATION_FAILED', 'message' => 'The request payload is invalid.']);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('rate_snapshots', 0);
        $rate = ['source_reference' => 'FICTIONAL-API-RATE-V1',            'currency' => 'THB',            'selling_amount_minor' => 125000,            'tax_amount_minor' => 2500,            'commission_amount_minor' => 12500,            'fx_rate' => '4.50000000',            'fx_base_currency' => 'CNY',            'fx_quote_currency' => 'THB',            'quoted_at' => '2026-08-10T00:00:00Z',            'valid_until' => '2026-08-10T01:00:00Z'];
        $booking = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/bookings:confirm', $payload + ['rate_snapshot' => $rate])->assertCreated()->assertJson(['code' => 'BOOKING_CONFIRMED']);
        $snapshotId = DB::table('bookings')->where('id', $booking->json('booking_id'))->value('rate_snapshot_id');
        $this->assertNotNull($snapshotId);
        $this->assertDatabaseCount('rate_snapshots', 1);
        $this->assertDatabaseHas('rate_snapshots', ['id' => $snapshotId,            'booking_id' => $booking->json('booking_id'),            'source_reference' => $rate['source_reference'],            'currency' => $rate['currency'],            'selling_amount_minor' => $rate['selling_amount_minor'],            'tax_amount_minor' => $rate['tax_amount_minor'],            'commission_amount_minor' => $rate['commission_amount_minor'],            'fx_rate' => $rate['fx_rate'],            'fx_base_currency' => $rate['fx_base_currency'],            'fx_quote_currency' => $rate['fx_quote_currency']]);
    }

    public function test_expired_confirm_delegates_expiry_and_has_no_partial_booking_write(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:00:00Z'));
        $context = $this->context();
        $inquiry = $this->inquiryWithHold($context, 'FICTIONAL-EXPIRED-CONFIRM', 5);
        $hold = DB::table('holds')->first();
        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:06:00Z'));
        $this->post("/operator/inquiries/{$inquiry}/holds/{$hold->id}/confirm", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303)->assertSessionHasErrors('booking');
        $this->assertDatabaseHas('holds', ['id' => $hold->id, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('allocations', ['id' => $hold->allocation_id, 'status' => 'EXPIRED']);
        foreach (['bookings', 'trips', 'rate_snapshots'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }        $this->assertSame(0, DB::table('audit_logs')->where('action', 'booking.confirmed')->count());
        $this->assertSame(0, DB::table('outbox_events')->where('event_type', 'booking.confirmed.v1')->count());
    }

    public function test_amend_success_replay_conflict_overlap_and_shared_atomic_effects(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:00:00Z'));
        $context = $this->context();
        $inquiry = $this->confirmedInquiry($context, 'FICTIONAL-AMEND');
        $booking = DB::table('bookings')->first();
        $trip = DB::table('trips')->first();
        $preparePayload = [
            'crew' => [[
                'external_reference' => 'FICTIONAL-AMEND-CREW',
                'display_name' => 'Fictional Amend Captain',
                'role' => 'CAPTAIN',
                'duty' => 'CAPTAIN',
            ]],
            'checklist' => [[
                'code' => 'AMEND_READY',
                'label' => 'Fictional readiness before amendment',
                'required' => true,
                'completed' => true,
            ]],
        ];
        $this->app->make(PrepareTripAction::class)->execute(
            $context['organization_id'],
            (int) $trip->id,
            $preparePayload,
            (string) Str::uuid(),
            HoldActor::operatorUser((int) $context['user']->id),
        );
        $this->assertDatabaseCount('crew_assignments', 1);
        $this->assertDatabaseCount('trip_checklists', 1);
        $newBoat = $this->boat($context['organization_id'], 'Fictional Alternate Resource', 10, 15);
        $newTemplate = $this->template($context['organization_id'], 'Fictional Alternate Product');
        $newSlot = $this->slot($context['organization_id'], 'Fictional Afternoon Slot', '13:00:00', '17:00:00');
        $key = (string) Str::uuid();
        $path = "/operator/inquiries/{$inquiry}/bookings/{$booking->id}/amend";
        $payload = ['idempotency_key' => $key,            'boat_id' => $newBoat,            'trip_template_id' => $newTemplate,            'slot_offering_id' => $newSlot,            'service_date' => '2026-09-11'];
        $this->post($path, $payload)->assertStatus(303);
        $this->post($path, $payload)->assertStatus(303);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id,            'boat_id' => $newBoat,            'trip_template_id' => $newTemplate,            'slot_offering_id' => $newSlot,            'service_date' => '2026-09-11',            'business_start' => '2026-09-11 06:00:00',            'business_end' => '2026-09-11 10:00:00',            'rate_snapshot_id' => null]);
        $this->assertDatabaseHas('allocations', ['booking_id' => $booking->id,            'boat_id' => $newBoat,            'occupied_start' => '2026-09-11 05:50:00',            'occupied_end' => '2026-09-11 10:15:00',            'status' => 'ACTIVE']);
        $this->assertDatabaseHas('trips', ['booking_id' => $booking->id,            'boat_id' => $newBoat,            'trip_template_id' => $newTemplate,            'planned_start' => '2026-09-11 06:00:00',            'planned_end' => '2026-09-11 10:00:00']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'booking.amended')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'booking.amended.v1')->count());
        $this->assertSame(3, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));
        $this->assertDatabaseCount('crew_assignments', 0);
        $this->assertDatabaseCount('trip_checklists', 0);
        $this->assertDatabaseCount('crew_members', 1);
        $amendAudit = json_decode((string) DB::table('audit_logs')->where('action', 'booking.amended')->value('after_values'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame((int) $trip->id, $amendAudit['trip_id']);
        $this->assertTrue($amendAudit['trip_readiness_invalidated']);
        $this->assertSame(1, $amendAudit['crew_assignments_cleared']);
        $this->assertSame(1, $amendAudit['checklist_items_cleared']);
        $notReady = $this->app->make(DepartTripAction::class)->execute(
            $context['organization_id'],
            (int) $trip->id,
            ['departed_at' => '2026-08-10T00:00:00Z'],
            (string) Str::uuid(),
            HoldActor::operatorUser((int) $context['user']->id),
        );
        $this->assertSame(409, $notReady->status);
        $this->assertSame('TRIP_NOT_READY', $notReady->payload['code']);
        $this->app->make(PrepareTripAction::class)->execute(
            $context['organization_id'],
            (int) $trip->id,
            $preparePayload,
            (string) Str::uuid(),
            HoldActor::operatorUser((int) $context['user']->id),
        );
        $this->post($path, [...$payload, 'service_date' => '2026-09-12'])->assertStatus(303)->assertSessionHasErrors('booking');
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'service_date' => '2026-09-11']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'booking.amended')->count());
        $this->assertDatabaseCount('crew_assignments', 1);
        $this->assertDatabaseCount('trip_checklists', 1);
        DB::table('allocations')->insert(['organization_id' => $context['organization_id'],            'boat_id' => $newBoat,            'allocation_type' => 'BLOCKED',            'status' => 'ACTIVE',            'business_start' => '2026-09-12 06:00:00',            'business_end' => '2026-09-12 10:00:00',            'occupied_start' => '2026-09-12 06:00:00',            'occupied_end' => '2026-09-12 10:00:00',            'created_at' => now(),            'updated_at' => now()]);
        $this->post($path, [...$payload, 'idempotency_key' => (string) Str::uuid(), 'service_date' => '2026-09-12'])->assertStatus(303)->assertSessionHasErrors('booking');
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'service_date' => '2026-09-11']);
        $this->assertDatabaseHas('trips', ['booking_id' => $booking->id, 'planned_start' => '2026-09-11 06:00:00']);
        $this->assertSame(3, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'booking.amended.v1')->count());
        $this->assertDatabaseCount('crew_assignments', 1);
        $this->assertDatabaseCount('trip_checklists', 1);
        $departed = $this->app->make(DepartTripAction::class)->execute(
            $context['organization_id'],
            (int) $trip->id,
            ['departed_at' => '2026-08-10T00:00:00Z'],
            (string) Str::uuid(),
            HoldActor::operatorUser((int) $context['user']->id),
        );
        $this->assertSame(200, $departed->status);
        $this->assertSame('DEPARTED', $departed->payload['status']);
    }

    public function test_cancel_success_replay_terminal_and_exact_shared_effects(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:00:00Z'));
        $context = $this->context();
        $inquiry = $this->confirmedInquiry($context, 'FICTIONAL-CANCEL');
        $booking = DB::table('bookings')->first();
        $trip = DB::table('trips')->first();
        $key = (string) Str::uuid();
        $path = "/operator/inquiries/{$inquiry}/bookings/{$booking->id}/cancel";
        $payload = ['idempotency_key' => $key, 'reason' => 'Fictional neutral cancellation'];
        $this->post($path, $payload)->assertStatus(303);
        $this->post($path, $payload)->assertStatus(303);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('allocations', ['booking_id' => $booking->id, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('trips', ['id' => $trip->id, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('audit_logs', ['actor_type' => 'operator_user',            'actor_id' => $context['user']->id,            'action' => 'booking.cancelled',            'object_id' => $booking->id,            'reason' => $payload['reason']]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'booking.cancelled.v1',            'aggregate_id' => $booking->id,            'inventory_revision' => 3]);
        $this->assertSame(3, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'booking.cancelled')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'booking.cancelled.v1')->count());
        $this->post($path, ['idempotency_key' => (string) Str::uuid()])->assertStatus(303)->assertSessionHasErrors('booking');
        $this->assertSame(3, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'booking.cancelled')->count());
        $this->post("/operator/inquiries/{$inquiry}/bookings/{$booking->id}/amend", $this->amendPayload($context))
            ->assertStatus(303)->assertSessionHasErrors('booking');
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'booking.amended')->count());
        $this->assertSame(3, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));
    }

    public function test_permission_and_foreign_identifiers_are_non_disclosing_and_adapters_only_delegate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:00:00Z'));
        $allowed = $this->context();
        $allowedInquiry = $this->confirmedInquiry($allowed, 'FICTIONAL-ALLOWED');
        $allowedBooking = DB::table('bookings')->where('organization_id', $allowed['organization_id'])->first();
        $foreign = $this->context();
        $foreignInquiry = $this->confirmedInquiry($foreign, 'FICTIONAL-FOREIGN');
        $foreignHold = DB::table('holds')->where('organization_id', $foreign['organization_id'])->first();
        $foreignBooking = DB::table('bookings')->where('organization_id', $foreign['organization_id'])->first();
        $this->actingAs($allowed['user']);
        $this->post("/operator/inquiries/{$foreignInquiry}/holds/{$foreignHold->id}/confirm", ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->post("/operator/inquiries/{$allowedInquiry}/holds/{$foreignHold->id}/confirm", ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->post("/operator/inquiries/{$allowedInquiry}/bookings/{$foreignBooking->id}/amend", $this->amendPayload($allowed))->assertNotFound();
        $this->post("/operator/inquiries/{$allowedInquiry}/bookings/{$foreignBooking->id}/cancel", ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        foreach (['boat_id', 'trip_template_id', 'slot_offering_id'] as $field) {
            $payload = $this->amendPayload($allowed);
            $payload[$field] = match ($field) {
                'boat_id' => $foreign['boat_id'],                'trip_template_id' => $foreign['template_id'],                'slot_offering_id' => $foreign['slot_id'],
            };
            $this->post("/operator/inquiries/{$allowedInquiry}/bookings/{$allowedBooking->id}/amend", $payload)->assertNotFound();
        }        $denied = $this->context(false);
        $deniedInquiry = $this->inquiry($denied, 'FICTIONAL-DENIED');
        $this->actingAs($denied['user']);
        $this->post("/operator/inquiries/{$deniedInquiry}/holds/999/confirm", ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->post("/operator/inquiries/{$deniedInquiry}/bookings/999/amend", $this->amendPayload($denied))->assertForbidden();
        $this->post("/operator/inquiries/{$deniedInquiry}/bookings/999/cancel", ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $source = file_get_contents(app_path('Http/Controllers/Operator/BookingWorkflowController.php'));
        $this->assertStringContainsString('$this->confirmBooking->execute(', $source);
        $this->assertStringContainsString('$this->amendBooking->execute(', $source);
        $this->assertStringContainsString('$this->cancelBooking->execute(', $source);
        $this->assertStringNotContainsString('DB::transaction', $source);
        $this->assertStringNotContainsString('->insert(', $source);
        $this->assertStringNotContainsString('->update(', $source);
        $this->assertStringNotContainsString('SlotAvailability', $source);
    }

    private function context(bool $bookingPermission = true): array
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Fictional Operator Organization '.Str::random(6),            'timezone' => 'Asia/Bangkok',            'inventory_revision' => 0,            'created_at' => now(),            'updated_at' => now()]);
        $user = User::create(['name' => 'Fictional Operator',            'email' => Str::random(10).'@example.test',            'password' => Hash::make('fictional-password')]);
        DB::table('operator_memberships')->insert(['organization_id' => $organizationId,            'user_id' => $user->id,            'status' => 'ACTIVE',            'can_calendar_read' => true,            'can_booking_workflow' => $bookingPermission,            'can_block' => false,            'created_at' => now(),            'updated_at' => now()]);
        $boat = $this->boat($organizationId, 'Fictional Primary Resource');
        $template = $this->template($organizationId, 'Fictional Primary Product');
        $slot = $this->slot($organizationId, 'Fictional Morning Slot', '08:00:00', '12:00:00');

        return ['organization_id' => $organizationId,            'user' => $user,            'boat_id' => $boat,            'template_id' => $template,            'slot_id' => $slot];
    }

    private function inquiryWithHold(array $context, string $reference, int $ttl = 30): int
    {
        DB::table('organization_settings')->insert(['organization_id' => $context['organization_id'],            'key' => OrganizationHoldTtlPolicy::KEY,            'value' => (string) $ttl,            'created_at' => now(),            'updated_at' => now()]);
        $inquiry = $this->inquiry($context, $reference);
        $this->actingAs($context['user']);
        $this->post("/operator/inquiries/{$inquiry}/hold", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303);

        return $inquiry;
    }

    private function confirmedInquiry(array $context, string $reference): int
    {
        $inquiry = $this->inquiryWithHold($context, $reference);
        $holdId = (int) DB::table('inquiries')->where('id', $inquiry)->value('hold_id');
        $this->post("/operator/inquiries/{$inquiry}/holds/{$holdId}/confirm", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303);

        return $inquiry;
    }

    private function inquiry(array $context, string $reference): int
    {
        return DB::table('inquiries')->insertGetId(['organization_id' => $context['organization_id'],            'reference' => $reference,            'status' => 'INQUIRY',            'boat_id' => $context['boat_id'],            'trip_template_id' => $context['template_id'],            'slot_offering_id' => $context['slot_id'],            'service_date' => '2026-09-10',            'notes' => 'Fictional neutral note',            'created_by_user_id' => $context['user']->id,            'created_at' => now(),            'updated_at' => now()]);
    }

    private function boat(int $organizationId, string $name, int $before = 0, int $after = 0): int
    {
        return DB::table('boats')->insertGetId(['organization_id' => $organizationId,            'name' => $name,            'status' => 'ACTIVE',            'buffer_before_minutes' => $before,            'buffer_after_minutes' => $after,            'created_at' => now(),            'updated_at' => now()]);
    }

    private function template(int $organizationId, string $name): int
    {
        return DB::table('trip_templates')->insertGetId(['organization_id' => $organizationId,            'code' => 'FICTIONAL-'.Str::upper(Str::random(8)),            'name' => $name,            'status' => 'ACTIVE',            'created_at' => now(),            'updated_at' => now()]);
    }

    private function slot(int $organizationId, string $name, string $start, string $end): int
    {
        return DB::table('slot_offerings')->insertGetId(['organization_id' => $organizationId,            'kind' => 'PRESET',            'code' => 'FICTIONAL_SLOT_'.Str::upper(Str::random(8)),            'name' => $name,            'status' => 'ACTIVE',            'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',            'service_start_time' => $start,            'service_end_time' => $end,            'duration_minutes' => 240,            'additional_buffer_before_minutes' => 0,            'additional_buffer_after_minutes' => 0,            'applies_to_all_boats' => true,            'created_at' => now(),            'updated_at' => now()]);
    }

    private function amendPayload(array $context): array
    {
        return ['idempotency_key' => (string) Str::uuid(),            'boat_id' => $context['boat_id'],            'trip_template_id' => $context['template_id'],            'slot_offering_id' => $context['slot_id'],            'service_date' => '2026-09-12'];
    }
}
