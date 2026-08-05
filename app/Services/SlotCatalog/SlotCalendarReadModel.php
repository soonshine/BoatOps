<?php

namespace App\Services\SlotCatalog;

use App\Exceptions\SlotCatalogException;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SlotCalendarReadModel
{
    public function __construct(
        private readonly SlotIntervalResolver $intervalResolver,
        private readonly SlotAvailabilityService $availability,
        private readonly SlotCompatibilityService $compatibility,
        private readonly SlotCatalogService $catalog,
    ) {}

    /**
     * Build a bounded, allocation-backed projection. This is not an inventory write
     * model: every eventual sale must still be re-adjudicated by the transactional
     * availability/HOLD command path.
     *
     * @return array<string, mixed>
     */
    public function read(
        object $organization,
        string $from,
        string $to,
        ?int $boatId = null,
    ): array {
        $fromDate = $this->exactDate($from, 'from');
        $toDate = $this->exactDate($to, 'to');
        $difference = (int) $fromDate->diffInDays($toDate, false);

        if ($difference < 0) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'to cannot precede from.', 422);
        }

        if ($difference + 1 > 31) {
            throw new SlotCatalogException(
                'VALIDATION_FAILED',
                'The calendar range cannot exceed 31 organization-local dates.',
                422,
            );
        }

        $timezone = $this->timezone((string) $organization->timezone);
        $boatsQuery = DB::table('boats')
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->orderBy('id');

        if ($boatId !== null) {
            $boatsQuery->where('id', $boatId);
            $boats = $boatsQuery->get();

            if ($boats->isEmpty()) {
                throw new SlotCatalogException(
                    'AUTHORIZATION_FAILED',
                    'The requested boat is not accessible.',
                    403,
                );
            }
        } else {
            $boats = $boatsQuery->where('status', 'ACTIVE')->get();
        }

        $localRangeStart = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        $localRangeEnd = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)?->addDay();

        if (! $localRangeStart || ! $localRangeEnd) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'The calendar range is invalid.', 422);
        }

        $entries = DB::table('slot_offerings')
            ->where('organization_id', $organization->id)
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($query) use ($from, $to): void {
                    $query->where('kind', 'CUSTOM_INSTANCE')
                        ->whereBetween('service_date', [$from, $to]);
                })->orWhere(function ($query) use ($from, $to): void {
                    $query->whereIn('kind', ['PRESET', 'CUSTOM_TEMPLATE'])
                        ->where(function ($query) use ($to): void {
                            $query->whereNull('valid_from')->orWhere('valid_from', '<=', $to);
                        })
                        ->where(function ($query) use ($from): void {
                            $query->whereNull('valid_until')->orWhere('valid_until', '>=', $from);
                        });
                });
            })
            ->orderBy('service_start_time')
            ->orderBy('code')
            ->get();
        $entryIds = $entries->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $boatScope = $entryIds === []
            ? collect()
            : DB::table('slot_offering_boats')
                ->where('organization_id', $organization->id)
                ->whereIn('slot_offering_id', $entryIds)
                ->get(['slot_offering_id', 'boat_id'])
                ->groupBy('slot_offering_id');
        $boatIds = $boats->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $allocations = $boatIds === []
            ? collect()
            : DB::table('allocations')
                ->where('organization_id', $organization->id)
                ->whereIn('boat_id', $boatIds)
                ->where('status', 'ACTIVE')
                ->where('occupied_start', '<', $localRangeEnd->utc())
                ->where('occupied_end', '>', $localRangeStart->utc())
                ->orderBy('boat_id')
                ->orderBy('occupied_start')
                ->orderBy('id')
                ->get([
                    'id',
                    'boat_id',
                    'allocation_type',
                    'status',
                    'slot_offering_id',
                    'custom_slot_instance_id',
                    'service_date',
                    'service_start',
                    'service_end',
                    'business_start',
                    'business_end',
                    'occupied_start',
                    'occupied_end',
                    'slot_code_snapshot',
                    'slot_name_snapshot',
                    'slot_duration_minutes_snapshot',
                ]);
        $allocationsByBoat = $allocations->groupBy('boat_id');
        $policies = $this->compatibility->policiesForOrganization((int) $organization->id);
        $dates = [];

        for ($date = $fromDate; $date->lessThanOrEqualTo($toDate); $date = $date->addDay()) {
            $dates[] = $date->format('Y-m-d');
        }

        $boatResults = [];

        foreach ($boats as $boat) {
            $boatAllocations = $allocationsByBoat->get($boat->id, collect());
            $dateResults = [];

            foreach ($dates as $date) {
                $dateStart = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
                $dateEnd = $dateStart?->addDay();

                if (! $dateStart || ! $dateEnd) {
                    throw new SlotCatalogException('VALIDATION_FAILED', 'The calendar date is invalid.', 422);
                }

                $dateAllocations = $boatAllocations
                    ->filter(fn (object $allocation): bool => $this->overlapsUtcDay($allocation, $dateStart, $dateEnd))
                    ->values();
                $slots = [];

                foreach ($entries as $entry) {
                    if (! $this->entryAppliesOnDate($entry, $date)) {
                        continue;
                    }

                    if (! $entry->applies_to_all_boats) {
                        $applicableBoatIds = $boatScope->get($entry->id, collect())
                            ->pluck('boat_id')
                            ->map(static fn (mixed $id): int => (int) $id);

                        if (! $applicableBoatIds->contains((int) $boat->id)) {
                            continue;
                        }
                    }

                    $resolved = $this->intervalResolver->resolveLoadedCatalogEntry(
                        $organization,
                        $boat,
                        $entry,
                        $date,
                    );
                    $decision = $this->availability->calendarDecision(
                        $resolved,
                        $dateAllocations,
                        $policies,
                    );

                    if ($entry->status !== 'ACTIVE' && $decision['available']) {
                        $decision = [
                            'available' => false,
                            'status' => 'UNAVAILABLE',
                            'conflict_code' => 'SLOT_UNAVAILABLE',
                            'message' => 'The slot definition is not active for future selection.',
                            'buffer_conflict' => false,
                            'direct_identity' => false,
                            'allocation' => null,
                        ];
                    }

                    $slots[] = $this->serializeSlot(
                        $entry,
                        $resolved,
                        $decision,
                        $timezone,
                    );
                }

                usort($slots, static fn (array $first, array $second): int => [
                    $first['service_start'],
                    $first['code'],
                    $first['definition_id'],
                ] <=> [
                    $second['service_start'],
                    $second['code'],
                    $second['definition_id'],
                ]);
                $dateResults[] = [
                    'date' => $date,
                    'allocations' => $dateAllocations
                        ->map(fn (object $allocation): array => $this->serializeAllocation($allocation, $timezone))
                        ->all(),
                    'slots' => $slots,
                ];
            }

            $boatResults[] = [
                'boat_id' => (int) $boat->id,
                'name' => (string) $boat->name,
                'status' => (string) $boat->status,
                'inventory_unit' => 'WHOLE_BOAT',
                'buffer_before_minutes' => (int) $boat->buffer_before_minutes,
                'buffer_after_minutes' => (int) $boat->buffer_after_minutes,
                'dates' => $dateResults,
            ];
        }

        $asOf = now()->utc();

        return [
            'request_id' => (string) Str::uuid(),
            'organization_id' => (int) $organization->id,
            'business_timezone' => (string) $organization->timezone,
            'from' => $from,
            'to' => $to,
            'as_of' => $asOf->format('Y-m-d\TH:i:s\Z'),
            'inventory_revision' => (int) DB::table('organizations')
                ->where('id', $organization->id)
                ->value('inventory_revision'),
            'inventory_truth' => 'ACTIVE_ALLOCATIONS',
            'read_model_notice' => 'Calendar projection only; availability and HOLD transactions re-adjudicate final inventory.',
            'operating_time_notice' => '演示默认档期；真实起止时间和周转缓冲尚未冻结。',
            'boats' => $boatResults,
        ];
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function serializeSlot(
        object $entry,
        ResolvedSlot $resolved,
        array $decision,
        DateTimeZone $timezone,
    ): array {
        $allocation = $decision['allocation'];
        $direct = $decision['direct_identity'] && $allocation !== null;
        $serviceStart = $direct
            ? CarbonImmutable::parse((string) ($allocation->service_start ?? $allocation->business_start), 'UTC')->utc()
            : $resolved->serviceStart;
        $serviceEnd = $direct
            ? CarbonImmutable::parse((string) ($allocation->service_end ?? $allocation->business_end), 'UTC')->utc()
            : $resolved->serviceEnd;
        $occupiedStart = $direct
            ? CarbonImmutable::parse((string) $allocation->occupied_start, 'UTC')->utc()
            : $resolved->occupiedStart;
        $occupiedEnd = $direct
            ? CarbonImmutable::parse((string) $allocation->occupied_end, 'UTC')->utc()
            : $resolved->occupiedEnd;
        $code = $direct && $allocation->slot_code_snapshot !== null
            ? (string) $allocation->slot_code_snapshot
            : (string) $entry->code;
        $name = $direct && $allocation->slot_name_snapshot !== null
            ? (string) $allocation->slot_name_snapshot
            : (string) $entry->name;
        $authority = $allocation === null ? null : [
            'allocation_id' => (int) $allocation->id,
            'allocation_type' => (string) $allocation->allocation_type,
            'occupied_start' => $this->utc((string) $allocation->occupied_start),
            'occupied_end' => $this->utc((string) $allocation->occupied_end),
        ];

        return [
            'definition_id' => (int) $entry->id,
            'identity' => [
                'type' => $entry->kind === 'CUSTOM_INSTANCE' ? 'CUSTOM_INSTANCE' : 'SLOT_OFFERING',
                'slot_offering_id' => $resolved->slotOfferingId,
                'custom_slot_instance_id' => $resolved->customSlotInstanceId,
            ],
            'slot_offering_id' => $resolved->slotOfferingId,
            'custom_slot_instance_id' => $resolved->customSlotInstanceId,
            'kind' => (string) $entry->kind,
            'code' => $code,
            'name' => $name,
            'definition_status' => (string) $entry->status,
            'operating_time_status' => (string) $entry->operating_time_status,
            'operating_time_notice' => $this->catalog->operatingTimeNotice((string) $entry->operating_time_status),
            'duration_minutes' => $direct && $allocation->slot_duration_minutes_snapshot !== null
                ? (int) $allocation->slot_duration_minutes_snapshot
                : (int) $entry->duration_minutes,
            'service_start' => $serviceStart->format('Y-m-d\TH:i:s\Z'),
            'service_end' => $serviceEnd->format('Y-m-d\TH:i:s\Z'),
            'occupied_start' => $occupiedStart->format('Y-m-d\TH:i:s\Z'),
            'occupied_end' => $occupiedEnd->format('Y-m-d\TH:i:s\Z'),
            'service_start_local' => $serviceStart->setTimezone($timezone)->toIso8601String(),
            'service_end_local' => $serviceEnd->setTimezone($timezone)->toIso8601String(),
            'occupied_start_local' => $occupiedStart->setTimezone($timezone)->toIso8601String(),
            'occupied_end_local' => $occupiedEnd->setTimezone($timezone)->toIso8601String(),
            'status' => $decision['status'],
            'selectable' => $entry->status === 'ACTIVE' && $decision['available'],
            'conflict_code' => $decision['conflict_code'],
            'conflict_message' => $decision['message'],
            'buffer_conflict' => $decision['buffer_conflict'],
            'authority' => $authority,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAllocation(object $allocation, DateTimeZone $timezone): array
    {
        $serviceStart = CarbonImmutable::parse(
            (string) ($allocation->service_start ?? $allocation->business_start),
            'UTC',
        )->utc();
        $serviceEnd = CarbonImmutable::parse(
            (string) ($allocation->service_end ?? $allocation->business_end),
            'UTC',
        )->utc();
        $occupiedStart = CarbonImmutable::parse((string) $allocation->occupied_start, 'UTC')->utc();
        $occupiedEnd = CarbonImmutable::parse((string) $allocation->occupied_end, 'UTC')->utc();

        return [
            'allocation_id' => (int) $allocation->id,
            'status' => match ((string) $allocation->allocation_type) {
                'HOLD', 'HELD' => 'HELD',
                'BOOKING', 'CONFIRMED' => 'CONFIRMED',
                'BLOCK', 'BLOCKED' => 'BLOCKED',
                default => 'UNAVAILABLE',
            },
            'allocation_type' => (string) $allocation->allocation_type,
            'slot_offering_id' => $allocation->slot_offering_id === null
                ? null
                : (int) $allocation->slot_offering_id,
            'custom_slot_instance_id' => $allocation->custom_slot_instance_id === null
                ? null
                : (int) $allocation->custom_slot_instance_id,
            'service_date' => $allocation->service_date === null
                ? $serviceStart->setTimezone($timezone)->format('Y-m-d')
                : (string) $allocation->service_date,
            'slot_code' => $allocation->slot_code_snapshot === null
                ? null
                : (string) $allocation->slot_code_snapshot,
            'slot_name' => $allocation->slot_name_snapshot === null
                ? null
                : (string) $allocation->slot_name_snapshot,
            'service_start' => $serviceStart->format('Y-m-d\TH:i:s\Z'),
            'service_end' => $serviceEnd->format('Y-m-d\TH:i:s\Z'),
            'occupied_start' => $occupiedStart->format('Y-m-d\TH:i:s\Z'),
            'occupied_end' => $occupiedEnd->format('Y-m-d\TH:i:s\Z'),
            'occupied_start_local' => $occupiedStart->setTimezone($timezone)->toIso8601String(),
            'occupied_end_local' => $occupiedEnd->setTimezone($timezone)->toIso8601String(),
            'authority' => 'ACTIVE_ALLOCATION',
        ];
    }

    private function entryAppliesOnDate(object $entry, string $date): bool
    {
        if ($entry->kind === 'CUSTOM_INSTANCE') {
            return (string) $entry->service_date === $date;
        }

        return ($entry->valid_from === null || (string) $entry->valid_from <= $date)
            && ($entry->valid_until === null || (string) $entry->valid_until >= $date);
    }

    private function overlapsUtcDay(
        object $allocation,
        CarbonImmutable $dateStart,
        CarbonImmutable $dateEnd,
    ): bool {
        $occupiedStart = CarbonImmutable::parse((string) $allocation->occupied_start, 'UTC')->utc();
        $occupiedEnd = CarbonImmutable::parse((string) $allocation->occupied_end, 'UTC')->utc();

        return $occupiedStart->lessThan($dateEnd->utc())
            && $occupiedEnd->greaterThan($dateStart->utc());
    }

    private function exactDate(string $date, string $field): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        } catch (Throwable) {
            $parsed = false;
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new SlotCatalogException('VALIDATION_FAILED', "The {$field} date is invalid.", 422);
        }

        return $parsed;
    }

    private function timezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'The organization timezone is invalid.', 422);
        }
    }

    private function utc(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
