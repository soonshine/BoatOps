<?php

namespace App\Services\SlotCatalog;

use App\Exceptions\SlotCatalogException;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SlotIntervalResolver
{
    /**
     * Resolve either a server-owned slot identity or the legacy caller-owned interval.
     *
     * @param  array<string, mixed>  $input
     */
    public function resolve(object $organization, object $boat, array $input): ResolvedSlot
    {
        $slotOfferingId = isset($input['slot_offering_id']) ? (int) $input['slot_offering_id'] : null;
        $customSlotInstanceId = isset($input['custom_slot_instance_id'])
            ? (int) $input['custom_slot_instance_id']
            : null;

        if ($slotOfferingId !== null && $customSlotInstanceId !== null) {
            throw new SlotCatalogException(
                'VALIDATION_FAILED',
                'Choose either a reusable slot offering or a date-specific custom slot instance.',
                422,
            );
        }

        if ($slotOfferingId === null && $customSlotInstanceId === null) {
            return $this->resolveLegacyInterval($organization, $boat, $input);
        }

        $entryId = $customSlotInstanceId ?? $slotOfferingId;
        $entry = DB::table('slot_offerings')
            ->where('organization_id', $organization->id)
            ->where('id', $entryId)
            ->first();

        if (! $entry) {
            throw new SlotCatalogException(
                'AUTHORIZATION_FAILED',
                'The requested slot is not accessible.',
                403,
            );
        }

        $expectedKinds = $customSlotInstanceId === null
            ? ['PRESET', 'CUSTOM_TEMPLATE']
            : ['CUSTOM_INSTANCE'];

        if (! in_array($entry->kind, $expectedKinds, true)) {
            throw new SlotCatalogException(
                'AUTHORIZATION_FAILED',
                'The requested slot identity is not accessible through this field.',
                403,
            );
        }

        if ($entry->status !== 'ACTIVE') {
            throw new SlotCatalogException('SLOT_UNAVAILABLE', 'The requested slot is not active.');
        }

        $serviceDate = $customSlotInstanceId === null
            ? ($input['service_date'] ?? null)
            : $entry->service_date;

        if (! is_string($serviceDate) || ! $this->isExactDate($serviceDate)) {
            throw new SlotCatalogException(
                'VALIDATION_FAILED',
                'A valid organization-local service_date is required for the selected slot.',
                422,
            );
        }

        if ($customSlotInstanceId !== null && $entry->service_date !== $serviceDate) {
            throw new SlotCatalogException('SLOT_UNAVAILABLE', 'The custom slot is not available on the requested date.');
        }

        if ($entry->valid_from !== null && $serviceDate < $entry->valid_from) {
            throw new SlotCatalogException('SLOT_UNAVAILABLE', 'The requested slot is not effective on this date.');
        }

        if ($entry->valid_until !== null && $serviceDate > $entry->valid_until) {
            throw new SlotCatalogException('SLOT_UNAVAILABLE', 'The requested slot is not effective on this date.');
        }

        if (! $entry->applies_to_all_boats) {
            $appliesToBoat = DB::table('slot_offering_boats')
                ->where('organization_id', $organization->id)
                ->where('slot_offering_id', $entry->id)
                ->where('boat_id', $boat->id)
                ->exists();

            if (! $appliesToBoat) {
                throw new SlotCatalogException('SLOT_UNAVAILABLE', 'The requested slot does not apply to this boat.');
            }
        }

        return $this->resolveLoadedCatalogEntry($organization, $boat, $entry, $serviceDate);
    }

    /**
     * Resolve a catalog row that has already been organization- and boat-scoped by a
     * bounded read model. This method intentionally does not require ACTIVE so retired
     * definitions can still render immutable historical allocation snapshots.
     */
    public function resolveLoadedCatalogEntry(
        object $organization,
        object $boat,
        object $entry,
        string $serviceDate,
    ): ResolvedSlot {
        if (! $this->isExactDate($serviceDate)) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'The slot service date is invalid.', 422);
        }

        [$serviceStart, $serviceEnd, $durationMinutes] = $this->localServiceInterval(
            $serviceDate,
            (string) $entry->service_start_time,
            (string) $entry->service_end_time,
            (int) $entry->duration_minutes,
            (string) $organization->timezone,
        );
        $beforeMinutes = (int) $boat->buffer_before_minutes
            + (int) $entry->additional_buffer_before_minutes;
        $afterMinutes = (int) $boat->buffer_after_minutes
            + (int) $entry->additional_buffer_after_minutes;
        $isCustomInstance = $entry->kind === 'CUSTOM_INSTANCE';

        return new ResolvedSlot(
            serviceStart: $serviceStart,
            serviceEnd: $serviceEnd,
            occupiedStart: $serviceStart->subMinutes($beforeMinutes),
            occupiedEnd: $serviceEnd->addMinutes($afterMinutes),
            serviceDate: $serviceDate,
            slotOfferingId: $isCustomInstance
                ? ($entry->template_slot_offering_id === null ? null : (int) $entry->template_slot_offering_id)
                : (int) $entry->id,
            customSlotInstanceId: $isCustomInstance ? (int) $entry->id : null,
            slotCode: (string) $entry->code,
            slotName: (string) $entry->name,
            durationMinutes: $durationMinutes,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveLegacyInterval(object $organization, object $boat, array $input): ResolvedSlot
    {
        if (! isset($input['starts_at'], $input['ends_at'])) {
            throw new SlotCatalogException(
                'VALIDATION_FAILED',
                'starts_at and ends_at are required when no slot identity is supplied.',
                422,
            );
        }

        $serviceStart = CarbonImmutable::parse((string) $input['starts_at'])->utc();
        $serviceEnd = CarbonImmutable::parse((string) $input['ends_at'])->utc();

        if ($serviceEnd->lessThanOrEqualTo($serviceStart)) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'ends_at must be after starts_at.', 422);
        }

        $serviceDate = $serviceStart
            ->setTimezone($this->timezone((string) $organization->timezone))
            ->toDateString();

        return new ResolvedSlot(
            serviceStart: $serviceStart,
            serviceEnd: $serviceEnd,
            occupiedStart: $serviceStart->subMinutes((int) $boat->buffer_before_minutes),
            occupiedEnd: $serviceEnd->addMinutes((int) $boat->buffer_after_minutes),
            serviceDate: $serviceDate,
            slotOfferingId: null,
            customSlotInstanceId: null,
            slotCode: null,
            slotName: null,
            durationMinutes: intdiv($serviceEnd->getTimestamp() - $serviceStart->getTimestamp(), 60),
        );
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable, int}
     */
    private function localServiceInterval(
        string $serviceDate,
        string $startTime,
        string $endTime,
        int $declaredDurationMinutes,
        string $timezone,
    ): array {
        $normalizedStart = $this->normalizeTime($startTime);
        $normalizedEnd = $this->normalizeTime($endTime);
        $startSecond = $this->secondOfDay($normalizedStart);
        $endSecond = $this->secondOfDay($normalizedEnd);

        if ($endSecond <= $startSecond) {
            throw new SlotCatalogException(
                'SLOT_CROSSES_MIDNIGHT',
                'Cross-midnight slot offerings are not supported in BoatOps v0.0.5.',
                422,
            );
        }

        $durationSeconds = $endSecond - $startSecond;

        if ($durationSeconds % 60 !== 0 || intdiv($durationSeconds, 60) !== $declaredDurationMinutes) {
            throw new SlotCatalogException(
                'VALIDATION_FAILED',
                'The slot duration does not match its service start and end times.',
                422,
            );
        }

        $localTimezone = $this->timezone($timezone);
        $serviceStart = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $serviceDate.' '.$normalizedStart,
            $localTimezone,
        );
        $serviceEnd = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $serviceDate.' '.$normalizedEnd,
            $localTimezone,
        );

        if (! $serviceStart || ! $serviceEnd) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'The slot service interval is invalid.', 422);
        }

        return [$serviceStart->utc(), $serviceEnd->utc(), $declaredDurationMinutes];
    }

    private function normalizeTime(string $time): string
    {
        if (! preg_match('/^(?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d)(?::(?<second>[0-5]\d))?$/', $time, $matches)) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'The slot service time is invalid.', 422);
        }

        return sprintf(
            '%s:%s:%s',
            $matches['hour'],
            $matches['minute'],
            ($matches['second'] ?? '') === '' ? '00' : $matches['second'],
        );
    }

    private function secondOfDay(string $time): int
    {
        [$hour, $minute, $second] = array_map('intval', explode(':', $time));

        return ($hour * 3600) + ($minute * 60) + $second;
    }

    private function isExactDate(string $date): bool
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        } catch (Throwable) {
            return false;
        }

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function timezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new SlotCatalogException(
                'VALIDATION_FAILED',
                'The organization timezone is invalid.',
                422,
            );
        }
    }
}
