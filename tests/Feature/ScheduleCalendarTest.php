<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Database\Seeders\SlotCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScheduleCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_seven_day_calendar_contains_available_slots_for_each_boat_and_local_date(): void
    {
        $context = $this->context();
        $response = $this->calendar($context, '2026-09-01', '2026-09-07')
            ->assertOk()
            ->assertJsonPath('organization_id', $context['organization_id'])
            ->assertJsonPath('business_timezone', 'Asia/Bangkok')
            ->assertJsonPath('from', '2026-09-01')
            ->assertJsonPath('to', '2026-09-07')
            ->assertJsonPath('inventory_truth', 'ACTIVE_ALLOCATIONS')
            ->assertJsonCount(2, 'boats')
            ->assertJsonCount(7, 'boats.0.dates')
            ->assertJsonCount(7, 'boats.1.dates');

        foreach ($response->json('boats') as $boat) {
            foreach ($boat['dates'] as $date) {
                $am = collect($date['slots'])->firstWhere('code', 'AM_4H');
                $this->assertNotNull($am);
                $this->assertSame('AVAILABLE', $am['status']);
                $this->assertTrue($am['selectable']);
                $this->assertNull($am['conflict_code']);
            }
        }
    }

    public function test_active_hold_and_confirmed_booking_come_from_authoritative_allocation_status(): void
    {
        $heldContext = $this->context();
        $hold = $this->createHold($heldContext, 'AM_4H', '2026-09-08', 'CALENDAR-HELD');
        $heldCalendar = $this->calendar($heldContext, '2026-09-08', '2026-09-08')->assertOk();
        $heldSlot = $this->slot($heldCalendar->json(), $heldContext['boat_id'], 'AM_4H');
        $heldAllocation = $this->allocation($heldCalendar->json(), $heldContext['boat_id'], 'HELD');

        $this->assertSame('HELD', $heldSlot['status']);
        $this->assertSame('SLOT_UNAVAILABLE', $heldSlot['conflict_code']);
        $this->assertSame(
            (int) DB::table('holds')->where('id', $hold->json('hold_id'))->value('allocation_id'),
            $heldSlot['authority']['allocation_id'],
        );
        $this->assertSame('HELD', $heldAllocation['status']);
        $this->assertSame('2026-09-08T01:00:00Z', $heldAllocation['service_start']);
        $this->assertSame('2026-09-08T05:00:00Z', $heldAllocation['service_end']);

        $confirmedContext = $this->context();
        $confirmableHold = $this->createHold(
            $confirmedContext,
            'AM_4H',
            '2026-09-09',
            'CALENDAR-CONFIRMED',
        );
        $booking = $this->withToken($confirmedContext['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings:confirm', [
                'hold_id' => $confirmableHold->json('hold_id'),
                'external_reference' => 'CALENDAR-CONFIRMED',
                'rate_snapshot' => [
                    'source_reference' => 'FICTIONAL-CALENDAR-RATE',
                    'currency' => 'THB',
                    'selling_amount_minor' => 100000,
                    'tax_amount_minor' => 0,
                    'commission_amount_minor' => 0,
                    'quoted_at' => '2026-08-01T00:00:00Z',
                    'valid_until' => '2026-08-01T01:00:00Z',
                ],
            ])->assertCreated();
        $confirmedCalendar = $this->calendar($confirmedContext, '2026-09-09', '2026-09-09')->assertOk();
        $confirmedSlot = $this->slot($confirmedCalendar->json(), $confirmedContext['boat_id'], 'AM_4H');
        $confirmedAllocation = $this->allocation(
            $confirmedCalendar->json(),
            $confirmedContext['boat_id'],
            'CONFIRMED',
        );

        $this->assertSame('CONFIRMED', $confirmedSlot['status']);
        $this->assertSame('SLOT_UNAVAILABLE', $confirmedSlot['conflict_code']);
        $this->assertSame($booking->json('booking_id'), (int) DB::table('allocations')
            ->where('id', $confirmedSlot['authority']['allocation_id'])->value('booking_id'));
        $this->assertSame('CONFIRMED', $confirmedAllocation['status']);
        $this->assertSame('ACTIVE_ALLOCATION', $confirmedAllocation['authority']);
    }

    public function test_block_allocation_renders_blocked_with_actual_occupied_interval(): void
    {
        $context = $this->context();
        $block = $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/blocks', [
                'external_reference' => 'FICTIONAL-CALENDAR-BLOCK',
                'boat_id' => $context['boat_id'],
                'starts_at' => '2026-09-10T02:15:00Z',
                'ends_at' => '2026-09-10T04:45:00Z',
                'reason_code' => 'MAINTENANCE',
                'reason' => 'Fictional calendar test block',
            ])->assertCreated();
        $calendar = $this->calendar($context, '2026-09-10', '2026-09-10')->assertOk();
        $am = $this->slot($calendar->json(), $context['boat_id'], 'AM_4H');
        $allocation = $this->allocation($calendar->json(), $context['boat_id'], 'BLOCKED');

        $this->assertSame('BLOCKED', $am['status']);
        $this->assertSame('SLOT_UNAVAILABLE', $am['conflict_code']);
        $this->assertSame($block->json('block_id'), (int) DB::table('allocations')
            ->where('id', $allocation['allocation_id'])->value('block_id'));
        $this->assertSame('2026-09-10T02:15:00Z', $allocation['occupied_start']);
        $this->assertSame('2026-09-10T04:45:00Z', $allocation['occupied_end']);
        $this->assertSame($allocation['occupied_start'], $am['authority']['occupied_start']);
        $this->assertSame($allocation['occupied_end'], $am['authority']['occupied_end']);
    }

    public function test_buffer_overlap_uses_slot_unavailable_after_explicit_allow(): void
    {
        $context = $this->context(31, 31);
        $amId = $this->slotId($context['organization_id'], 'AM_4H');
        $pmId = $this->slotId($context['organization_id'], 'PM_4H');
        $this->withToken($context['token'])
            ->postJson('/api/internal/v1/schedule/compatibility-rules', [
                'first_slot_offering_id' => $amId,
                'second_slot_offering_id' => $pmId,
                'policy' => 'ALLOW',
                'reason' => 'Fictional buffer-only conflict test',
            ])->assertOk();
        $this->createHold($context, 'AM_4H', '2026-09-11', 'CALENDAR-BUFFER');
        $calendar = $this->calendar($context, '2026-09-11', '2026-09-11')->assertOk();
        $pm = $this->slot($calendar->json(), $context['boat_id'], 'PM_4H');

        $this->assertSame('HELD', $pm['status']);
        $this->assertSame('SLOT_UNAVAILABLE', $pm['conflict_code']);
        $this->assertTrue($pm['buffer_conflict']);
        $this->assertSame('2026-09-11T06:00:00Z', $pm['service_start']);
        $this->assertSame('2026-09-11T05:29:00Z', $pm['occupied_start']);
        $this->assertSame('2026-09-11T05:31:00Z', $pm['authority']['occupied_end']);
    }

    public function test_compatibility_deny_is_stable_and_different_boat_remains_available(): void
    {
        $context = $this->context();
        $this->createHold($context, 'AM_4H', '2026-09-12', 'CALENDAR-DENY');
        $calendar = $this->calendar($context, '2026-09-12', '2026-09-12')->assertOk();
        $sameBoatPm = $this->slot($calendar->json(), $context['boat_id'], 'PM_4H');
        $otherBoatAm = $this->slot($calendar->json(), $context['other_boat_id'], 'AM_4H');
        $otherBoatPm = $this->slot($calendar->json(), $context['other_boat_id'], 'PM_4H');

        $this->assertSame('UNAVAILABLE', $sameBoatPm['status']);
        $this->assertSame('SLOT_COMPATIBILITY_CONFLICT', $sameBoatPm['conflict_code']);
        $this->assertFalse($sameBoatPm['selectable']);
        $this->assertSame('AVAILABLE', $otherBoatAm['status']);
        $this->assertSame('AVAILABLE', $otherBoatPm['status']);
        $this->assertNull($otherBoatAm['authority']);
        $this->assertNull($otherBoatPm['conflict_code']);
    }

    public function test_calendar_uses_completed_booking_compatibility_without_reporting_physical_occupancy(): void
    {
        $context = $this->context();
        $hold = $this->createHold($context, 'AM_4H', '2026-09-15', 'CALENDAR-COMPLETED');
        $booking = $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings:confirm', [
                'hold_id' => $hold->json('hold_id'),
                'external_reference' => 'CALENDAR-COMPLETED',
                'rate_snapshot' => [
                    'source_reference' => 'FICTIONAL-CALENDAR-COMPLETED',
                    'currency' => 'THB',
                    'selling_amount_minor' => 100000,
                    'tax_amount_minor' => 0,
                    'commission_amount_minor' => 0,
                    'quoted_at' => '2026-08-01T00:00:00Z',
                    'valid_until' => '2026-08-01T01:00:00Z',
                ],
            ])->assertCreated();
        $bookingId = (int) $booking->json('booking_id');
        $allocationId = (int) DB::table('bookings')->where('id', $bookingId)->value('allocation_id');
        DB::table('bookings')->where('id', $bookingId)->update(['status' => 'COMPLETED']);
        DB::table('allocations')->where('id', $allocationId)->update(['status' => 'COMPLETED']);

        $calendar = $this->calendar($context, '2026-09-15', '2026-09-15')->assertOk();
        $sameBoatPm = $this->slot($calendar->json(), $context['boat_id'], 'PM_4H');
        $otherBoatPm = $this->slot($calendar->json(), $context['other_boat_id'], 'PM_4H');

        $this->assertSame('UNAVAILABLE', $sameBoatPm['status']);
        $this->assertSame('SLOT_COMPATIBILITY_CONFLICT', $sameBoatPm['conflict_code']);
        $this->assertFalse($sameBoatPm['selectable']);
        $this->assertSame('AVAILABLE', $otherBoatPm['status']);
        $this->assertCount(0, $calendar->json('boats.0.dates.0.allocations'));

        $this->withToken($context['token'])
            ->postJson('/api/internal/v1/schedule/compatibility-rules', [
                'first_slot_offering_id' => $this->slotId($context['organization_id'], 'AM_4H'),
                'second_slot_offering_id' => $this->slotId($context['organization_id'], 'PM_4H'),
                'policy' => 'ALLOW',
                'reason' => 'Fictional completed Booking calendar allowance',
            ])->assertOk();
        $allowedCalendar = $this->calendar($context, '2026-09-15', '2026-09-15')->assertOk();
        $allowedPm = $this->slot($allowedCalendar->json(), $context['boat_id'], 'PM_4H');
        $this->assertSame('AVAILABLE', $allowedPm['status']);
        $this->assertTrue($allowedPm['selectable']);
    }

    public function test_bangkok_date_boundary_and_date_specific_custom_slot_are_not_cut_by_server_timezone(): void
    {
        $context = $this->context(15, 20);
        $created = $this->withToken($context['token'])
            ->postJson('/api/internal/v1/schedule/custom-slot-instances', [
                'code' => 'BANGKOK_BOUNDARY_INSTANCE',
                'name' => 'Fictional Bangkok Boundary Instance',
                'status' => 'ACTIVE',
                'service_date' => '2026-09-13',
                'service_start_time' => '00:30',
                'service_end_time' => '02:30',
                'duration_minutes' => 120,
                'additional_buffer_before_minutes' => 10,
                'additional_buffer_after_minutes' => 5,
                'applies_to_all_boats' => false,
                'boat_ids' => [$context['boat_id']],
            ])->assertCreated();
        $instanceId = $created->json('custom_slot_instance.id');
        $calendar = $this->calendar($context, '2026-09-13', '2026-09-13')->assertOk();
        $slot = collect($calendar->json('boats.0.dates.0.slots'))->firstWhere('definition_id', $instanceId);

        $this->assertNotNull($slot);
        $this->assertSame('2026-09-12T17:30:00Z', $slot['service_start']);
        $this->assertSame('2026-09-12T19:30:00Z', $slot['service_end']);
        $this->assertSame('2026-09-12T17:05:00Z', $slot['occupied_start']);
        $this->assertSame('2026-09-12T19:55:00Z', $slot['occupied_end']);
        $this->assertStringStartsWith('2026-09-13T00:30:00+07:00', $slot['service_start_local']);
        $this->assertSame('2026-09-13', $calendar->json('boats.0.dates.0.date'));
    }

    public function test_invalid_reversed_and_over_31_day_ranges_are_rejected(): void
    {
        $context = $this->context();

        $this->withToken($context['token'])
            ->getJson('/api/internal/v1/schedule/calendar?from=2026-02-30&to=2026-03-01')
            ->assertUnprocessable()
            ->assertJson(['code' => 'VALIDATION_FAILED']);
        $this->withToken($context['token'])
            ->getJson('/api/internal/v1/schedule/calendar?from=2026-09-10&to=2026-09-01')
            ->assertUnprocessable()
            ->assertJson(['code' => 'VALIDATION_FAILED']);
        $this->withToken($context['token'])
            ->getJson('/api/internal/v1/schedule/calendar?from=2026-09-01&to=2026-10-01')
            ->assertOk()
            ->assertJsonCount(31, 'boats.0.dates');
        $this->withToken($context['token'])
            ->getJson('/api/internal/v1/schedule/calendar?from=2026-09-01&to=2026-10-02')
            ->assertUnprocessable()
            ->assertJson(['code' => 'VALIDATION_FAILED']);
        $this->withToken($context['token'])
            ->getJson('/api/internal/v1/schedule/calendar?from=2026-09-01')
            ->assertUnprocessable()
            ->assertJson(['code' => 'VALIDATION_FAILED']);
    }

    public function test_calendar_response_contains_no_customer_contact_lodging_or_price_fields(): void
    {
        $context = $this->context();
        $this->createHold($context, 'AM_4H', '2026-09-14', 'CALENDAR-NO-PII');
        $payload = $this->calendar($context, '2026-09-14', '2026-09-14')->assertOk()->json();
        $serialized = strtolower(json_encode($payload, JSON_THROW_ON_ERROR));

        foreach ([
            'customer_name',
            'customer_phone',
            'phone_number',
            'telephone',
            'email_address',
            'hotel_name',
            'hotel',
            'room_number',
            'room_no',
            'selling_amount',
            'price',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
        $this->assertStringContainsString('active_allocations', $serialized);
        $this->assertStringContainsString('whole_boat', $serialized);
    }

    /**
     * @return array<string, int|string>
     */
    private function context(int $bufferBefore = 0, int $bufferAfter = 0): array
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00 UTC');
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Fictional Calendar '.Str::random(8),
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = Str::random(48);
        $apiClientId = DB::table('api_clients')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Fictional Calendar Client',
            'token_hash' => hash('sha256', $token),
            'scopes' => json_encode([
                'operations.schedule.write',
                'operations.write',
            ], JSON_THROW_ON_ERROR),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $boatId = $this->createBoat($organizationId, 'Fictional Calendar A', $bufferBefore, $bufferAfter);
        $otherBoatId = $this->createBoat($organizationId, 'Fictional Calendar B', $bufferBefore, $bufferAfter);
        $tripTemplateId = DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'FICTIONAL-CALENDAR-TRIP',
            'name' => 'Fictional Calendar Trip',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seed(SlotCatalogSeeder::class);

        return [
            'organization_id' => $organizationId,
            'token' => $token,
            'api_client_id' => $apiClientId,
            'boat_id' => $boatId,
            'other_boat_id' => $otherBoatId,
            'trip_template_id' => $tripTemplateId,
        ];
    }

    private function createBoat(
        int $organizationId,
        string $name,
        int $bufferBefore,
        int $bufferAfter,
    ): int {
        return DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $name,
            'status' => 'ACTIVE',
            'buffer_before_minutes' => $bufferBefore,
            'buffer_after_minutes' => $bufferAfter,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function slotId(int $organizationId, string $code): int
    {
        return (int) DB::table('slot_offerings')
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->value('id');
    }

    private function createHold(array $context, string $code, string $date, string $reference): mixed
    {
        return $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => $reference,
                'boat_id' => $context['boat_id'],
                'trip_template_id' => $context['trip_template_id'],
                'slot_offering_id' => $this->slotId($context['organization_id'], $code),
                'service_date' => $date,
                'expires_at' => '2026-08-01T00:30:00Z',
            ])->assertCreated();
    }

    private function calendar(array $context, string $from, string $to): mixed
    {
        return $this->withToken($context['token'])->getJson(
            "/api/internal/v1/schedule/calendar?from={$from}&to={$to}",
        );
    }

    /**
     * @param  array<string, mixed>  $calendar
     * @return array<string, mixed>
     */
    private function slot(array $calendar, int $boatId, string $code): array
    {
        $boat = collect($calendar['boats'])->firstWhere('boat_id', $boatId);
        $slot = collect($boat['dates'][0]['slots'])->firstWhere('code', $code);
        $this->assertNotNull($slot);

        return $slot;
    }

    /**
     * @param  array<string, mixed>  $calendar
     * @return array<string, mixed>
     */
    private function allocation(array $calendar, int $boatId, string $status): array
    {
        $boat = collect($calendar['boats'])->firstWhere('boat_id', $boatId);
        $allocation = collect($boat['dates'][0]['allocations'])->firstWhere('status', $status);
        $this->assertNotNull($allocation);

        return $allocation;
    }
}
