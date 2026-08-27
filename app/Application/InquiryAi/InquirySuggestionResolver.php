<?php

namespace App\Application\InquiryAi;

use Illuminate\Support\Facades\DB;

/**
 * Application-layer conversion (#54 / 51B):
 *
 * ```text
 * validated 51A extraction DTO (ExtractedInquiry)
 * -> BoatOps field semantics/ranges re-validation
 * -> organization-scoped deterministic Boat resolution
 * -> safe form suggestion payload (InquirySuggestion)
 * ```
 *
 * Guarantees:
 * - read-only: this resolver never writes Inquiry / Booking / Trip / audit
 *   rows and performs no business decision;
 * - `boat_name` is resolved only against Boats visible to the CURRENT
 *   organization (same ACTIVE visibility the operator Inquiry form uses) and
 *   only via deterministic normalization (trim, whitespace collapse,
 *   lowercase); exactly one normalized match produces `boat_id`, zero or
 *   multiple matches leave `boat_id` empty and preserve the extracted name
 *   as non-authoritative suggestion context only;
 * - no fuzzy guessing and no AI-generated database IDs;
 * - unknown / unsupported / absent facts remain null;
 * - contradictions (e.g. self-arrival hotel marker plus pickup required)
 *   resolve to null instead of silently choosing a value.
 */
final class InquirySuggestionResolver
{
    /** Exactly one deterministic normalized Boat match produced a boat_id. */
    public const RESOLVED = 'RESOLVED';

    /** No organization-scoped Boat matches the extracted name. */
    public const NO_MATCH = 'NO_MATCH';

    /** More than one organization-scoped Boat matches the extracted name. */
    public const AMBIGUOUS = 'AMBIGUOUS';

    /** The extraction did not produce a boat name at all. */
    public const NOT_EXTRACTED = 'NOT_EXTRACTED';

    /** Mirrors the operator Inquiry form contact method vocabulary. */
    private const CONTACT_METHODS = ['PHONE', 'WHATSAPP', 'WECHAT', 'LINE', 'EMAIL', 'OTHER'];

    /**
     * Hotel values whose whole normalized value only means self-arrival /
     * no transfer. They are never a real accommodation name, so they must
     * not become a fabricated `hotel_name`. Matching is deterministic full
     * equality on the normalized value; there is no substring guessing.
     *
     * @var list<string>
     */
    private const SELF_ARRIVAL_MARKERS = [
        'self',
        'self-arrival',
        'self arrival',
        'self-arrive',
        'self arrive',
        'self-arrived',
        'self arrived',
        'selfarrival',
        'no transfer',
        'no-transfer',
        'no pickup',
        'no-pickup',
        'nopickup',
        '自行',
        '自行前往',
        '自行到达',
        '自行到码头',
        '自己到',
        '自己前往',
        '自己到达',
        '无接送',
        '不接送',
        '无需接送',
        '不需接送',
        '不需要接送',
        '直接到码头',
        '无需酒店接送',
        '不需要酒店接送',
    ];

    /**
     * Convert one validated extraction DTO into a BoatOps-safe suggestion.
     *
     * @param  iterable<BoatCandidate>  $organizationBoats  boats visible to the current organization
     */
    public function build(ExtractedInquiry $extracted, iterable $organizationBoats): InquirySuggestion
    {
        [$boatId, $boatResolution] = $this->resolveBoat($extracted->boatName, $organizationBoats);
        [$hotelName, $pickupRequired] = $this->resolvePickupAndHotel(
            $extracted->hotelName,
            $extracted->pickupRequired,
        );

        $contactMethod = $this->revalidateContactMethod($extracted->contactMethod);
        $contactValue = $this->revalidateString($extracted->contactValue, 255);
        // The Inquiry form requires the method and value pair (required_with
        // each other); a suggestion must never produce an invalid form state.
        if ($contactMethod === null || $contactValue === null) {
            $contactMethod = null;
            $contactValue = null;
        }

        return new InquirySuggestion(
            serviceDate: $this->revalidateDate($extracted->serviceDate),
            boatId: $boatId,
            boatNameSuggestion: $extracted->boatName,
            boatResolution: $boatResolution,
            tripTemplateId: null,
            slotOfferingId: null,
            routeSummary: $this->revalidateString($extracted->routeSummary, 2000),
            contactName: $this->revalidateString($extracted->contactName, 255),
            contactMethod: $contactMethod,
            contactValue: $contactValue,
            partySize: $this->revalidatePartySize($extracted->partySize),
            adultCount: null,
            childCount: null,
            childAges: null,
            meetingPoint: $this->revalidateString($extracted->meetingPoint, 2000),
            hotelName: $hotelName,
            roomNumber: $this->revalidateString($extracted->roomNumber, 255),
            pickupRequired: $pickupRequired,
            pickupTime: $this->revalidateTime($extracted->pickupTime),
            serviceLocation: $this->revalidateString($extracted->serviceLocation, 2000),
            salesSource: null,
            agentReference: null,
            serviceNotes: null,
            internalNotes: null,
            sellingCurrency: null,
            sellingAmountMinor: null,
            departureTime: null,
            captainCrew: null,
        );
    }

