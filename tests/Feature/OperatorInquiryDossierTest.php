<?php

namespace Tests\Feature;

use App\Application\Holds\OrganizationHoldTtlPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        $this->get('/operator/inquiries/create')->assertOk()
            ->assertSee('Operational Dossier')
            ->assertSee('name="contact_name"', false)
            ->assertSee('name="selling_amount_minor"', false);

        $completeId = $this->createInquiry($context, 'FICTIONAL-DOSSIER-COMPLETE', $dossier);
        $this->assertDatabaseHas('inquiries', [
            'id' => $completeId,
            ...$dossier,
        ]);
        $this->get("/operator/inquiries/{$completeId}")->assertOk()
            ->assertSee('Operational Dossier')
            ->assertSee($dossier['contact_name'])
            ->assertSee($dossier['contact_value'])
            ->assertSee($dossier['meeting_point'])
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
        $this->assertNull($partial->boat_id);
        $this->assertNull($partial->service_date);
        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('allocations', 0);
    }

    public function test_validation_enforces_pairing_ranges_currency_amount_and_max_lengths(): void
    {
        $context = $this->context();
        $this->actingAs($context['user']);
        $cases = [
            [['contact_method' => 'PHONE'], ['contact_value']],
            [['contact_value' => 'fictional-contact'], ['contact_method']],
            [['contact_method' => 'SMS', 'contact_value' => 'fictional-contact'], ['contact_method']],
            [['party_size' => 0], ['party_size']],
            [['party_size' => 1000], ['party_size']],
            [['party_size' => '1.5'], ['party_size']],
            [['selling_currency' => 'THB'], ['selling_amount_minor']],
            [['selling_amount_minor' => 0], ['selling_currency']],
            [['selling_currency' => 'thb', 'selling_amount_minor' => 0], ['selling_currency']],
            [['selling_currency' => 'THB', 'selling_amount_minor' => -1], ['selling_amount_minor']],
            [['selling_currency' => 'THB', 'selling_amount_minor' => '1.5'], ['selling_amount_minor']],
            [['contact_name' => str_repeat('N', 256)], ['contact_name']],
            [['contact_method' => 'PHONE', 'contact_value' => str_repeat('V', 256)], ['contact_value']],
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

        $early = $this->dossier('EARLY');
        $this->updateDossier($allowedId, $early);
        $this->assertDatabaseHas('inquiries', ['id' => $allowedId, ...$early]);

        $this->configureHoldPolicy($allowed['organization_id']);
        $this->post("/operator/inquiries/{$allowedId}/hold", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303);
        $withHold = $this->dossier('HOLD');
        $this->updateDossier($allowedId, $withHold);
        $this->assertDatabaseHas('inquiries', ['id' => $allowedId, ...$withHold]);

        $holdId = (int) DB::table('inquiries')->where('id', $allowedId)->value('hold_id');
        $this->post("/operator/inquiries/{$allowedId}/holds/{$holdId}/confirm", ['idempotency_key' => (string) Str::uuid()])->assertStatus(303);
        $confirmed = $this->dossier('CONFIRMED');
        $this->updateDossier($allowedId, $confirmed);
        $this->assertDatabaseHas('inquiries', ['id' => $allowedId, ...$confirmed]);
        $this->assertDatabaseHas('bookings', ['hold_id' => $holdId, 'status' => 'CONFIRMED']);

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

        $this->assertDatabaseHas('inquiries', ['id' => $inquiryId, ...$payload]);
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
        $created['service_notes'] = 'Fictional Private Service Notes Create';
        $created['internal_notes'] = 'Fictional Private Internal Notes Create';
        $this->actingAs($context['user']);
        $inquiryId = $this->createInquiry($context, 'FICTIONAL-DOSSIER-PII', $created, 'Fictional private legacy note');
        $updated = $this->dossier('PII-UPDATE');
        $updated['contact_name'] = 'Fictional Private Name Update';
        $updated['contact_value'] = '+66-000-PII-UPDATE';
        $updated['meeting_point'] = 'Fictional Private Pier Update';
        $updated['service_location'] = 'Fictional Private Dropoff Update';
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
            $created['service_notes'], $created['internal_notes'], 'Fictional private legacy note',
            $updated['contact_name'], $updated['contact_value'], $updated['meeting_point'], $updated['service_location'],
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
        $this->assertArrayNotHasKey('contact_name', $createdAudit);
        $this->assertArrayNotHasKey('contact_value', $createdAudit);
        $this->assertContains('contact_name', $updatedAudit['changed_fields']);
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
            foreach ([$dossier['contact_name'], $dossier['contact_value'], $dossier['meeting_point'], $dossier['service_notes'], $dossier['internal_notes']] as $rawValue) {
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
            ...$dossier,
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
        ])->assertStatus(303);
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
            'meeting_point' => "Fictional Meeting Point {$suffix}",
            'service_location' => "Fictional Service Location {$suffix}",
            'sales_source' => 'FICTIONAL_DIRECT',
            'agent_reference' => "FICTIONAL-AGENT-{$suffix}",
            'service_notes' => "Fictional Service Notes {$suffix}",
            'internal_notes' => "Fictional Internal Notes {$suffix}",
            'selling_currency' => 'THB',
            'selling_amount_minor' => 250000,
        ];
    }

    /** @return list<string> */
    private function dossierFields(): array
    {
        return [
            'contact_name',
            'contact_method',
            'contact_value',
            'party_size',
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
