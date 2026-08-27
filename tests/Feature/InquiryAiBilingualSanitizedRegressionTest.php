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

/**
 * #51D / AI-INQUIRY-PARSE-001 sanitized end-to-end regression.
 *
 * Reproduces the observed real-use bilingual Inquiry order semantics with
 * synthetic data ONLY (no real customer PII). The path exercised is the
 * merged production path:
 *
 *   POST /operator/inquiries/ai-suggest
 *     -> InquiryAiExtractor (#53 / 51A) + InquiryExtractionSchema
 *     -> ExtractedInquiry
 *     -> InquirySuggestionResolver (#54 / 51B)
 *     -> InquirySuggestion JSON (#55 / 51C)
 *
 * The external provider HTTP call is stubbed via Http::fake for determinism;
 * no network request is made and nothing here is written to the operational
 * database.
 */
final class InquiryAiBilingualSanitizedRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_HOST = 'api.deepseek.com';

    /**
     * Sanitized bilingual reproduction of the observed real-use order. Every
     * value is synthetic: boat name, passenger count, fishing route, service
     * date, explicit no-transfer/self-arrival markers, fictional contact.
     */
    private const RAW_ORDER = <<<'TXT'
        2026-08-30 PLAN C fishing trip 6 people Koh Tao 海域海钓 no transfer 不需要酒店接送
        REG-SANITIZED-51D-NO-ECHO Wang Xiaoming 王小明 +66 99 111 0000
        TXT;

    /**
     * Operational tables the parse path must never touch.
     *
     * @var list<string>
     */
    private const OPERATIONAL_TABLES = [
        'inquiries',
        'holds',
        'bookings',
        'trips',
        'blocks',
        'allocations',
        'crew_assignments',
        'trip_checklists',
        'audit_logs',
        'idempotency_keys',
        'outbox_events',
        'rate_snapshots',
        'cash_postings',
        'stock_balances',
        'fuel_logs',
        'expenses',
        'stock_movements',
        'finance_reversals',
        'boats',
    ];

    private int $organizationId;

    private int $planCBoatId;

    private int $planCPlusBoatId;

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
            'name' => 'Fictional Sanitized Regression Operator',
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Prefix-sharing sibling: deterministic exact-normalized resolution
        // must never collapse 'PLAN C' onto 'PLAN C PLUS' (or vice versa).
        $this->planCBoatId = $this->addBoat('PLAN C');
        $this->planCPlusBoatId = $this->addBoat('PLAN C PLUS');

        $this->operator = $this->operatorUser(['can_booking_workflow' => true]);
    }

    /** @param array<string, bool> $permissions */
    private function operatorUser(array $permissions): User
    {
        $user = User::create([
            'name' => 'Fictional Sanitized Operator',
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

    /**
     * Simulate a deterministic provider extraction result for the sanitized
     * bilingual order. The provider itself is never called with real data;
     * this is the exact merge-path answer the fake endpoint returns.
     */
    private function fakeProviderResponse(string $content): void
    {
        Http::fake([
            self::PROVIDER_HOST.'/*' => Http::response(json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            ], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']),
        ]);
    }

    private function fakeProviderFailure(int $status): void
    {
        Http::fake([
            self::PROVIDER_HOST.'/*' => Http::response(['error' => ['message' => 'fictional provider error']], $status),
        ]);
    }

    /** @return array<string, int> */
    private function operationalCounts(): array
    {
        $counts = [];
        foreach (self::OPERATIONAL_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    public function test_bilingual_sanitized_fishing_inquiry_produces_full_suggestion(): void
    {
        $this->fakeProviderResponse(json_encode([
            'service_date' => '2026-08-30',
            'boat_name' => 'PLAN C',
            'route_summary' => 'Koh Tao 海域海钓',
            'contact_name' => '王小明',
            'contact_method' => 'PHONE',
            'contact_value' => '+66 99 111 0000',
            'party_size' => 6,
            'pickup_required' => false,
            'hotel_name' => '不需要酒店接送',
            'room_number' => null,
            'pickup_time' => null,
            'meeting_point' => null,
            'service_location' => null,
        ], JSON_THROW_ON_ERROR));

        $response = $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => self::RAW_ORDER])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'suggestion' => [
                    'service_date' => '2026-08-30',
                    'boat_id' => $this->planCBoatId,
                    'boat_resolution' => InquirySuggestionResolver::RESOLVED,
                    'boat_name_suggestion' => 'PLAN C',
                    'route_summary' => 'Koh Tao 海域海钓',
                    'contact_name' => '王小明',
                    'contact_method' => 'PHONE',
                    'contact_value' => '+66 99 111 0000',
                    'party_size' => 6,
                    'pickup_required' => false,
                    'hotel_name' => null,
                    // Absent / unknown facts stay null: adult-child split,
                    // template, slot, departure time, price, captain/crew.
                    'adult_count' => null,
                    'child_count' => null,
                    'child_ages' => null,
                    'trip_template_id' => null,
                    'slot_offering_id' => null,
                    'departure_time' => null,
                    'captain_crew' => null,
                    'selling_currency' => null,
                    'selling_amount_minor' => null,
                    'meeting_point' => null,
                    'room_number' => null,
                    'pickup_time' => null,
                    'service_location' => null,
                    'sales_source' => null,
                    'agent_reference' => null,
                    'service_notes' => null,
                    'internal_notes' => null,
                ],
            ]);

        // The raw pasted text must never be echoed back into the response:
        // only allowlisted suggestion fields may appear, never the raw order.
        $this->assertStringNotContainsString('REG-SANITIZED-51D-NO-ECHO', $response->getContent());
        $this->assertStringNotContainsString('fishing trip', $response->getContent());

        // The provider round trip happened (stubbed) exactly once.
        Http::assertSent(fn ($request) => $request->url() === 'https://'.self::PROVIDER_HOST.'/chat/completions');
    }

    public function test_plan_c_resolution_ignores_prefix_sharing_sibling_boat(): void
    {
        // The sibling 'PLAN C PLUS' exists in the same organization; the
        // sanitized order names 'PLAN C' and must resolve to exactly the
        // PLAN C boat, never to the sibling.
        $this->fakeProviderResponse(json_encode([
            'boat_name' => 'PLAN C',
            'party_size' => 6,
            'pickup_required' => false,
            'hotel_name' => null,
        ], JSON_THROW_ON_ERROR));

        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => 'PLAN C 6 人 钓鱼'])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'suggestion' => [
                    'boat_id' => $this->planCBoatId,
                    'boat_resolution' => InquirySuggestionResolver::RESOLVED,
                    'boat_name_suggestion' => 'PLAN C',
                ],
            ]);
    }

    public function test_plan_c_plus_resolves_to_its_own_boat(): void
    {
        // The other direction: the prefix-sharing name 'PLAN C PLUS' must
        // resolve to its own boat id - normalized names never blur into one
        // another, and the resolver is exact only.
        $this->fakeProviderResponse(json_encode([
            'boat_name' => 'PLAN C PLUS',
            'party_size' => 6,
        ], JSON_THROW_ON_ERROR));

        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => 'PLAN C PLUS 6 人 钓鱼'])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'suggestion' => [
                    'boat_id' => $this->planCPlusBoatId,
                    'boat_resolution' => InquirySuggestionResolver::RESOLVED,
                    'boat_name_suggestion' => 'PLAN C PLUS',
                ],
            ]);
    }

    public function test_parse_path_performs_zero_operational_writes(): void
    {
        $this->fakeProviderResponse(json_encode([
            'service_date' => '2026-08-30',
            'boat_name' => 'PLAN C',
            'route_summary' => 'Koh Tao 海域海钓',
            'contact_name' => '王小明',
            'contact_method' => 'PHONE',
            'contact_value' => '+66 99 111 0000',
            'party_size' => 6,
            'pickup_required' => false,
            'hotel_name' => '不需要酒店接送',
        ], JSON_THROW_ON_ERROR));

        $before = $this->operationalCounts();

        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => self::RAW_ORDER])
            ->assertOk();

        $this->assertSame($before, $this->operationalCounts(), 'the AI suggest parse path must not write any operational row');
    }

    public function test_provider_rate_limit_keeps_manual_fallback_without_writes(): void
    {
        $this->fakeProviderFailure(429);

        $before = $this->operationalCounts();

        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => self::RAW_ORDER])
            ->assertOk()
            ->assertJson([
                'ok' => false,
                'code' => InquiryExtractionException::PROVIDER_RATE_LIMITED,
            ])
            ->assertJsonPath('message', fn ($message) => is_string($message) && str_contains($message, '手工填写'));

        $this->assertSame($before, $this->operationalCounts(), 'provider failure must not produce partial writes');
        $this->assertDatabaseCount('inquiries', 0);
    }

    public function test_provider_transport_error_keeps_manual_fallback_without_writes(): void
    {
        $this->fakeProviderFailure(500);

        $before = $this->operationalCounts();

        $this->actingAs($this->operator)
            ->postJson('/operator/inquiries/ai-suggest', ['raw_text' => self::RAW_ORDER])
            ->assertOk()
            ->assertJson([
                'ok' => false,
                'code' => InquiryExtractionException::PROVIDER_TRANSPORT_ERROR,
            ])
            ->assertJsonPath('message', fn ($message) => is_string($message) && str_contains($message, '手工填写'));

        $this->assertSame($before, $this->operationalCounts(), 'provider failure must not produce partial writes');
    }
}
