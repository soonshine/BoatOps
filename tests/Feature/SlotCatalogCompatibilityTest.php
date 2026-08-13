<?php

namespace Tests\Feature;

use App\Exceptions\SlotCatalogException;
use App\Services\SlotCatalog\SlotCatalogService;
use App\Services\SlotCatalog\SlotCompatibilityService;
use Carbon\CarbonImmutable;
use Database\Seeders\SlotCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SlotCatalogCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_presets_are_seeded_idempotently_with_a_safe_data_driven_matrix(): void
    {
        $organizationId = $this->createOrganization('Fictional Seed Matrix');

        $this->seed(SlotCatalogSeeder::class);
        $this->seed(SlotCatalogSeeder::class);

        $this->assertDatabaseCount('slot_offerings', 5);
        $this->assertDatabaseCount('slot_compatibility_rules', 10);
        $this->assertSame(
            ['AM_4H', 'FULL_DAY_6H', 'FULL_DAY_8H', 'PM_2_5H', 'PM_4H'],
            DB::table('slot_offerings')
                ->where('organization_id', $organizationId)
                ->orderBy('code')
                ->pluck('code')
                ->all(),
        );
        $this->assertSame(
            [
                'AM_4H' => 240,
                'FULL_DAY_6H' => 360,
                'FULL_DAY_8H' => 480,
                'PM_2_5H' => 150,
                'PM_4H' => 240,
            ],
            DB::table('slot_offerings')
                ->where('organization_id', $organizationId)
                ->orderBy('code')
                ->pluck('duration_minutes', 'code')
                ->map(static fn (mixed $minutes): int => (int) $minutes)
                ->all(),
        );
        $this->assertSame(5, DB::table('slot_offerings')->where('kind', 'PRESET')->where('status', 'ACTIVE')->count());
        $this->assertSame(10, DB::table('slot_compatibility_rules')->where('policy', 'DENY')->count());
    }

    public function test_full_day_eight_hours_blocks_each_other_preset(): void
    {
        $context = $this->context();
        $this->createSlotHold($context, 'FULL_DAY_8H', '2026-09-01', 'FULL8-WINNER')->assertCreated();

        foreach (['FULL_DAY_6H', 'AM_4H', 'PM_4H', 'PM_2_5H'] as $code) {
            $this->availability($context, $code, '2026-09-01')
                ->assertOk()
                ->assertJson([
                    'available' => false,
                    'code' => 'SLOT_COMPATIBILITY_CONFLICT',
                    'manual_action_required' => false,
                ]);
        }

        $this->assertDatabaseCount('allocations', 1);
    }

    public function test_full_day_six_hours_blocks_each_other_preset(): void
    {
        $context = $this->context();
        $this->createSlotHold($context, 'FULL_DAY_6H', '2026-09-02', 'FULL6-WINNER')->assertCreated();

        foreach (['FULL_DAY_8H', 'AM_4H', 'PM_4H', 'PM_2_5H'] as $code) {
            $this->availability($context, $code, '2026-09-02')
                ->assertOk()
                ->assertJson([
                    'available' => false,
                    'code' => 'SLOT_COMPATIBILITY_CONFLICT',
                ]);
        }

        $this->assertDatabaseCount('allocations', 1);
    }

    public function test_am_and_pm_can_combine_only_after_a_single_audited_allow_rule_when_buffers_touch(): void
    {
        $context = $this->context(30, 30);
        $amId = $this->slotId($context['organization_id'], 'AM_4H');
        $pmId = $this->slotId($context['organization_id'], 'PM_4H');
        $pairKey = min($amId, $pmId).':'.max($amId, $pmId);

        app(SlotCompatibilityService::class)->setRule(
            $context['organization_id'],
            $pmId,
            $amId,
            'ALLOW',
            $context['api_client_id'],
            'Fictional tested same-day split charter',
        );

        $this->assertSame(1, DB::table('slot_compatibility_rules')->where('pair_key', $pairKey)->count());
        $this->assertDatabaseHas('slot_compatibility_rules', ['pair_key' => $pairKey, 'policy' => 'ALLOW']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'slot.compatibility.updated',
            'object_type' => 'slot_compatibility_rule',
        ]);

        $this->createSlotHold($context, 'AM_4H', '2026-09-03', 'AM-ALLOW')->assertCreated();
        $this->createSlotHold($context, 'PM_4H', '2026-09-03', 'PM-ALLOW')
            ->assertCreated()
            ->assertJson([
                'slot_offering_id' => $pmId,
                'service_start' => '2026-09-03T06:00:00Z',
                'occupied_start' => '2026-09-03T05:30:00Z',
            ]);

        $this->assertDatabaseHas('allocations', [
            'slot_offering_id' => $amId,
            'occupied_end' => '2026-09-03 05:30:00',
        ]);
        $this->assertDatabaseHas('allocations', [
            'slot_offering_id' => $pmId,
            'occupied_start' => '2026-09-03 05:30:00',
        ]);
        $this->assertSame(2, DB::table('allocations')->where('status', 'ACTIVE')->count());
    }

    public function test_am_and_pm_are_rejected_by_deny_and_by_insufficient_turnaround_even_when_allowed(): void
    {
        $denied = $this->context();
        $this->createSlotHold($denied, 'AM_4H', '2026-09-04', 'AM-DENY')->assertCreated();
        $this->createSlotHold($denied, 'PM_4H', '2026-09-04', 'PM-DENY')
            ->assertConflict()
            ->assertJson(['code' => 'SLOT_COMPATIBILITY_CONFLICT']);

        $buffered = $this->context(31, 31);
        app(SlotCompatibilityService::class)->setRule(
            $buffered['organization_id'],
            $this->slotId($buffered['organization_id'], 'AM_4H'),
            $this->slotId($buffered['organization_id'], 'PM_4H'),
            'ALLOW',
        );
        $this->createSlotHold($buffered, 'AM_4H', '2026-09-04', 'AM-BUFFER')->assertCreated();
        $this->createSlotHold($buffered, 'PM_4H', '2026-09-04', 'PM-BUFFER')
            ->assertConflict()
            ->assertJson([
                'code' => 'SLOT_UNAVAILABLE',
                'message' => 'The requested slot is unavailable.',
            ]);
    }

    public function test_pm_four_hours_and_pm_two_and_a_half_hours_are_mutually_exclusive_by_default(): void
    {
        $context = $this->context();
        $this->createSlotHold($context, 'PM_4H', '2026-09-05', 'PM4-WINNER')->assertCreated();
        $this->createSlotHold($context, 'PM_2_5H', '2026-09-05', 'PM25-LOSER')
            ->assertConflict()
            ->assertJson(['code' => 'SLOT_COMPATIBILITY_CONFLICT']);

        $this->assertDatabaseCount('holds', 1);
        $this->assertDatabaseCount('allocations', 1);
    }

    public function test_custom_slot_defaults_to_deny_and_can_combine_only_after_explicit_allow(): void
    {
        $context = $this->context();
        $customId = app(SlotCatalogService::class)->createCustomInstance(
            $context['organization_id'],
            [
                'code' => 'CUSTOM-EXPLICIT-ALLOW-0905',
                'name' => 'Fictional Evening Custom Slot',
                'status' => 'ACTIVE',
                'service_date' => '2026-09-05',
                'service_start_time' => '18:00',
                'service_end_time' => '20:00',
                'duration_minutes' => 120,
            ],
        );
        $this->createSlotHold($context, 'PM_4H', '2026-09-05', 'CUSTOM-ALLOW-PM')->assertCreated();
        $this->createCustomHold($context, $customId, 'CUSTOM-DEFAULT-DENY')
            ->assertConflict()
            ->assertJson(['code' => 'SLOT_COMPATIBILITY_CONFLICT']);

        app(SlotCompatibilityService::class)->setRule(
            $context['organization_id'],
            $customId,
            $this->slotId($context['organization_id'], 'PM_4H'),
            'ALLOW',
            $context['api_client_id'],
            'Fictional non-overlapping evening combination',
        );
        $this->createCustomHold($context, $customId, 'CUSTOM-EXPLICIT-ALLOW')
            ->assertCreated()
            ->assertJson([
                'custom_slot_instance_id' => $customId,
                'occupied_start' => '2026-09-05T11:00:00Z',
            ]);

        $this->assertSame(2, DB::table('allocations')->where('status', 'ACTIVE')->count());
    }

    public function test_overlapping_one_time_custom_slot_is_rejected_by_existing_allocation(): void
    {
        $context = $this->context();
        $customId = app(SlotCatalogService::class)->createCustomInstance(
            $context['organization_id'],
            [
                'code' => 'CUSTOM-OVERLAP-0906',
                'name' => 'Fictional One-Time Custom Slot',
                'status' => 'ACTIVE',
                'service_date' => '2026-09-06',
                'service_start_time' => '10:00',
                'service_end_time' => '12:00',
                'duration_minutes' => 120,
            ],
        );
        $this->createLegacyAllocation(
            $context['organization_id'],
            $context['boat_id'],
            '2026-09-06 03:30:00',
            '2026-09-06 04:30:00',
        );

        $this->customAvailability($context, $customId)
            ->assertOk()
            ->assertJson([
                'available' => false,
                'code' => 'SLOT_UNAVAILABLE',
            ]);
        $this->createCustomHold($context, $customId, 'CUSTOM-OVERLAP-LOSER')
            ->assertConflict()
            ->assertJson(['code' => 'SLOT_UNAVAILABLE']);
    }

    public function test_same_custom_slot_instance_does_not_conflict_across_boats(): void
    {
        $context = $this->context();
        $customId = app(SlotCatalogService::class)->createCustomInstance(
            $context['organization_id'],
            [
                'code' => 'CUSTOM-TWO-BOATS-0907',
                'name' => 'Fictional Fleet-Wide Custom Slot',
                'status' => 'ACTIVE',
                'service_date' => '2026-09-07',
                'service_start_time' => '10:00',
                'service_end_time' => '12:00',
                'duration_minutes' => 120,
            ],
        );

        $this->createCustomHold($context, $customId, 'CUSTOM-BOAT-A')->assertCreated();
        $context['boat_id'] = $context['other_boat_id'];
        $this->createCustomHold($context, $customId, 'CUSTOM-BOAT-B')->assertCreated();

        $this->assertSame(2, DB::table('allocations')->where('custom_slot_instance_id', $customId)->count());
        $this->assertSame(2, DB::table('allocations')->where('status', 'ACTIVE')->distinct()->count('boat_id'));
    }

    public function test_hold_confirmed_blocked_and_expired_states_keep_existing_inventory_semantics(): void
    {
        $context = $this->context();

        $confirmedHold = $this->createSlotHold($context, 'AM_4H', '2026-09-08', 'STATE-CONFIRMED')
            ->assertCreated();
        $booking = $this->confirmHold($context, $confirmedHold->json('hold_id'), 'STATE-CONFIRMED')
            ->assertCreated();
        $this->assertDatabaseHas('holds', ['id' => $confirmedHold->json('hold_id'), 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('bookings', ['id' => $booking->json('booking_id'), 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('allocations', [
            'booking_id' => $booking->json('booking_id'),
            'allocation_type' => 'BOOKING',
            'status' => 'ACTIVE',
        ]);
        $this->availability($context, 'AM_4H', '2026-09-08')
            ->assertOk()
            ->assertJson(['available' => false]);

        $expiringHold = $this->createSlotHold(
            $context,
            'AM_4H',
            '2026-09-09',
            'STATE-EXPIRES',
            '2026-08-01T00:05:00Z',
        )->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:06:00Z'));
        $this->artisan('holds:expire')->assertSuccessful();
        $this->assertDatabaseHas('holds', ['id' => $expiringHold->json('hold_id'), 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('allocations', ['hold_id' => $expiringHold->json('hold_id'), 'status' => 'EXPIRED']);
        $this->createSlotHold(
            $context,
            'AM_4H',
            '2026-09-09',
            'STATE-AFTER-EXPIRY',
            '2026-08-01T00:30:00Z',
        )->assertCreated();

        $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/blocks', [
                'external_reference' => 'STATE-BLOCKED',
                'boat_id' => $context['boat_id'],
                'starts_at' => '2026-09-10T01:00:00Z',
                'ends_at' => '2026-09-10T05:00:00Z',
                'reason_code' => 'MAINTENANCE',
            ])->assertCreated();
        $this->assertDatabaseHas('allocations', [
            'allocation_type' => 'BLOCKED',
            'status' => 'ACTIVE',
        ]);
        $this->availability($context, 'AM_4H', '2026-09-10')
            ->assertOk()
            ->assertJson([
                'available' => false,
                'code' => 'SLOT_UNAVAILABLE',
            ]);
    }

    public function test_completed_booking_remains_a_bounded_compatibility_fact_for_hold_confirm_and_amend(): void
    {
        $context = $this->context();
        $serviceDate = '2026-09-20';
        $amId = $this->slotId($context['organization_id'], 'AM_4H');
        $pmId = $this->slotId($context['organization_id'], 'PM_4H');
        $amHold = $this->createSlotHold($context, 'AM_4H', $serviceDate, 'COMPLETED-AM')
            ->assertCreated();
        $amBooking = $this->confirmHold($context, $amHold->json('hold_id'), 'COMPLETED-AM')
            ->assertCreated();
        $amBookingId = (int) $amBooking->json('booking_id');
        $amAllocationId = (int) DB::table('bookings')->where('id', $amBookingId)->value('allocation_id');
        DB::table('bookings')->where('id', $amBookingId)->update(['status' => 'COMPLETED']);
        DB::table('allocations')->where('id', $amAllocationId)->update(['status' => 'COMPLETED']);

        $this->availability($context, 'PM_4H', $serviceDate)
            ->assertOk()
            ->assertJson([
                'available' => false,
                'code' => 'SLOT_COMPATIBILITY_CONFLICT',
            ]);
        $this->createSlotHold($context, 'PM_4H', $serviceDate, 'COMPLETED-DENY-HOLD')
            ->assertConflict()
            ->assertJson(['code' => 'SLOT_COMPATIBILITY_CONFLICT']);

        $context['boat_id'] = $context['other_boat_id'];
        $this->availability($context, 'PM_4H', $serviceDate)
            ->assertOk()
            ->assertJson(['available' => true]);
        $context['boat_id'] = (int) DB::table('bookings')->where('id', $amBookingId)->value('boat_id');
        $this->availability($context, 'PM_4H', '2026-09-21')
            ->assertOk()
            ->assertJson(['available' => true]);

        app(SlotCompatibilityService::class)->setRule(
            $context['organization_id'],
            $amId,
            $pmId,
            'ALLOW',
        );
        $this->availability($context, 'PM_4H', $serviceDate)
            ->assertOk()
            ->assertJson(['available' => true]);
        $pmHold = $this->createSlotHold($context, 'PM_4H', $serviceDate, 'COMPLETED-ALLOW-HOLD')
            ->assertCreated();

        app(SlotCompatibilityService::class)->setRule(
            $context['organization_id'],
            $amId,
            $pmId,
            'DENY',
        );
        $this->confirmHold($context, $pmHold->json('hold_id'), 'COMPLETED-ALLOW-HOLD')
            ->assertConflict()
            ->assertJson(['code' => 'SLOT_COMPATIBILITY_CONFLICT']);
        $this->assertDatabaseHas('holds', ['id' => $pmHold->json('hold_id'), 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('allocations', ['hold_id' => $pmHold->json('hold_id'), 'status' => 'ACTIVE']);
        $this->assertDatabaseMissing('bookings', ['external_reference' => 'COMPLETED-ALLOW-HOLD']);
        $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds/'.$pmHold->json('hold_id').':release', [
                'external_reference' => 'COMPLETED-ALLOW-HOLD',
            ])->assertOk();

        $amendHold = $this->createSlotHold($context, 'AM_4H', '2026-09-22', 'COMPLETED-AMEND')
            ->assertCreated();
        $amendBooking = $this->confirmHold($context, $amendHold->json('hold_id'), 'COMPLETED-AMEND')
            ->assertCreated();
        $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings/'.$amendBooking->json('booking_id').':amend', [
                'external_reference' => 'COMPLETED-AMEND',
                'boat_id' => $context['boat_id'],
                'trip_template_id' => $context['trip_template_id'],
                'slot_offering_id' => $pmId,
                'service_date' => $serviceDate,
            ])->assertConflict()->assertJson(['code' => 'SLOT_COMPATIBILITY_CONFLICT']);
        $this->assertDatabaseHas('bookings', [
            'id' => $amendBooking->json('booking_id'),
            'service_date' => '2026-09-22',
            'status' => 'CONFIRMED',
        ]);

        $cancelHold = $this->createSlotHold($context, 'AM_4H', '2026-09-23', 'CANCELLED-AM')
            ->assertCreated();
        $cancelBooking = $this->confirmHold($context, $cancelHold->json('hold_id'), 'CANCELLED-AM')
            ->assertCreated();
        $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings/'.$cancelBooking->json('booking_id').':cancel', [
                'external_reference' => 'CANCELLED-AM',
            ])->assertOk();
        $this->availability($context, 'PM_4H', '2026-09-23')
            ->assertOk()
            ->assertJson(['available' => true]);
    }

    public function test_complete_at_occupied_end_preserves_same_date_compatibility_for_the_next_hold(): void
    {
        $context = $this->context();
        $hold = $this->createSlotHold($context, 'AM_4H', '2026-09-24', 'COMPLETE-CROSS-INVARIANT')
            ->assertCreated();
        $booking = $this->confirmHold(
            $context,
            $hold->json('hold_id'),
            'COMPLETE-CROSS-INVARIANT',
        )->assertCreated();
        $tripId = (int) $booking->json('trip_id');
        $tripPath = '/api/internal/v1/trips/'.$tripId;

        $this->travelTo(CarbonImmutable::parse('2026-09-24T05:00:00Z'));
        $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($tripPath.':prepare', [
                'crew' => [[
                    'external_reference' => 'FICTIONAL-CROSS-CAPTAIN',
                    'display_name' => 'Fictional Cross Captain',
                    'role' => 'CAPTAIN',
                    'duty' => 'CAPTAIN',
                ]],
                'checklist' => [[
                    'code' => 'CROSS_READY',
                    'label' => 'Fictional cross-invariant readiness',
                    'required' => true,
                    'completed' => true,
                ]],
            ])->assertOk();
        $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($tripPath.':depart', ['departed_at' => '2026-09-24T04:00:00Z'])
            ->assertOk();
        $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($tripPath.':return', ['returned_at' => '2026-09-24T05:00:00Z'])
            ->assertOk();
        $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($tripPath.':complete')
            ->assertOk()
            ->assertJson(['status' => 'COMPLETED']);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->json('booking_id'),
            'status' => 'COMPLETED',
        ]);
        $this->assertDatabaseHas('allocations', [
            'booking_id' => $booking->json('booking_id'),
            'status' => 'COMPLETED',
        ]);
        $this->createSlotHold($context, 'PM_4H', '2026-09-24', 'COMPLETE-CROSS-LOSER', '2026-09-24T05:30:00Z')
            ->assertConflict()
            ->assertJson(['code' => 'SLOT_COMPATIBILITY_CONFLICT']);
    }

    public function test_competing_requests_for_the_same_slot_have_exactly_one_winner(): void
    {
        $context = $this->context();
        $responses = [
            $this->createSlotHold($context, 'AM_4H', '2026-09-11', 'RACE-FIRST'),
            $this->createSlotHold($context, 'AM_4H', '2026-09-11', 'RACE-SECOND'),
        ];
        $statuses = array_map(static fn ($response): int => $response->getStatusCode(), $responses);
        sort($statuses);

        $this->assertSame([201, 409], $statuses);
        $this->assertSame(1, DB::table('holds')->whereIn('external_reference', ['RACE-FIRST', 'RACE-SECOND'])->count());
        $this->assertSame(1, DB::table('allocations')->where('service_date', '2026-09-11')->count());
        $loser = collect($responses)->first(static fn ($response): bool => $response->getStatusCode() === 409);
        $this->assertSame('SLOT_COMPATIBILITY_CONFLICT', $loser->json('code'));
    }

    public function test_slot_identity_is_organization_isolated(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationA = $this->createOrganization('Fictional Slot Organization A');
        $organizationB = $this->createOrganization('Fictional Slot Organization B');
        [$tokenA] = $this->createApiClient($organizationA);
        $boatA = $this->createBoat($organizationA);
        $templateA = $this->createTripTemplate($organizationA);
        $this->seed(SlotCatalogSeeder::class);
        $slotB = $this->slotId($organizationB, 'AM_4H');

        $payload = [
            'boat_id' => $boatA,
            'trip_template_id' => $templateA,
            'slot_offering_id' => $slotB,
            'service_date' => '2026-09-12',
        ];
        $this->withToken($tokenA)->postJson('/api/v1/availability:check', $payload)
            ->assertForbidden()
            ->assertJson(['code' => 'AUTHORIZATION_FAILED']);
        $this->withToken($tokenA)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', $payload + [
                'external_reference' => 'CROSS-ORG-SLOT',
                'expires_at' => '2026-08-01T00:30:00Z',
            ])->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);

        $this->assertDatabaseMissing('holds', ['external_reference' => 'CROSS-ORG-SLOT']);
    }

    public function test_bangkok_local_date_boundary_and_slot_buffers_are_converted_to_utc_without_trusting_caller_times(): void
    {
        $context = $this->context(15, 20);
        $customId = app(SlotCatalogService::class)->createCustomInstance(
            $context['organization_id'],
            [
                'code' => 'CUSTOM-BANGKOK-MIDNIGHT',
                'name' => 'Fictional Bangkok Early Slot',
                'status' => 'ACTIVE',
                'service_date' => '2026-09-13',
                'service_start_time' => '00:30',
                'service_end_time' => '02:30',
                'duration_minutes' => 120,
                'additional_buffer_before_minutes' => 10,
                'additional_buffer_after_minutes' => 5,
            ],
        );

        $this->withToken($context['token'])->postJson('/api/v1/availability:check', [
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['trip_template_id'],
            'custom_slot_instance_id' => $customId,
            'starts_at' => '2030-01-01T00:00:00Z',
            'ends_at' => '2030-01-01T01:00:00Z',
        ])->assertOk()->assertJson([
            'available' => true,
            'service_date' => '2026-09-13',
            'service_start' => '2026-09-12T17:30:00Z',
            'service_end' => '2026-09-12T19:30:00Z',
            'occupied_start' => '2026-09-12T17:05:00Z',
            'occupied_end' => '2026-09-12T19:55:00Z',
            'business_timezone' => 'Asia/Bangkok',
        ]);
    }

    public function test_cross_midnight_custom_slot_returns_stable_business_error(): void
    {
        $context = $this->context();

        try {
            app(SlotCatalogService::class)->createCustomInstance(
                $context['organization_id'],
                [
                    'code' => 'CUSTOM-CROSS-MIDNIGHT',
                    'name' => 'Fictional Unsupported Overnight Slot',
                    'status' => 'ACTIVE',
                    'service_date' => '2026-09-14',
                    'service_start_time' => '23:00',
                    'service_end_time' => '01:00',
                    'duration_minutes' => 120,
                ],
            );
            $this->fail('Expected cross-midnight slot creation to fail.');
        } catch (SlotCatalogException $exception) {
            $this->assertSame('SLOT_CROSSES_MIDNIGHT', $exception->errorCode);
            $this->assertSame(422, $exception->httpStatus);
            $this->assertSame(
                'Cross-midnight slot offerings are not supported in BoatOps v0.0.5.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('slot_offerings', ['code' => 'CUSTOM-CROSS-MIDNIGHT']);
    }

    public function test_confirm_uses_the_frozen_hold_slot_snapshot_after_catalog_definition_changes(): void
    {
        $context = $this->context();
        $slotId = $this->slotId($context['organization_id'], 'AM_4H');
        $hold = $this->createSlotHold($context, 'AM_4H', '2026-09-15', 'FROZEN-SLOT')->assertCreated();

        DB::table('slot_offerings')->where('id', $slotId)->update([
            'name' => 'Changed Catalog Name',
            'service_start_time' => '10:00:00',
            'service_end_time' => '12:00:00',
            'duration_minutes' => 120,
            'updated_at' => now(),
        ]);
        $booking = $this->confirmHold($context, $hold->json('hold_id'), 'FROZEN-SLOT')
            ->assertCreated()
            ->assertJson([
                'slot_offering_id' => $slotId,
                'service_start' => '2026-09-15T01:00:00Z',
                'service_end' => '2026-09-15T05:00:00Z',
            ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->json('booking_id'),
            'slot_offering_id' => $slotId,
            'service_start' => '2026-09-15 01:00:00',
            'service_end' => '2026-09-15 05:00:00',
            'slot_name_snapshot' => 'Morning 4 Hours',
            'slot_duration_minutes_snapshot' => 240,
        ]);
        $this->assertDatabaseHas('allocations', [
            'booking_id' => $booking->json('booking_id'),
            'service_start' => '2026-09-15 01:00:00',
            'service_end' => '2026-09-15 05:00:00',
            'slot_name_snapshot' => 'Morning 4 Hours',
        ]);
    }

    public function test_reusable_custom_template_and_date_override_keep_boat_scope(): void
    {
        $context = $this->context();
        $catalog = app(SlotCatalogService::class);
        $templateId = $catalog->createReusableOffering(
            $context['organization_id'],
            [
                'code' => 'CUSTOM-REUSABLE-TEMPLATE',
                'name' => 'Fictional Reusable Private Slot',
                'status' => 'ACTIVE',
                'service_start_time' => '10:00',
                'service_end_time' => '12:00',
                'duration_minutes' => 120,
                'valid_from' => '2026-09-01',
                'valid_until' => '2026-09-30',
                'applies_to_all_boats' => false,
            ],
            [$context['boat_id']],
        );
        $instanceId = $catalog->createCustomInstance(
            $context['organization_id'],
            [
                'template_slot_offering_id' => $templateId,
                'code' => 'CUSTOM-REUSABLE-OVERRIDE-0916',
                'status' => 'ACTIVE',
                'service_date' => '2026-09-16',
                'service_start_time' => '11:00',
                'service_end_time' => '13:00',
            ],
        );

        $this->assertDatabaseHas('slot_offerings', [
            'id' => $instanceId,
            'kind' => 'CUSTOM_INSTANCE',
            'template_slot_offering_id' => $templateId,
            'duration_minutes' => 120,
            'applies_to_all_boats' => false,
        ]);
        $this->assertDatabaseHas('slot_offering_boats', [
            'slot_offering_id' => $instanceId,
            'boat_id' => $context['boat_id'],
        ]);
        $this->createCustomHold($context, $instanceId, 'CUSTOM-SCOPED-OK')
            ->assertCreated()
            ->assertJson([
                'slot_offering_id' => $templateId,
                'custom_slot_instance_id' => $instanceId,
            ]);

        $context['boat_id'] = $context['other_boat_id'];
        $this->customAvailability($context, $instanceId)
            ->assertConflict()
            ->assertJson(['code' => 'SLOT_UNAVAILABLE']);
    }

    public function test_reusable_slot_creation_accepts_canonical_operating_time_statuses_and_rejects_unknown_status(): void
    {
        $organizationId = $this->createOrganization('Operating Time Status Validation');
        $catalog = app(SlotCatalogService::class);
        $statuses = [
            'UNVERIFIED',
            'DEMO_DEFAULT_UNVERIFIED',
            'FICTIONAL_VALIDATION_SCENARIO',
            'VERIFIED',
        ];

        foreach ($statuses as $index => $status) {
            $code = "OPERATING_STATUS_{$index}";
            $catalog->createReusableOffering($organizationId, [
                'code' => $code,
                'name' => "Operating Time Status {$status}",
                'service_start_time' => '08:00',
                'service_end_time' => '12:00',
                'duration_minutes' => 240,
                'operating_time_status' => $status,
            ]);

            $this->assertDatabaseHas('slot_offerings', [
                'organization_id' => $organizationId,
                'code' => $code,
                'operating_time_status' => $status,
            ]);
        }

        try {
            $catalog->createReusableOffering($organizationId, [
                'code' => 'OPERATING_STATUS_INVALID',
                'name' => 'Invalid Operating Time Status',
                'service_start_time' => '08:00',
                'service_end_time' => '12:00',
                'duration_minutes' => 240,
                'operating_time_status' => 'NOT_A_REAL_STATUS',
            ]);
            $this->fail('Expected unknown operating time status to fail closed.');
        } catch (SlotCatalogException $exception) {
            $this->assertSame('VALIDATION_FAILED', $exception->errorCode);
            $this->assertSame(422, $exception->httpStatus);
            $this->assertSame('The operating time status is invalid.', $exception->getMessage());
        }

        $this->assertDatabaseCount('slot_offerings', count($statuses));
        $this->assertDatabaseMissing('slot_offerings', ['code' => 'OPERATING_STATUS_INVALID']);
    }

    public function test_amend_rechecks_compatibility_and_persists_the_new_slot_identity_atomically(): void
    {
        $context = $this->context();
        $originalHold = $this->createSlotHold($context, 'AM_4H', '2026-09-17', 'AMEND-SLOT-BOOKING')
            ->assertCreated();
        $booking = $this->confirmHold($context, $originalHold->json('hold_id'), 'AMEND-SLOT-BOOKING')
            ->assertCreated();
        $this->createSlotHold($context, 'PM_4H', '2026-09-18', 'AMEND-SLOT-CONFLICT')->assertCreated();
        $fullDayId = $this->slotId($context['organization_id'], 'FULL_DAY_8H');

        $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings/'.$booking->json('booking_id').':amend', [
                'external_reference' => 'AMEND-SLOT-BOOKING',
                'boat_id' => $context['boat_id'],
                'trip_template_id' => $context['trip_template_id'],
                'slot_offering_id' => $fullDayId,
                'service_date' => '2026-09-18',
            ])->assertConflict()->assertJson(['code' => 'SLOT_COMPATIBILITY_CONFLICT']);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->json('booking_id'),
            'slot_offering_id' => $this->slotId($context['organization_id'], 'AM_4H'),
            'service_date' => '2026-09-17',
        ]);

        $pmId = $this->slotId($context['organization_id'], 'PM_4H');
        $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings/'.$booking->json('booking_id').':amend', [
                'external_reference' => 'AMEND-SLOT-BOOKING',
                'boat_id' => $context['boat_id'],
                'trip_template_id' => $context['trip_template_id'],
                'slot_offering_id' => $pmId,
                'service_date' => '2026-09-19',
            ])->assertOk()->assertJson([
                'code' => 'BOOKING_AMENDED',
                'slot_offering_id' => $pmId,
                'service_date' => '2026-09-19',
                'service_start' => '2026-09-19T06:00:00Z',
            ]);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->json('booking_id'),
            'slot_offering_id' => $pmId,
            'service_date' => '2026-09-19',
            'service_start' => '2026-09-19 06:00:00',
        ]);
        $this->assertDatabaseHas('allocations', [
            'booking_id' => $booking->json('booking_id'),
            'slot_offering_id' => $pmId,
            'service_date' => '2026-09-19',
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function context(int $bufferBeforeMinutes = 0, int $bufferAfterMinutes = 0): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Slot Context '.Str::random(8));
        [$token, $apiClientId] = $this->createApiClient($organizationId, ['operations.write']);
        $boatId = $this->createBoat($organizationId, $bufferBeforeMinutes, $bufferAfterMinutes);
        $otherBoatId = $this->createBoat($organizationId, $bufferBeforeMinutes, $bufferAfterMinutes);
        $tripTemplateId = $this->createTripTemplate($organizationId);
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

    private function createOrganization(string $name): int
    {
        return DB::table('organizations')->insertGetId([
            'name' => $name,
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $scopes
     * @return array{string, int}
     */
    private function createApiClient(int $organizationId, array $scopes = []): array
    {
        $token = Str::random(48);
        $id = DB::table('api_clients')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Fictional Slot API Client '.Str::random(6),
            'token_hash' => hash('sha256', $token),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$token, $id];
    }

    private function createBoat(
        int $organizationId,
        int $bufferBeforeMinutes = 0,
        int $bufferAfterMinutes = 0,
    ): int {
        return DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Fictional Slot Boat '.Str::random(6),
            'status' => 'ACTIVE',
            'buffer_before_minutes' => $bufferBeforeMinutes,
            'buffer_after_minutes' => $bufferAfterMinutes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTripTemplate(int $organizationId): int
    {
        return DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'SLOT-TRIP-'.Str::upper(Str::random(8)),
            'name' => 'Fictional Slot Charter',
            'status' => 'ACTIVE',
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

    private function availability(array $context, string $code, string $serviceDate)
    {
        return $this->withToken($context['token'])->postJson('/api/v1/availability:check', [
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['trip_template_id'],
            'slot_offering_id' => $this->slotId($context['organization_id'], $code),
            'service_date' => $serviceDate,
        ]);
    }

    private function customAvailability(array $context, int $customSlotInstanceId)
    {
        return $this->withToken($context['token'])->postJson('/api/v1/availability:check', [
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['trip_template_id'],
            'custom_slot_instance_id' => $customSlotInstanceId,
        ]);
    }

    private function createSlotHold(
        array $context,
        string $code,
        string $serviceDate,
        string $externalReference,
        string $expiresAt = '2026-08-01T00:20:00Z',
    ) {
        return $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => $externalReference,
                'boat_id' => $context['boat_id'],
                'trip_template_id' => $context['trip_template_id'],
                'slot_offering_id' => $this->slotId($context['organization_id'], $code),
                'service_date' => $serviceDate,
                'expires_at' => $expiresAt,
            ]);
    }

    private function createCustomHold(array $context, int $customSlotInstanceId, string $externalReference)
    {
        return $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => $externalReference,
                'boat_id' => $context['boat_id'],
                'trip_template_id' => $context['trip_template_id'],
                'custom_slot_instance_id' => $customSlotInstanceId,
                'expires_at' => '2026-08-01T00:20:00Z',
            ]);
    }

    private function confirmHold(array $context, int $holdId, string $externalReference)
    {
        return $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings:confirm', [
                'hold_id' => $holdId,
                'external_reference' => $externalReference,
                'rate_snapshot' => [
                    'source_reference' => 'FICTIONAL-SLOT-RATE',
                    'currency' => 'THB',
                    'selling_amount_minor' => 100000,
                    'tax_amount_minor' => 0,
                    'commission_amount_minor' => 0,
                    'quoted_at' => '2026-08-01T00:00:00Z',
                    'valid_until' => '2026-08-01T01:00:00Z',
                ],
            ]);
    }

    private function createLegacyAllocation(
        int $organizationId,
        int $boatId,
        string $occupiedStart,
        string $occupiedEnd,
    ): int {
        return DB::table('allocations')->insertGetId([
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'allocation_type' => 'BLOCKED',
            'status' => 'ACTIVE',
            'business_start' => $occupiedStart,
            'business_end' => $occupiedEnd,
            'service_start' => $occupiedStart,
            'service_end' => $occupiedEnd,
            'occupied_start' => $occupiedStart,
            'occupied_end' => $occupiedEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
