<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripApplicationActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_api_and_operator_adapters_delegate_trip_mutations_to_shared_actions(): void
    {
        $api = (string) file_get_contents(app_path('Http/Controllers/Api/Internal/V1/OperationsCommandController.php'));
        $operator = (string) file_get_contents(app_path('Http/Controllers/Operator/TripWorkflowController.php'));
        foreach (['prepareTripAction', 'departTripAction', 'returnTripAction', 'completeTripAction'] as $action) {
            $this->assertStringContainsString('$this->'.$action.'->execute(', $api);
        }
        foreach (['prepareTrip', 'departTrip', 'returnTrip', 'completeTrip'] as $action) {
            $this->assertStringContainsString('$this->'.$action.'->execute(', $operator);
        }
        $this->assertStringNotContainsString('DB::table', $api);
        foreach ([$api, $operator] as $adapter) {
            $this->assertStringNotContainsString('DB::transaction', $adapter);
            $this->assertStringNotContainsString('->insert(', $adapter);
            $this->assertStringNotContainsString('->update(', $adapter);
            $this->assertStringNotContainsString('->delete(', $adapter);
        }
    }

    public function test_api_prepare_contract_replacement_idempotency_scope_and_side_effects_are_preserved(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T10:00:00Z'));
        $context = $this->context(['operations.write']);
        $trip = $this->trip($context, 'FICTIONAL-PREPARE-CONTRACT');
        $key = (string) Str::uuid();
        $payload = $this->preparePayload('FICTIONAL-CREW-ONE', 'SAFETY_ONE');
        $path = '/api/internal/v1/trips/'.$trip['trip_id'].':prepare';

        $first = $this->withToken($context['token'])->withHeader('Idempotency-Key', $key)
            ->postJson($path, $payload)->assertOk()->assertJson([
                'idempotency_key' => $key,
                'organization_id' => $context['organization_id'],
                'trip_id' => $trip['trip_id'],
                'status' => 'PLANNED',
                'code' => 'TRIP_PREPARED',
                'crew_count' => 1,
                'required_checklist_count' => 1,
                'incomplete_required_count' => 0,
                'business_timezone' => 'UTC',
            ])->assertJsonStructure(['request_id', 'occurred_at']);
        $replay = $this->withToken($context['token'])->withHeader('Idempotency-Key', $key)
            ->postJson($path, array_reverse($payload, true))->assertOk();
        $this->assertSame($first->json(), $replay->json());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'trip.prepared')->count());
        $this->assertSame(1, DB::table('idempotency_keys')->where('operation', 'prepareTrip:'.$trip['trip_id'])->count());
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'api_client',
            'actor_id' => $context['api_client_id'],
            'action' => 'trip.prepared',
        ]);

        $replacementKey = (string) Str::uuid();
        $replacement = $this->preparePayload('FICTIONAL-CREW-TWO', 'SAFETY_TWO');
        $this->withToken($context['token'])->withHeader('Idempotency-Key', $replacementKey)
            ->postJson($path, $replacement)->assertOk();
        $this->assertDatabaseCount('crew_members', 2);
        $this->assertDatabaseCount('crew_assignments', 1);
        $this->assertDatabaseCount('trip_checklists', 1);
        $this->assertDatabaseHas('crew_members', ['external_reference' => 'FICTIONAL-CREW-ONE']);
        $this->assertDatabaseHas('crew_members', ['external_reference' => 'FICTIONAL-CREW-TWO']);
        $conflict = $replacement;
        $conflict['checklist'][0]['label'] = 'Different fictional intent';
        $this->withToken($context['token'])->withHeader('Idempotency-Key', $replacementKey)
            ->postJson($path, $conflict)->assertConflict()->assertJson([
                'code' => 'IDEMPOTENCY_CONFLICT',
                'retryable' => false,
                'manual_action_required' => true,
            ]);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'trip.prepared')->count());
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));

        $this->withToken($context['token'])->withHeader('Idempotency-Key', 'bad')
            ->postJson($path, $payload)->assertUnprocessable()->assertJson(['code' => 'VALIDATION_FAILED']);
        $unscoped = $this->context();
        $unscopedTrip = $this->trip($unscoped, 'FICTIONAL-UNSCOPED-PREPARE');
        $this->withToken($unscoped['token'])->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/trips/'.$unscopedTrip['trip_id'].':prepare', $payload)
            ->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);
        $foreign = $this->context(['operations.write']);
        $foreignTrip = $this->trip($foreign, 'FICTIONAL-FOREIGN-PREPARE');
        $this->withToken($context['token'])->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/trips/'.$foreignTrip['trip_id'].':prepare', $payload)
            ->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);
        DB::table('trips')->where('id', $trip['trip_id'])->update(['status' => 'DEPARTED']);
        $this->withToken($context['token'])->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($path, $payload)->assertConflict()->assertJson(['code' => 'INVALID_TRANSITION']);
    }

    public function test_api_timestamp_boundaries_and_future_return_completion_guard_are_enforced(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10T10:00:00Z'));
        $context = $this->context(['operations.write']);
        $trip = $this->trip($context, 'FICTIONAL-TIME-SAFETY');
        $this->prepare($context, $trip['trip_id'], 'FICTIONAL-TIME-CREW');
        $base = '/api/internal/v1/trips/'.$trip['trip_id'];

        $this->command($context, $base.':depart', ['departed_at' => '2026-08-10T10:00:01Z'])
            ->assertUnprocessable()->assertJson(['code' => 'VALIDATION_FAILED']);
        $this->assertDatabaseHas('trips', ['id' => $trip['trip_id'], 'status' => 'PLANNED']);
        $this->command($context, $base.':depart', ['departed_at' => '2026-08-10T10:00:00Z'])
            ->assertOk()->assertJson(['status' => 'DEPARTED', 'departed_at' => '2026-08-10T10:00:00Z']);
        $this->command($context, $base.':return', ['returned_at' => '2026-08-10T09:59:59Z'])
            ->assertUnprocessable()->assertJson(['code' => 'VALIDATION_FAILED']);
        $this->command($context, $base.':return', ['returned_at' => '2026-08-10T10:00:01Z'])
            ->assertUnprocessable()->assertJson(['code' => 'VALIDATION_FAILED']);
        $this->command($context, $base.':return', ['returned_at' => '2026-08-10T10:00:00Z'])
            ->assertOk()->assertJson(['status' => 'RETURNED', 'returned_at' => '2026-08-10T10:00:00Z']);

        DB::table('trips')->where('id', $trip['trip_id'])->update(['actual_returned_at' => '2026-08-10 10:00:01']);
        $this->command($context, $base.':complete')
            ->assertUnprocessable()->assertJson(['code' => 'VALIDATION_FAILED']);
        $this->assertDatabaseHas('trips', ['id' => $trip['trip_id'], 'status' => 'RETURNED']);
        $this->assertDatabaseHas('bookings', ['id' => $trip['booking_id'], 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('allocations', ['id' => $trip['allocation_id'], 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $context['organization_id'])->value('inventory_revision'));

        DB::table('trips')->where('id', $trip['trip_id'])->update(['actual_returned_at' => '2026-08-10 10:00:00']);
        $completeKey = (string) Str::uuid();
        $complete = $this->withToken($context['token'])->withHeader('Idempotency-Key', $completeKey)->postJson($base.':complete')
            ->assertOk()->assertJson(['status' => 'COMPLETED', 'inventory_revision' => 1]);
        $replay = $this->withToken($context['token'])->withHeader('Idempotency-Key', $completeKey)->postJson($base.':complete')->assertOk();
        $this->assertSame($complete->json(), $replay->json());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'trip.completed.v1')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'trip.completed')->count());
        $completedAt = CarbonImmutable::parse((string) DB::table('trips')->where('id', $trip['trip_id'])->value('completed_at'), 'UTC');
        $returnedAt = CarbonImmutable::parse((string) DB::table('trips')->where('id', $trip['trip_id'])->value('actual_returned_at'), 'UTC');
        $this->assertTrue($completedAt->greaterThanOrEqualTo($returnedAt));

        $pastTrip = $this->trip($context, 'FICTIONAL-PAST-DEPARTURE');
        $this->prepare($context, $pastTrip['trip_id'], 'FICTIONAL-PAST-CREW');
        $this->command($context, '/api/internal/v1/trips/'.$pastTrip['trip_id'].':depart', [
            'departed_at' => '2026-08-10T09:59:59Z',
        ])->assertOk()->assertJson(['departed_at' => '2026-08-10T09:59:59Z']);
    }

    private function context(array $scopes = []): array
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Fictional Trip Actions '.Str::random(6),
            'timezone' => 'UTC',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $boatId = DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Fictional Action Boat',
            'status' => 'ACTIVE',
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $templateId = DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'FICTIONAL-'.Str::upper(Str::random(8)),
            'name' => 'Fictional Action Product',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = Str::random(48);
        $apiClientId = DB::table('api_clients')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Fictional Operations Client',
            'token_hash' => hash('sha256', $token),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'template_id' => $templateId,
            'token' => $token,
            'api_client_id' => $apiClientId,
        ];
    }

    private function trip(array $context, string $reference): array
    {
        $allocationId = DB::table('allocations')->insertGetId([
            'organization_id' => $context['organization_id'],
            'boat_id' => $context['boat_id'],
            'allocation_type' => 'BOOKING',
            'status' => 'ACTIVE',
            'business_start' => '2026-08-10 08:00:00',
            'business_end' => '2026-08-10 12:00:00',
            'occupied_start' => '2026-08-10 08:00:00',
            'occupied_end' => '2026-08-10 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bookingId = DB::table('bookings')->insertGetId([
            'organization_id' => $context['organization_id'],
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['template_id'],
            'external_reference' => $reference,
            'status' => 'CONFIRMED',
            'business_start' => '2026-08-10 08:00:00',
            'business_end' => '2026-08-10 12:00:00',
            'allocation_id' => $allocationId,
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('allocations')->where('id', $allocationId)->update(['booking_id' => $bookingId]);
        $tripId = DB::table('trips')->insertGetId([
            'organization_id' => $context['organization_id'],
            'booking_id' => $bookingId,
            'boat_id' => $context['boat_id'],
            'trip_template_id' => $context['template_id'],
            'status' => 'PLANNED',
            'planned_start' => '2026-08-10 08:00:00',
            'planned_end' => '2026-08-10 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'allocation_id' => $allocationId,
            'booking_id' => $bookingId,
            'trip_id' => $tripId,
        ];
    }

    private function prepare(array $context, int $tripId, string $crewReference): void
    {
        $this->withToken($context['token'])->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/trips/'.$tripId.':prepare', $this->preparePayload($crewReference, 'READY_'.Str::upper(Str::random(5))))
            ->assertOk();
    }

    private function preparePayload(string $crewReference, string $checklistCode): array
    {
        return [
            'crew' => [[
                'external_reference' => $crewReference,
                'display_name' => 'Fictional Action Captain',
                'role' => 'CAPTAIN',
                'duty' => 'CAPTAIN',
            ]],
            'checklist' => [[
                'code' => $checklistCode,
                'label' => 'Fictional action readiness check',
                'required' => true,
                'completed' => true,
            ]],
        ];
    }

    private function command(array $context, string $path, array $payload = [])
    {
        return $this->withToken($context['token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($path, $payload);
    }
}
