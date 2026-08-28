<?php

namespace Tests\Feature;

use App\Application\InquiryAi\ExtractedInquiry;
use App\Application\InquiryAi\InquirySuggestionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InquirySuggestionResolverTest extends TestCase
{
    use RefreshDatabase;

    private function organizationId(): int
    {
        return DB::table('organizations')->insertGetId([
            'name' => 'Fictional Resolver Operator',
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addBoat(int $organizationId, string $name, string $status = 'ACTIVE'): int
    {
        return DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $name,
            'status' => $status,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $facts */
    private function extracted(array $facts = []): ExtractedInquiry
    {
        return ExtractedInquiry::fromNormalized([
            'service_date' => $facts['service_date'] ?? null,
            'boat_name' => $facts['boat_name'] ?? null,
            'route_summary' => $facts['route_summary'] ?? null,
            'contact_name' => $facts['contact_name'] ?? null,
            'contact_method' => $facts['contact_method'] ?? null,
            'contact_value' => $facts['contact_value'] ?? null,
            'party_size' => $facts['party_size'] ?? null,
            'pickup_required' => $facts['pickup_required'] ?? null,
            'hotel_name' => $facts['hotel_name'] ?? null,
            'room_number' => $facts['room_number'] ?? null,
            'pickup_time' => $facts['pickup_time'] ?? null,
            'meeting_point' => $facts['meeting_point'] ?? null,
            'service_location' => $facts['service_location'] ?? null,
        ]);
    }

    private function resolver(): InquirySuggestionResolver
    {
        return app(InquirySuggestionResolver::class);
    }

    public function test_boat_resolution_is_deterministic_and_unique_match_sets_boat_id(): void
    {
        $org = $this->organizationId();
        $expected = $this->addBoat($org, 'Sea Star One');
        $this->addBoat($org, 'Sea Star Two');

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted(['boat_name' => '  Sea Star ONE  ']),
            $org,
        );

        $this->assertSame($expected, $suggestion->boatId);
        $this->assertSame(InquirySuggestionResolver::RESOLVED, $suggestion->boatResolution);
        $this->assertSame('  Sea Star ONE  ', $suggestion->boatNameSuggestion);
    }

    public function test_no_boat_match_leaves_boat_id_empty_and_preserves_extracted_name(): void
    {
        $org = $this->organizationId();
        $this->addBoat($org, 'Sea Star One');

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted(['boat_name' => 'Ghost Boat']),
            $org,
        );

        $this->assertNull($suggestion->boatId);
        $this->assertSame(InquirySuggestionResolver::NO_MATCH, $suggestion->boatResolution);
        $this->assertSame('Ghost Boat', $suggestion->boatNameSuggestion);
    }

    public function test_ambiguous_boat_match_leaves_boat_id_empty(): void
    {
        $org = $this->organizationId();
        $this->addBoat($org, 'Sea Star');
        $this->addBoat($org, 'Sea Star');

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted(['boat_name' => 'sea star']),
            $org,
        );

        $this->assertNull($suggestion->boatId);
        $this->assertSame(InquirySuggestionResolver::AMBIGUOUS, $suggestion->boatResolution);
        $this->assertSame('sea star', $suggestion->boatNameSuggestion);
    }

    public function test_absent_boat_name_is_not_resolved(): void
    {
        $org = $this->organizationId();
        $this->addBoat($org, 'Sea Star');

        $suggestion = $this->resolver()->resolveForOrganization($this->extracted(), $org);

        $this->assertNull($suggestion->boatId);
        $this->assertSame(InquirySuggestionResolver::NOT_EXTRACTED, $suggestion->boatResolution);
        $this->assertNull($suggestion->boatNameSuggestion);
    }

    public function test_explicit_no_transfer_semantics_keep_pickup_required_false(): void
    {
        $org = $this->organizationId();

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted([
                'pickup_required' => false,
                'hotel_name' => 'Sands Resort',
            ]),
            $org,
        );

        $this->assertFalse($suggestion->pickupRequired);
        $this->assertSame('Sands Resort', $suggestion->hotelName);
    }

    public function test_self_arrival_hotel_value_is_not_a_fabricated_hotel_name(): void
    {
        $org = $this->organizationId();

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted(['hotel_name' => ' SELF-ARRIVAL ']),
            $org,
        );

        $this->assertNull($suggestion->hotelName);
        $this->assertFalse($suggestion->pickupRequired);
    }

    public function test_self_arrival_marker_plus_pickup_required_is_contradiction_and_stays_unknown(): void
    {
        $org = $this->organizationId();

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted([
                'hotel_name' => 'no transfer',
                'pickup_required' => true,
            ]),
            $org,
        );

        $this->assertNull($suggestion->hotelName);
        $this->assertNull($suggestion->pickupRequired);
    }

    public function test_unknown_facts_and_absent_dimensions_remain_empty(): void
    {
        $org = $this->organizationId();
        $boat = $this->addBoat($org, 'Sea Star One');

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted([
                'service_date' => '2026-08-22',
                'boat_name' => 'Sea Star One',
                'route_summary' => 'Koh Tan + Koh Madsum',
                'contact_name' => '王三',
                'contact_method' => 'WHATSAPP',
                'contact_value' => '+66 81 234 5678',
                'party_size' => 5,
                'pickup_required' => true,
                'hotel_name' => 'Sands Resort',
                'room_number' => '302',
                'pickup_time' => '08:30',
                'meeting_point' => 'Hotel lobby',
                'service_location' => 'Koh Samui',
            ]),
            $org,
        );

        $this->assertSame($boat, $suggestion->boatId);
        $this->assertSame('2026-08-22', $suggestion->serviceDate);
        $this->assertSame(5, $suggestion->partySize);

        $this->assertNull($suggestion->adultCount);
        $this->assertNull($suggestion->childCount);
        $this->assertNull($suggestion->childAges);
        $this->assertNull($suggestion->tripTemplateId);
        $this->assertNull($suggestion->slotOfferingId);
        $this->assertNull($suggestion->sellingCurrency);
        $this->assertNull($suggestion->sellingAmountMinor);
        $this->assertNull($suggestion->departureTime);
        $this->assertNull($suggestion->captainCrew);
        $this->assertNull($suggestion->salesSource);
        $this->assertNull($suggestion->agentReference);
        $this->assertNull($suggestion->serviceNotes);
        $this->assertNull($suggestion->internalNotes);
    }

    public function test_contact_method_and_value_must_travel_as_a_pair(): void
    {
        $org = $this->organizationId();

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted(['contact_method' => 'LINE']),
            $org,
        );

        $this->assertNull($suggestion->contactMethod);
        $this->assertNull($suggestion->contactValue);
    }

    public function test_boat_resolution_is_strictly_organization_scoped(): void
    {
        $orgA = $this->organizationId();
        $orgB = $this->organizationId();
        $boatA = $this->addBoat($orgA, 'Same Name');
        $boatB = $this->addBoat($orgB, 'Same Name');
        $this->addBoat($orgB, 'Only In B');

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted(['boat_name' => 'Same Name']),
            $orgA,
        );

        $this->assertSame($boatA, $suggestion->boatId);
        $this->assertNotSame($boatB, $suggestion->boatId);

        $foreignSuggestion = $this->resolver()->resolveForOrganization(
            $this->extracted(['boat_name' => 'Only In B']),
            $orgA,
        );
        $this->assertNull($foreignSuggestion->boatId);
        $this->assertSame(InquirySuggestionResolver::NO_MATCH, $foreignSuggestion->boatResolution);
    }

    public function test_inactive_boat_is_not_resolvable(): void
    {
        $org = $this->organizationId();
        $this->addBoat($org, 'Sleepy Boat', 'INACTIVE');

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted(['boat_name' => 'Sleepy Boat']),
            $org,
        );

        $this->assertNull($suggestion->boatId);
        $this->assertSame(InquirySuggestionResolver::NO_MATCH, $suggestion->boatResolution);
    }

    public function test_plan_c_is_no_match_when_production_catalog_has_no_such_boat(): void
    {
        // #62 production truth (verified read-only on the live catalog): the
        // production organization has exactly one ACTIVE boat whose normalized
        // name is "demo coral one"; no boat, trip_template or slot_offering is
        // named PLAN C (0 exact and 0 ILIKE '%plan%' matches). The sanitized
        // acceptance order "PLAN C" therefore has no deterministic Boat match
        // and must stay NO_MATCH - the resolver must NOT invent a mapping.
        $org = $this->organizationId();
        $this->addBoat($org, 'demo coral one');

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted(['boat_name' => 'PLAN C']),
            $org,
        );

        $this->assertNull($suggestion->boatId);
        $this->assertSame(InquirySuggestionResolver::NO_MATCH, $suggestion->boatResolution);
        $this->assertSame('PLAN C', $suggestion->boatNameSuggestion);
        $this->assertNull($suggestion->tripTemplateId);
        $this->assertNull($suggestion->slotOfferingId);
    }

    public function test_no_transfer_bilingual_marker_maps_to_pickup_false_and_no_hotel(): void
    {
        // #62 Issue 1 server truth: the sanitized bilingual no-transfer marker
        // (as extracted by the provider, matching the #51D fixture) is a
        // self-arrival marker, so pickup_required stays false and no hotel
        // name is fabricated from it.
        $org = $this->organizationId();

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted([
                'pickup_required' => false,
                'hotel_name' => '不需要酒店接送',
            ]),
            $org,
        );

        $this->assertFalse($suggestion->pickupRequired);
        $this->assertNull($suggestion->hotelName);
    }

    public function test_boatops_field_semantics_are_revalidated_before_suggesting(): void
    {
        $org = $this->organizationId();

        $suggestion = $this->resolver()->resolveForOrganization(
            $this->extracted([
                'service_date' => '2026-02-30',
                'party_size' => 0,
                'pickup_time' => '25:99',
                'contact_method' => 'PIGEON',
                'contact_value' => '+66 81 234 5678',
                'route_summary' => str_repeat('x', 2001),
            ]),
            $org,
        );

        $this->assertNull($suggestion->serviceDate);
        $this->assertNull($suggestion->partySize);
        $this->assertNull($suggestion->pickupTime);
        $this->assertNull($suggestion->contactMethod);
        $this->assertNull($suggestion->contactValue);
        $this->assertNull($suggestion->routeSummary);
    }

    public function test_resolution_never_mutates_operational_truth(): void
    {
        $org = $this->organizationId();
        $this->addBoat($org, 'Sea Star One');

        $countsBefore = [
            'inquiries' => DB::table('inquiries')->count(),
            'bookings' => DB::table('bookings')->count(),
            'trips' => DB::table('trips')->count(),
            'holds' => DB::table('holds')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'boats' => DB::table('boats')->count(),
        ];

        $this->resolver()->resolveForOrganization(
            $this->extracted([
                'boat_name' => 'Sea Star One',
                'service_date' => '2026-08-22',
                'party_size' => 5,
                'pickup_required' => false,
            ]),
            $org,
        );

        $this->assertSame($countsBefore, [
            'inquiries' => DB::table('inquiries')->count(),
            'bookings' => DB::table('bookings')->count(),
            'trips' => DB::table('trips')->count(),
            'holds' => DB::table('holds')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'boats' => DB::table('boats')->count(),
        ]);
    }
}
