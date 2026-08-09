<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class BookingWorkbenchController extends Controller
{
    private const BOOKING_STATUSES = ['CONFIRMED', 'CANCELLED'];

    private const VIEWS = ['today', 'upcoming', 'all'];

    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $input = $request->validate([
            'view' => ['nullable', 'string', Rule::in(self::VIEWS)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', Rule::in(self::BOOKING_STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $organization = $request->attributes->get('organization');
        $organizationId = (int) $organization->id;
        $selectedView = $input['view'] ?? 'today';

        $query = DB::table('bookings')
            ->join('boats', function ($join): void {
                $join->on('boats.id', '=', 'bookings.boat_id')
                    ->on('boats.organization_id', '=', 'bookings.organization_id');
            })
            ->join('trip_templates', function ($join): void {
                $join->on('trip_templates.id', '=', 'bookings.trip_template_id')
                    ->on('trip_templates.organization_id', '=', 'bookings.organization_id');
            })
            ->leftJoin('trips', function ($join): void {
                $join->on('trips.booking_id', '=', 'bookings.id')
                    ->on('trips.organization_id', '=', 'bookings.organization_id');
            })
            ->leftJoin('inquiries', function ($join): void {
                $join->on('inquiries.hold_id', '=', 'bookings.hold_id')
                    ->on('inquiries.organization_id', '=', 'bookings.organization_id');
            })
            ->where('bookings.organization_id', $organizationId)
            ->select([
                'bookings.id',
                'bookings.external_reference',
                'bookings.status',
                'bookings.business_start',
                'bookings.business_end',
                'boats.name as boat_name',
                'trip_templates.name as product_name',
                'trips.status as trip_status',
                'inquiries.reference as inquiry_reference',
                'inquiries.contact_name',
                'inquiries.party_size',
                'inquiries.sales_source',
            ]);

        if (isset($input['date'])) {
            [$utcStart, $utcEnd] = $this->localDayUtcBounds($input['date'], (string) $organization->timezone);
            $query->where('bookings.business_start', '>=', $utcStart)
                ->where('bookings.business_start', '<', $utcEnd);
        } elseif ($selectedView === 'today') {
            [$utcStart, $utcEnd] = $this->todayUtcBounds((string) $organization->timezone);
            $query->where('bookings.business_start', '>=', $utcStart)
                ->where('bookings.business_start', '<', $utcEnd);
        } elseif ($selectedView === 'upcoming') {
            [, $nextLocalDayUtc] = $this->todayUtcBounds((string) $organization->timezone);
            $query->where('bookings.business_start', '>=', $nextLocalDayUtc);
        }

        if (isset($input['status'])) {
            $query->where('bookings.status', $input['status']);
        }

        $search = isset($input['q']) ? trim($input['q']) : '';
        if ($search !== '') {
            $literalSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search));
            $pattern = '%'.$literalSearch.'%';
            $query->where(function ($searchQuery) use ($pattern): void {
                $searchQuery->whereRaw("LOWER(bookings.external_reference) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(inquiries.reference) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(inquiries.contact_name) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        $direction = $selectedView === 'all' ? 'desc' : 'asc';
        $bookings = $query
            ->orderBy('bookings.business_start', $direction)
            ->orderBy('bookings.id', $direction)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('operator.bookings.index', [
            'organization' => $organization,
            'bookings' => $bookings,
            'selectedView' => $selectedView,
            'bookingStatuses' => self::BOOKING_STATUSES,
        ]);
    }

    public function show(Request $request, int $booking): View
    {
        $organization = $request->attributes->get('organization');
        $organizationId = (int) $organization->id;
        $record = DB::table('bookings')
            ->join('boats', function ($join): void {
                $join->on('boats.id', '=', 'bookings.boat_id')
                    ->on('boats.organization_id', '=', 'bookings.organization_id');
            })
            ->join('trip_templates', function ($join): void {
                $join->on('trip_templates.id', '=', 'bookings.trip_template_id')
                    ->on('trip_templates.organization_id', '=', 'bookings.organization_id');
            })
            ->leftJoin('trips', function ($join): void {
                $join->on('trips.booking_id', '=', 'bookings.id')
                    ->on('trips.organization_id', '=', 'bookings.organization_id');
            })
            ->leftJoin('inquiries', function ($join): void {
                $join->on('inquiries.hold_id', '=', 'bookings.hold_id')
                    ->on('inquiries.organization_id', '=', 'bookings.organization_id');
            })
            ->where('bookings.organization_id', $organizationId)
            ->where('bookings.id', $booking)
            ->select([
                'bookings.*',
                'boats.name as boat_name',
                'trip_templates.name as product_name',
                'inquiries.id as inquiry_id',
                'inquiries.reference as inquiry_reference',
                'inquiries.contact_name',
                'inquiries.contact_method',
                'inquiries.contact_value',
                'inquiries.party_size',
                'inquiries.meeting_point',
                'inquiries.service_location',
                'inquiries.sales_source',
                'inquiries.agent_reference',
                'inquiries.service_notes',
                'inquiries.internal_notes',
                'inquiries.selling_currency',
                'inquiries.selling_amount_minor',
                'trips.id as trip_id',
                'trips.status as trip_status',
                'trips.planned_start',
                'trips.planned_end',
                'trips.actual_departed_at',
                'trips.actual_returned_at',
                'trips.completed_at',
            ])
            ->first();
        abort_if(! $record, 404);

        return view('operator.bookings.show', [
            'organization' => $organization,
            'booking' => $record,
            'boats' => DB::table('boats')
                ->where('organization_id', $organizationId)
                ->where('status', 'ACTIVE')
                ->orderBy('name')
                ->get(),
            'products' => DB::table('trip_templates')
                ->where('organization_id', $organizationId)
                ->where('status', 'ACTIVE')
                ->orderBy('name')
                ->get(),
            'slots' => DB::table('slot_offerings')
                ->where('organization_id', $organizationId)
                ->where('status', 'ACTIVE')
                ->whereIn('kind', ['PRESET', 'CUSTOM_TEMPLATE'])
                ->orderBy('name')
                ->get(),
            'amendIdempotencyKey' => (string) Str::uuid(),
            'cancelIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function todayUtcBounds(string $timezone): array
    {
        $localStart = CarbonImmutable::now($timezone)->startOfDay();

        return [$localStart->utc(), $localStart->addDay()->utc()];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function localDayUtcBounds(string $date, string $timezone): array
    {
        $localStart = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        abort_if($localStart === false, 422);

        return [$localStart->utc(), $localStart->addDay()->utc()];
    }
}
