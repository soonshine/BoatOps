<?php

namespace Tests\Feature;

use App\Application\InquiryAi\InquiryExtractionException;
use App\Application\InquiryAi\InquirySuggestionResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorInquiryAiSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_HOST = 'api.deepseek.com';

    private const PII_MARKER = 'LINE_ID_PII_7d1f4c2a9b';

    private int $organizationId;

    private int $boatId;

    private User $operator;

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

        $this->organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Fictional AI Suggestion Operator',
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->boatId = $this->addBoat('Sea Star One');

        $this->operator = $this->operatorUser(['can_booking_workflow' => true]);
    }

    /** @param array<string, bool> $permissions */
    private function operatorUser(array $permissions): User
    {
        $user = User::create([
            'name' => 'Fictional Operator',
            'email' => Str::random(8).'@example.test',
            'password' => Hash::make('fictional-password'),
        ]);
        DB::table('operator_memberships')->insert([
            'organization_id' => $this->organizationId,
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'can_calendar_read' => $permissions['can_calendar_read'] ?? false,
            'can_booking_workflow' => $permissions['can_booking_workflow'] ?? false,
            'can_block' => $permissions['can_block'] ?? false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function addBoat(string $name, string $status = 'ACTIVE'): int
    {
        return DB::table('boats')->insertGetId([
            'organization_id' => $this->organizationId,
            'name' => $name,
            'status' => $status,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
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

    public function test_unauthenticated_request_redirects_to_operator_login(): void
    {
        $this->postJson('/operator/inquiries/ai-suggest', ['raw_text' => 'fictional text'])
            ->assertStatus(302)
            ->assertRedirect(route('operator.login'));
    }

    public function test_membership_without_booking_workflow_permission_returns_403(): void
    {
        $observer = $this->operatorUser(['can_calendar_read' => true]);

        $this->actingAs($observer)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => 'fictional text'])
            ->assertStatus(403);
    }

    public function test_successful_suggestion_response_is_form_shaped_and_resolves_boat(): void
    {
        $this->fakeProviderResponse(json_encode([
            'service_date' => '2026-08-22',
            'boat_name' => '  Sea Star ONE  ',
            'route_summary' => 'Koh Tan + Koh Madsum',
            'contact_name' => '王三',
            'contact_method' => 'WHATSAPP',
            'contact_value' => '+66 81 234 5678',
            'party_size' => 4,
            'pickup_required' => true,
            'hotel_name' => 'Sands Resort',
            'room_number' => '302',
            'pickup_time' => '08:30',
            'meeting_point' => 'Hotel lobby',
            'service_location' => 'Koh Samui',
        ], JSON_THROW_ON_ERROR));

        $response = $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => 'fictional sanitized text '.self::PII_MARKER])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'suggestion' => [
                    'service_date' => '2026-08-22',
                    'boat_id' => $this->boatId,
                    'boat_resolution' => InquirySuggestionResolver::RESOLVED,
                    'route_summary' => 'Koh Tan + Koh Madsum',
                    'contact_name' => '王三',
                    'contact_method' => 'WHATSAPP',
                    'contact_value' => '+66 81 234 5678',
                    'party_size' => 4,
                    'pickup_required' => true,
                    'hotel_name' => 'Sands Resort',
                    'room_number' => '302',
                    'pickup_time' => '08:30',
                    'meeting_point' => 'Hotel lobby',
                    'service_location' => 'Koh Samui',
                ],
            ]);

        $this->assertStringNotContainsString(self::PII_MARKER, $response->getContent(), 'the response must not echo raw pasted text');
        Http::assertSent(fn ($request) => $request->url() === 'https://'.self::PROVIDER_HOST.'/chat/completions');
    }

    public function test_ambiguous_boat_name_never_suggests_boat_id(): void
    {
        $this->addBoat('Sea Star One');
        $this->fakeProviderResponse(json_encode(['boat_name' => 'sea star one'], JSON_THROW_ON_ERROR));

        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => 'fictional ambiguous boat text'])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'suggestion' => [
                    'boat_id' => null,
                    'boat_resolution' => InquirySuggestionResolver::AMBIGUOUS,
                    'boat_name_suggestion' => 'sea star one',
                ],
            ]);
    }

    public function test_ai_disabled_returns_clear_manual_fallback_without_provider_call(): void
    {
        config(['ai_inference.enabled' => false]);
        Http::fake();

        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => 'fictional text'])
            ->assertOk()
            ->assertJson([
                'ok' => false,
                'code' => InquiryExtractionException::AI_DISABLED,
            ])
            ->assertJsonPath('message', fn ($message) => is_string($message) && str_contains($message, '直接手工填写'));

        Http::assertNothingSent();
    }

    public function test_provider_rate_limit_returns_manual_fallback(): void
    {
        Http::fake([self::PROVIDER_HOST.'/*' => Http::response(['error' => ['message' => 'rate limit']], 429)]);

        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => 'fictional text'])
            ->assertOk()
            ->assertJson([
                'ok' => false,
                'code' => InquiryExtractionException::PROVIDER_RATE_LIMITED,
            ]);
    }

    public function test_malformed_provider_content_returns_manual_fallback(): void
    {
        $this->fakeProviderResponse('definitely not json');

        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => 'fictional text'])
            ->assertOk()
            ->assertJson([
                'ok' => false,
                'code' => InquiryExtractionException::PROVIDER_INVALID_JSON,
            ]);
    }

    public function test_missing_or_oversized_raw_text_is_rejected_without_provider_call(): void
    {
        Http::fake();

        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', [])
            ->assertOk()
            ->assertJson(['ok' => false, 'code' => 'VALIDATION_FAILED']);
        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => str_repeat('x', 10001)])
            ->assertOk()
            ->assertJson(['ok' => false, 'code' => 'VALIDATION_FAILED']);

        Http::assertNothingSent();
    }

    public function test_parse_performs_no_operational_write(): void
    {
        $this->fakeProviderResponse(json_encode([
            'boat_name' => 'Sea Star One',
            'party_size' => 4,
            'pickup_required' => false,
        ], JSON_THROW_ON_ERROR));

        $this->assertDatabaseCount('inquiries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('trips', 0);

        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => 'fictional text'])
            ->assertOk();

        $this->assertDatabaseCount('inquiries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('trips', 0);
    }

    public function test_create_page_exposes_ai_suggestion_controls(): void
    {
        $this->actingAs($this->operator)
            ->get('/operator/inquiries/create')
            ->assertOk()
            ->assertSee('AI 智能识别（建议）')
            ->assertSee('id="quick-paste-ai"', false)
            ->assertSee('/operator/inquiries/ai-suggest', false)
            ->assertSee('AI 识别仅为建议')
            ->assertSee('不会自动提交')
            ->assertSee('只有空字段会被填充', false);
    }

    public function test_create_page_pickup_required_defaults_to_genuine_unset_state(): void
    {
        // #62 Issue 1: the create form must render pickup_required with the
        // unset 待确认 option selected (value="") on a fresh page, never 需要,
        // so the AI suggestion JS can fill true->1 / false->0 into an empty
        // field while a non-empty operator selection is never overwritten.
        $html = $this->actingAs($this->operator)
            ->get('/operator/inquiries/create')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<select name="pickup_required">', $html);
        $this->assertMatchesRegularExpression(
            '/<option value=""\s+selected>待确认<\/option>/',
            $html,
            'the 待确认 (unset) option must be selected by default',
        );
        $this->assertStringNotContainsString(
            '<option value="1" selected>需要</option>',
            $html,
            '需要 must never be the default pickup state',
        );
    }
}
