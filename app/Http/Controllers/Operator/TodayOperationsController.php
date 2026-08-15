<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class TodayOperationsController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        $today = CarbonImmutable::now((string) $organization->timezone)->startOfDay();
        $utcStart = $today->utc();
        $utcEnd = $today->addDay()->utc();
        $trips = DB::table('trips as trip')
            ->leftJoin('bookings as booking', function ($join): void {
                $join->on('booking.id', '=', 'trip.booking_id')
                    ->on('booking.organization_id', '=', 'trip.organization_id');
            })
            ->leftJoin('boats as booking_boat', function ($join): void {
                $join->on('booking_boat.id', '=', 'booking.boat_id')
                    ->on('booking_boat.organization_id', '=', 'trip.organization_id');
            })
            ->leftJoin('trip_templates as booking_product', function ($join): void {
                $join->on('booking_product.id', '=', 'booking.trip_template_id')
                    ->on('booking_product.organization_id', '=', 'trip.organization_id');
            })
            ->leftJoin('allocations as allocation', function ($join): void {
                $join->on('allocation.id', '=', 'booking.allocation_id')
                    ->on('allocation.organization_id', '=', 'trip.organization_id');
            })
            ->leftJoin('boats as boat', function ($join): void {
                $join->on('boat.id', '=', 'trip.boat_id')
                    ->on('boat.organization_id', '=', 'trip.organization_id');
            })
            ->leftJoin('trip_templates as product', function ($join): void {
                $join->on('product.id', '=', 'trip.trip_template_id')
                    ->on('product.organization_id', '=', 'trip.organization_id');
            })
            ->leftJoin('inquiries as inquiry', function ($join): void {
                $join->on('inquiry.hold_id', '=', 'booking.hold_id')
                    ->on('inquiry.organization_id', '=', 'trip.organization_id');
            })
            ->where('trip.organization_id', (int) $organization->id)
            ->where('trip.planned_start', '>=', $utcStart)
            ->where('trip.planned_start', '<', $utcEnd)
            ->select([
                'trip.id',
                'trip.booking_id',
                'trip.boat_id',
                'trip.trip_template_id',
                'trip.status',
                'trip.planned_start',
                'trip.planned_end',
                'trip.actual_departed_at',
                'trip.actual_returned_at',
                'trip.completed_at',
                'booking.id as related_booking_id',
                'booking.boat_id as booking_boat_id',
                'booking.trip_template_id as booking_trip_template_id',
                'booking.external_reference as booking_reference',
                'booking.status as booking_status',
                'booking_boat.id as related_booking_boat_id',
                'booking_product.id as related_booking_product_id',
                'allocation.id as related_allocation_id',
                'allocation.boat_id as allocation_boat_id',
                'allocation.booking_id as allocation_booking_id',
                'allocation.allocation_type',
                'allocation.status as allocation_status',
                'boat.id as related_boat_id',
                'boat.name as boat_name',
                'boat.status as boat_status',
                'product.id as related_product_id',
                'product.name as product_name',
                'inquiry.party_size',
            ])
            ->selectSub(function ($query): void {
                $query->from('blocks as active_block')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('active_block.organization_id', 'trip.organization_id')
                    ->whereColumn('active_block.boat_id', 'trip.boat_id')
                    ->where('active_block.status', 'ACTIVE')
                    ->whereColumn('active_block.occupied_start', '<', 'allocation.occupied_end')
                    ->whereColumn('active_block.occupied_end', '>', 'allocation.occupied_start');
            }, 'active_block_count')
            ->orderBy('trip.planned_start')
            ->orderBy('trip.id')
            ->get()
            ->map(function (object $trip): object {
                $trip->trip_detail_available = $trip->related_booking_id !== null
                    && $trip->related_boat_id !== null
                    && $trip->related_product_id !== null;
                $trip->booking_detail_available = $trip->related_booking_id !== null
                    && $trip->related_booking_boat_id !== null
                    && $trip->related_booking_product_id !== null;
                $trip->attention_reasons = $this->attentionReasons($trip);
                $trip->needs_attention = $trip->attention_reasons !== [];

                return $trip;
            });
        $attentionTrips = $trips->where('needs_attention', true)->values();
        $summary = [
            'total' => $trips->count(),
            'planned' => $trips->where('status', 'PLANNED')->count(),
            'departed' => $trips->where('status', 'DEPARTED')->count(),
            'returned' => $trips->where('status', 'RETURNED')->count(),
            'completed' => $trips->where('status', 'COMPLETED')->count(),
            'attention' => $attentionTrips->count(),
        ];

        return view('operator.today', [
            'organization' => $organization,
            'date' => $today->format('Y-m-d'),
            'dateLabel' => $today->format('Y年n月j日'),
            'summary' => $summary,
            'attentionTrips' => $attentionTrips,
            'trips' => $trips,
        ]);
    }

    /** @return list<string> */
    private function attentionReasons(object $trip): array
    {
        $reasons = [];
        $activeStatuses = ['PLANNED', 'DEPARTED', 'RETURNED'];
        $expectedStatuses = match ($trip->status) {
            'PLANNED', 'DEPARTED', 'RETURNED' => ['booking' => 'CONFIRMED', 'allocation' => 'ACTIVE'],
            'COMPLETED' => ['booking' => 'COMPLETED', 'allocation' => 'COMPLETED'],
            'CANCELLED' => ['booking' => 'CANCELLED', 'allocation' => 'CANCELLED'],
            default => null,
        };

        if ($trip->related_booking_id === null) {
            $reasons[] = '订单关联缺失或不属于当前组织';
        }
        if ($trip->related_boat_id === null) {
            $reasons[] = '船只关联缺失或不属于当前组织';
        }
        if ($trip->related_product_id === null) {
            $reasons[] = '产品 / 航线关联缺失或不属于当前组织';
        }
        if ($trip->related_booking_id !== null
            && ((int) $trip->booking_boat_id !== (int) $trip->boat_id
                || (int) $trip->booking_trip_template_id !== (int) $trip->trip_template_id)) {
            $reasons[] = '出航与订单关联不一致';
        }
        if ($trip->related_booking_id !== null && ($trip->related_allocation_id === null
            || (int) $trip->allocation_booking_id !== (int) $trip->related_booking_id
            || (int) $trip->allocation_boat_id !== (int) $trip->boat_id
            || $trip->allocation_type !== 'BOOKING')) {
            $reasons[] = '订单库存关联异常';
        }
        if ($expectedStatuses === null) {
            $reasons[] = '未知出航状态，需要人工核对';
        } elseif ($trip->booking_status !== $expectedStatuses['booking']
            || $trip->allocation_status !== $expectedStatuses['allocation']) {
            $reasons[] = '出航、订单与库存状态不一致';
        }
        if (in_array($trip->status, $activeStatuses, true)
            && $trip->related_boat_id !== null
            && $trip->boat_status !== 'ACTIVE') {
            $reasons[] = '任务船只当前不是生效状态';
        }
        if (in_array($trip->status, $activeStatuses, true) && (int) $trip->active_block_count > 0) {
            $reasons[] = '船只在任务时段存在生效中的停用记录';
        }
        if ($trip->status === 'DEPARTED' && $trip->actual_departed_at === null) {
            $reasons[] = '已出航任务缺少实际出航时间';
        }
        if ($trip->status === 'RETURNED'
            && ($trip->actual_departed_at === null || $trip->actual_returned_at === null)) {
            $reasons[] = '已返航任务缺少实际出航或返航时间';
        }
        if ($trip->status === 'COMPLETED'
            && ($trip->actual_departed_at === null || $trip->actual_returned_at === null || $trip->completed_at === null)) {
            $reasons[] = '已完成任务缺少执行时间记录';
        }

        return array_values(array_unique($reasons));
    }
}
