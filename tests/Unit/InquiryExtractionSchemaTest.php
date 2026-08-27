<?php

namespace Tests\Unit;

use App\Application\InquiryAi\InquiryExtractionSchema;
use PHPUnit\Framework\TestCase;

class InquiryExtractionSchemaTest extends TestCase
{
    public function test_allowlist_is_exactly_the_issue_51_first_slice_fields(): void
    {
        $this->assertSame([
            'service_date',
            'boat_name',
            'route_summary',
            'contact_name',
            'contact_method',
            'contact_value',
            'party_size',
            'pickup_required',
            'hotel_name',
            'room_number',
            'pickup_time',
            'meeting_point',
            'service_location',
        ], InquiryExtractionSchema::ALLOWED_FIELD_NAMES);
    }

    public function test_missing_and_unknown_facts_normalize_to_null_without_extra_keys(): void
    {
        $result = InquiryExtractionSchema::normalize([
            'service_date' => '2026-08-22',
            'boat_name' => 'Sea Star',
            'passport_number' => 'P1234567',
            'internal_memo' => ['note' => 'x'],
        ]);

        $this->assertSame('2026-08-22', $result['service_date']);
        $this->assertSame('Sea Star', $result['boat_name']);
        $this->assertSame(null, $result['party_size']);
        $this->assertSame(null, $result['pickup_required']);
        $this->assertSame(null, $result['contact_method']);
        $this->assertSame(array_keys($result), InquiryExtractionSchema::ALLOWED_FIELD_NAMES);
        $this->assertArrayNotHasKey('passport_number', $result);
        $this->assertArrayNotHasKey('internal_memo', $result);
    }

    public function test_values_that_fail_their_rule_normalize_to_null(): void
    {
        $result = InquiryExtractionSchema::normalize([
            'service_date' => '2026-02-30',
            'pickup_time' => '25:99',
            'party_size' => 'many',
            'pickup_required' => 'yes',
            'contact_method' => 'telegram',
            'boat_name' => 123,
            'room_number' => '   ',
            'contact_name' => str_repeat('x', 256),
            'contact_value' => str_repeat('y', 256),
            'hotel_name' => [],
        ]);

        $this->assertNull($result['service_date']);
        $this->assertNull($result['pickup_time']);
        $this->assertNull($result['party_size']);
        $this->assertNull($result['pickup_required']);
        $this->assertNull($result['contact_method']);
        $this->assertNull($result['boat_name']);
        $this->assertNull($result['room_number']);
        $this->assertNull($result['contact_name']);
        $this->assertNull($result['contact_value']);
        $this->assertNull($result['hotel_name']);
    }

    public function test_valid_values_are_normalized_deterministically(): void
    {
        $result = InquiryExtractionSchema::normalize([
            'service_date' => '2026-08-22',
            'pickup_time' => '08:30',
            'party_size' => 5,
            'pickup_required' => true,
            'contact_method' => ' line ',
            'boat_name' => '  Sea Star  ',
            'route_summary' => 'Koh Tan + Koh Madsum',
            'contact_name' => '张三',
            'contact_value' => '+66 81 234 5678',
            'hotel_name' => 'Sands Resort',
            'room_number' => '302',
            'meeting_point' => 'Hotel lobby',
            'service_location' => 'Koh Samui',
        ]);

        $this->assertSame('2026-08-22', $result['service_date']);
        $this->assertSame('08:30', $result['pickup_time']);
        $this->assertSame(5, $result['party_size']);
        $this->assertTrue($result['pickup_required']);
        $this->assertSame('LINE', $result['contact_method']);
        $this->assertSame('Sea Star', $result['boat_name']);
        $this->assertSame('Koh Tan + Koh Madsum', $result['route_summary']);
        $this->assertSame('张三', $result['contact_name']);
        $this->assertSame('+66 81 234 5678', $result['contact_value']);
        $this->assertSame('Sands Resort', $result['hotel_name']);
        $this->assertSame('302', $result['room_number']);
        $this->assertSame('Hotel lobby', $result['meeting_point']);
        $this->assertSame('Koh Samui', $result['service_location']);
    }

    public function test_numeric_string_party_size_and_integer_bounds_follow_the_declared_rule(): void
    {
        $this->assertSame(12, InquiryExtractionSchema::normalize(['party_size' => '12'])['party_size']);
        $this->assertNull(InquiryExtractionSchema::normalize(['party_size' => '0'])['party_size']);
        $this->assertNull(InquiryExtractionSchema::normalize(['party_size' => '1000'])['party_size']);
        $this->assertNull(InquiryExtractionSchema::normalize(['party_size' => true])['party_size']);
        $this->assertNull(InquiryExtractionSchema::normalize(['party_size' => 1.5])['party_size']);
        $this->assertNull(InquiryExtractionSchema::normalize(['service_date' => '2026-8-2'])['service_date']);
        $this->assertNull(InquiryExtractionSchema::normalize(['service_date' => '2026-08-22T00:00:00Z'])['service_date']);
    }
}
