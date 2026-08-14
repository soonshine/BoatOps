<?php

namespace App\Http\Controllers\Operator;

use App\Exceptions\SlotCatalogException;
use App\Http\Controllers\Controller;
use App\Services\SlotCatalog\SlotCalendarReadModel;
use App\Services\SlotCatalog\SlotCatalogService;
use App\Support\OperatorUi;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class OperatorCalendarController extends Controller
{
    public function __construct(private readonly SlotCalendarReadModel $calendar, private readonly SlotCatalogService $catalog) {}

    public function index(Request $r): View
    {
        $i = $r->validate([
            'range' => ['sometimes', 'integer', 'in:7,14,30'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'boat_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $o = $r->attributes->get('organization');
        $range = (int) ($i['range'] ?? 7);
        $from = (string) ($i['from'] ?? CarbonImmutable::now((string) $o->timezone)->format('Y-m-d'));
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $from, (string) $o->timezone);
        $selectedBoatId = isset($i['boat_id']) ? (int) $i['boat_id'] : null;

        try {
            $calendar = $this->calendar->read(
                $o,
                $from,
                $d->addDays($range - 1)->format('Y-m-d'),
                $selectedBoatId,
            );
            $slots = $this->catalog->listOfferings((int) $o->id);
        } catch (SlotCatalogException $e) {
            abort($e->errorCode === 'AUTHORIZATION_FAILED' ? 404 : $e->httpStatus, OperatorUi::actionError([
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]));
        }

        $products = DB::table('trip_templates')
            ->where('organization_id', $o->id)
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get();
        $boats = DB::table('boats')
            ->where('organization_id', $o->id)
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);
        $dateHeaders = [];
        $weekdayLabels = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];

        for ($offset = 0; $offset < $range; $offset++) {
            $date = $d->addDays($offset);
            $dateHeaders[] = [
                'date' => $date->format('Y-m-d'),
                'weekday' => $weekdayLabels[$date->dayOfWeek],
                'label' => $date->format('n月j日'),
            ];
        }

        return view('operator.calendar', [
            'organization' => $o,
            'calendar' => $calendar,
            'products' => $products,
            'slots' => $slots,
            'boats' => $boats,
            'range' => $range,
            'from' => $from,
            'selectedBoatId' => $selectedBoatId,
            'dateHeaders' => $dateHeaders,
            'rangeStartLabel' => $d->format('Y年n月j日'),
            'rangeEndLabel' => $d->addDays($range - 1)->format('Y年n月j日'),
            'previousFrom' => $d->subDays($range)->format('Y-m-d'),
            'nextFrom' => $d->addDays($range)->format('Y-m-d'),
            'todayFrom' => CarbonImmutable::now((string) $o->timezone)->format('Y-m-d'),
            'summary' => $this->statusSummary($calendar),
            'allocationActionLinks' => $this->allocationActionLinks((int) $o->id, $calendar),
        ]);
    }

    /**
     * Count projected service slots, not boats or allocations.
     *
     * @param  array<string, mixed>  $calendar
     * @return array<string, int>
     */
    private function statusSummary(array $calendar): array
    {
        $summary = array_fill_keys(['AVAILABLE', 'HELD', 'CONFIRMED', 'BLOCKED'], 0);

        foreach ($calendar['boats'] as $boat) {
            foreach ($boat['dates'] as $date) {
                foreach ($date['slots'] as $slot) {
                    if (array_key_exists($slot['status'], $summary)) {
                        $summary[$slot['status']]++;
                    }
                }
            }
        }

        return $summary;
    }

    /**
     * Resolve optional UI destinations from organization-scoped allocation links.
     * Inventory state still comes exclusively from SlotCalendarReadModel.
     *
     * @param  array<string, mixed>  $calendar
     * @return array<int, array{label: string, url: string}>
     */
    private function allocationActionLinks(int $organizationId, array $calendar): array
    {
        $allocationIds = [];

        foreach ($calendar['boats'] as $boat) {
            foreach ($boat['dates'] as $date) {
                foreach ($date['slots'] as $slot) {
                    $allocationId = (int) ($slot['authority']['allocation_id'] ?? 0);
                    if ($allocationId > 0) {
                        $allocationIds[] = $allocationId;
                    }
                }
            }
        }

        $allocationIds = array_values(array_unique($allocationIds));
        if ($allocationIds === []) {
            return [];
        }

        $allocations = DB::table('allocations as allocation')
            ->leftJoin('inquiries as inquiry', function ($join): void {
                $join->on('inquiry.hold_id', '=', 'allocation.hold_id')
                    ->on('inquiry.organization_id', '=', 'allocation.organization_id');
            })
            ->leftJoin('bookings as booking', function ($join): void {
                $join->on('booking.id', '=', 'allocation.booking_id')
                    ->on('booking.organization_id', '=', 'allocation.organization_id');
            })
            ->leftJoin('trips as trip', function ($join): void {
                $join->on('trip.booking_id', '=', 'booking.id')
                    ->on('trip.organization_id', '=', 'allocation.organization_id');
            })
            ->leftJoin('blocks as block', function ($join): void {
                $join->on('block.id', '=', 'allocation.block_id')
                    ->on('block.organization_id', '=', 'allocation.organization_id');
            })
            ->where('allocation.organization_id', $organizationId)
            ->whereIn('allocation.id', $allocationIds)
            ->get([
                'allocation.id',
                'inquiry.id as inquiry_id',
                'booking.id as booking_id',
                'trip.id as trip_id',
                'block.id as block_id',
            ]);
        $links = [];

        foreach ($allocations as $allocation) {
            $bookingId = (int) ($allocation->booking_id ?? 0);
            $inquiryId = (int) ($allocation->inquiry_id ?? 0);
            $blockId = (int) ($allocation->block_id ?? 0);

            if ($bookingId > 0) {
                $tripId = (int) ($allocation->trip_id ?? 0);
                $links[(int) $allocation->id] = $tripId === 0
                    ? ['label' => '查看订单', 'url' => route('operator.bookings.show', $bookingId)]
                    : ['label' => '查看出航', 'url' => route('operator.trips.show', $tripId)];
            } elseif ($inquiryId > 0) {
                $links[(int) $allocation->id] = [
                    'label' => '查看询价',
                    'url' => route('operator.inquiries.show', $inquiryId),
                ];
            } elseif ($blockId > 0) {
                $links[(int) $allocation->id] = [
                    'label' => '查看停用记录',
                    'url' => route('operator.blocks.index'),
                ];
            }
        }

        return $links;
    }
}
