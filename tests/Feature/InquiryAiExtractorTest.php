<?php

namespace Tests\Feature;

use App\Application\InquiryAi\InquiryAiExtractor;
use App\Application\InquiryAi\InquiryExtractionException;
use App\Application\InquiryAi\InquiryExtractionSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class InquiryAiExtractorTest extends TestCase
{
    private const PII_MARKER = 'LINE_ID_PII_9f2b7c3d1a';

    private const PROVIDER_HOST = 'api.deepseek.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_inference.enabled' => true,
            'ai_inference.base_url' => 'https://'.self::PROVIDER_HOST,
            'ai_inference.model' => 'deepseek-chat',
            'ai_inference.timeout_seconds' => 5,
            'ai_inference.api_key' => 'fictional-key-for-tests',
        ]);
    }

    private function fakeProviderResponse(string $content): void
    {
        Http::fake([
            self::PROVIDER_HOST.'/*' => Http::response(json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            ], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']),
        ]);
    }

    public function test_successful_extraction_returns_normalized_first_slice_dto(): void
    {
        $this->fakeProviderResponse(json_encode([
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
        ], JSON_THROW_ON_ERROR));

        $dto = app(InquiryAiExtractor::class)->extract('fictional sanitized inquiry text '.self::PII_MARKER);

        $this->assertSame('2026-08-22', $dto->serviceDate);
        $this->assertSame('Sea Star One', $dto->boatName);
        $this->assertSame('Koh Tan + Koh Madsum', $dto->routeSummary);
        $this->assertSame('王三', $dto->contactName);
        $this->assertSame('WHATSAPP', $dto->contactMethod);
        $this->assertSame('+66 81 234 5678', $dto->contactValue);
        $this->assertSame(5, $dto->partySize);
        $this->assertTrue($dto->pickupRequired);
        $this->assertSame('Sands Resort', $dto->hotelName);
        $this->assertSame('302', $dto->roomNumber);
        $this->assertSame('08:30', $dto->pickupTime);
        $this->assertSame('Hotel lobby', $dto->meetingPoint);
        $this->assertSame('Koh Samui', $dto->serviceLocation);
        $this->assertSame(array_keys($dto->toArray()), InquiryExtractionSchema::ALLOWED_FIELD_NAMES);
    }

    public function test_secret_is_sent_to_provider_only_and_never_appears_in_the_dto(): void
    {
        $this->fakeProviderResponse(json_encode(['boat_name' => 'Sea Star'], JSON_THROW_ON_ERROR));

        $dto = app(InquiryAiExtractor::class)->extract('fictional inquiry text');

        $this->assertSame('Sea Star', $dto->boatName);
        $this->assertStringNotContainsString('fictional-key-for-tests', json_encode($dto->toArray()));

        Http::assertSent(fn ($request) => str_contains($request->url(), self::PROVIDER_HOST.'/chat/completions')
            && ($request->headers()['Authorization'][0] ?? '') === 'Bearer fictional-key-for-tests');
    }

    public function test_extra_provider_fields_are_discarded_deterministically(): void
    {
        $this->fakeProviderResponse(json_encode([
            'service_date' => '2026-08-22',
            'boat_name' => 'Boat B',
            'passport_number' => 'P1234567',
            'credit_card' => '4111111111111111',
            'internal_memo' => ['secret' => 'x'],
            'boat_id' => 42,
        ], JSON_THROW_ON_ERROR));

        $dto = app(InquiryAiExtractor::class)->extract('fictional inquiry text');

        $this->assertSame('Boat B', $dto->boatName);
        $this->assertSame(array_keys($dto->toArray()), InquiryExtractionSchema::ALLOWED_FIELD_NAMES);
        $this->assertNull($dto->partySize);
        $this->assertArrayNotHasKey('passport_number', $dto->toArray());
        $this->assertArrayNotHasKey('credit_card', $dto->toArray());
        $this->assertArrayNotHasKey('internal_memo', $dto->toArray());
    }

    public function test_invalid_values_normalize_to_null_instead_of_inventing_facts(): void
    {
        $this->fakeProviderResponse(json_encode([
            'service_date' => '2026-13-01',
            'pickup_time' => '24:00',
            'party_size' => 0,
            'contact_method' => 'pigeon',
            'pickup_required' => 'yes',
            'boat_name' => 999,
            'contact_value' => '   ',
        ], JSON_THROW_ON_ERROR));

        $dto = app(InquiryAiExtractor::class)->extract('fictional inquiry text');

        $this->assertNull($dto->serviceDate);
        $this->assertNull($dto->pickupTime);
        $this->assertNull($dto->partySize);
        $this->assertNull($dto->contactMethod);
        $this->assertNull($dto->pickupRequired);
        $this->assertNull($dto->boatName);
        $this->assertNull($dto->contactValue);
    }

    public function test_rate_limit_returns_typed_failure(): void
    {
        Http::fake([self::PROVIDER_HOST.'/*' => Http::response(
            ['error' => ['message' => 'rate limit reached']],
            429,
        )]);

        try {
            app(InquiryAiExtractor::class)->extract('fictional inquiry text');
            $this->fail('Expected InquiryExtractionException');
        } catch (InquiryExtractionException $e) {
            $this->assertSame(InquiryExtractionException::PROVIDER_RATE_LIMITED, $e->kind);
        }
    }

    public function test_timeout_returns_typed_failure(): void
    {
        Http::fake([
            self::PROVIDER_HOST.'/*' => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out after 5000 milliseconds',
            ),
        ]);

        try {
            app(InquiryAiExtractor::class)->extract('fictional inquiry text');
            $this->fail('Expected InquiryExtractionException');
        } catch (InquiryExtractionException $e) {
            $this->assertSame(InquiryExtractionException::PROVIDER_TIMEOUT, $e->kind);
        }
    }

    public function test_transport_error_returns_typed_failure(): void
    {
        Http::fake([
            self::PROVIDER_HOST.'/*' => fn () => throw new ConnectionException(
                'cURL error 7: Failed to connect to api.deepseek.com port 443',
            ),
        ]);

        try {
            app(InquiryAiExtractor::class)->extract('fictional inquiry text');
            $this->fail('Expected InquiryExtractionException');
        } catch (InquiryExtractionException $e) {
            $this->assertSame(InquiryExtractionException::PROVIDER_TRANSPORT_ERROR, $e->kind);
        }
    }

    public function test_provider_server_error_returns_typed_failure(): void
    {
        Http::fake([self::PROVIDER_HOST.'/*' => Http::response('server error', 500)]);

        try {
            app(InquiryAiExtractor::class)->extract('fictional inquiry text');
            $this->fail('Expected InquiryExtractionException');
        } catch (InquiryExtractionException $e) {
            $this->assertSame(InquiryExtractionException::PROVIDER_TRANSPORT_ERROR, $e->kind);
        }
    }

    public function test_invalid_json_body_returns_typed_failure_without_leaking_input(): void
    {
        Http::fake([self::PROVIDER_HOST.'/*' => Http::response('{oops not json', 200)]);

        try {
            app(InquiryAiExtractor::class)->extract('raw text with '.self::PII_MARKER);
            $this->fail('Expected InquiryExtractionException');
        } catch (InquiryExtractionException $e) {
            $this->assertSame(InquiryExtractionException::PROVIDER_INVALID_JSON, $e->kind);
            $this->assertStringNotContainsString(self::PII_MARKER, $e->getMessage());
        }
    }

    public function test_invalid_json_content_returns_typed_failure(): void
    {
        $this->fakeProviderResponse('this is definitely not json');

        try {
            app(InquiryAiExtractor::class)->extract('fictional inquiry text');
            $this->fail('Expected InquiryExtractionException');
        } catch (InquiryExtractionException $e) {
            $this->assertSame(InquiryExtractionException::PROVIDER_INVALID_JSON, $e->kind);
        }
    }

    public function test_schema_failure_when_provider_shape_is_wrong(): void
    {
        Http::fake([self::PROVIDER_HOST.'/*' => Http::response(
            json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR),
            200,
        )]);

        try {
            app(InquiryAiExtractor::class)->extract('fictional inquiry text');
            $this->fail('Expected InquiryExtractionException');
        } catch (InquiryExtractionException $e) {
            $this->assertSame(InquiryExtractionException::PROVIDER_SCHEMA_FAILURE, $e->kind);
        }
    }

    public function test_schema_failure_when_content_is_not_an_object(): void
    {
        $this->fakeProviderResponse(json_encode([1, 2, 3], JSON_THROW_ON_ERROR));

        try {
            app(InquiryAiExtractor::class)->extract('fictional inquiry text');
            $this->fail('Expected InquiryExtractionException');
        } catch (InquiryExtractionException $e) {
            $this->assertSame(InquiryExtractionException::PROVIDER_SCHEMA_FAILURE, $e->kind);
        }
    }

    public function test_disabled_boundary_returns_typed_failure_without_http_call(): void
    {
        config(['ai_inference.enabled' => false]);
        Http::fake([self::PROVIDER_HOST.'/*' => Http::response(['data' => 'unexpected'], 200)]);

        try {
            app(InquiryAiExtractor::class)->extract('fictional inquiry text');
            $this->fail('Expected InquiryExtractionException');
        } catch (InquiryExtractionException $e) {
            $this->assertSame(InquiryExtractionException::AI_DISABLED, $e->kind);
        }

        Http::assertNothingSent();
    }

    public function test_missing_api_key_returns_typed_failure_without_http_call(): void
    {
        config(['ai_inference.api_key' => '']);
        Http::fake([self::PROVIDER_HOST.'/*' => Http::response(['data' => 'unexpected'], 200)]);

        try {
            app(InquiryAiExtractor::class)->extract('fictional inquiry text');
            $this->fail('Expected InquiryExtractionException');
        } catch (InquiryExtractionException $e) {
            $this->assertSame(InquiryExtractionException::AI_DISABLED, $e->kind);
        }

        Http::assertNothingSent();
    }

    public function test_routine_logs_never_contain_raw_customer_text(): void
    {
        $captured = [];
        Log::listen(function (string $level, string $message, array $context) use (&$captured): void {
            $captured[] = $message.' '.json_encode($context, JSON_THROW_ON_ERROR);
        });

        $this->fakeProviderResponse(json_encode(['boat_name' => 'Boat'], JSON_THROW_ON_ERROR));
        $dto = app(InquiryAiExtractor::class)->extract('raw text with '.self::PII_MARKER.' and full prompt context');

        $this->assertSame('Boat', $dto->boatName);
        $this->assertSame([], $captured, 'the extraction boundary must not emit routine logs at all');
        foreach ($captured as $entry) {
            $this->assertStringNotContainsString(self::PII_MARKER, $entry);
        }
    }
}
