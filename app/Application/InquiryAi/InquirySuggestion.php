<?php

namespace App\Application\InquiryAi;

/**
 * BoatOps-safe Inquiry form suggestion payload (#54 / 51B).
 *
 * This is the backend contract for 51C: it converts the single validated
 * 51A extraction DTO into what the existing operator Inquiry form may be
 * prefilled with. It performs no business decision and never becomes
 * operational authority; every value is a *suggestion* for human review.
 *
 * Invariants:
 * - `boat_id` is set only when exactly one deterministic normalized Boat
 *   match exists among the boats visible to the current organization; zero
 *   or multiple matches leave it null and only carry the extracted name as
 *   non-authoritative suggestion context (`boat_name_suggestion`).
 * - explicit no-transfer (`pickup_required=false`) stays `false`;
 * - a hotel value that only means self-arrival / no transfer becomes
 *   `hotel_name=null` instead of a fabricated hotel name;
 * - adult/child split, product/template, slot, price, captain/crew and
 *   departure time are not produced by the 51A extraction schema, so they
 *   remain null here; absence of evidence is not permission to infer a
 *   business fact.
 */
final readonly class InquirySuggestion
{
    /**
     * @param  array<int, int>|null  $childAges
     */
    public function __construct(
        public ?string $serviceDate,
        public ?int $boatId,
        public ?string $boatNameSuggestion,
        public string $boatResolution,
        public ?int $tripTemplateId,
        public ?int $slotOfferingId,
        public ?string $routeSummary,
        public ?string $contactName,
        public ?string $contactMethod,
        public ?string $contactValue,
        public ?int $partySize,
        public ?int $adultCount,
        public ?int $childCount,
        public ?array $childAges,
        public ?string $meetingPoint,
        public ?string $hotelName,
        public ?string $roomNumber,
        public ?bool $pickupRequired,
        public ?string $pickupTime,
        public ?string $serviceLocation,
        public ?string $salesSource,
        public ?string $agentReference,
        public ?string $serviceNotes,
        public ?string $internalNotes,
        public ?string $sellingCurrency,
        public ?int $sellingAmountMinor,
        public ?string $departureTime,
        public ?string $captainCrew,
    ) {}

    /**
     * Flat, form-shaped representation keyed by the operator Inquiry field
     * names plus the deterministic resolution metadata used by 51C.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_date' => $this->serviceDate,
            'boat_id' => $this->boatId,
            'trip_template_id' => $this->tripTemplateId,
            'slot_offering_id' => $this->slotOfferingId,
            'route_summary' => $this->routeSummary,
            'contact_name' => $this->contactName,
            'contact_method' => $this->contactMethod,
            'contact_value' => $this->contactValue,
            'party_size' => $this->partySize,
            'adult_count' => $this->adultCount,
            'child_count' => $this->childCount,
            'child_ages' => $this->childAges,
            'meeting_point' => $this->meetingPoint,
            'hotel_name' => $this->hotelName,
            'room_number' => $this->roomNumber,
            'pickup_required' => $this->pickupRequired,
            'pickup_time' => $this->pickupTime,
            'service_location' => $this->serviceLocation,
            'sales_source' => $this->salesSource,
            'agent_reference' => $this->agentReference,
            'service_notes' => $this->serviceNotes,
            'internal_notes' => $this->internalNotes,
            'selling_currency' => $this->sellingCurrency,
            'selling_amount_minor' => $this->sellingAmountMinor,
            'departure_time' => $this->departureTime,
            'captain_crew' => $this->captainCrew,
            'boat_name_suggestion' => $this->boatNameSuggestion,
            'boat_resolution' => $this->boatResolution,
        ];
    }
}
