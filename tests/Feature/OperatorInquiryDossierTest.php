<?php

namespace Tests\Feature;

use App\Application\Holds\OrganizationHoldTtlPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorInquiryDossierTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_create_persists_complete_dossier_and_partial_inquiries_remain_allowed(): void
    {
        $context = $this->context();
        $dossier = $this->dossier('CREATE');
        $this->actingAs($context['user']);

        $this->assertTrue(Schema::hasColumns('inquiries', [
            'adult_count',
            'child_count',
            'child_ages',
            'hotel_name',
            'room_number',
            'pickup_required',
            'pickup_time',
            'route_summary',
        ]));
        $this->get('/operator/inquiries/create')->assertOk()
            ->assertSee('新建询价')
            ->assertSeeInOrder(['出航需求', '客人信息', '接送信息', '服务要求', '来源与内部资料'])
            ->assertSee('客人 / 联系人姓名')
            ->assertSee('销售金额')
            ->assertDontSee('Operational Dossier')
            ->assertSee('name="contact_name"', false)
            ->assertSee('name="adult_count"', false)
            ->assertSee('name="child_ages"', false)
            ->assertSee('name="pickup_required"', false)
            ->assertSee('name="route_summary"', false)
            ->assertSee('name="selling_amount"', false)
            ->assertDontSee('name="selling_amount_minor"', false)
            ->assertSee('08:00–12:00')
            ->assertSee('4 小时');

        $completeId = $this->createInquiry($context, 'FICTIONAL-DOSSIER-COMPLETE', $dossier);
        $this->assertDatabaseHas('inquiries', [
            'id' => $completeId,
            ...$this->storedDossier($dossier),
        ]);
        $this->get('/operator/inquiries')->assertOk()
            ->assertSee('询价列表')
            ->assertSee('新建询价')
            ->assertSee('询价中')
            ->assertSee('FICTIONAL-DOSSIER-COMPLETE');
        $this->get("/operator/inquiries/{$completeId}")->assertOk()
            ->assertSeeInOrder(['出航需求', '客人信息', '接送信息', '服务要求', '来源与内部资料'])
            ->assertSee('询价状态：询价中')
            ->assertSee('核心执行资料已记录')
            ->assertSee($dossier['contact_name'])
            ->assertSee($dossier['contact_value'])
            ->assertSee($dossier['meeting_point'])
            ->assertSee($dossier['route_summary'])
            ->assertSee('船只出发 / 服务时间')
            ->assertSee('08:00–12:00')
            ->assertSee('时长（来自所选服务时段）')
            ->assertSee('value="2500.00"', false)
            ->assertDontSee('最小货币单位')
            ->assertSee('name="idempotency_key"', false)
            ->assertSee(route('operator.inquiries.dossier.update', $completeId), false);

        $partialKey = (string) Str::uuid();
        $this->post('/operator/inquiries', [
            'idempotency_key' => $partialKey,
            'reference' => 'FICTIONAL-DOSSIER-PARTIAL',
        ])->assertStatus(303);
        $partial = DB::table('inquiries')->where('reference', 'FICTIONAL-DOSSIER-PARTIAL')->sole();
        foreach ($this->dossierFields() as $field) {
            $this->assertNull($partial->{$field}, "{$field} must remain nullable for an early inquiry.");
        }
        $this->get("/operator/inquiries/{$partial->id}")->assertOk()
            ->assertSee('执行资料待补充')
            ->assertSee('路线 / 目的地')
            ->assertSee('成人 / 儿童人数拆分')
            ->assertSee('是否需要接送')
            ->assertSee('房间号可在客人入住后补充')
            ->assertSee('不会改变现有创建预留或确认订单门槛');
        $this->assertNull($partial->boat_id);
        $this->assertNull($partial->service_date);
        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('allocations', 0);
    }

    public function test_partial_inquiry_execution_fields_can_be_completed_idempotently_before_hold_without_inventory_writes(): void
    {
        $context = $this->context();
        $this->actingAs($context['user']);
        $this->post('/operator/inquiries', [
            'idempotency_key' => (string) Str::uuid(),
            'reference' => 'FICTIONAL-EXECUTION-COMPLETE',
        ])->assertStatus(303);
        $inquiryId = (int) DB::table('inquiries')->where('reference', 'FICTIONAL-EXECUTION-COMPLETE')->value('id');
        $path = "/operator/inquiries/{$inquiryId}/execution";
        $payload = [
            'service_date' => '2026-09-12',
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['template_id'],
            'slot_offering_id' => $context['slot_id'],
        ];
        $key = (string) Str::uuid();
        $beforeInventory = $this->inventoryState($context['organization_id']);

        $this->post($path, ['idempotency_key' => $key, ...$payload])->assertStatus(303);
        $this->assertDatabaseHas('inquiries', ['id' => $inquiryId, ...$payload, 'hold_id' => null]);
        $this->assertSame($beforeInventory, $this->inventoryState($context['organization_id']));
        $this->assertDatabaseHas('audit_logs', ['action' => 'INQUIRY_EXECUTION_UPDATED', 'object_id' => $inquiryId]);
        $this->assertSame(1, DB::table('idempotency_keys')->where('operation', 'operator.inquiries.execution.update:'.$inquiryId)->count());

        $this->post($path, ['idempotency_key' => $key, ...$payload])->assertStatus(303);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'INQUIRY_EXECUTION_UPDATED')->where('object_id', $inquiryId)->count());
        $this->post($path, ['idempotency_key' => $key, ...$payload, 'service_date' => '2026-09-13'])->assertConflict();

        $this->configureHoldPolicy($context['organization_id']);
        $this->post("/operator/inquiries/{$inquiryId}/hold", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303);
        $holdId = (int) DB::table('inquiries')->where('id', $inquiryId)->value('hold_id');
        $this->assertGreaterThan(0, $holdId);
        $this->post($path, ['idempotency_key' => (string) Str::uuid(), ...$payload, 'service_date' => '2026-09-14'])->assertConflict();
        $this->assertDatabaseHas('inquiries', ['id' => $inquiryId, 'service_date' => '2026-09-12']);
    }

    public function test_validation_enforces_pairing_party_age_pickup_currency_and_max_lengths(): void
    {
        $context = $this->context();
        $this->actingAs($context['user']);
        $overflowAmount = (intdiv(PHP_INT_MAX, 100) + 1).'.00';
        $cases = [
            [['contact_method' => 'PHONE'], ['contact_value']],
            [['contact_value' => 'fictional-contact'], ['contact_method']],
            [['contact_method' => 'SMS', 'contact_value' => 'fictional-contact'], ['contact_method']],
            [['party_size' => 0], ['party_size']],
            [['party_size' => 1000], ['party_size']],
            [['party_size' => '1.5'], ['party_size']],
            [['adult_count' => -1], ['adult_count']],
            [['adult_count' => 1000], ['adult_count']],
            [['child_count' => -1], ['child_count']],
            [['party_size' => 3, 'adult_count' => 2, 'child_count' => 2], ['party_size']],
            [['child_count' => 1, 'child_ages' => ['4', '7']], ['child_ages']],
            [['child_ages' => ['-1']], ['child_ages.0']],
            [['child_ages' => ['4.5']], ['child_ages.0']],
            [['child_count' => 1, 'child_ages' => '4,7'], ['child_ages']],
            [['pickup_required' => '2'], ['pickup_required']],
            [['pickup_time' => '25:00'], ['pickup_time']],
            [['selling_currency' => 'THB'], ['selling_amount']],
            [['selling_amount' => '0'], ['selling_currency']],
            [['selling_currency' => 'thb', 'selling_amount' => '0'], ['selling_currency']],
            [['selling_currency' => 'JPY', 'selling_amount' => '100'], ['selling_currency']],
            [['selling_currency' => 'THB', 'selling_amount' => '-1'], ['selling_amount']],
            [['selling_currency' => 'THB', 'selling_amount' => '1.001'], ['selling_amount']],
            [['selling_currency' => 'THB', 'selling_amount' => '1e2'], ['selling_amount']],
            [['selling_currency' => 'THB', 'selling_amount' => '1,00'], ['selling_amount']],
            [['selling_currency' => 'THB', 'selling_amount' => $overflowAmount], ['selling_amount']],
            [['contact_name' => str_repeat('N', 256)], ['contact_name']],
            [['contact_method' => 'PHONE', 'contact_value' => str_repeat('V', 256)], ['contact_value']],
            [['hotel_name' => str_repeat('H', 256)], ['hotel_name']],
            [['room_number' => str_repeat('R', 256)], ['room_number']],
            [['route_summary' => str_repeat('T', 2001)], ['route_summary']],
            [['meeting_point' => str_repeat('M', 2001)], ['meeting_point']],
            [['service_location' => str_repeat('L', 2001)], ['service_location']],
            [['sales_source' => str_repeat('S', 256)], ['sales_source']],
            [['agent_reference' => str_repeat('A', 256)], ['agent_reference']],
            [['service_notes' => str_repeat('C', 5001)], ['service_notes']],
            [['internal_notes' => str_repeat('I', 5001)], ['internal_notes']],
        ];

        foreach ($cases as $index => [$invalid, $errors]) {
            $this->post('/operator/inquiries', [
                'idempotency_key' => (string) Str::uuid(),
                'reference' => 'FICTIONAL-VALIDATION-'.$index,
                ...$invalid,
            ])->assertSessionHasErrors($errors);
        }

        $this->assertDatabaseCount('inquiries', 0);
    }

    public function test_party_breakdown_child_ages_and_pickup_tri_state_preserve_optional_early_capture(): void
    {
        $context = $this->context();
        $this->actingAs($context['user']);

        $structuredId = $this->createInquiry($context, 'FICTIONAL-STRUCTURED-AGES', [
            'party_size' => 4,
            'adult_count' => 1,
            'child_count' => 3,
            'child_ages' => "21,4\n7",
            'pickup_required' => '',
        ]);
        $this->assertDatabaseHas('inquiries', [
            'id' => $structuredId,
            'party_size' => 4,
            'adult_count' => 1,
            'child_count' => 3,
            'child_ages' => '[21,4,7]',
            'pickup_required' => null,
        ]);

        $missingAgesId = $this->createInquiry($context, 'FICTIONAL-MISSING-AGES', [
            'party_size' => 2,
            'adult_count' => 0,
            'child_count' => 2,
            'pickup_required' => '0',
        ]);
        $this->assertDatabaseHas('inquiries', [
            'id' => $missingAgesId,
            'child_ages' => null,
            'pickup_required' => false,
        ]);

        $this->updateDossier($missingAgesId, [
            'pickup_required' => '1',
        ]);
        $this->assertDatabaseHas('inquiries', ['id' => $missingAgesId, 'pickup_required' => true]);
        $this->updateDossier($missingAgesId, []);
        $this->assertDatabaseHas('inquiries', ['id' => $missingAgesId, 'pickup_required' => null]);
    }

    public function test_decimal_selling_amount_converts_at_the_form_boundary_and_displays_without_rounding(): void
    {
        $context = $this->context();
        $this->actingAs($context['user']);

        foreach ([
            '0' => [0, '0.00'],
            '0.01' => [1, '0.01'],
            '1234.56' => [123456, '1234.56'],
        ] as $decimal => [$minor, $display]) {
            $reference = 'FICTIONAL-AMOUNT-'.str_replace('.', '-', $decimal);
            $inquiryId = $this->createInquiry($context, $reference, [
                'selling_currency' => 'THB',
                'selling_amount' => $decimal,
            ]);
            $this->assertDatabaseHas('inquiries', [
                'id' => $inquiryId,
                'selling_currency' => 'THB',
                'selling_amount_minor' => $minor,
            ]);
            $this->get("/operator/inquiries/{$inquiryId}")->assertOk()
                ->assertSee('value="'.$display.'"', false)
                ->assertDontSee('最小货币单位');
        }
    }

    public function test_dossier_is_scoped_permissioned_and_editable_before_and_after_confirmation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:00:00Z'));
        $allowed = $this->context();
        $foreign = $this->context();
        $denied = $this->context(false);
        $allowedId = $this->directInquiry($allowed, 'FICTIONAL-DOSSIER-LIFECYCLE');
        $foreignId = $this->directInquiry($foreign, 'FICTIONAL-DOSSIER-FOREIGN', $this->dossier('FOREIGN'));
        $deniedId = $this->directInquiry($denied, 'FICTIONAL-DOSSIER-DENIED');

        $this->actingAs($allowed['user']);
        $this->get("/operator/inquiries/{$foreignId}")->assertNotFound();
        $this->post("/operator/inquiries/{$foreignId}/dossier", [
            'idempotency_key' => (string) Str::uuid(),
            ...$this->dossier('CROSS-ORG'),
        ])->assertNotFound();
        $this->post("/operator/inquiries/{$foreignId}/execution", [
            'idempotency_key' => (string) Str::uuid(),
            'service_date' => '2026-09-11',
            'boat_id' => $allowed['boat_id'],
            'trip_template_id' => $allowed['template_id'],
            'slot_offering_id' => $allowed['slot_id'],
        ])->assertNotFound();

        $early = $this->dossier('EARLY');
        $this->updateDossier($allowedId, $early);
        $this->assertDatabaseHas('inquiries', ['id' => $allowedId, ...$this->storedDossier($early)]);

        $this->configureHoldPolicy($allowed['organization_id']);
        $this->post("/operator/inquiries/{$allowedId}/hold", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303);
        $withHold = $this->dossier('HOLD');
        $this->updateDossier($allowedId, $withHold);
        $this->assertDatabaseHas('inquiries', ['id' => $allowedId, ...$this->storedDossier($withHold)]);

        $holdId = (int) DB::table('inquiries')->where('id', $allowedId)->value('hold_id');
        $this->assertDatabaseHas('holds', [
            'id' => $holdId,
            'organization_id' => $allowed['organization_id'],
            'external_reference' => 'FICTIONAL-DOSSIER-LIFECYCLE',
            'status' => 'ACTIVE',
        ]);
        $this->post("/operator/inquiries/{$allowedId}/holds/{$holdId}/confirm", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303);
        $confirmed = $this->dossier('CONFIRMED');
        $this->updateDossier($allowedId, $confirmed);
        $this->assertDatabaseHas('inquiries', ['id' => $allowedId, ...$this->storedDossier($confirmed)]);
        $this->assertDatabaseHas('bookings', ['hold_id' => $holdId, 'status' => 'CONFIRMED']);
        $bookingId = (int) DB::table('bookings')->where('hold_id', $holdId)->value('id');
        $this->assertDatabaseHas('trips', [
            'organization_id' => $allowed['organization_id'],
            'booking_id' => $bookingId,
            'status' => 'PLANNED',
        ]);

        $this->actingAs($denied['user']);
        $this->post("/operator/inquiries/{$deniedId}/dossier", [
            'idempotency_key' => (string) Str::uuid(),
            ...$this->dossier('DENIED'),
        ])->assertForbidden();
    }

    public function test_dossier_update_replays_conflicts_and_preserves_inventory_and_lifecycle(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:00:00Z'));
        $context = $this->context();
        $inquiryId = $this->confirmedInquiry($context, 'FICTIONAL-DOSSIER-INVARIANTS');
        $operation = 'operator.inquiries.dossier.update:'.$inquiryId;
        $payload = $this->dossier('INVARIANT');
        $key = (string) Str::uuid();
        $path = "/operator/inquiries/{$inquiryId}/dossier";
        $before = $this->inventoryState($context['organization_id']);
        $auditCount = DB::table('audit_logs')->where('action', 'INQUIRY_DOSSIER_UPDATED')->count();

        $this->actingAs($context['user'])->withHeader('Idempotency-Key', $key)
            ->post($path, $payload)->assertStatus(303);
        $this->post($path, ['idempotency_key' => $key, ...array_reverse($payload, true)])->assertStatus(303);
        $this->post($path, [
            'idempotency_key' => $key,
            ...$payload,
            'contact_name' => 'Fictional Changed Conflict Name',
        ])->assertConflict();

        $this->assertDatabaseHas('inquiries', ['id' => $inquiryId, ...$this->storedDossier($payload)]);
        $this->assertDatabaseHas('idempotency_keys', [
            'organization_id' => $context['organization_id'],
            'operation' => $operation,
            'idempotency_key' => $key,
            'response_status' => 303,
        ]);
        $this->assertSame(1, DB::table('idempotency_keys')->where('operation', $operation)->count());
        $this->assertSame($auditCount + 1, DB::table('audit_logs')->where('action', 'INQUIRY_DOSSIER_UPDATED')->count());
        $this->assertSame($before, $this->inventoryState($context['organization_id']));
        $this->assertDatabaseCount('rate_snapshots', 0);
    }

    public function test_create_and_update_audits_and_inventory_events_exclude_raw_pii(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T00:00:00Z'));
        $context = $this->context();
        $created = $this->dossier('PII-CREATE');
        $created['contact_name'] = 'Fictional Private Name Create';
        $created['contact_value'] = 'fictional-private-create@example.test';
        $created['meeting_point'] = 'Fictional Private Pier Create';
        $created['service_location'] = 'Fictional Private Dropoff Create';
        $created['hotel_name'] = 'Fictional Private Hotel Create';
        $created['room_number'] = 'Fictional Private Room Create';
        $created['route_summary'] = 'Fictional Private Route Create';
        $created['pickup_time'] = '03:17';
        $created['child_ages'] = ['987654'];
        $created['service_notes'] = 'Fictional Private Service Notes Create';
        $created['internal_notes'] = 'Fictional Private Internal Notes Create';
        $this->actingAs($context['user']);
        $inquiryId = $this->createInquiry($context, 'FICTIONAL-DOSSIER-PII', $created, 'Fictional private legacy note');
        $updated = $this->dossier('PII-UPDATE');
        $updated['contact_name'] = 'Fictional Private Name Update';
        $updated['contact_value'] = '+66-000-PII-UPDATE';
        $updated['meeting_point'] = 'Fictional Private Pier Update';
        $updated['service_location'] = 'Fictional Private Dropoff Update';
        $updated['hotel_name'] = 'Fictional Private Hotel Update';
        $updated['room_number'] = 'Fictional Private Room Update';
        $updated['route_summary'] = 'Fictional Private Route Update';
        $updated['pickup_time'] = '04:19';
        $updated['child_ages'] = ['876543'];
        $updated['service_notes'] = 'Fictional Private Service Notes Update';
        $updated['internal_notes'] = 'Fictional Private Internal Notes Update';
        $this->updateDossier($inquiryId, $updated);
        $this->configureHoldPolicy($context['organization_id']);
        $this->post("/operator/inquiries/{$inquiryId}/hold", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303);
        $holdId = (int) DB::table('inquiries')->where('id', $inquiryId)->value('hold_id');
        $this->post("/operator/inquiries/{$inquiryId}/holds/{$holdId}/confirm", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303);

        $auditJson = json_encode(DB::table('audit_logs')->orderBy('id')->get(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $outboxJson = json_encode(DB::table('outbox_events')->orderBy('id')->get(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $idempotencyJson = json_encode(DB::table('idempotency_keys')->orderBy('id')->get(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ([
            $created['contact_name'], $created['contact_value'], $created['meeting_point'], $created['service_location'],
            $created['hotel_name'], $created['room_number'], $created['route_summary'], $created['pickup_time'], (string) $created['child_ages'][0],
            $created['service_notes'], $created['internal_notes'], 'Fictional private legacy note',
            $updated['contact_name'], $updated['contact_value'], $updated['meeting_point'], $updated['service_location'],
            $updated['hotel_name'], $updated['room_number'], $updated['route_summary'], $updated['pickup_time'], (string) $updated['child_ages'][0],
            $updated['service_notes'], $updated['internal_notes'],
        ] as $rawValue) {
            $this->assertStringNotContainsString($rawValue, $auditJson);
            $this->assertStringNotContainsString($rawValue, $outboxJson);
            $this->assertStringNotContainsString($rawValue, $idempotencyJson);
        }

        $createdAudit = json_decode((string) DB::table('audit_logs')->where('action', 'INQUIRY_CREATED')->value('after_values'), true, 512, JSON_THROW_ON_ERROR);
        $updatedAudit = json_decode((string) DB::table('audit_logs')->where('action', 'INQUIRY_DOSSIER_UPDATED')->value('after_values'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($createdAudit['contact_name_present']);
        $this->assertTrue($createdAudit['contact_value_present']);
        $this->assertSame('EMAIL', $createdAudit['contact_method']);
        $this->assertSame(1, $createdAudit['child_ages_count']);
        $this->assertTrue($createdAudit['hotel_name_present']);
        $this->assertTrue($createdAudit['room_number_present']);
        $this->assertTrue($createdAudit['pickup_time_present']);
        $this->assertTrue($createdAudit['route_summary_present']);
        $this->assertArrayNotHasKey('contact_name', $createdAudit);
        $this->assertArrayNotHasKey('contact_value', $createdAudit);
        $this->assertArrayNotHasKey('child_ages', $createdAudit);
        $this->assertArrayNotHasKey('hotel_name', $createdAudit);
        $this->assertArrayNotHasKey('room_number', $createdAudit);
        $this->assertArrayNotHasKey('pickup_time', $createdAudit);
        $this->assertArrayNotHasKey('route_summary', $createdAudit);
        $this->assertContains('contact_name', $updatedAudit['changed_fields']);
        $this->assertContains('hotel_name', $updatedAudit['changed_fields']);
        $this->assertContains('route_summary', $updatedAudit['changed_fields']);
        $this->assertContains('service_notes', $updatedAudit['changed_fields']);
    }

    public function test_schedule_and_inventory_provider_responses_exclude_dossier_fields_and_values(): void
    {
        $context = $this->context();
        $dossier = $this->dossier('NON-DISCLOSURE');
        $this->directInquiry($context, 'FICTIONAL-DOSSIER-NON-DISCLOSURE', $dossier);
        $token = 'fictional-dossier-api-token-'.Str::random(16);
        DB::table('api_clients')->insert([
            'organization_id' => $context['organization_id'],
            'name' => 'Fictional Dossier Non-Disclosure Client',
            'token_hash' => hash('sha256', $token),
            'scopes' => json_encode(['operations.schedule.read'], JSON_THROW_ON_ERROR),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $availability = $this->withToken($token)->postJson('/api/v1/availability:check', [
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['template_id'],
            'starts_at' => '2026-09-10T01:00:00Z',
            'ends_at' => '2026-09-10T02:00:00Z',
        ])->assertOk();
        $schedule = $this->withToken($token)
            ->getJson('/api/internal/v1/schedule/calendar?from=2026-09-10&to=2026-09-10')
            ->assertOk();

        foreach ([$availability->getContent(), $schedule->getContent()] as $responseBody) {
            foreach ($this->dossierFields() as $field) {
                $this->assertStringNotContainsString($field, $responseBody);
            }
            foreach ([
                $dossier['contact_name'],
                $dossier['contact_value'],
                $dossier['meeting_point'],
                $dossier['hotel_name'],
                $dossier['room_number'],
                $dossier['route_summary'],
                $dossier['service_notes'],
                $dossier['internal_notes'],
            ] as $rawValue) {
                $this->assertStringNotContainsString($rawValue, $responseBody);
            }
        }
    }

    /** @param array<string, mixed> $dossier */
    private function createInquiry(array $context, string $reference, array $dossier = [], ?string $notes = null): int
    {
        $this->actingAs($context['user'])->post('/operator/inquiries', [
            'idempotency_key' => (string) Str::uuid(),
            'reference' => $reference,
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['template_id'],
            'slot_offering_id' => $context['slot_id'],
            'service_date' => '2026-09-10',
            'notes' => $notes,
            ...$dossier,
        ])->assertStatus(303);

        return (int) DB::table('inquiries')->where('organization_id', $context['organization_id'])->where('reference', $reference)->value('id');
    }

    /** @param array<string, mixed> $dossier */
    private function directInquiry(array $context, string $reference, array $dossier = []): int
    {
        $nullDossier = array_fill_keys($this->dossierFields(), null);
        $storedDossier = $this->storedDossier($dossier);

        return DB::table('inquiries')->insertGetId([
            'organization_id' => $context['organization_id'],
            'reference' => $reference,
            'status' => 'INQUIRY',
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['template_id'],
            'slot_offering_id' => $context['slot_id'],
            'service_date' => '2026-09-10',
            'notes' => null,
            ...$nullDossier,
            ...$storedDossier,
            'created_by_user_id' => $context['user']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function confirmedInquiry(array $context, string $reference): int
    {
        $inquiryId = $this->directInquiry($context, $reference);
        $this->configureHoldPolicy($context['organization_id']);
        $this->actingAs($context['user'])->post("/operator/inquiries/{$inquiryId}/hold", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(303);
        $holdId = (int) DB::table('inquiries')->where('id', $inquiryId)->value('hold_id');
        $this->post("/operator/inquiries/{$inquiryId}/holds/{$holdId}/confirm", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(303);

        return $inquiryId;
    }

    /** @param array<string, mixed> $payload */
    private function updateDossier(int $inquiryId, array $payload): void
    {
        $this->post("/operator/inquiries/{$inquiryId}/dossier", [
            'idempotency_key' => (string) Str::uuid(),
            ...$payload,
        ])->assertStatus(303)->assertSessionHas('status', '运营资料已更新。');
    }

    private function configureHoldPolicy(int $organizationId): void
    {
        DB::table('organization_settings')->insertOrIgnore([
            'organization_id' => $organizationId,
            'key' => OrganizationHoldTtlPolicy::KEY,
            'value' => '30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function context(bool $bookingPermission = true): array
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Fictional Dossier Organization '.Str::random(8),
            'timezone' => 'UTC',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::create([
            'name' => 'Fictional Dossier Operator',
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('fictional-password'),
        ]);
        DB::table('operator_memberships')->insert([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'can_calendar_read' => true,
            'can_booking_workflow' => $bookingPermission,
            'can_block' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $boatId = DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Fictional Dossier Resource',
            'status' => 'ACTIVE',
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $templateId = DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'FICTIONAL-DOSSIER-'.Str::upper(Str::random(6)),
            'name' => 'Fictional Dossier Product',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $slotId = DB::table('slot_offerings')->insertGetId([
            'organization_id' => $organizationId,
            'kind' => 'PRESET',
            'code' => 'FICTIONAL_DOSSIER_'.Str::upper(Str::random(6)),
            'name' => 'Fictional Dossier Slot',
            'status' => 'ACTIVE',
            'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
            'service_start_time' => '08:00:00',
            'service_end_time' => '12:00:00',
            'duration_minutes' => 240,
            'additional_buffer_before_minutes' => 0,
            'additional_buffer_after_minutes' => 0,
            'applies_to_all_boats' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'organization_id' => $organizationId,
            'user' => $user,
            'boat_id' => $boatId,
            'template_id' => $templateId,
            'slot_id' => $slotId,
        ];
    }

    /** @return array<string, mixed> */
    private function dossier(string $suffix): array
    {
        return [
            'contact_name' => "Fictional Contact {$suffix}",
            'contact_method' => 'EMAIL',
            'contact_value' => 'fictional-'.strtolower($suffix).'@example.test',
            'party_size' => 7,
            'adult_count' => 5,
            'child_count' => 2,
            'child_ages' => ['6', '21'],
            'hotel_name' => "Fictional Hotel {$suffix}",
            'room_number' => "Fictional Room {$suffix}",
            'pickup_required' => '1',
            'pickup_time' => '07:15',
            'route_summary' => "Fictional Route {$suffix}",
            'meeting_point' => "Fictional Meeting Point {$suffix}",
            'service_location' => "Fictional Service Location {$suffix}",
            'sales_source' => 'FICTIONAL_DIRECT',
            'agent_reference' => "FICTIONAL-AGENT-{$suffix}",
            'service_notes' => "Fictional Service Notes {$suffix}",
            'internal_notes' => "Fictional Internal Notes {$suffix}",
            'selling_currency' => 'THB',
            'selling_amount' => '2500.00',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function storedDossier(array $input): array
    {
        $stored = $input;
        if (array_key_exists('child_ages', $stored) && $stored['child_ages'] !== null && $stored['child_ages'] !== '') {
            $ages = is_array($stored['child_ages'])
                ? $stored['child_ages']
                : (preg_split('/\R/u', (string) $stored['child_ages']) ?: []);
            $stored['child_ages'] = json_encode(array_map('intval', array_values(array_filter($ages, static fn (mixed $age): bool => $age !== ''))), JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('pickup_required', $stored)) {
            $stored['pickup_required'] = $stored['pickup_required'] === '' || $stored['pickup_required'] === null
                ? null
                : (bool) $stored['pickup_required'];
        }
        if (isset($stored['pickup_time'])) {
            $stored['pickup_time'] .= ':00';
        }
        if (array_key_exists('selling_amount', $stored)) {
            [$whole, $fraction] = array_pad(explode('.', (string) $stored['selling_amount'], 2), 2, '');
            $stored['selling_amount_minor'] = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
            unset($stored['selling_amount']);
        }

        return $stored;
    }

    /** @return list<string> */
    private function dossierFields(): array
    {
        return [
            'contact_name',
            'contact_method',
            'contact_value',
            'party_size',
            'adult_count',
            'child_count',
            'child_ages',
            'hotel_name',
            'room_number',
            'pickup_required',
            'pickup_time',
            'route_summary',
            'meeting_point',
            'service_location',
            'sales_source',
            'agent_reference',
            'service_notes',
            'internal_notes',
            'selling_currency',
            'selling_amount_minor',
        ];
    }

    private function inventoryState(int $organizationId): string
    {
        $state = [
            'inventory_revision' => DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'),
            'allocations' => DB::table('allocations')->where('organization_id', $organizationId)->orderBy('id')->get(),
            'holds' => DB::table('holds')->where('organization_id', $organizationId)->orderBy('id')->get(),
            'bookings' => DB::table('bookings')->where('organization_id', $organizationId)->orderBy('id')->get(),
            'trips' => DB::table('trips')->where('organization_id', $organizationId)->orderBy('id')->get(),
            'outbox_events' => DB::table('outbox_events')->where('organization_id', $organizationId)->orderBy('id')->get(),
        ];

        return json_encode($state, JSON_THROW_ON_ERROR);
    }
}
