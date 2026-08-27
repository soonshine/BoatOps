<?php

namespace App\Application\InquiryAi;

/**
 * Normalized extraction result for one raw Inquiry text (Issue #51 first slice).
 *
 * AI output is a validated suggestion only; this DTO never becomes operational
 * authority and performs no business decision. Every field is null when the
 * provider did not supply (or supply a valid) fact.
 */
final readonly class ExtractedInquiry
{
    public function __construct(
        public ?string $serviceDate,
        public ?string $boatName,
        public ?string $routeSummary,
        public ?string $contactName,
        public ?string $contactMethod,
        public ?string $contactValue,
        public ?int $partySize,
        public ?bool $pickupRequired,
        public ?string $hotelName,
        public ?string $roomNumber,
        public ?string $pickupTime,
        public ?string $meetingPoint,
        public ?string $serviceLocation,
    ) {}

    /**
     * Build the DTO from a schema-normalized provider result.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromNormalized(array $data): self
    {
        return new self(
            serviceDate: $data['service_date'],
            boatName: $data['boat_name'],
            routeSummary: $data['route_summary'],
            contactName: $data['contact_name'],
            contactMethod: $data['contact_method'],
            contactValue: $data['contact_value'],
            partySize: $data['party_size'],
            pickupRequired: $data['pickup_required'],
            hotelName: $data['hotel_name'],
            roomNumber: $data['room_number'],
            pickupTime: $data['pickup_time'],
            meetingPoint: $data['meeting_point'],
            serviceLocation: $data['service_location'],
        );
    }

    /**
     * Flat representation keyed by the Issue #51 field names.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_date' => $this->serviceDate,
            'boat_name' => $this->boatName,
            'route_summary' => $this->routeSummary,
            'contact_name' => $this->contactName,
            'contact_method' => $this->contactMethod,
            'contact_value' => $this->contactValue,
            'party_size' => $this->partySize,
            'pickup_required' => $this->pickupRequired,
            'hotel_name' => $this->hotelName,
            'room_number' => $this->roomNumber,
            'pickup_time' => $this->pickupTime,
            'meeting_point' => $this->meetingPoint,
            'service_location' => $this->serviceLocation,
        ];
    }
}
