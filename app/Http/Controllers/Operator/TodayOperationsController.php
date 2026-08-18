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
        $organizationId = (int) $organization->id;
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
            ->where('trip.organization_id', $organizationId)
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
                'inquiry.contact_name',
                'inquiry.party_size',
                'inquiry.pickup_required',
                'inquiry.pickup_time',
                'inquiry.meeting_point',
                'inquiry.hotel_name',
                'inquiry.room_number',
                'inquiry.route_summary',
                'inquiry.service_location',
                'inquiry.service_notes',
                'inquiry.internal_notes',
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
            ->selectSub(function ($query): void {
                $query->from('crew_assignments as crew_assignment')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('crew_assignment.organization_id', 'trip.organization_id')
                    ->whereColumn('crew_assignment.trip_id', 'trip.id');
            }, 'crew_count')
            ->selectSub(function ($query): void {
                $query->from('trip_checklists as checklist')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('checklist.organization_id', 'trip.organization_id')
                    ->whereColumn('checklist.trip_id', 'trip.id')
                    ->where('checklist.required', true);
            }, 'required_checklist_count')
            ->selectSub(function ($query): void {
                $query->from('trip_checklists as checklist')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('checklist.organization_id', 'trip.organization_id')
                    ->whereColumn('checklist.trip_id', 'trip.id')
                    ->where('checklist.required', true)
                    ->where('checklist.completed', true);
            }, 'completed_required_count')
            ->orderBy('trip.planned_start')
            ->orderBy('trip.id')
            ->get();

        $tripIds = $trips->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $crewByTrip = collect();
        if ($tripIds !== []) {
            $crewByTrip = DB::table('crew_assignments as assignment')
                ->join('crew_members as crew', function ($join): void {
                    $join->on('crew.id', '=', 'assignment.crew_member_id')
                        ->on('crew.organization_id', '=', 'assignment.organization_id');
                })
                ->where('assignment.organization_id', $organizationId)
                ->whereIn('assignment.trip_id', $tripIds)
                ->select([
                    'assignment.trip_id',
                    'assignment.duty',
                    'crew.display_name',
                    'crew.role',
                ])
                ->orderBy('assignment.trip_id')
                ->orderBy('assignment.id')
                ->get()
                ->groupBy('trip_id');
        }

        $trips = $trips->map(function (object $trip) use ($crewByTrip): object {
            $trip->trip_detail_available = $trip->related_booking_id !== null
                && $trip->related_boat_id !== null
                && $trip->related_product_id !== null;
            $trip->booking_detail_available = $trip->related_booking_id !== null
                && $trip->related_booking_boat_id !== null
                && $trip->related_booking_product_id !== null;
            $trip->crew = $crewByTrip->get($trip->id, collect());
            $trip->ready = (int) $trip->crew_count > 0
                && (int) $trip->required_checklist_count > 0
                && (int) $trip->required_checklist_count === (int) $trip->completed_required_count;
            $trip->attention_reasons = $this->attentionReasons($trip);
            $trip->needs_attention = $trip->attention_reasons !== [];
            $trip->execution_gaps = $this->executionGaps($trip);
            $trip->next_action_label = $this->nextActionLabel($trip);

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
        $workflowSummary = [
            'preparing' => $trips->where('status', 'PLANNED')->where('ready', false)->count(),
            'ready' => $trips->where('status', 'PLANNED')->where('ready', true)->count(),
            'departed' => $summary['departed'],
            'returned' => $summary['returned'],
            'completed' => $summary['completed'],
            'attention' => $summary['attention'],
        ];

        return view('operator.today', [
            'organization' => $organization,
            'date' => $today->format('Y-m-d'),
            'dateLabel' => $today->format('Y年n月j日'),
            'total' => $summary['total'],
            'summary' => $summary,
            'workflowSummary' => $workflowSummary,
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

    /** @return list<string> */
    private function executionGaps(object $trip): array
    {
        if (! in_array($trip->status, ['PLANNED', 'DEPARTED', 'RETURNED'], true)) {
            return [];
        }

        $gaps = [];
        if ($trip->status === 'PLANNED') {
            if ((int) $trip->crew_count === 0) {
                $gaps[] = '未安排负责人 / 船员';
            }
            if ((int) $trip->required_checklist_count === 0) {
                $gaps[] = '尚未建立必检清单';
            } elseif ((int) $trip->completed_required_count < (int) $trip->required_checklist_count) {
                $remaining = (int) $trip->required_checklist_count - (int) $trip->completed_required_count;
                $gaps[] = '还有 '.$remaining.' 项必检未完成';
            }
        }
        if ($trip->party_size === null) {
            $gaps[] = '客人人数未记录';
        }
        if ($this->blank($trip->route_summary)) {
            $gaps[] = '路线未记录';
        }
        if ($trip->pickup_required === null) {
            $gaps[] = '接送需求待确认';
        } elseif ((bool) $trip->pickup_required) {
            if ($this->blank($trip->pickup_time)) {
                $gaps[] = '接客时间未记录';
            }
            if ($this->blank($trip->meeting_point)) {
                $gaps[] = '接客地点未记录';
            }
        }

        return $gaps;
    }

    private function nextActionLabel(object $trip): string
    {
        return match ($trip->status) {
            'PLANNED' => $trip->ready ? '登记出航' : '完成出航准备',
            'DEPARTED' => '登记返航',
            'RETURNED' => '完成任务',
            'COMPLETED' => '已完成',
            'CANCELLED' => '已取消',
            default => '核对任务状态',
        };
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
