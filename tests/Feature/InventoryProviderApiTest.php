<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryProviderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_common_read_responses_include_trace_time_and_business_timezone(): void
    {
        $organizationId = $this->createOrganization('Fictional Trace Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);

        $this->withToken($token)->getJson('/api/v1/inventory/revision')
            ->assertOk()
            ->assertJson([
                'organization_id' => $organizationId,
                'inventory_revision' => 0,
                'business_timezone' => 'UTC',
            ])->assertJsonStructure(['request_id', 'occurred_at']);

        $this->withToken($token)->postJson('/api/v1/availability:check', [
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-01T10:00:00Z',
            'ends_at' => '2026-09-01T12:00:00Z',
        ])->assertOk()->assertJson([
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'available' => true,
            'business_timezone' => 'UTC',
        ])->assertJsonStructure(['request_id', 'occurred_at']);
    }

    public function test_api_errors_include_uuid_request_id(): void
    {
        $response = $this->getJson('/api/v1/inventory/revision')
            ->assertStatus(401)
            ->assertJsonStructure(['request_id']);

        $this->assertTrue(Str::isUuid($response->json('request_id')));
    }

    public function test_inventory_revision_requires_bearer_authentication(): void
    {
        $this->getJson('/api/v1/inventory/revision')
            ->assertStatus(401)
            ->assertJson([
                'code' => 'AUTHORIZATION_FAILED',
                'retryable' => false,
                'manual_action_required' => false,
                'message' => 'A valid bearer token is required.',
            ]);
    }

    public function test_inventory_revision_uses_authenticated_organization_and_ignores_submitted_organization(): void
    {
        $firstOrganization = $this->createOrganization('Fictional North Harbor', 7);
        $otherOrganization = $this->createOrganization('Fictional South Harbor', 91);
        $token = $this->createApiClient($firstOrganization);

        $this->withToken($token)
            ->getJson('/api/v1/inventory/revision?organization_id='.$otherOrganization)
            ->assertOk()
            ->assertJson([
                'organization_id' => $firstOrganization,
                'inventory_revision' => 7,
                'business_timezone' => 'UTC',
            ]);
    }

    public function test_half_open_boundary_is_available(): void
    {
        $organizationId = $this->createOrganization('Fictional Boundary Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $this->createAllocation(
            $organizationId,
            $boatId,
            '2026-09-01 10:00:00',
            '2026-09-01 12:00:00',
        );

        $this->withToken($token)->postJson('/api/v1/availability:check', [
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-01T12:00:00Z',
            'ends_at' => '2026-09-01T14:00:00Z',
        ])->assertOk()->assertJson([
            'organization_id' => $organizationId,
            'available' => true,
            'occupied_start' => '2026-09-01T12:00:00Z',
            'occupied_end' => '2026-09-01T14:00:00Z',
            'inventory_revision' => 0,
            'business_timezone' => 'UTC',
        ]);
    }

    public function test_overlapping_interval_is_blocked_with_stable_slot_error(): void
    {
        $organizationId = $this->createOrganization('Fictional Overlap Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $this->createAllocation(
            $organizationId,
            $boatId,
            '2026-09-01 10:00:00',
            '2026-09-01 12:00:00',
        );

        $this->withToken($token)->postJson('/api/v1/availability:check', [
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-01T11:59:59Z',
            'ends_at' => '2026-09-01T14:00:00Z',
        ])->assertOk()->assertJson([
            'available' => false,
            'code' => 'SLOT_UNAVAILABLE',
            'retryable' => false,
            'manual_action_required' => false,
            'message' => 'The requested slot is unavailable.',
        ]);
    }

    public function test_boat_turnaround_buffers_expand_the_occupied_interval(): void
    {
        $organizationId = $this->createOrganization('Fictional Buffered Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId, 30, 45);
        $templateId = $this->createTripTemplate($organizationId);

        $this->withToken($token)->postJson('/api/v1/availability:check', [
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-01T10:00:00Z',
            'ends_at' => '2026-09-01T12:00:00Z',
        ])->assertOk()->assertJson([
            'available' => true,
            'occupied_start' => '2026-09-01T09:30:00Z',
            'occupied_end' => '2026-09-01T12:45:00Z',
        ]);
    }

    public function test_invalid_request_uses_stable_validation_error_shape(): void
    {
        $organizationId = $this->createOrganization('Fictional Validation Charters');
        $token = $this->createApiClient($organizationId);

        $this->withToken($token)->postJson('/api/v1/availability:check', [])
            ->assertStatus(422)
            ->assertJson([
                'code' => 'VALIDATION_FAILED',
                'retryable' => false,
                'manual_action_required' => false,
                'message' => 'The request payload is invalid.',
            ]);
    }

    public function test_creating_hold_persists_buffered_allocation_with_explicit_expiry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Hold Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId, 15, 20);
        $templateId = $this->createTripTemplate($organizationId);
        $key = (string) Str::uuid();

        $response = $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/holds', [
                'external_reference' => 'FICTIONAL-HOLD-001',
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-10T10:00:00Z',
                'ends_at' => '2026-09-10T12:00:00Z',
                'expires_at' => '2026-08-01T00:20:00Z',
            ]);

        $response->assertCreated()->assertJson([
            'idempotency_key' => $key,
            'organization_id' => $organizationId,
            'external_reference' => 'FICTIONAL-HOLD-001',
            'status' => 'ACTIVE',
            'code' => 'HOLD_CREATED',
            'inventory_revision' => 1,
            'expires_at' => '2026-08-01T00:20:00Z',
            'business_timezone' => 'UTC',
        ])->assertJsonStructure(['request_id', 'hold_id', 'occurred_at']);

        $this->assertDatabaseHas('allocations', [
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'allocation_type' => 'HOLD',
            'status' => 'ACTIVE',
            'occupied_start' => '2026-09-10 09:45:00',
            'occupied_end' => '2026-09-10 12:20:00',
        ]);
        $this->assertDatabaseCount('holds', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_same_hold_command_repeated_ten_times_creates_one_business_record(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Idempotent Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $key = (string) Str::uuid();
        $payload = [
            'external_reference' => 'FICTIONAL-HOLD-IDEMPOTENT-001',
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-11T10:00:00Z',
            'ends_at' => '2026-09-11T12:00:00Z',
            'expires_at' => '2026-08-01T00:20:00Z',
        ];
        $holdIds = [];

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $response = $this->withToken($token)
                ->withHeader('Idempotency-Key', $key)
                ->postJson('/api/v1/holds', $payload)
                ->assertCreated()
                ->assertJson(['inventory_revision' => 1]);
            $holdIds[] = $response->json('hold_id');
        }

        $this->assertCount(1, array_unique($holdIds));
        $this->assertDatabaseCount('holds', 1);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertSame(1, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_reusing_idempotency_key_with_different_payload_is_rejected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Conflict Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $key = (string) Str::uuid();
        $payload = [
            'external_reference' => 'FICTIONAL-HOLD-CONFLICT-001',
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-12T10:00:00Z',
            'ends_at' => '2026-09-12T12:00:00Z',
            'expires_at' => '2026-08-01T00:20:00Z',
        ];

        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/holds', $payload)
            ->assertCreated();

        $payload['ends_at'] = '2026-09-12T13:00:00Z';
        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/holds', $payload)
            ->assertStatus(409)
            ->assertJson([
                'code' => 'IDEMPOTENCY_CONFLICT',
                'retryable' => false,
                'manual_action_required' => true,
                'message' => 'The idempotency key was used with another payload.',
            ]);

        $this->assertDatabaseCount('holds', 1);
        $this->assertDatabaseCount('allocations', 1);
    }

    public function test_releasing_hold_frees_allocation_and_advances_revision(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Release Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $externalReference = 'FICTIONAL-HOLD-RELEASE-001';
        $hold = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => $externalReference,
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-13T10:00:00Z',
                'ends_at' => '2026-09-13T12:00:00Z',
                'expires_at' => '2026-08-01T00:20:00Z',
            ])->assertCreated();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds/'.$hold->json('hold_id').':release', [
                'external_reference' => $externalReference,
            ])->assertOk()->assertJson([
                'organization_id' => $organizationId,
                'hold_id' => $hold->json('hold_id'),
                'external_reference' => $externalReference,
                'status' => 'RELEASED',
                'code' => 'HOLD_RELEASED',
                'inventory_revision' => 2,
            ]);

        $this->assertDatabaseHas('holds', ['id' => $hold->json('hold_id'), 'status' => 'RELEASED']);
        $this->assertDatabaseHas('allocations', ['hold_id' => $hold->json('hold_id'), 'status' => 'RELEASED']);
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertDatabaseCount('audit_logs', 2);

        $this->withToken($token)->postJson('/api/v1/availability:check', [
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-13T10:00:00Z',
            'ends_at' => '2026-09-13T12:00:00Z',
        ])->assertOk()->assertJson(['available' => true, 'inventory_revision' => 2]);
    }

    public function test_confirming_active_hold_creates_one_booking_and_trip(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Confirm Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $externalReference = 'FICTIONAL-BOOKING-CONFIRM-001';
        $hold = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => $externalReference,
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-14T10:00:00Z',
                'ends_at' => '2026-09-14T12:00:00Z',
                'expires_at' => '2026-08-01T00:20:00Z',
            ])->assertCreated();

        $storedExpiresAt = CarbonImmutable::parse(
            DB::table('holds')->where('id', $hold->json('hold_id'))->value('expires_at'),
        )->utc();
        $this->assertSame('2026-08-01T00:20:00Z', $storedExpiresAt->format('Y-m-d\\TH:i:s\\Z'));
        $this->assertTrue(
            $storedExpiresAt->greaterThan(now()->utc()),
            sprintf('stored expires_at=%s, now=%s', $storedExpiresAt->toIso8601String(), now()->utc()->toIso8601String()),
        );

        $confirmed = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings:confirm', [
                'hold_id' => $hold->json('hold_id'),
                'external_reference' => $externalReference,
                'rate_snapshot' => $this->rateSnapshot(),
            ]);
        $this->assertSame(201, $confirmed->getStatusCode(), $confirmed->getContent());
        $confirmed->assertJson([
            'organization_id' => $organizationId,
            'external_reference' => $externalReference,
            'status' => 'CONFIRMED',
            'code' => 'BOOKING_CONFIRMED',
            'inventory_revision' => 2,
        ])->assertJsonStructure(['request_id', 'booking_id', 'trip_id', 'occurred_at']);

        $this->assertDatabaseHas('holds', ['id' => $hold->json('hold_id'), 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('bookings', [
            'id' => $confirmed->json('booking_id'),
            'organization_id' => $organizationId,
            'status' => 'CONFIRMED',
        ]);
        $this->assertDatabaseHas('allocations', [
            'hold_id' => $hold->json('hold_id'),
            'booking_id' => $confirmed->json('booking_id'),
            'allocation_type' => 'BOOKING',
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('trips', [
            'id' => $confirmed->json('trip_id'),
            'booking_id' => $confirmed->json('booking_id'),
            'status' => 'PLANNED',
        ]);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('trips', 1);
        $this->assertDatabaseCount('outbox_events', 2);

        $outbox = DB::table('outbox_events')->where('event_type', 'booking.confirmed.v1')->value('payload');
        $this->assertNotNull($outbox);
        $this->assertStringNotContainsString('customer', strtolower($outbox));
        $this->assertStringNotContainsString('cost', strtolower($outbox));
        $this->assertStringNotContainsString('profit', strtolower($outbox));
    }

    public function test_confirming_booking_freezes_private_rate_snapshot_without_exporting_amounts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Rate Snapshot Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $externalReference = 'FICTIONAL-RATE-SNAPSHOT-001';
        $hold = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => $externalReference,
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-26T10:00:00Z',
                'ends_at' => '2026-09-26T12:00:00Z',
                'expires_at' => '2026-08-01T00:20:00Z',
            ])->assertCreated();

        $response = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings:confirm', [
                'hold_id' => $hold->json('hold_id'),
                'external_reference' => $externalReference,
                'rate_snapshot' => $this->rateSnapshot(),
            ])->assertCreated();

        $bookingId = $response->json('booking_id');
        $this->assertDatabaseHas('rate_snapshots', [
            'organization_id' => $organizationId,
            'booking_id' => $bookingId,
            'source_reference' => 'FICTIONAL-RATE-V1',
            'currency' => 'THB',
            'selling_amount_minor' => 125000,
            'tax_amount_minor' => 0,
            'commission_amount_minor' => 12500,
            'fx_base_currency' => 'CNY',
            'fx_quote_currency' => 'THB',
        ]);
        $this->assertNotNull(DB::table('bookings')->where('id', $bookingId)->value('rate_snapshot_id'));
        $this->assertStringNotContainsString('amount', strtolower($response->getContent()));
        $eventPayload = (string) DB::table('outbox_events')->where('event_type', 'booking.confirmed.v1')->value('payload');
        $this->assertStringNotContainsString('amount', strtolower($eventPayload));
        $this->assertStringNotContainsString('currency', strtolower($eventPayload));
        $this->assertStringNotContainsString('fx_', strtolower($eventPayload));
    }

    public function test_expired_rate_snapshot_requires_requote_without_consuming_active_hold(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Expired Rate Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $externalReference = 'FICTIONAL-EXPIRED-RATE-001';
        $hold = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => $externalReference,
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-27T10:00:00Z',
                'ends_at' => '2026-09-27T12:00:00Z',
                'expires_at' => '2026-08-01T03:00:00Z',
            ])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-08-01T02:00:00Z'));

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings:confirm', [
                'hold_id' => $hold->json('hold_id'),
                'external_reference' => $externalReference,
                'rate_snapshot' => $this->rateSnapshot(),
            ])->assertStatus(409)->assertJson([
                'code' => 'RATE_CHANGED',
                'retryable' => false,
                'manual_action_required' => true,
            ]);

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('rate_snapshots', 0);
        $this->assertDatabaseHas('holds', ['id' => $hold->json('hold_id'), 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('allocations', ['hold_id' => $hold->json('hold_id'), 'status' => 'ACTIVE']);
        $this->assertSame(1, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_expired_hold_cannot_be_confirmed_and_releases_inventory(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Expiry Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $externalReference = 'FICTIONAL-HOLD-EXPIRED-001';
        $hold = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => $externalReference,
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-15T10:00:00Z',
                'ends_at' => '2026-09-15T12:00:00Z',
                'expires_at' => '2026-08-01T00:05:00Z',
            ])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:06:00Z'));

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings:confirm', [
                'hold_id' => $hold->json('hold_id'),
                'external_reference' => $externalReference,
                'rate_snapshot' => $this->rateSnapshot(),
            ])->assertStatus(409)->assertJson([
                'code' => 'HOLD_EXPIRED',
                'retryable' => false,
                'manual_action_required' => true,
                'message' => 'The HOLD has expired.',
            ]);

        $this->assertDatabaseHas('holds', ['id' => $hold->json('hold_id'), 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('allocations', ['hold_id' => $hold->json('hold_id'), 'status' => 'EXPIRED']);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('trips', 0);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'hold.expired.v1', 'inventory_revision' => 2]);
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_expiry_command_releases_unconfirmed_holds_exactly_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Scheduled Expiry Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $hold = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => 'FICTIONAL-SCHEDULED-EXPIRY-001',
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-16T10:00:00Z',
                'ends_at' => '2026-09-16T12:00:00Z',
                'expires_at' => '2026-08-01T00:05:00Z',
            ])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:06:00Z'));

        $this->artisan('holds:expire')->assertSuccessful();
        $this->artisan('holds:expire')->assertSuccessful();

        $this->assertDatabaseHas('holds', ['id' => $hold->json('hold_id'), 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('allocations', ['hold_id' => $hold->json('hold_id'), 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_type' => 'hold',
            'aggregate_id' => $hold->json('hold_id'),
            'event_type' => 'hold.expired.v1',
            'inventory_revision' => 2,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hold.expired',
            'object_type' => 'hold',
            'object_id' => $hold->json('hold_id'),
        ]);
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'hold.expired.v1')->count());
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
    }

    public function test_cancelling_booking_releases_inventory_and_cancels_trip(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Cancel Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $externalReference = 'FICTIONAL-BOOKING-CANCEL-001';
        $hold = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => $externalReference,
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-16T10:00:00Z',
                'ends_at' => '2026-09-16T12:00:00Z',
                'expires_at' => '2026-08-01T00:20:00Z',
            ])->assertCreated();
        $booking = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings:confirm', [
                'hold_id' => $hold->json('hold_id'),
                'external_reference' => $externalReference,
                'rate_snapshot' => $this->rateSnapshot(),
            ])->assertCreated();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings/'.$booking->json('booking_id').':cancel', [
                'external_reference' => $externalReference,
                'reason' => 'Fictional test cancellation',
            ])->assertOk()->assertJson([
                'organization_id' => $organizationId,
                'booking_id' => $booking->json('booking_id'),
                'trip_id' => $booking->json('trip_id'),
                'external_reference' => $externalReference,
                'status' => 'CANCELLED',
                'code' => 'BOOKING_CANCELLED',
                'inventory_revision' => 3,
            ]);

        $this->assertDatabaseHas('bookings', ['id' => $booking->json('booking_id'), 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('allocations', ['booking_id' => $booking->json('booking_id'), 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('trips', ['id' => $booking->json('trip_id'), 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'booking.cancelled.v1', 'inventory_revision' => 3]);

        $this->withToken($token)->postJson('/api/v1/availability:check', [
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-16T10:00:00Z',
            'ends_at' => '2026-09-16T12:00:00Z',
        ])->assertOk()->assertJson(['available' => true, 'inventory_revision' => 3]);
    }

    public function test_amending_booking_moves_allocation_and_trip_atomically(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Amend Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId, 10, 15);
        $templateId = $this->createTripTemplate($organizationId);
        $externalReference = 'FICTIONAL-BOOKING-AMEND-001';
        $hold = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => $externalReference,
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-17T10:00:00Z',
                'ends_at' => '2026-09-17T12:00:00Z',
                'expires_at' => '2026-08-01T00:20:00Z',
            ])->assertCreated();
        $booking = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings:confirm', [
                'hold_id' => $hold->json('hold_id'),
                'external_reference' => $externalReference,
                'rate_snapshot' => $this->rateSnapshot(),
            ])->assertCreated();
        $rateSnapshotId = (int) DB::table('bookings')->where('id', $booking->json('booking_id'))->value('rate_snapshot_id');
        $rateHashBefore = (string) DB::table('rate_snapshots')->where('id', $rateSnapshotId)->value('canonical_hash');

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings/'.$booking->json('booking_id').':amend', [
                'external_reference' => $externalReference,
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-17T14:00:00Z',
                'ends_at' => '2026-09-17T16:00:00Z',
            ])->assertOk()->assertJson([
                'organization_id' => $organizationId,
                'booking_id' => $booking->json('booking_id'),
                'trip_id' => $booking->json('trip_id'),
                'external_reference' => $externalReference,
                'status' => 'CONFIRMED',
                'code' => 'BOOKING_AMENDED',
                'inventory_revision' => 3,
            ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->json('booking_id'),
            'business_start' => '2026-09-17 14:00:00',
            'business_end' => '2026-09-17 16:00:00',
        ]);
        $this->assertDatabaseHas('allocations', [
            'booking_id' => $booking->json('booking_id'),
            'occupied_start' => '2026-09-17 13:50:00',
            'occupied_end' => '2026-09-17 16:15:00',
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('trips', [
            'booking_id' => $booking->json('booking_id'),
            'planned_start' => '2026-09-17 14:00:00',
            'planned_end' => '2026-09-17 16:00:00',
        ]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'booking.amended.v1', 'inventory_revision' => 3]);
        $this->assertSame($rateSnapshotId, (int) DB::table('bookings')->where('id', $booking->json('booking_id'))->value('rate_snapshot_id'));
        $this->assertSame($rateHashBefore, (string) DB::table('rate_snapshots')->where('id', $rateSnapshotId)->value('canonical_hash'));
    }

    public function test_failed_amend_keeps_original_allocation_and_revision(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Amend Conflict Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $externalReference = 'FICTIONAL-BOOKING-AMEND-CONFLICT-001';
        $hold = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', [
                'external_reference' => $externalReference,
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-18T10:00:00Z',
                'ends_at' => '2026-09-18T12:00:00Z',
                'expires_at' => '2026-08-01T00:20:00Z',
            ])->assertCreated();
        $booking = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings:confirm', [
                'hold_id' => $hold->json('hold_id'),
                'external_reference' => $externalReference,
                'rate_snapshot' => $this->rateSnapshot(),
            ])->assertCreated();
        $this->createAllocation($organizationId, $boatId, '2026-09-18 14:00:00', '2026-09-18 16:00:00');

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings/'.$booking->json('booking_id').':amend', [
                'external_reference' => $externalReference,
                'boat_id' => $boatId,
                'trip_template_id' => $templateId,
                'starts_at' => '2026-09-18T14:00:00Z',
                'ends_at' => '2026-09-18T16:00:00Z',
            ])->assertStatus(409)->assertJson([
                'code' => 'SLOT_UNAVAILABLE',
                'retryable' => false,
                'manual_action_required' => false,
                'message' => 'The requested slot is unavailable.',
            ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->json('booking_id'),
            'business_start' => '2026-09-18 10:00:00',
            'business_end' => '2026-09-18 12:00:00',
        ]);
        $this->assertDatabaseHas('allocations', [
            'booking_id' => $booking->json('booking_id'),
            'occupied_start' => '2026-09-18 10:00:00',
            'occupied_end' => '2026-09-18 12:00:00',
            'status' => 'ACTIVE',
        ]);
        $this->assertSame(2, (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision'));
        $this->assertDatabaseCount('outbox_events', 2);
    }

    public function test_new_idempotency_key_with_duplicate_external_reference_is_rejected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationId = $this->createOrganization('Fictional Duplicate Reference Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $payload = [
            'external_reference' => 'FICTIONAL-DUPLICATE-REFERENCE-001',
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-19T10:00:00Z',
            'ends_at' => '2026-09-19T12:00:00Z',
            'expires_at' => '2026-08-01T00:20:00Z',
        ];

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', $payload)
            ->assertCreated();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', $payload)
            ->assertStatus(409)
            ->assertJson([
                'code' => 'DUPLICATE_EXTERNAL_REFERENCE',
                'retryable' => false,
                'manual_action_required' => true,
                'message' => 'The external reference already exists.',
            ]);

        $this->assertDatabaseCount('holds', 1);
        $this->assertDatabaseCount('allocations', 1);
    }

    public function test_api_client_cannot_use_resources_from_another_organization(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
        $organizationA = $this->createOrganization('Fictional Isolated Charter A');
        $organizationB = $this->createOrganization('Fictional Isolated Charter B');
        $tokenA = $this->createApiClient($organizationA);
        $boatB = $this->createBoat($organizationB);
        $templateB = $this->createTripTemplate($organizationB);
        $payload = [
            'organization_id' => $organizationB,
            'boat_id' => $boatB,
            'trip_template_id' => $templateB,
            'starts_at' => '2026-09-20T10:00:00Z',
            'ends_at' => '2026-09-20T12:00:00Z',
        ];

        $this->withToken($tokenA)->postJson('/api/v1/availability:check', $payload)
            ->assertForbidden()
            ->assertJson([
                'code' => 'AUTHORIZATION_FAILED',
                'retryable' => false,
                'manual_action_required' => false,
            ]);

        $this->withToken($tokenA)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/holds', $payload + [
                'external_reference' => 'FICTIONAL-CROSS-ORG-001',
                'expires_at' => '2026-08-01T00:20:00Z',
            ])
            ->assertForbidden()
            ->assertJson(['code' => 'AUTHORIZATION_FAILED']);

        $this->assertDatabaseCount('holds', 0);
        $this->assertDatabaseCount('allocations', 0);
    }

    public function test_write_command_requires_idempotency_key(): void
    {
        $organizationId = $this->createOrganization('Fictional Idempotency Gate Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);

        $this->withToken($token)->postJson('/api/v1/holds', [
            'external_reference' => 'FICTIONAL-MISSING-IDEMPOTENCY-001',
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-21T10:00:00Z',
            'ends_at' => '2026-09-21T12:00:00Z',
            'expires_at' => '2026-08-01T00:20:00Z',
        ])->assertStatus(422)
            ->assertJson([
                'code' => 'VALIDATION_FAILED',
                'retryable' => false,
                'manual_action_required' => false,
                'message' => 'A valid Idempotency-Key header is required.',
            ])
            ->assertJsonStructure(['request_id']);

        $this->assertDatabaseCount('holds', 0);
    }

    public function test_internal_block_command_reserves_inventory_and_emits_minimal_event(): void
    {
        $organizationId = $this->createOrganization('Fictional Block Charters');
        $token = $this->createApiClient($organizationId, ['operations.write']);
        $boatId = $this->createBoat($organizationId);
        $key = (string) Str::uuid();

        $response = $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/internal/v1/blocks', [
                'external_reference' => 'FICTIONAL-BLOCK-001',
                'boat_id' => $boatId,
                'starts_at' => '2026-09-22T10:00:00Z',
                'ends_at' => '2026-09-22T12:00:00Z',
                'reason_code' => 'MAINTENANCE',
                'reason' => 'Fictional scheduled inspection',
            ])
            ->assertCreated()
            ->assertJson([
                'idempotency_key' => $key,
                'organization_id' => $organizationId,
                'external_reference' => 'FICTIONAL-BLOCK-001',
                'status' => 'ACTIVE',
                'code' => 'RESOURCE_BLOCKED',
                'inventory_revision' => 1,
            ])->assertJsonStructure(['request_id', 'block_id', 'occurred_at']);

        $this->assertDatabaseHas('blocks', [
            'id' => $response->json('block_id'),
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'status' => 'ACTIVE',
            'reason_code' => 'MAINTENANCE',
        ]);
        $this->assertDatabaseHas('allocations', [
            'block_id' => $response->json('block_id'),
            'allocation_type' => 'BLOCKED',
            'status' => 'ACTIVE',
            'occupied_start' => '2026-09-22 10:00:00',
            'occupied_end' => '2026-09-22 12:00:00',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'resource.blocked.v1',
            'inventory_revision' => 1,
        ]);
        $eventPayload = DB::table('outbox_events')->where('event_type', 'resource.blocked.v1')->value('payload');
        $this->assertStringNotContainsString('reason', strtolower((string) $eventPayload));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_internal_unblock_command_releases_inventory_and_emits_event(): void
    {
        $organizationId = $this->createOrganization('Fictional Unblock Charters');
        $token = $this->createApiClient($organizationId, ['operations.write']);
        $boatId = $this->createBoat($organizationId);
        $externalReference = 'FICTIONAL-BLOCK-RELEASE-001';
        $block = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/blocks', [
                'external_reference' => $externalReference,
                'boat_id' => $boatId,
                'starts_at' => '2026-09-23T10:00:00Z',
                'ends_at' => '2026-09-23T12:00:00Z',
                'reason_code' => 'WEATHER',
            ])->assertCreated();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/blocks/'.$block->json('block_id').':release', [
                'external_reference' => $externalReference,
                'reason' => 'Fictional weather cleared',
            ])->assertOk()->assertJson([
                'organization_id' => $organizationId,
                'block_id' => $block->json('block_id'),
                'external_reference' => $externalReference,
                'status' => 'RELEASED',
                'code' => 'RESOURCE_UNBLOCKED',
                'inventory_revision' => 2,
            ]);

        $this->assertDatabaseHas('blocks', ['id' => $block->json('block_id'), 'status' => 'RELEASED']);
        $this->assertDatabaseHas('allocations', ['block_id' => $block->json('block_id'), 'status' => 'RELEASED']);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'resource.unblocked.v1',
            'inventory_revision' => 2,
        ]);
    }

    public function test_internal_operations_require_explicit_write_scope(): void
    {
        $organizationId = $this->createOrganization('Fictional Unscoped Charters');
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/blocks', [
                'external_reference' => 'FICTIONAL-UNSCOPED-BLOCK-001',
                'boat_id' => $boatId,
                'starts_at' => '2026-09-24T10:00:00Z',
                'ends_at' => '2026-09-24T12:00:00Z',
                'reason_code' => 'MANUAL',
            ])->assertForbidden()->assertJson([
                'code' => 'AUTHORIZATION_FAILED',
                'retryable' => false,
                'manual_action_required' => false,
            ]);

        $this->assertDatabaseCount('blocks', 0);
    }

    public function test_trip_cannot_depart_without_crew_and_required_checklist(): void
    {
        $organizationId = $this->createOrganization('Fictional Trip Readiness Charters');
        $token = $this->createApiClient($organizationId, ['operations.write']);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $tripId = $this->createPlannedTrip($organizationId, $boatId, $templateId);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/trips/'.$tripId.':depart', [
                'departed_at' => '2026-09-25T10:02:00Z',
            ])->assertStatus(409)->assertJson([
                'code' => 'TRIP_NOT_READY',
                'retryable' => false,
                'manual_action_required' => true,
            ]);

        $this->assertDatabaseHas('trips', ['id' => $tripId, 'status' => 'PLANNED']);
    }

    public function test_prepared_trip_with_completed_required_checks_can_depart(): void
    {
        $organizationId = $this->createOrganization('Fictional Prepared Trip Charters');
        $token = $this->createApiClient($organizationId, ['operations.write']);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $tripId = $this->createPlannedTrip($organizationId, $boatId, $templateId);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/trips/'.$tripId.':prepare', [
                'crew' => [[
                    'external_reference' => 'FICTIONAL-CREW-001',
                    'display_name' => 'Fictional Captain One',
                    'role' => 'CAPTAIN',
                    'duty' => 'CAPTAIN',
                ]],
                'checklist' => [[
                    'code' => 'SAFETY',
                    'label' => 'Fictional safety check',
                    'required' => true,
                    'completed' => true,
                ], [
                    'code' => 'WEATHER',
                    'label' => 'Fictional weather check',
                    'required' => true,
                    'completed' => true,
                ]],
            ])->assertOk()->assertJson([
                'organization_id' => $organizationId,
                'trip_id' => $tripId,
                'status' => 'PLANNED',
                'code' => 'TRIP_PREPARED',
                'crew_count' => 1,
                'required_checklist_count' => 2,
                'incomplete_required_count' => 0,
            ]);

        $this->assertDatabaseCount('crew_members', 1);
        $this->assertDatabaseCount('crew_assignments', 1);
        $this->assertDatabaseCount('trip_checklists', 2);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/trips/'.$tripId.':depart', [
                'departed_at' => '2026-09-25T10:02:00Z',
            ])->assertOk()->assertJson([
                'organization_id' => $organizationId,
                'trip_id' => $tripId,
                'status' => 'DEPARTED',
                'code' => 'TRIP_DEPARTED',
                'departed_at' => '2026-09-25T10:02:00Z',
            ]);

        $this->assertDatabaseHas('trips', [
            'id' => $tripId,
            'status' => 'DEPARTED',
            'actual_departed_at' => '2026-09-25 10:02:00',
        ]);
    }

    public function test_returned_trip_can_complete_and_release_inventory(): void
    {
        $organizationId = $this->createOrganization('Fictional Completed Trip Charters');
        $token = $this->createApiClient($organizationId, ['operations.write']);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);
        $tripId = $this->createPlannedTrip($organizationId, $boatId, $templateId);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/trips/'.$tripId.':prepare', [
                'crew' => [[
                    'external_reference' => 'FICTIONAL-CREW-COMPLETE-001',
                    'display_name' => 'Fictional Captain Complete',
                    'role' => 'CAPTAIN',
                    'duty' => 'CAPTAIN',
                ]],
                'checklist' => [[
                    'code' => 'READY',
                    'label' => 'Fictional ready check',
                    'required' => true,
                    'completed' => true,
                ]],
            ])->assertOk();
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/trips/'.$tripId.':depart', [
                'departed_at' => '2026-09-25T10:02:00Z',
            ])->assertOk();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/trips/'.$tripId.':return', [
                'returned_at' => '2026-09-25T12:06:00Z',
            ])->assertOk()->assertJson([
                'organization_id' => $organizationId,
                'trip_id' => $tripId,
                'status' => 'RETURNED',
                'code' => 'TRIP_RETURNED',
                'returned_at' => '2026-09-25T12:06:00Z',
            ]);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/trips/'.$tripId.':complete')
            ->assertOk()->assertJson([
                'organization_id' => $organizationId,
                'trip_id' => $tripId,
                'status' => 'COMPLETED',
                'code' => 'TRIP_COMPLETED',
                'inventory_revision' => 1,
            ]);

        $bookingId = (int) DB::table('trips')->where('id', $tripId)->value('booking_id');
        $this->assertDatabaseHas('trips', ['id' => $tripId, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('allocations', ['booking_id' => $bookingId, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'trip.completed.v1', 'inventory_revision' => 1]);
        $eventPayload = (string) DB::table('outbox_events')->where('event_type', 'trip.completed.v1')->value('payload');
        $this->assertStringNotContainsString('customer', strtolower($eventPayload));
        $this->assertStringNotContainsString('crew', strtolower($eventPayload));
        $this->assertStringNotContainsString('cost', strtolower($eventPayload));
        $this->assertStringNotContainsString('profit', strtolower($eventPayload));
    }

    public function test_thailand_midnight_boundary_preserves_utc_interval(): void
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Fictional Bangkok Midnight Charters',
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = $this->createApiClient($organizationId);
        $boatId = $this->createBoat($organizationId);
        $templateId = $this->createTripTemplate($organizationId);

        $this->withToken($token)->postJson('/api/v1/availability:check', [
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-21T17:00:00Z',
            'ends_at' => '2026-09-21T19:00:00Z',
        ])->assertOk()->assertJson([
            'available' => true,
            'occupied_start' => '2026-09-21T17:00:00Z',
            'occupied_end' => '2026-09-21T19:00:00Z',
            'business_timezone' => 'Asia/Bangkok',
        ]);
    }

    private function rateSnapshot(): array
    {
        return [
            'source_reference' => 'FICTIONAL-RATE-V1',
            'currency' => 'THB',
            'selling_amount_minor' => 125000,
            'tax_amount_minor' => 0,
            'commission_amount_minor' => 12500,
            'fx_rate' => '4.50000000',
            'fx_base_currency' => 'CNY',
            'fx_quote_currency' => 'THB',
            'quoted_at' => '2026-08-01T00:00:00Z',
            'valid_until' => '2026-08-01T01:00:00Z',
        ];
    }

    private function createPlannedTrip(int $organizationId, int $boatId, int $tripTemplateId): int
    {
        $allocationId = $this->createAllocation(
            $organizationId,
            $boatId,
            '2026-09-25 10:00:00',
            '2026-09-25 12:00:00',
            'BOOKING',
        );
        $bookingId = DB::table('bookings')->insertGetId([
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'trip_template_id' => $tripTemplateId,
            'external_reference' => 'FICTIONAL-TRIP-'.Str::upper(Str::random(8)),
            'status' => 'CONFIRMED',
            'business_start' => '2026-09-25 10:00:00',
            'business_end' => '2026-09-25 12:00:00',
            'allocation_id' => $allocationId,
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('allocations')->where('id', $allocationId)->update(['booking_id' => $bookingId]);

        return DB::table('trips')->insertGetId([
            'organization_id' => $organizationId,
            'booking_id' => $bookingId,
            'boat_id' => $boatId,
            'trip_template_id' => $tripTemplateId,
            'status' => 'PLANNED',
            'planned_start' => '2026-09-25 10:00:00',
            'planned_end' => '2026-09-25 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBoat(
        int $organizationId,
        int $bufferBeforeMinutes = 0,
        int $bufferAfterMinutes = 0,
    ): int {
        return DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Fictional Vessel '.Str::random(6),
            'status' => 'ACTIVE',
            'buffer_before_minutes' => $bufferBeforeMinutes,
            'buffer_after_minutes' => $bufferAfterMinutes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTripTemplate(int $organizationId): int
    {
        return DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'FICTIONAL-'.Str::upper(Str::random(8)),
            'name' => 'Fictional Coastal Trip',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAllocation(
        int $organizationId,
        int $boatId,
        string $occupiedStart,
        string $occupiedEnd,
        string $type = 'BLOCKED',
    ): int {
        return DB::table('allocations')->insertGetId([
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'allocation_type' => $type,
            'status' => 'ACTIVE',
            'business_start' => $occupiedStart,
            'business_end' => $occupiedEnd,
            'occupied_start' => $occupiedStart,
            'occupied_end' => $occupiedEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrganization(string $name, int $revision = 0): int
    {
        return DB::table('organizations')->insertGetId([
            'name' => $name,
            'timezone' => 'UTC',
            'inventory_revision' => $revision,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createApiClient(int $organizationId, array $scopes = []): string
    {
        $token = Str::random(48);

        DB::table('api_clients')->insert([
            'organization_id' => $organizationId,
            'name' => 'Fictional Channel Client',
            'token_hash' => hash('sha256', $token),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
