<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class TripDeskController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $organization = $request->attributes->get('organization');
        $organizationId = (int) $organization->id;
        $timezone = (string) $organization->timezone;
        $date = $input['date'] ?? CarbonImmutable::now($timezone)->format('Y-m-d');
        [$utcStart, $utcEnd] = $this->localDayUtcBounds($date, $timezone);

        $trips = DB::table('trips')
            ->join('bookings', function ($join): void {
                $join->on('bookings.id', '=', 'trips.booking_id')
                    ->on('bookings.organization_id', '=', 'trips.organization_id');
            })
            ->join('boats', function ($join): void {
                $join->on('boats.id', '=', 'trips.boat_id')
                    ->on('boats.organization_id', '=', 'trips.organization_id');
            })
            ->join('trip_templates', function ($join): void {
                $join->on('trip_templates.id', '=', 'trips.trip_template_id')
                    ->on('trip_templates.organization_id', '=', 'trips.organization_id');
            })
            ->leftJoin('inquiries', function ($join): void {
                $join->on('inquiries.hold_id', '=', 'bookings.hold_id')
                    ->on('inquiries.organization_id', '=', 'trips.organization_id');
            })
            ->where('trips.organization_id', $organizationId)
            ->where('trips.planned_start', '>=', $utcStart)
            ->where('trips.planned_start', '<', $utcEnd)
            ->select([
                'trips.id',
                'trips.status',
                'trips.planned_start',
                'trips.planned_end',
                'bookings.external_reference as booking_reference',
                'boats.name as boat_name',
                'trip_templates.name as product_name',
                'inquiries.contact_name',
                'inquiries.party_size',
            ])
            ->selectSub(function ($query): void {
                $query->from('crew_assignments')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('crew_assignments.organization_id', 'trips.organization_id')
                    ->whereColumn('crew_assignments.trip_id', 'trips.id');
            }, 'crew_count')
            ->selectSub(function ($query): void {
                $query->from('trip_checklists')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('trip_checklists.organization_id', 'trips.organization_id')
                    ->whereColumn('trip_checklists.trip_id', 'trips.id')
                    ->where('trip_checklists.required', true);
            }, 'required_checklist_count')
            ->selectSub(function ($query): void {
                $query->from('trip_checklists')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('trip_checklists.organization_id', 'trips.organization_id')
                    ->whereColumn('trip_checklists.trip_id', 'trips.id')
                    ->where('trip_checklists.required', true)
                    ->where('trip_checklists.completed', true);
            }, 'completed_required_count')
            ->orderBy('trips.planned_start')
            ->orderBy('trips.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('operator.trips.index', compact('organization', 'trips', 'date'));
    }

    public function show(Request $request, int $trip): View
    {
        $organization = $request->attributes->get('organization');
        $organizationId = (int) $organization->id;
        $record = DB::table('trips')
            ->join('bookings', function ($join): void {
                $join->on('bookings.id', '=', 'trips.booking_id')
                    ->on('bookings.organization_id', '=', 'trips.organization_id');
            })
            ->join('boats', function ($join): void {
                $join->on('boats.id', '=', 'trips.boat_id')
                    ->on('boats.organization_id', '=', 'trips.organization_id');
            })
            ->join('trip_templates', function ($join): void {
                $join->on('trip_templates.id', '=', 'trips.trip_template_id')
                    ->on('trip_templates.organization_id', '=', 'trips.organization_id');
            })
            ->leftJoin('inquiries', function ($join): void {
                $join->on('inquiries.hold_id', '=', 'bookings.hold_id')
                    ->on('inquiries.organization_id', '=', 'trips.organization_id');
            })
            ->where('trips.organization_id', $organizationId)
            ->where('trips.id', $trip)
            ->select([
                'trips.*',
                'bookings.id as booking_id',
                'bookings.external_reference as booking_reference',
                'bookings.status as booking_status',
                'boats.name as boat_name',
                'trip_templates.name as product_name',
                'inquiries.id as inquiry_id',
                'inquiries.contact_name',
                'inquiries.contact_method',
                'inquiries.contact_value',
                'inquiries.party_size',
                'inquiries.route_summary',
                'inquiries.pickup_required',
                'inquiries.hotel_name',
                'inquiries.room_number',
                'inquiries.pickup_time',
                'inquiries.meeting_point',
                'inquiries.service_location',
                'inquiries.service_notes',
                'inquiries.internal_notes',
                'inquiries.sales_source',
            ])
            ->first();
        abort_if(! $record, 404);

        $crew = DB::table('crew_assignments')
            ->join('crew_members', function ($join): void {
                $join->on('crew_members.id', '=', 'crew_assignments.crew_member_id')
                    ->on('crew_members.organization_id', '=', 'crew_assignments.organization_id');
            })
            ->where('crew_assignments.organization_id', $organizationId)
            ->where('crew_assignments.trip_id', $trip)
            ->select([
                'crew_members.external_reference',
                'crew_members.display_name',
                'crew_members.role',
                'crew_assignments.duty',
            ])
            ->orderBy('crew_assignments.id')
            ->get();
        $checklist = DB::table('trip_checklists')
            ->where('organization_id', $organizationId)
            ->where('trip_id', $trip)
            ->orderBy('id')
            ->get();
        $requiredCount = $checklist->where('required', true)->count();
        $completedRequiredCount = $checklist->where('required', true)->where('completed', true)->count();
        $ready = $crew->isNotEmpty() && $requiredCount > 0 && $requiredCount === $completedRequiredCount;
        $crewRows = $crew->map(fn (object $row): array => [
            'external_reference' => $row->external_reference,
            'display_name' => $row->display_name,
            'role' => $row->role,
            'duty' => $row->duty,
        ])->all();
        $checklistRows = $checklist->map(fn (object $row): array => [
            'code' => $row->code,
            'label' => $row->label,
            'required' => (bool) $row->required,
            'completed' => (bool) $row->completed,
        ])->all();
        if ($crewRows === []) {
            $crewRows[] = ['external_reference' => '', 'display_name' => '', 'role' => '', 'duty' => ''];
        }
        if ($checklistRows === []) {
            $checklistRows[] = ['code' => '', 'label' => '', 'required' => true, 'completed' => false];
        }

        $nextActionLabel = match ($record->status) {
            'PLANNED' => $ready ? '登记出航' : '完成准备',
            'DEPARTED' => '登记返航',
            'RETURNED' => '完成任务',
            'COMPLETED' => '已完成',
            'CANCELLED' => '已取消',
            default => '核对任务状态',
        };

        return view('operator.trips.show', [
            'organization' => $organization,
            'trip' => $record,
            'crew' => $crew,
            'checklist' => $checklist,
            'crewRows' => $crewRows,
            'checklistRows' => $checklistRows,
            'requiredCount' => $requiredCount,
            'completedRequiredCount' => $completedRequiredCount,
            'ready' => $ready,
            'nextActionLabel' => $nextActionLabel,
            'localNow' => CarbonImmutable::now((string) $organization->timezone)->format('Y-m-d\TH:i'),
            'prepareIdempotencyKey' => (string) Str::uuid(),
            'departIdempotencyKey' => (string) Str::uuid(),
            'returnIdempotencyKey' => (string) Str::uuid(),
            'completeIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function localDayUtcBounds(string $date, string $timezone): array
    {
        $localStart = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        abort_if($localStart === false, 422);

        return [$localStart->utc(), $localStart->addDay()->utc()];
    }
}
