<?php

namespace App\Services\SlotCatalog;

use Carbon\CarbonImmutable;

final readonly class ResolvedSlot
{
    public function __construct(
        public CarbonImmutable $serviceStart,
        public CarbonImmutable $serviceEnd,
        public CarbonImmutable $occupiedStart,
        public CarbonImmutable $occupiedEnd,
        public string $serviceDate,
        public ?int $slotOfferingId,
        public ?int $customSlotInstanceId,
        public ?string $slotCode,
        public ?string $slotName,
        public int $durationMinutes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function databaseValues(): array
    {
        return [
            'slot_offering_id' => $this->slotOfferingId,
            'custom_slot_instance_id' => $this->customSlotInstanceId,
            'service_date' => $this->serviceDate,
            'service_start' => $this->serviceStart,
            'service_end' => $this->serviceEnd,
            'business_start' => $this->serviceStart,
            'business_end' => $this->serviceEnd,
            'occupied_start' => $this->occupiedStart,
            'occupied_end' => $this->occupiedEnd,
            'slot_code_snapshot' => $this->slotCode,
            'slot_name_snapshot' => $this->slotName,
            'slot_duration_minutes_snapshot' => $this->slotCode === null ? null : $this->durationMinutes,
        ];
    }

    /**
     * Exact instance identity is checked before its reusable template identity.
     *
     * @return list<int>
     */
    public function compatibilityIdentityIds(): array
    {
        return array_values(array_unique(array_filter([
            $this->customSlotInstanceId,
            $this->slotOfferingId,
        ], static fn (?int $id): bool => $id !== null)));
    }

    /**
     * @return array<string, int|string>
     */
    public function responseValues(): array
    {
        $values = [
            'service_date' => $this->serviceDate,
            'service_start' => $this->serviceStart->format('Y-m-d\TH:i:s\Z'),
            'service_end' => $this->serviceEnd->format('Y-m-d\TH:i:s\Z'),
            'occupied_start' => $this->occupiedStart->format('Y-m-d\TH:i:s\Z'),
            'occupied_end' => $this->occupiedEnd->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($this->slotOfferingId !== null) {
            $values['slot_offering_id'] = $this->slotOfferingId;
        }

        if ($this->customSlotInstanceId !== null) {
            $values['custom_slot_instance_id'] = $this->customSlotInstanceId;
        }

        if ($this->slotCode !== null) {
            $values['slot_code'] = $this->slotCode;
        }

        return $values;
    }
}
