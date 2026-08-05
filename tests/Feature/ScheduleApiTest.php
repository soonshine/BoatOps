<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Database\Seeders\SlotCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_schedule_scopes_and_cross_organization_ids_fail_closed(): void
    {
        $organizationA = $this->createOrganization('Fictional Schedule A');
        $organizationB = $this->createOrganization('Fictional Schedule B');
        [$noScopeToken] = $this->createApiClient($organizationA, []);
        [$readToken] = $this->createApiClient($organizationA, ['operations.schedule.read']);
        [$writeToken] = $this->createApiClient($organizationA, ['operations.schedule.write']);
        $boatA = $this->createBoat($organizationA, 'Fictional A Boat');
        $boatB = $this->createBoat($organizationB, 'Fictional B Boat');
        $this->seed(SlotCatalogSeeder::class);
        $slotA = $this->slotId($organizationA, 'AM_4H');
        $slotB = $this->slotId($organizationB, 'PM_4H');

        $this->getJson('/api/internal/v1/schedule/slot-offerings')
            ->assertUnauthorized()
            ->assertJson(['code' => 'AUTHORIZATION_FAILED']);
        $this->withToken($noScopeToken)->getJson('/api/internal/v1/schedule/slot-offerings')
            ->assertForbidden()
            ->assertJson(['code' => 'AUTHORIZATION_FAILED']);
        $this->withToken($readToken)->getJson('/api/internal/v1/schedule/slot-offerings')
            ->assertOk();
        $this->withToken($readToken)->postJson(
            '/api/internal/v1/schedule/slot-offerings',
            $this->reusablePayload('READ_SCOPE_CANNOT_WRITE'),
        )->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);
        $this->withToken($writeToken)->getJson('/api/internal/v1/schedule/slot-offerings')
            ->assertOk();

        $this->withToken($writeToken)->postJson(
            "/api/internal/v1/schedule/slot-offerings/{$slotB}:retire",
        )->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);
        $this->withToken($writeToken)->postJson('/api/internal/v1/schedule/compatibility-rules', [
            'first_slot_offering_id' => $slotA,
            'second_slot_offering_id' => $slotB,
            'policy' => 'ALLOW',
        ])->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);
        $this->withToken($writeToken)->getJson(
            '/api/internal/v1/schedule/calendar?from=2026-09-01&to=2026-09-07&boat_id='.$boatB,
        )->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);

        $this->assertDatabaseHas('boats', ['id' => $boatA, 'organization_id' => $organizationA]);
        $this->assertDatabaseHas('slot_offerings', ['id' => $slotB, 'status' => 'ACTIVE']);
    }

    public function test_five_presets_are_listed_with_durations_and_unverified_demo_time_marker(): void
    {
        $context = $this->context();
        $response = $this->withToken($context['token'])
            ->getJson('/api/internal/v1/schedule/slot-offerings')
            ->assertOk()
            ->assertJsonPath('organization_id', $context['organization_id'])
            ->assertJsonPath('business_timezone', 'Asia/Bangkok')
            ->assertJsonPath('operating_time_notice', '演示默认档期；真实起止时间和周转缓冲尚未冻结。');
        $presets = collect($response->json('slot_offerings'))->where('kind', 'PRESET')->keyBy('code');

        $this->assertSame(5, $presets->count());
        $this->assertSame(480, $presets['FULL_DAY_8H']['duration_minutes']);
        $this->assertSame(360, $presets['FULL_DAY_6H']['duration_minutes']);
        $this->assertSame(240, $presets['AM_4H']['duration_minutes']);
        $this->assertSame(240, $presets['PM_4H']['duration_minutes']);
        $this->assertSame(150, $presets['PM_2_5H']['duration_minutes']);
        $this->assertSame(
            ['DEMO_DEFAULT_UNVERIFIED'],
            $presets->pluck('operating_time_status')->unique()->values()->all(),
        );
        $this->assertSame(
            ['DEMO DEFAULT / UNVERIFIED OPERATING TIME'],
            $presets->pluck('operating_time_notice')->unique()->values()->all(),
        );
    }

    public function test_reusable_custom_offering_is_created_draft_scoped_and_audited(): void
    {
        $context = $this->context();
        $response = $this->withToken($context['token'])
            ->postJson('/api/internal/v1/schedule/slot-offerings', [
                ...$this->reusablePayload('CUSTOM_REUSABLE_API'),
                'applies_to_all_boats' => false,
                'boat_ids' => [$context['boat_id']],
            ])->assertCreated()
            ->assertJsonPath('code', 'SLOT_OFFERING_CREATED')
            ->assertJsonPath('slot_offering.kind', 'CUSTOM_TEMPLATE')
            ->assertJsonPath('slot_offering.status', 'DRAFT')
            ->assertJsonPath('slot_offering.boat_ids.0', $context['boat_id'])
            ->assertJsonPath('slot_offering.operating_time_status', 'UNVERIFIED');
        $id = $response->json('slot_offering.id');

        $this->assertDatabaseHas('slot_offerings', [
            'id' => $id,
            'organization_id' => $context['organization_id'],
            'code' => 'CUSTOM_REUSABLE_API',
            'status' => 'DRAFT',
        ]);
        $this->assertDatabaseHas('slot_offering_boats', [
            'slot_offering_id' => $id,
            'boat_id' => $context['boat_id'],
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $context['organization_id'],
            'actor_id' => $context['api_client_id'],
            'action' => 'slot.created',
            'object_id' => $id,
        ]);
    }

    public function test_date_specific_six_hour_instance_converts_bangkok_noon_to_utc_and_keeps_boat_scope(): void
    {
        $context = $this->context();
        $fullDaySix = $this->slotId($context['organization_id'], 'FULL_DAY_6H');
        $created = $this->withToken($context['token'])
            ->postJson('/api/internal/v1/schedule/custom-slot-instances', [
                'template_slot_offering_id' => $fullDaySix,
                'code' => 'FICTIONAL_6H_20260904_1200',
                'name' => 'Fictional Date-Specific 12-18 Validation',
                'status' => 'ACTIVE',
                'service_date' => '2026-09-04',
                'service_start_time' => '12:00',
                'service_end_time' => '18:00',
                'duration_minutes' => 360,
                'applies_to_all_boats' => false,
                'boat_ids' => [$context['boat_id']],
            ])->assertCreated()
            ->assertJsonPath('custom_slot_instance.kind', 'CUSTOM_INSTANCE')
            ->assertJsonPath('custom_slot_instance.template_slot_offering_id', $fullDaySix);
        $instanceId = $created->json('custom_slot_instance.id');
        $calendar = $this->withToken($context['token'])
            ->getJson('/api/internal/v1/schedule/calendar?from=2026-09-04&to=2026-09-04')
            ->assertOk();
        $firstBoatSlots = collect($calendar->json('boats.0.dates.0.slots'));
        $secondBoatSlots = collect($calendar->json('boats.1.dates.0.slots'));
        $slot = $firstBoatSlots->firstWhere('definition_id', $instanceId);

        $this->assertNotNull($slot);
        $this->assertSame('2026-09-04T05:00:00Z', $slot['service_start']);
        $this->assertSame('2026-09-04T11:00:00Z', $slot['service_end']);
        $this->assertSame(360, $slot['duration_minutes']);
        $this->assertNull($secondBoatSlots->firstWhere('definition_id', $instanceId));
        $this->assertDatabaseHas('slot_offering_boats', [
            'slot_offering_id' => $instanceId,
            'boat_id' => $context['boat_id'],
        ]);
    }

    public function test_draft_active_retired_transitions_are_terminal_and_audited(): void
    {
        $context = $this->context();
        $created = $this->withToken($context['token'])
            ->postJson('/api/internal/v1/schedule/slot-offerings', $this->reusablePayload('STATUS_TRANSITION_API'))
            ->assertCreated();
        $id = $created->json('slot_offering.id');

        $this->withToken($context['token'])
            ->postJson("/api/internal/v1/schedule/slot-offerings/{$id}:activate")
            ->assertOk()
            ->assertJsonPath('slot_offering.status', 'ACTIVE')
            ->assertJsonPath('code', 'SLOT_OFFERING_ACTIVATED');
        $this->withToken($context['token'])
            ->postJson("/api/internal/v1/schedule/slot-offerings/{$id}:retire")
            ->assertOk()
            ->assertJsonPath('slot_offering.status', 'RETIRED')
            ->assertJsonPath('code', 'SLOT_OFFERING_RETIRED');
        $this->withToken($context['token'])
            ->postJson("/api/internal/v1/schedule/slot-offerings/{$id}:activate")
            ->assertConflict()
            ->assertJson(['code' => 'INVALID_TRANSITION']);
        $this->withToken($context['token'])
            ->postJson('/api/internal/v1/schedule/custom-slot-instances', [
                'template_slot_offering_id' => $id,
                'code' => 'RETIRED_TEMPLATE_SELECTION',
                'service_date' => '2026-09-07',
            ])->assertConflict()->assertJson(['code' => 'SLOT_UNAVAILABLE']);

        $this->assertDatabaseMissing('slot_offerings', ['code' => 'RETIRED_TEMPLATE_SELECTION']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'slot.retired', 'object_id' => $id]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'slot.activated')->where('object_id', $id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'slot.retired')->where('object_id', $id)->count());
    }

    public function test_used_definition_is_retired_not_rewritten_and_replacement_keeps_history_snapshot(): void
    {
        $context = $this->context();
        $created = $this->withToken($context['token'])
            ->postJson('/api/internal/v1/schedule/slot-offerings', [
                ...$this->reusablePayload('USED_SLOT_V1'),
                'status' => 'ACTIVE',
            ])->assertCreated();
        $usedId = $created->json('slot_offering.id');
        $hold = $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => 'FICTIONAL-USED-SLOT-HOLD',
                'boat_id' => $context['boat_id'],
                'trip_template_id' => $context['trip_template_id'],
                'slot_offering_id' => $usedId,
                'service_date' => '2026-09-08',
                'expires_at' => '2026-08-01T00:30:00Z',
            ])->assertCreated();
        $holdId = $hold->json('hold_id');

        $this->withToken($context['token'])
            ->postJson("/api/internal/v1/schedule/slot-offerings/{$usedId}:retire")
            ->assertOk();
        $replacement = $this->withToken($context['token'])
            ->postJson('/api/internal/v1/schedule/slot-offerings', [
                ...$this->reusablePayload('USED_SLOT_V2'),
                'service_start_time' => '11:00',
                'service_end_time' => '14:00',
                'duration_minutes' => 180,
            ])->assertCreated();

        $this->assertNotSame($usedId, $replacement->json('slot_offering.id'));
        $this->assertDatabaseHas('slot_offerings', [
            'id' => $usedId,
            'code' => 'USED_SLOT_V1',
            'service_start_time' => '10:00:00',
            'service_end_time' => '12:00:00',
            'status' => 'RETIRED',
        ]);
        $this->assertDatabaseHas('holds', [
            'id' => $holdId,
            'slot_offering_id' => $usedId,
            'service_start' => '2026-09-08 03:00:00',
            'service_end' => '2026-09-08 05:00:00',
            'slot_code_snapshot' => 'USED_SLOT_V1',
            'slot_name_snapshot' => 'Fictional Reusable API Slot',
            'slot_duration_minutes_snapshot' => 120,
        ]);
        $calendar = $this->withToken($context['token'])
            ->getJson('/api/internal/v1/schedule/calendar?from=2026-09-08&to=2026-09-08')
            ->assertOk();
        $historical = collect($calendar->json('boats.0.dates.0.slots'))->firstWhere('definition_id', $usedId);
        $this->assertSame('RETIRED', $historical['definition_status']);
        $this->assertSame('HELD', $historical['status']);
        $this->assertSame('USED_SLOT_V1', $historical['code']);
        $this->assertSame('2026-09-08T03:00:00Z', $historical['service_start']);
    }

    public function test_allow_deny_upsert_uses_one_canonical_pair_and_audits_each_modification(): void
    {
        $context = $this->context();
        $am = $this->slotId($context['organization_id'], 'AM_4H');
        $pm = $this->slotId($context['organization_id'], 'PM_4H');
        $pairKey = min($am, $pm).':'.max($am, $pm);

        $allow = $this->withToken($context['token'])
            ->postJson('/api/internal/v1/schedule/compatibility-rules', [
                'first_slot_offering_id' => $pm,
                'second_slot_offering_id' => $am,
                'policy' => 'ALLOW',
                'reason' => 'Fictional allow validation',
            ])->assertOk()
            ->assertJsonPath('compatibility_rule.pair_key', $pairKey)
            ->assertJsonPath('compatibility_rule.policy', 'ALLOW');
        $ruleId = $allow->json('compatibility_rule.id');
        $this->withToken($context['token'])
            ->postJson('/api/internal/v1/schedule/compatibility-rules', [
                'first_slot_offering_id' => $am,
                'second_slot_offering_id' => $pm,
                'policy' => 'DENY',
                'reason' => 'Fictional deny validation',
            ])->assertOk()
            ->assertJsonPath('compatibility_rule.id', $ruleId)
            ->assertJsonPath('compatibility_rule.policy', 'DENY');

        $this->assertSame(1, DB::table('slot_compatibility_rules')
            ->where('organization_id', $context['organization_id'])
            ->where('pair_key', $pairKey)->count());
        $this->assertDatabaseHas('slot_compatibility_rules', [
            'id' => $ruleId,
            'first_slot_offering_id' => min($am, $pm),
            'second_slot_offering_id' => max($am, $pm),
            'policy' => 'DENY',
        ]);
        $this->assertSame(2, DB::table('audit_logs')
            ->where('object_type', 'slot_compatibility_rule')
            ->where('object_id', $ruleId)->count());
    }

    /**
     * @return array<string, int|string>
     */
    private function context(): array
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00 UTC');
        $organizationId = $this->createOrganization('Fictional Schedule '.Str::random(8));
        [$token, $apiClientId] = $this->createApiClient($organizationId, [
            'operations.schedule.write',
            'operations.write',
        ]);
        $boatId = $this->createBoat($organizationId, 'Fictional Plan A');
        $otherBoatId = $this->createBoat($organizationId, 'Fictional Plan B');
        $tripTemplateId = DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'FICTIONAL-SCHEDULE-TRIP',
            'name' => 'Fictional Schedule Trip',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seed(SlotCatalogSeeder::class);

        return compact(
            'organizationId',
            'token',
            'apiClientId',
            'boatId',
            'otherBoatId',
            'tripTemplateId',
        ) + [
            'organization_id' => $organizationId,
            'api_client_id' => $apiClientId,
            'boat_id' => $boatId,
            'other_boat_id' => $otherBoatId,
            'trip_template_id' => $tripTemplateId,
        ];
    }

    private function createOrganization(string $name): int
    {
        return DB::table('organizations')->insertGetId([
            'name' => $name,
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $scopes
     * @return array{string, int}
     */
    private function createApiClient(int $organizationId, array $scopes): array
    {
        $token = Str::random(48);
        $id = DB::table('api_clients')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Fictional Schedule Client '.Str::random(6),
            'token_hash' => hash('sha256', $token),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$token, $id];
    }

    private function createBoat(int $organizationId, string $name): int
    {
        return DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $name,
            'status' => 'ACTIVE',
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function slotId(int $organizationId, string $code): int
    {
        return (int) DB::table('slot_offerings')
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function reusablePayload(string $code): array
    {
        return [
            'code' => $code,
            'name' => 'Fictional Reusable API Slot',
            'service_start_time' => '10:00',
            'service_end_time' => '12:00',
            'duration_minutes' => 120,
            'additional_buffer_before_minutes' => 0,
            'additional_buffer_after_minutes' => 0,
            'valid_from' => '2026-09-01',
            'valid_until' => '2026-09-30',
            'applies_to_all_boats' => true,
        ];
    }
}
