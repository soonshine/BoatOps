<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SlotCatalog\SlotCompatibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperatorCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_fleet_inventory_calendar_renders_status_matrix_navigation_and_existing_workflow_links(): void
    {
        $context = $this->context();
        $this->actingAs($context['user']);
        $inventoryCounts = $this->inventoryCounts();

        $response = $this->get('/operator/calendar?from=2026-09-01&range=7')
            ->assertOk()
            ->assertViewIs('operator.calendar')
            ->assertSee('Fleet Inventory')
            ->assertSee('data-fleet-calendar', false)
            ->assertSee('data-view-days="7"', false)
            ->assertSee('data-calendar-status="AVAILABLE"', false)
            ->assertSee('data-calendar-status="HELD"', false)
            ->assertSee('data-calendar-status="CONFIRMED"', false)
            ->assertSee('data-calendar-status="BLOCKED"', false)
            ->assertSee('data-calendar-status="UNAVAILABLE"', false)
            ->assertSee('Buffer −15 / +20 min', false)
            ->assertDontSee('definition #', false)
            ->assertDontSee('as_of', false);

        $this->assertSame([
            'AVAILABLE' => 25,
            'HELD' => 1,
            'CONFIRMED' => 1,
            'BLOCKED' => 1,
        ], $response->viewData('summary'));
        $this->assertCount(7, $response->viewData('dateHeaders'));
        $response->assertSee(route('operator.inquiries.create', [
            'boat_id' => $context['boat_ids']['available'],
            'service_date' => '2026-09-01',
            'slot_offering_id' => $context['slot_ids']['available'],
        ]));
        $response->assertSee(route('operator.inquiries.show', $context['inquiry_id']), false);
        $response->assertSee(route('operator.trips.show', $context['trip_id']), false);
        $response->assertSee(route('operator.blocks.index'), false);

        $fourteenDays = $this->get('/operator/calendar?from=2026-09-01&range=14&boat_id='.$context['boat_ids']['available'])
            ->assertOk();
        $this->assertCount(14, $fourteenDays->viewData('dateHeaders'));
        $this->assertCount(14, $fourteenDays->viewData('calendar')['boats'][0]['dates']);
        $this->assertSame('2026-08-18', $fourteenDays->viewData('previousFrom'));
        $this->assertSame('2026-09-15', $fourteenDays->viewData('nextFrom'));
        $this->assertSame($inventoryCounts, $this->inventoryCounts());

        $this->get(route('operator.inquiries.create', [
            'boat_id' => $context['boat_ids']['available'],
            'service_date' => '2026-09-01',
            'slot_offering_id' => $context['slot_ids']['available'],
        ]))
            ->assertOk()
            ->assertSee('value="2026-09-01"', false)
            ->assertSee('value="'.$context['boat_ids']['available'].'" selected', false)
            ->assertSee('value="'.$context['slot_ids']['available'].'" selected', false);
        $this->assertSame($inventoryCounts, $this->inventoryCounts());
    }

    public function test_calendar_read_only_operator_sees_inventory_without_mutation_actions(): void
    {
        $context = $this->context(false, false);
        $this->actingAs($context['user']);

        $response = $this->get('/operator/calendar?from=2026-09-01&range=7')
            ->assertOk()
            ->assertSee('data-calendar-status="AVAILABLE"', false)
            ->assertSee('data-calendar-status="HELD"', false)
            ->assertSee('data-calendar-status="CONFIRMED"', false)
            ->assertSee('data-calendar-status="BLOCKED"', false)
            ->assertSee('No permitted direct action. Inventory detail remains visible.')
            ->assertDontSee('Start inquiry')
            ->assertDontSee(route('operator.inquiries.show', $context['inquiry_id']), false)
            ->assertDontSee(route('operator.trips.show', $context['trip_id']), false);

        $this->assertSame(
            array_sum($response->viewData('summary')),
            substr_count($response->getContent(), 'No permitted direct action. Inventory detail remains visible.'),
        );
    }

    public function test_calendar_shows_buffer_only_occupied_interval_conflict(): void
    {
        $context = $this->context();
        $now = now();
        $bufferSlotId = DB::table('slot_offerings')->insertGetId([
            'organization_id' => $context['organization_id'],
            'template_slot_offering_id' => null,
            'kind' => 'PRESET',
            'code' => 'FLEET_BUFFER_ONLY',
            'name' => 'Turnaround Service',
            'status' => 'ACTIVE',
            'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
            'service_date' => null,
            'service_start_time' => '12:30:00',
            'service_end_time' => '14:30:00',
            'duration_minutes' => 120,
            'additional_buffer_before_minutes' => 0,
            'additional_buffer_after_minutes' => 0,
            'valid_from' => null,
            'valid_until' => null,
            'applies_to_all_boats' => false,
            'created_by_api_client_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('slot_offering_boats')->insert([
            'organization_id' => $context['organization_id'],
            'slot_offering_id' => $bufferSlotId,
            'boat_id' => $context['boat_ids']['held'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        app(SlotCompatibilityService::class)->setRule(
            $context['organization_id'],
            $context['slot_ids']['held'],
            $bufferSlotId,
            'ALLOW',
        );

        $this->actingAs($context['user'])
            ->get('/operator/calendar?from=2026-09-01&range=7&boat_id='.$context['boat_ids']['held'])
            ->assertOk()
            ->assertSee('data-slot-code="FLEET_BUFFER_ONLY"', false)
            ->assertSee('data-calendar-status="HELD"', false)
            ->assertSee('Buffer conflict · occupied 12:15–14:50');
    }

    /**
     * @return array<string, mixed>
     */
    private function context(bool $canBooking = true, bool $canBlock = true): array
    {
        $now = now();
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Fictional Fleet Operations',
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 7,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $user = User::create([
            'name' => 'Fictional Fleet Operator',
            'email' => 'fleet-operator@example.test',
            'password' => Hash::make('fictional-password'),
        ]);
        DB::table('operator_memberships')->insert([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'can_calendar_read' => true,
            'can_booking_workflow' => $canBooking,
            'can_block' => $canBlock,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $tripTemplateId = DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'FICTIONAL-FLEET-SERVICE',
            'name' => 'Fictional Fleet Service',
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $boatIds = [];
        $slotIds = [];

        foreach ([
            'available' => ['Aster', 'ACTIVE'],
            'held' => ['Borealis', 'ACTIVE'],
            'confirmed' => ['Calypso', 'ACTIVE'],
            'blocked' => ['Dorado', 'ACTIVE'],
            'unavailable' => ['Endeavour', 'RETIRED'],
        ] as $key => [$boatName, $slotStatus]) {
            $boatIds[$key] = DB::table('boats')->insertGetId([
                'organization_id' => $organizationId,
                'name' => $boatName,
                'status' => 'ACTIVE',
                'buffer_before_minutes' => 15,
                'buffer_after_minutes' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $slotIds[$key] = DB::table('slot_offerings')->insertGetId([
                'organization_id' => $organizationId,
                'template_slot_offering_id' => null,
                'kind' => 'PRESET',
                'code' => 'FLEET_'.strtoupper($key),
                'name' => ucfirst($key).' Service',
                'status' => $slotStatus,
                'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
                'service_date' => null,
                'service_start_time' => '08:00:00',
                'service_end_time' => '12:00:00',
                'duration_minutes' => 240,
                'additional_buffer_before_minutes' => 0,
                'additional_buffer_after_minutes' => 0,
                'valid_from' => null,
                'valid_until' => null,
                'applies_to_all_boats' => false,
                'created_by_api_client_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('slot_offering_boats')->insert([
                'organization_id' => $organizationId,
                'slot_offering_id' => $slotIds[$key],
                'boat_id' => $boatIds[$key],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $holdId = DB::table('holds')->insertGetId([
            'organization_id' => $organizationId,
            'boat_id' => $boatIds['held'],
            'trip_template_id' => $tripTemplateId,
            'slot_offering_id' => $slotIds['held'],
            'custom_slot_instance_id' => null,
            'external_reference' => 'FLEET-HELD',
            'status' => 'ACTIVE',
            'service_date' => '2026-09-01',
            'service_start' => '2026-09-01 01:00:00',
            'service_end' => '2026-09-01 05:00:00',
            'business_start' => '2026-09-01 01:00:00',
            'business_end' => '2026-09-01 05:00:00',
            'occupied_start' => '2026-09-01 00:45:00',
            'occupied_end' => '2026-09-01 05:20:00',
            'slot_code_snapshot' => 'FLEET_HELD',
            'slot_name_snapshot' => 'Held Service',
            'slot_duration_minutes_snapshot' => 240,
            'expires_at' => '2026-09-01 06:00:00',
            'allocation_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $heldAllocationId = $this->allocation(
            $organizationId,
            $boatIds['held'],
            $slotIds['held'],
            'HOLD',
            $holdId,
        );
        DB::table('holds')->where('id', $holdId)->update(['allocation_id' => $heldAllocationId]);
        $inquiryId = DB::table('inquiries')->insertGetId([
            'organization_id' => $organizationId,
            'reference' => 'FLEET-INQUIRY',
            'status' => 'HOLD',
            'boat_id' => $boatIds['held'],
            'trip_template_id' => $tripTemplateId,
            'slot_offering_id' => $slotIds['held'],
            'service_date' => '2026-09-01',
            'notes' => null,
            'created_by_user_id' => $user->id,
            'hold_id' => $holdId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $confirmedAllocationId = $this->allocation(
            $organizationId,
            $boatIds['confirmed'],
            $slotIds['confirmed'],
            'BOOKING',
        );
        $bookingId = DB::table('bookings')->insertGetId([
            'organization_id' => $organizationId,
            'hold_id' => null,
            'boat_id' => $boatIds['confirmed'],
            'trip_template_id' => $tripTemplateId,
            'slot_offering_id' => $slotIds['confirmed'],
            'custom_slot_instance_id' => null,
            'external_reference' => 'FLEET-CONFIRMED',
            'status' => 'CONFIRMED',
            'service_date' => '2026-09-01',
            'service_start' => '2026-09-01 01:00:00',
            'service_end' => '2026-09-01 05:00:00',
            'business_start' => '2026-09-01 01:00:00',
            'business_end' => '2026-09-01 05:00:00',
            'occupied_start' => '2026-09-01 00:45:00',
            'occupied_end' => '2026-09-01 05:20:00',
            'slot_code_snapshot' => 'FLEET_CONFIRMED',
            'slot_name_snapshot' => 'Confirmed Service',
            'slot_duration_minutes_snapshot' => 240,
            'allocation_id' => $confirmedAllocationId,
            'rate_snapshot_id' => null,
            'confirmed_at' => $now,
            'cancelled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('allocations')->where('id', $confirmedAllocationId)->update(['booking_id' => $bookingId]);
        $tripId = DB::table('trips')->insertGetId([
            'organization_id' => $organizationId,
            'booking_id' => $bookingId,
            'boat_id' => $boatIds['confirmed'],
            'trip_template_id' => $tripTemplateId,
            'status' => 'PLANNED',
            'planned_start' => '2026-09-01 01:00:00',
            'planned_end' => '2026-09-01 05:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $blockId = DB::table('blocks')->insertGetId([
            'organization_id' => $organizationId,
            'boat_id' => $boatIds['blocked'],
            'external_reference' => 'FLEET-BLOCKED',
            'status' => 'ACTIVE',
            'reason_code' => 'MAINTENANCE',
            'reason' => 'Fictional scheduled maintenance',
            'business_start' => '2026-09-01 01:00:00',
            'business_end' => '2026-09-01 05:00:00',
            'occupied_start' => '2026-09-01 00:45:00',
            'occupied_end' => '2026-09-01 05:20:00',
            'allocation_id' => null,
            'released_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $blockedAllocationId = $this->allocation(
            $organizationId,
            $boatIds['blocked'],
            $slotIds['blocked'],
            'BLOCK',
            null,
            $blockId,
        );
        DB::table('blocks')->where('id', $blockId)->update(['allocation_id' => $blockedAllocationId]);

        return [
            'organization_id' => $organizationId,
            'user' => $user,
            'boat_ids' => $boatIds,
            'slot_ids' => $slotIds,
            'inquiry_id' => $inquiryId,
            'trip_id' => $tripId,
        ];
    }

    private function allocation(
        int $organizationId,
        int $boatId,
        int $slotOfferingId,
        string $type,
        ?int $holdId = null,
        ?int $blockId = null,
    ): int {
        return DB::table('allocations')->insertGetId([
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'slot_offering_id' => $slotOfferingId,
            'custom_slot_instance_id' => null,
            'allocation_type' => $type,
            'status' => 'ACTIVE',
            'service_date' => '2026-09-01',
            'service_start' => '2026-09-01 01:00:00',
            'service_end' => '2026-09-01 05:00:00',
            'business_start' => '2026-09-01 01:00:00',
            'business_end' => '2026-09-01 05:00:00',
            'occupied_start' => '2026-09-01 00:45:00',
            'occupied_end' => '2026-09-01 05:20:00',
            'slot_code_snapshot' => 'FLEET_'.strtoupper($type),
            'slot_name_snapshot' => ucfirst(strtolower($type)).' Service',
            'slot_duration_minutes_snapshot' => 240,
            'hold_id' => $holdId,
            'booking_id' => null,
            'block_id' => $blockId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function inventoryCounts(): array
    {
        return [
            'allocations' => DB::table('allocations')->count(),
            'holds' => DB::table('holds')->count(),
            'bookings' => DB::table('bookings')->count(),
            'blocks' => DB::table('blocks')->count(),
        ];
    }
}
