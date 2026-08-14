<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorTodayOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_operator_can_open_chinese_today_page_with_empty_state(): void
    {
        $context = $this->context();
        $this->actingAs($context['user']);

        $this->get('/operator/today')
            ->assertOk()
            ->assertSee('<title>今日运营</title>', false)
            ->assertSee('今日运营')
            ->assertSee('今天暂无出航任务');
    }

    public function test_today_page_keeps_existing_authentication_and_booking_permission_boundaries(): void
    {
        $this->get('/operator/today')->assertRedirect('/operator/login');

        $denied = $this->context(false);
        $this->actingAs($denied['user']);
        $this->get('/operator/today')->assertForbidden();
    }

    public function test_today_query_uses_organization_timezone_and_scope(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-14T03:00:00Z'));
        $allowed = $this->context();
        $included = $this->trip($allowed, 'FICTIONAL-TODAY-INCLUDED', '2026-08-13 17:30:00', dossier: true);
        $this->trip($allowed, 'FICTIONAL-PREVIOUS-DAY', '2026-08-13 16:59:59');
        $this->trip($allowed, 'FICTIONAL-NEXT-DAY', '2026-08-14 17:00:00');
        $foreign = $this->context();
        $this->trip($foreign, 'FICTIONAL-FOREIGN-TODAY', '2026-08-13 18:00:00');
        $this->actingAs($allowed['user']);

        $response = $this->get('/operator/today')->assertOk()
            ->assertSee('FICTIONAL-TODAY-INCLUDED')
            ->assertSee($allowed['boat_name'])
            ->assertSee($allowed['product_name'])
            ->assertSee('人数：4')
            ->assertSee('2026年8月14日 00:30–01:30')
            ->assertDontSee('FICTIONAL-PREVIOUS-DAY')
            ->assertDontSee('FICTIONAL-NEXT-DAY')
            ->assertDontSee('FICTIONAL-FOREIGN-TODAY')
            ->assertSee('href="'.route('operator.trips.show', $included['trip_id']).'"', false)
            ->assertSee('href="'.route('operator.bookings.show', $included['booking_id']).'"', false);

        $this->assertSame(
            [(int) $included['trip_id']],
            $response->viewData('trips')->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_summary_counts_existing_trip_workflow_statuses(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-14T03:00:00Z'));
        $context = $this->context();
        $this->trip($context, 'FICTIONAL-SUMMARY-PLANNED', '2026-08-13 18:00:00');
        $this->trip($context, 'FICTIONAL-SUMMARY-DEPARTED', '2026-08-13 19:00:00', 'DEPARTED');
        $this->trip($context, 'FICTIONAL-SUMMARY-RETURNED', '2026-08-13 20:00:00', 'RETURNED');
        $this->trip($context, 'FICTIONAL-SUMMARY-COMPLETED', '2026-08-13 21:00:00', 'COMPLETED');
        $this->trip($context, 'FICTIONAL-SUMMARY-CANCELLED', '2026-08-13 22:00:00', 'CANCELLED');
        $this->actingAs($context['user']);

        $response = $this->get('/operator/today')->assertOk();

        $this->assertSame([
            'total' => 5,
            'planned' => 1,
            'departed' => 1,
            'returned' => 1,
            'completed' => 1,
            'attention' => 0,
        ], $response->viewData('summary'));
    }

    public function test_attention_uses_only_reliable_links_execution_timestamps_and_active_blocks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-14T03:00:00Z'));
        $context = $this->context();
        $missingDeparture = $this->trip($context, 'FICTIONAL-MISSING-DEPARTURE', '2026-08-13 18:00:00', 'DEPARTED');
        DB::table('trips')->where('id', $missingDeparture['trip_id'])->update(['actual_departed_at' => null]);
        $blocked = $this->trip($context, 'FICTIONAL-ACTIVE-BLOCK', '2026-08-13 19:00:00');
        DB::table('allocations')->where('id', $blocked['allocation_id'])->update([
            'occupied_start' => '2026-08-13 18:30:00',
            'occupied_end' => '2026-08-13 20:30:00',
        ]);
        DB::table('blocks')->insert([
            'organization_id' => $context['organization_id'],
            'boat_id' => $context['boat_id'],
            'external_reference' => 'FICTIONAL-TODAY-BLOCK',
            'status' => 'ACTIVE',
            'reason_code' => 'MAINTENANCE',
            'business_start' => '2026-08-13 18:45:00',
            'business_end' => '2026-08-13 18:55:00',
            'occupied_start' => '2026-08-13 18:45:00',
            'occupied_end' => '2026-08-13 18:55:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mismatchedBoat = $this->trip($context, 'FICTIONAL-MISMATCHED-BOAT', '2026-08-13 20:00:00');
        $foreign = $this->context();
        DB::table('trips')->where('id', $mismatchedBoat['trip_id'])->update(['boat_id' => $foreign['boat_id']]);
        $brokenInventoryState = $this->trip($context, 'FICTIONAL-BROKEN-INVENTORY-STATE', '2026-08-13 20:30:00');
        DB::table('allocations')->where('id', $brokenInventoryState['allocation_id'])->update(['status' => 'RELEASED']);
        $healthy = $this->trip($context, 'FICTIONAL-HEALTHY-NO-CREW-CHECK', '2026-08-13 21:00:00');
        $this->actingAs($context['user']);

        $response = $this->get('/operator/today')->assertOk()
            ->assertSee('已出航任务缺少实际出航时间')
            ->assertSee('船只在任务时段存在生效中的停用记录')
            ->assertSee('船只关联缺失或不属于当前组织')
            ->assertSee('出航、订单与库存状态不一致')
            ->assertDontSee('需要安排船员')
            ->assertDontSee('需要设置接送');

        $this->assertSame(4, $response->viewData('summary')['attention']);
        $this->assertSame([
            (int) $missingDeparture['trip_id'],
            (int) $blocked['trip_id'],
            (int) $mismatchedBoat['trip_id'],
            (int) $brokenInventoryState['trip_id'],
        ], $response->viewData('attentionTrips')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertFalse((bool) $response->viewData('trips')->firstWhere('id', $healthy['trip_id'])->needs_attention);
    }

    public function test_broken_detail_links_are_not_offered_when_scoped_relations_are_missing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-14T03:00:00Z'));
        $context = $this->context();
        $record = $this->trip($context, 'FICTIONAL-BROKEN-DETAIL-LINK', '2026-08-13 20:00:00');
        $brokenBooking = $this->trip($context, 'FICTIONAL-BROKEN-BOOKING-LINK', '2026-08-13 21:00:00');
        $foreign = $this->context();
        DB::table('trips')->where('id', $record['trip_id'])->update(['boat_id' => $foreign['boat_id']]);
        DB::table('bookings')->where('id', $brokenBooking['booking_id'])->update(['boat_id' => $foreign['boat_id']]);
        $this->actingAs($context['user']);

        $response = $this->get('/operator/today')->assertOk()
            ->assertSee('出航详情因关联异常暂不可打开')
            ->assertSee('订单详情因关联异常暂不可打开')
            ->assertDontSee('任务船只当前不是生效状态')
            ->assertDontSee('href="'.route('operator.trips.show', $record['trip_id']).'"', false)
            ->assertSee('href="'.route('operator.bookings.show', $record['booking_id']).'"', false)
            ->assertSee('href="'.route('operator.trips.show', $brokenBooking['trip_id']).'"', false)
            ->assertDontSee('href="'.route('operator.bookings.show', $brokenBooking['booking_id']).'"', false);

        $trip = $response->viewData('trips')->firstWhere('id', $record['trip_id']);
        $this->assertFalse($trip->trip_detail_available);
        $this->assertTrue($trip->booking_detail_available);
        $tripWithBrokenBooking = $response->viewData('trips')->firstWhere('id', $brokenBooking['trip_id']);
        $this->assertTrue($tripWithBrokenBooking->trip_detail_available);
        $this->assertFalse($tripWithBrokenBooking->booking_detail_available);
        $this->get(route('operator.trips.show', $record['trip_id']))->assertNotFound();
        $this->get(route('operator.bookings.show', $record['booking_id']))->assertOk();
        $this->get(route('operator.trips.show', $brokenBooking['trip_id']))->assertOk();
        $this->get(route('operator.bookings.show', $brokenBooking['booking_id']))->assertNotFound();
    }

    public function test_today_page_uses_responsive_task_cards_and_clear_status_badges(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-14T03:00:00Z'));
        $context = $this->context();
        $this->trip($context, 'FICTIONAL-RESPONSIVE-CARD', '2026-08-13 18:00:00');
        $this->actingAs($context['user']);

        $this->get('/operator/today')->assertOk()
            ->assertSee('class="today-operations-body"', false)
            ->assertSee('class="today-summary-grid"', false)
            ->assertSee('class="today-task-card"', false)
            ->assertSee('class="status-badge status-planned"', false)
            ->assertSee('@media (max-width: 720px)', false)
            ->assertDontSee('@if', false)
            ->assertDontSee('@endif', false)
            ->assertDontSee('<canvas', false);
    }

    private function context(bool $bookingPermission = true): array
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Fictional Today Operations '.Str::random(6),
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::create([
            'name' => 'Fictional Today Operator',
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
        $boatName = 'Fictional Today Boat '.Str::random(4);
        $productName = 'Fictional Today Product '.Str::random(4);
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

        return [
            'organization_id' => $organizationId,
            'user' => $user,
            'boat_id' => $boatId,
            'template_id' => $templateId,
            'boat_name' => $boatName,
            'product_name' => $productName,
        ];
    }

    private function trip(array $context, string $reference, string $start, string $status = 'PLANNED', bool $dossier = false): array
    {
        $startAt = CarbonImmutable::parse($start, 'UTC');
        $end = $startAt->addHour()->format('Y-m-d H:i:s');
        $serviceDate = $startAt->setTimezone('Asia/Bangkok')->format('Y-m-d');
        $recordStatus = match ($status) {
            'COMPLETED' => 'COMPLETED',
            'CANCELLED' => 'CANCELLED',
            default => 'ACTIVE',
        };
        $allocationId = DB::table('allocations')->insertGetId([
            'organization_id' => $context['organization_id'],
            'boat_id' => $context['boat_id'],
            'allocation_type' => 'BOOKING',
            'status' => $recordStatus,
            'service_date' => $serviceDate,
            'service_start' => $start,
            'service_end' => $end,
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
                'service_date' => $serviceDate,
                'service_start' => $start,
                'service_end' => $end,
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
                'service_date' => $serviceDate,
                'created_by_user_id' => $context['user']->id,
                'hold_id' => $holdId,
                'contact_name' => 'Fictional Today Contact',
                'party_size' => 4,
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
            'status' => $status === 'COMPLETED' ? 'COMPLETED' : ($status === 'CANCELLED' ? 'CANCELLED' : 'CONFIRMED'),
            'service_date' => $serviceDate,
            'service_start' => $start,
            'service_end' => $end,
            'business_start' => $start,
            'business_end' => $end,
            'occupied_start' => $start,
            'occupied_end' => $end,
            'allocation_id' => $allocationId,
            'confirmed_at' => now(),
            'cancelled_at' => $status === 'CANCELLED' ? now() : null,
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
            'actual_departed_at' => in_array($status, ['DEPARTED', 'RETURNED', 'COMPLETED'], true) ? $start : null,
            'actual_returned_at' => in_array($status, ['RETURNED', 'COMPLETED'], true) ? $end : null,
            'completed_at' => $status === 'COMPLETED' ? $end : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'allocation_id' => $allocationId,
            'booking_id' => $bookingId,
            'trip_id' => $tripId,
        ];
    }
}