    /**
     * Organization-scoped read path: same visibility as the operator Inquiry
     * form (ACTIVE boats of the current organization only).
     */
    public function resolveForOrganization(ExtractedInquiry $extracted, int $organizationId): InquirySuggestion
    {
        $boats = DB::table('boats')
            ->where('organization_id', $organizationId)
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->get();

        return $this->build($extracted, $boats->map(
            static fn (object $boat): BoatCandidate => new BoatCandidate((int) $boat->id, (string) $boat->name),
        ));
    }

    /**
     * Deterministic organization-scoped resolution.
     *
     * @param  iterable<BoatCandidate>  $organizationBoats
     * @return array{0: ?int, 1: string}
     */
    private function resolveBoat(?string $boatName, iterable $organizationBoats): array
    {
        $normalizedName = $this->normalizeName($boatName);

        if ($normalizedName === null || $normalizedName === '') {
            return [null, self::NOT_EXTRACTED];
        }

        $matches = [];
        foreach ($organizationBoats as $candidate) {
            if ($this->normalizeName($candidate->name) === $normalizedName) {
                $matches[] = $candidate;
            }
        }

        return match (count($matches)) {
            1 => [$matches[0]->id, self::RESOLVED],
            0 => [null, self::NO_MATCH],
            default => [null, self::AMBIGUOUS],
        };
    }

    /**
     * Hotel / pickup semantics:
     * - a hotel value that only means self-arrival / no transfer becomes
     *   `hotel_name=null` (it is not an accommodation name);
     * - the same value also means explicit no-transfer semantics, so pickup
     *   is suggested `false`; if the extraction simultaneously claimed pickup
     *   IS required, the pair is contradictory, so both facts stay null for
     *   human confirmation instead of silently choosing one.
     *
     * @return array{0: ?string, 1: ?bool}
     */
    private function resolvePickupAndHotel(?string $hotelName, ?bool $pickupRequired): array
    {
        if (! $this->isSelfArrivalMarker($hotelName)) {
            return [$this->revalidateString($hotelName, 255), $pickupRequired];
        }

        return [null, $pickupRequired === true ? null : false];
    }

    private function isSelfArrivalMarker(?string $hotelName): bool
    {
        $normalized = $this->normalizeName($hotelName);

        return $normalized !== null && $normalized !== '' && in_array($normalized, self::SELF_ARRIVAL_MARKERS, true);
    }

    /**
     * Deterministic Boat name normalization: trim, collapse whitespace runs
     * to a single space, lowercase. No synonyms, no fuzzy similarity.
     */
    private function normalizeName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $collapsed = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $collapsed === '' ? '' : mb_strtolower($collapsed, 'UTF-8');
    }

    private function revalidateDate(?string $value): ?string
    {
        if ($value === null || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    private function revalidateTime(?string $value): ?string
    {
        if ($value === null || ! preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $value)) {
            return null;
        }

        return $value;
    }

    private function revalidatePartySize(?int $value): ?int
    {
        if ($value === null || $value < 1 || $value > 999) {
            return null;
        }

        return $value;
    }

    private function revalidateContactMethod(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return in_array($value, self::CONTACT_METHODS, true) ? $value : null;
    }

    private function revalidateString(?string $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value, 'UTF-8') <= $maxLength ? $value : null;
    }
}
