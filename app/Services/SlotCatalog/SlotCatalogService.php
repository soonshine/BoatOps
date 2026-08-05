<?php

namespace App\Services\SlotCatalog;

use App\Exceptions\SlotCatalogException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SlotCatalogService
{
    /**
     * Create a reusable organization-level custom offering.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $boatIds
     */
    public function createReusableOffering(
        int $organizationId,
        array $attributes,
        array $boatIds = [],
        ?int $actorApiClientId = null,
    ): int {
        return $this->create(
            organizationId: $organizationId,
            kind: 'CUSTOM_TEMPLATE',
            attributes: $attributes,
            boatIds: $boatIds,
            actorApiClientId: $actorApiClientId,
        );
    }

    /**
     * Create a one-time slot or a date-specific override of a reusable offering.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $boatIds
     */
    public function createCustomInstance(
        int $organizationId,
        array $attributes,
        array $boatIds = [],
        ?int $actorApiClientId = null,
    ): int {
        $templateId = isset($attributes['template_slot_offering_id'])
            ? (int) $attributes['template_slot_offering_id']
            : null;

        if ($templateId !== null) {
            $template = DB::table('slot_offerings')
                ->where('organization_id', $organizationId)
                ->whereIn('kind', ['PRESET', 'CUSTOM_TEMPLATE'])
                ->where('id', $templateId)
                ->first();

            if (! $template) {
                throw new SlotCatalogException(
                    'AUTHORIZATION_FAILED',
                    'The custom slot template is not accessible.',
                    403,
                );
            }

            if ($template->status === 'RETIRED') {
                throw new SlotCatalogException(
                    'SLOT_UNAVAILABLE',
                    'A retired slot definition cannot be selected for a new custom instance.',
                    409,
                );
            }

            if (
                $template->status === 'DRAFT'
                && strtoupper((string) ($attributes['status'] ?? 'DRAFT')) === 'ACTIVE'
            ) {
                throw new SlotCatalogException(
                    'SLOT_UNAVAILABLE',
                    'A draft template cannot create an active custom instance.',
                    409,
                );
            }

            foreach ([
                'name',
                'service_start_time',
                'service_end_time',
                'duration_minutes',
                'additional_buffer_before_minutes',
                'additional_buffer_after_minutes',
                'applies_to_all_boats',
                'operating_time_status',
            ] as $inheritableAttribute) {
                if (array_key_exists($inheritableAttribute, $attributes) && $attributes[$inheritableAttribute] === null) {
                    unset($attributes[$inheritableAttribute]);
                }
            }

            $attributes += [
                'name' => $template->name,
                'service_start_time' => $template->service_start_time,
                'service_end_time' => $template->service_end_time,
                'duration_minutes' => $template->duration_minutes,
                'additional_buffer_before_minutes' => $template->additional_buffer_before_minutes,
                'additional_buffer_after_minutes' => $template->additional_buffer_after_minutes,
                'applies_to_all_boats' => (bool) $template->applies_to_all_boats,
                'operating_time_status' => $template->operating_time_status,
            ];

            if ($boatIds === [] && ! $template->applies_to_all_boats) {
                $boatIds = DB::table('slot_offering_boats')
                    ->where('organization_id', $organizationId)
                    ->where('slot_offering_id', $template->id)
                    ->orderBy('boat_id')
                    ->pluck('boat_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
            }
        }

        return $this->create(
            organizationId: $organizationId,
            kind: 'CUSTOM_INSTANCE',
            attributes: $attributes,
            boatIds: $boatIds,
            actorApiClientId: $actorApiClientId,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOfferings(int $organizationId): array
    {
        $this->assertOrganizationExists($organizationId);
        $offerings = DB::table('slot_offerings')
            ->where('organization_id', $organizationId)
            ->orderByRaw("CASE status WHEN 'ACTIVE' THEN 0 WHEN 'DRAFT' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE kind WHEN 'PRESET' THEN 0 WHEN 'CUSTOM_TEMPLATE' THEN 1 ELSE 2 END")
            ->orderBy('code')
            ->get();

        if ($offerings->isEmpty()) {
            return [];
        }

        $offeringIds = $offerings->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $boatIdsByOffering = DB::table('slot_offering_boats')
            ->where('organization_id', $organizationId)
            ->whereIn('slot_offering_id', $offeringIds)
            ->orderBy('boat_id')
            ->get(['slot_offering_id', 'boat_id'])
            ->groupBy('slot_offering_id');
        $usedIds = $this->usedDefinitionIds($organizationId);

        return $offerings
            ->map(fn (object $offering): array => $this->serializeOffering(
                $offering,
                $boatIdsByOffering->get($offering->id, collect()),
                isset($usedIds[(int) $offering->id]),
            ))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function offering(int $organizationId, int $offeringId): array
    {
        $offering = DB::table('slot_offerings')
            ->where('organization_id', $organizationId)
            ->where('id', $offeringId)
            ->first();

        if (! $offering) {
            throw new SlotCatalogException(
                'AUTHORIZATION_FAILED',
                'The requested slot offering is not accessible.',
                403,
            );
        }

        $boatRows = DB::table('slot_offering_boats')
            ->where('organization_id', $organizationId)
            ->where('slot_offering_id', $offeringId)
            ->orderBy('boat_id')
            ->get(['slot_offering_id', 'boat_id']);

        return $this->serializeOffering(
            $offering,
            $boatRows,
            $this->definitionIsUsed($organizationId, $offeringId),
        );
    }

    /**
     * A retired definition remains queryable and attached historical snapshots remain
     * untouched. RETIRED is terminal; changed times or identity require a new row.
     *
     * @return array<string, mixed>
     */
    public function transitionStatus(
        int $organizationId,
        int $offeringId,
        string $targetStatus,
        ?int $actorApiClientId = null,
    ): array {
        $targetStatus = strtoupper($targetStatus);

        if (! in_array($targetStatus, ['ACTIVE', 'RETIRED'], true)) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'The requested slot transition is invalid.', 422);
        }

        DB::transaction(function () use ($organizationId, $offeringId, $targetStatus, $actorApiClientId): void {
            $this->lockOrganization($organizationId);
            $offering = DB::table('slot_offerings')
                ->where('organization_id', $organizationId)
                ->where('id', $offeringId)
                ->lockForUpdate()
                ->first();

            if (! $offering) {
                throw new SlotCatalogException(
                    'AUTHORIZATION_FAILED',
                    'The requested slot offering is not accessible.',
                    403,
                );
            }

            $allowed = $targetStatus === 'ACTIVE'
                ? $offering->status === 'DRAFT'
                : in_array($offering->status, ['DRAFT', 'ACTIVE'], true);

            if (! $allowed) {
                throw new SlotCatalogException(
                    'INVALID_TRANSITION',
                    "The slot offering cannot transition from {$offering->status} to {$targetStatus}.",
                    409,
                );
            }

            $now = now()->utc();
            DB::table('slot_offerings')->where('id', $offeringId)->update([
                'status' => $targetStatus,
                'updated_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId,
                'actor_type' => $actorApiClientId === null ? 'system' : 'api_client',
                'actor_id' => $actorApiClientId,
                'action' => $targetStatus === 'ACTIVE' ? 'slot.activated' : 'slot.retired',
                'object_type' => 'slot_offering',
                'object_id' => $offeringId,
                'before_values' => json_encode(['status' => $offering->status], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['status' => $targetStatus], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('organizations')->where('id', $organizationId)->increment('inventory_revision');
        }, 3);

        return $this->offering($organizationId, $offeringId);
    }

    public function definitionIsUsed(int $organizationId, int $offeringId): bool
    {
        foreach (['allocations', 'holds', 'bookings'] as $table) {
            if (DB::table($table)
                ->where('organization_id', $organizationId)
                ->where(function ($query) use ($offeringId): void {
                    $query->where('slot_offering_id', $offeringId)
                        ->orWhere('custom_slot_instance_id', $offeringId);
                })
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    public function operatingTimeNotice(string $status): string
    {
        return match ($status) {
            'DEMO_DEFAULT_UNVERIFIED' => 'DEMO DEFAULT / UNVERIFIED OPERATING TIME',
            'FICTIONAL_VALIDATION_SCENARIO' => 'FICTIONAL VALIDATION SCENARIO / NOT A DEFAULT OPERATING TIME',
            default => 'UNVERIFIED OPERATING TIME',
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $boatIds
     */
    private function create(
        int $organizationId,
        string $kind,
        array $attributes,
        array $boatIds,
        ?int $actorApiClientId,
    ): int {
        $this->assertOrganizationExists($organizationId);
        $code = $attributes['code'] ?? null;
        $name = $attributes['name'] ?? null;
        $status = strtoupper((string) ($attributes['status'] ?? 'DRAFT'));
        $operatingTimeStatus = strtoupper((string) ($attributes['operating_time_status'] ?? 'UNVERIFIED'));

        if (! is_string($code) || ! preg_match('/^[A-Z0-9][A-Z0-9_-]{1,99}$/', $code)) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'The slot code is invalid.', 422);
        }

        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'The slot name is invalid.', 422);
        }

        if (! in_array($status, ['ACTIVE', 'DRAFT', 'RETIRED'], true)) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'The slot status is invalid.', 422);
        }

        if (! in_array($operatingTimeStatus, [
            'UNVERIFIED',
            'DEMO_DEFAULT_UNVERIFIED',
            'FICTIONAL_VALIDATION_SCENARIO',
        ], true)) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'The operating time status is invalid.', 422);
        }

        $serviceStartTime = $this->normalizeTime($attributes['service_start_time'] ?? null);
        $serviceEndTime = $this->normalizeTime($attributes['service_end_time'] ?? null);
        $durationMinutes = $this->positiveInteger($attributes['duration_minutes'] ?? null, 'duration_minutes');
        $startSecond = $this->secondOfDay($serviceStartTime);
        $endSecond = $this->secondOfDay($serviceEndTime);

        if ($endSecond <= $startSecond) {
            throw new SlotCatalogException(
                'SLOT_CROSSES_MIDNIGHT',
                'Cross-midnight slot offerings are not supported in BoatOps v0.0.5.',
                422,
            );
        }

        $durationSeconds = $endSecond - $startSecond;

        if ($durationSeconds % 60 !== 0 || intdiv($durationSeconds, 60) !== $durationMinutes) {
            throw new SlotCatalogException(
                'VALIDATION_FAILED',
                'The slot duration does not match its service start and end times.',
                422,
            );
        }

        $serviceDate = $kind === 'CUSTOM_INSTANCE'
            ? $this->date($attributes['service_date'] ?? null, 'service_date', false)
            : null;
        $validFrom = $kind === 'CUSTOM_INSTANCE'
            ? null
            : $this->date($attributes['valid_from'] ?? null, 'valid_from', true);
        $validUntil = $kind === 'CUSTOM_INSTANCE'
            ? null
            : $this->date($attributes['valid_until'] ?? null, 'valid_until', true);

        if ($validFrom !== null && $validUntil !== null && $validUntil < $validFrom) {
            throw new SlotCatalogException('VALIDATION_FAILED', 'valid_until cannot precede valid_from.', 422);
        }

        $additionalBufferBefore = $this->nonNegativeInteger(
            $attributes['additional_buffer_before_minutes'] ?? 0,
            'additional_buffer_before_minutes',
        );
        $additionalBufferAfter = $this->nonNegativeInteger(
            $attributes['additional_buffer_after_minutes'] ?? 0,
            'additional_buffer_after_minutes',
        );
        $appliesToAllBoats = (bool) ($attributes['applies_to_all_boats'] ?? $boatIds === []);
        $boatIds = array_values(array_unique(array_map('intval', $boatIds)));

        if (! $appliesToAllBoats && $boatIds === []) {
            throw new SlotCatalogException(
                'VALIDATION_FAILED',
                'At least one applicable boat is required when a slot is not organization-wide.',
                422,
            );
        }

        if ($boatIds !== []) {
            $accessibleBoatCount = DB::table('boats')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $boatIds)
                ->count();

            if ($accessibleBoatCount !== count($boatIds)) {
                throw new SlotCatalogException(
                    'AUTHORIZATION_FAILED',
                    'One or more applicable boats are not accessible.',
                    403,
                );
            }
        }

        return DB::transaction(function () use (
            $organizationId,
            $kind,
            $attributes,
            $boatIds,
            $actorApiClientId,
            $code,
            $name,
            $status,
            $operatingTimeStatus,
            $serviceDate,
            $serviceStartTime,
            $serviceEndTime,
            $durationMinutes,
            $additionalBufferBefore,
            $additionalBufferAfter,
            $validFrom,
            $validUntil,
            $appliesToAllBoats,
        ): int {
            $this->lockOrganization($organizationId);

            if (DB::table('slot_offerings')->where('organization_id', $organizationId)->where('code', $code)->exists()) {
                throw new SlotCatalogException('VALIDATION_FAILED', 'The slot code already exists.', 422);
            }

            $now = now()->utc();
            $slotOfferingId = DB::table('slot_offerings')->insertGetId([
                'organization_id' => $organizationId,
                'template_slot_offering_id' => $kind === 'CUSTOM_INSTANCE'
                    ? ($attributes['template_slot_offering_id'] ?? null)
                    : null,
                'kind' => $kind,
                'code' => $code,
                'name' => trim($name),
                'status' => $status,
                'operating_time_status' => $operatingTimeStatus,
                'service_date' => $serviceDate,
                'service_start_time' => $serviceStartTime,
                'service_end_time' => $serviceEndTime,
                'duration_minutes' => $durationMinutes,
                'additional_buffer_before_minutes' => $additionalBufferBefore,
                'additional_buffer_after_minutes' => $additionalBufferAfter,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'applies_to_all_boats' => $appliesToAllBoats,
                'created_by_api_client_id' => $actorApiClientId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (! $appliesToAllBoats) {
                foreach ($boatIds as $boatId) {
                    DB::table('slot_offering_boats')->insert([
                        'organization_id' => $organizationId,
                        'slot_offering_id' => $slotOfferingId,
                        'boat_id' => $boatId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId,
                'actor_type' => $actorApiClientId === null ? 'system' : 'api_client',
                'actor_id' => $actorApiClientId,
                'action' => 'slot.created',
                'object_type' => 'slot_offering',
                'object_id' => $slotOfferingId,
                'before_values' => null,
                'after_values' => json_encode([
                    'kind' => $kind,
                    'code' => $code,
                    'name' => trim($name),
                    'status' => $status,
                    'operating_time_status' => $operatingTimeStatus,
                    'service_date' => $serviceDate,
                    'service_start_time' => $serviceStartTime,
                    'service_end_time' => $serviceEndTime,
                    'duration_minutes' => $durationMinutes,
                    'additional_buffer_before_minutes' => $additionalBufferBefore,
                    'additional_buffer_after_minutes' => $additionalBufferAfter,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                    'applies_to_all_boats' => $appliesToAllBoats,
                    'boat_ids' => $appliesToAllBoats ? [] : $boatIds,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('organizations')->where('id', $organizationId)->increment('inventory_revision');

            return $slotOfferingId;
        }, 3);
    }

    /**
     * @param  Collection<int, object>  $boatRows
     * @return array<string, mixed>
     */
    private function serializeOffering(object $offering, Collection $boatRows, bool $isUsed): array
    {
        $operatingTimeStatus = (string) $offering->operating_time_status;

        return [
            'id' => (int) $offering->id,
            'template_slot_offering_id' => $offering->template_slot_offering_id === null
                ? null
                : (int) $offering->template_slot_offering_id,
            'kind' => (string) $offering->kind,
            'code' => (string) $offering->code,
            'name' => (string) $offering->name,
            'status' => (string) $offering->status,
            'operating_time_status' => $operatingTimeStatus,
            'operating_time_notice' => $this->operatingTimeNotice($operatingTimeStatus),
            'service_date' => $offering->service_date === null ? null : (string) $offering->service_date,
            'service_start_time' => (string) $offering->service_start_time,
            'service_end_time' => (string) $offering->service_end_time,
            'duration_minutes' => (int) $offering->duration_minutes,
            'additional_buffer_before_minutes' => (int) $offering->additional_buffer_before_minutes,
            'additional_buffer_after_minutes' => (int) $offering->additional_buffer_after_minutes,
            'valid_from' => $offering->valid_from === null ? null : (string) $offering->valid_from,
            'valid_until' => $offering->valid_until === null ? null : (string) $offering->valid_until,
            'applies_to_all_boats' => (bool) $offering->applies_to_all_boats,
            'boat_ids' => $boatRows->pluck('boat_id')->map(static fn (mixed $id): int => (int) $id)->all(),
            'has_historical_usage' => $isUsed,
            'created_at' => CarbonImmutable::parse((string) $offering->created_at, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => CarbonImmutable::parse((string) $offering->updated_at, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array<int, true>
     */
    private function usedDefinitionIds(int $organizationId): array
    {
        $used = [];

        foreach (['allocations', 'holds', 'bookings'] as $table) {
            foreach (['slot_offering_id', 'custom_slot_instance_id'] as $column) {
                foreach (DB::table($table)
                    ->where('organization_id', $organizationId)
                    ->whereNotNull($column)
                    ->distinct()
                    ->pluck($column) as $id) {
                    $used[(int) $id] = true;
                }
            }
        }

        return $used;
    }

    private function assertOrganizationExists(int $organizationId): void
    {
        if (! DB::table('organizations')->where('id', $organizationId)->exists()) {
            throw new SlotCatalogException('AUTHORIZATION_FAILED', 'The organization is not accessible.', 403);
        }
    }

    private function lockOrganization(int $organizationId): void
    {
        if (! DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first()) {
            throw new SlotCatalogException('AUTHORIZATION_FAILED', 'The organization is not accessible.', 403);
        }
    }

    private function normalizeTime(mixed $time): string
    {
        if (! is_string($time) || ! preg_match(
            '/^(?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d)(?::(?<second>[0-5]\d))?$/',
            $time,
            $matches,
        )) {
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

    private function date(mixed $date, string $field, bool $nullable): ?string
    {
        if ($date === null && $nullable) {
            return null;
        }

        if (! is_string($date)) {
            throw new SlotCatalogException('VALIDATION_FAILED', "The {$field} value is invalid.", 422);
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        } catch (Throwable) {
            $parsed = false;
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new SlotCatalogException('VALIDATION_FAILED', "The {$field} value is invalid.", 422);
        }

        return $date;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if ($integer === false || $integer < 1 || $integer > 1440) {
            throw new SlotCatalogException('VALIDATION_FAILED', "The {$field} value is invalid.", 422);
        }

        return $integer;
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if ($integer === false || $integer < 0 || $integer > 1440) {
            throw new SlotCatalogException('VALIDATION_FAILED', "The {$field} value is invalid.", 422);
        }

        return $integer;
    }
}
