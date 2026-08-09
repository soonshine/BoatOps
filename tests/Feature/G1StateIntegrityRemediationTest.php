<?php

namespace Tests\Feature;

use App\Application\Blocks\CreateBlockAction;
use App\Application\Blocks\ReleaseBlockAction;
use App\Application\Bookings\AmendBookingAction;
use App\Application\Bookings\CancelBookingAction;
use App\Application\Bookings\ConfirmBookingAction;
use App\Application\Holds\CreateHoldAction;
use App\Application\Holds\ExpireDueHoldAction;
use App\Application\Holds\ExpireDueHolds;
use App\Application\Holds\HoldActor;
use App\Application\Holds\ReleaseHoldAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class G1StateIntegrityRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:00:00Z'));
    }

    #[DataProvider('allocationCorruptions')]
    public function test_confirm_rejects_missing_inactive_mismatched_and_foreign_allocations_without_partial_writes(string $corruption): void
    {
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Confirm Integrity');
        [$foreignOrganizationId, $foreignBoatId] = $this->inventory('Fictional Confirm Foreign');
        $hold = $this->createHold($organizationId, $boatId, $templateId, 'CONFIRM-INTEGRITY-001', 'confirm-integrity-hold');
        $holdId = (int) $hold->payload['hold_id'];
        $allocationId = (int) DB::table('holds')->where('id', $holdId)->value('allocation_id');
        $this->corruptAllocation($allocationId, $corruption, $foreignOrganizationId, $foreignBoatId);
        $before = $this->snapshot();

        $result = app(ConfirmBookingAction::class)->execute($organizationId, [
            'hold_id' => $holdId,
            'external_reference' => 'CONFIRM-INTEGRITY-001',
            'rate_snapshot' => $this->rateSnapshot(),
        ], 'confirm-integrity-command', HoldActor::operatorUser(501));

        $this->assertIntegrityFailure($result->status, $result->payload);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('allocationCorruptions')]
    public function test_amend_rejects_missing_inactive_mismatched_and_foreign_allocations_without_partial_writes(string $corruption): void
    {
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Amend Integrity');
        [$foreignOrganizationId, $foreignBoatId] = $this->inventory('Fictional Amend Foreign');
        $bookingId = $this->confirmedBooking($organizationId, $boatId, $templateId, 'AMEND-INTEGRITY-001');
        $allocationId = (int) DB::table('bookings')->where('id', $bookingId)->value('allocation_id');
        $this->corruptAllocation($allocationId, $corruption, $foreignOrganizationId, $foreignBoatId);
        $before = $this->snapshot();

        $result = app(AmendBookingAction::class)->execute(
            $organizationId,
            $bookingId,
            $this->amendInput($boatId, $templateId, 'AMEND-INTEGRITY-001'),
            'amend-integrity-command',
            HoldActor::operatorUser(502),
        );

        $this->assertIntegrityFailure($result->status, $result->payload);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('amendIllegalTripStates')]
    public function test_amend_rejects_every_non_planned_trip_state_without_partial_writes(string $tripStatus): void
    {
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Amend Trip State');
        $bookingId = $this->confirmedBooking($organizationId, $boatId, $templateId, 'AMEND-TRIP-STATE-'.$tripStatus);
        DB::table('trips')->where('booking_id', $bookingId)->update(['status' => $tripStatus]);
        $before = $this->snapshot();

        $result = app(AmendBookingAction::class)->execute(
            $organizationId,
            $bookingId,
            $this->amendInput($boatId, $templateId, 'AMEND-TRIP-STATE-'.$tripStatus),
            'amend-trip-state-'.strtolower($tripStatus),
            HoldActor::operatorUser(503),
        );

        $this->assertSame(409, $result->status);
        $this->assertSame('INVALID_TRANSITION', $result->payload['code']);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('cancelAllowedTripStates')]
    public function test_cancel_allows_planned_and_prepared_trip_states(string $tripStatus): void
    {
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Cancel Allowed');
        $reference = 'CANCEL-ALLOWED-'.$tripStatus;
        $bookingId = $this->confirmedBooking($organizationId, $boatId, $templateId, $reference);
        DB::table('trips')->where('booking_id', $bookingId)->update(['status' => $tripStatus]);

        $result = app(CancelBookingAction::class)->execute($organizationId, $bookingId, [
            'external_reference' => $reference,
            'reason' => 'FICTIONAL_ALLOWED_CANCELLATION',
        ], 'cancel-allowed-'.strtolower($tripStatus), HoldActor::operatorUser(504));

        $this->assertSame(200, $result->status);
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('trips', ['booking_id' => $bookingId, 'status' => 'CANCELLED']);
    }

    #[DataProvider('cancelIllegalTripStates')]
    public function test_cancel_rejects_departed_returned_and_completed_trips_without_partial_writes(string $tripStatus): void
    {
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Cancel Illegal');
        $reference = 'CANCEL-ILLEGAL-'.$tripStatus;
        $bookingId = $this->confirmedBooking($organizationId, $boatId, $templateId, $reference);
        DB::table('trips')->where('booking_id', $bookingId)->update(['status' => $tripStatus]);
        $before = $this->snapshot();

        $result = app(CancelBookingAction::class)->execute($organizationId, $bookingId, [
            'external_reference' => $reference,
            'reason' => 'FICTIONAL_ILLEGAL_CANCELLATION',
        ], 'cancel-illegal-'.strtolower($tripStatus), HoldActor::operatorUser(505));

        $this->assertSame(409, $result->status);
        $this->assertSame('INVALID_TRANSITION', $result->payload['code']);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('allocationCorruptions')]
    public function test_cancel_rejects_corrupt_allocation_without_partial_writes(string $corruption): void
    {
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Cancel Integrity');
        [$foreignOrganizationId, $foreignBoatId] = $this->inventory('Fictional Cancel Foreign');
        $bookingId = $this->confirmedBooking($organizationId, $boatId, $templateId, 'CANCEL-INTEGRITY-001');
        $allocationId = (int) DB::table('bookings')->where('id', $bookingId)->value('allocation_id');
        $this->corruptAllocation($allocationId, $corruption, $foreignOrganizationId, $foreignBoatId);
        $before = $this->snapshot();

        $result = app(CancelBookingAction::class)->execute($organizationId, $bookingId, [
            'external_reference' => 'CANCEL-INTEGRITY-001',
        ], 'cancel-integrity-command', HoldActor::operatorUser(506));

        $this->assertIntegrityFailure($result->status, $result->payload);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('tripCorruptions')]
    public function test_amend_rejects_corrupt_trip_relationships_without_partial_writes(string $corruption): void
    {
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Amend Trip Integrity');
        [$foreignOrganizationId, $foreignBoatId, $foreignTemplateId] = $this->inventory('Fictional Amend Trip Foreign');
        $reference = 'AMEND-TRIP-INTEGRITY-'.$corruption;
        $bookingId = $this->confirmedBooking($organizationId, $boatId, $templateId, $reference);
        $this->corruptTrip($bookingId, $corruption, $foreignOrganizationId, $foreignBoatId, $foreignTemplateId);
        $before = $this->snapshot();

        $result = app(AmendBookingAction::class)->execute(
            $organizationId,
            $bookingId,
            $this->amendInput($boatId, $templateId, $reference),
            'amend-trip-integrity-'.strtolower($corruption),
            HoldActor::operatorUser(506),
        );

        $this->assertIntegrityFailure($result->status, $result->payload);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('tripCorruptions')]
    public function test_cancel_rejects_corrupt_trip_relationships_without_partial_writes(string $corruption): void
    {
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Cancel Trip Integrity');
        [$foreignOrganizationId, $foreignBoatId, $foreignTemplateId] = $this->inventory('Fictional Cancel Trip Foreign');
        $reference = 'CANCEL-TRIP-INTEGRITY-'.$corruption;
        $bookingId = $this->confirmedBooking($organizationId, $boatId, $templateId, $reference);
        $this->corruptTrip($bookingId, $corruption, $foreignOrganizationId, $foreignBoatId, $foreignTemplateId);
        $before = $this->snapshot();

        $result = app(CancelBookingAction::class)->execute($organizationId, $bookingId, [
            'external_reference' => $reference,
        ], 'cancel-trip-integrity-'.strtolower($corruption), HoldActor::operatorUser(506));

        $this->assertIntegrityFailure($result->status, $result->payload);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('allocationCorruptions')]
    public function test_release_hold_rejects_corrupt_allocation_without_partial_writes(string $corruption): void
    {
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Release Hold Integrity');
        [$foreignOrganizationId, $foreignBoatId] = $this->inventory('Fictional Release Hold Foreign');
        $hold = $this->createHold($organizationId, $boatId, $templateId, 'RELEASE-HOLD-INTEGRITY', 'release-hold-integrity-create');
        $holdId = (int) $hold->payload['hold_id'];
        $allocationId = (int) DB::table('holds')->where('id', $holdId)->value('allocation_id');
        $this->corruptAllocation($allocationId, $corruption, $foreignOrganizationId, $foreignBoatId);
        $before = $this->snapshot();

        $result = app(ReleaseHoldAction::class)->execute($organizationId, $holdId, [
            'external_reference' => 'RELEASE-HOLD-INTEGRITY',
        ], 'release-hold-integrity-command', HoldActor::operatorUser(507));

        $this->assertIntegrityFailure($result->status, $result->payload);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_expiry_rejects_corrupt_allocation_without_partial_writes_and_worker_fails_loudly(): void
    {
        [$organizationId, $boatId, $templateId] = $this->inventory('Fictional Expiry Integrity');
        $hold = $this->createHold($organizationId, $boatId, $templateId, 'EXPIRY-INTEGRITY', 'expiry-integrity-create', '2026-08-01T00:01:00Z');
        $holdId = (int) $hold->payload['hold_id'];
        DB::table('allocations')->where('hold_id', $holdId)->update(['allocation_type' => 'BOOKING']);
        $this->travelTo(CarbonImmutable::parse('2026-08-01T00:02:00Z'));
        $before = $this->snapshot();

        $result = app(ExpireDueHoldAction::class)->execute(
            $holdId,
            CarbonImmutable::now('UTC'),
            HoldActor::system(),
        );

        $this->assertIntegrityFailure($result->status, $result->payload);
        $this->assertSame($before, $this->snapshot());
        try {
            app(ExpireDueHolds::class)->execute(CarbonImmutable::now('UTC'));
            $this->fail('Expected the expiry worker to fail loudly.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('integrity', strtolower($exception->getMessage()));
        }
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('allocationCorruptions')]
    public function test_release_block_rejects_corrupt_allocation_without_partial_writes(string $corruption): void
    {
        [$organizationId, $boatId] = $this->inventory('Fictional Release Block Integrity');
        [$foreignOrganizationId, $foreignBoatId] = $this->inventory('Fictional Release Block Foreign');
        $created = app(CreateBlockAction::class)->execute($organizationId, [
            'external_reference' => 'RELEASE-BLOCK-INTEGRITY',
            'boat_id' => $boatId,
            'starts_at' => '2026-09-02T10:00:00Z',
            'ends_at' => '2026-09-02T12:00:00Z',
            'reason_code' => 'MAINTENANCE',
            'reason' => 'Fictional integrity test',
        ], 'release-block-integrity-create', HoldActor::operatorUser(508));
        $blockId = (int) $created->payload['block_id'];
        $allocationId = (int) DB::table('blocks')->where('id', $blockId)->value('allocation_id');
        $this->corruptAllocation($allocationId, $corruption, $foreignOrganizationId, $foreignBoatId);
        if ($corruption === 'type') {
            DB::table('allocations')->where('id', $allocationId)->update(['allocation_type' => 'HOLD']);
        }
        $before = $this->snapshot();

        $result = app(ReleaseBlockAction::class)->execute($organizationId, $blockId, [
            'external_reference' => 'RELEASE-BLOCK-INTEGRITY',
            'reason' => 'Fictional release',
        ], 'release-block-integrity-command', HoldActor::operatorUser(509));

        $this->assertIntegrityFailure($result->status, $result->payload);
        $this->assertSame($before, $this->snapshot());
    }

    /** @return array<string, array{string}> */
    public static function allocationCorruptions(): array
    {
        return [
            'missing' => ['missing'],
            'inactive' => ['inactive'],
            'wrong type' => ['type'],
            'foreign organization' => ['foreign'],
            'wrong resource' => ['resource'],
            'wrong interval' => ['interval'],
            'wrong relation' => ['relation'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function amendIllegalTripStates(): array
    {
        return collect(['PREPARED', 'DEPARTED', 'RETURNED', 'COMPLETED', 'CANCELLED'])
            ->mapWithKeys(fn (string $state): array => [$state => [$state]])->all();
    }

    /** @return array<string, array{string}> */
    public static function cancelAllowedTripStates(): array
    {
        return ['planned' => ['PLANNED'], 'prepared' => ['PREPARED']];
    }

    /** @return array<string, array{string}> */
    public static function cancelIllegalTripStates(): array
    {
        return [
            'departed' => ['DEPARTED'],
            'returned' => ['RETURNED'],
            'completed' => ['COMPLETED'],
            'cancelled' => ['CANCELLED'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function tripCorruptions(): array
    {
        return [
            'missing' => ['missing'],
            'foreign organization' => ['foreign'],
            'wrong resource' => ['resource'],
            'wrong template' => ['template'],
            'wrong interval' => ['interval'],
        ];
    }

    private function corruptAllocation(int $allocationId, string $corruption, int $foreignOrganizationId, int $foreignBoatId): void
    {
        match ($corruption) {
            'missing' => DB::table('allocations')->where('id', $allocationId)->delete(),
            'inactive' => DB::table('allocations')->where('id', $allocationId)->update(['status' => 'RELEASED']),
            'type' => DB::table('allocations')->where('id', $allocationId)->update(['allocation_type' => 'BLOCKED']),
            'foreign' => DB::table('allocations')->where('id', $allocationId)->update(['organization_id' => $foreignOrganizationId]),
            'resource' => DB::table('allocations')->where('id', $allocationId)->update(['boat_id' => $foreignBoatId]),
            'interval' => DB::table('allocations')->where('id', $allocationId)->update(['occupied_end' => '2026-09-01 13:00:00']),
            'relation' => DB::table('allocations')->where('id', $allocationId)->update(['hold_id' => null, 'booking_id' => null, 'block_id' => null]),
        };
    }

    private function corruptTrip(
        int $bookingId,
        string $corruption,
        int $foreignOrganizationId,
        int $foreignBoatId,
        int $foreignTemplateId,
    ): void {
        match ($corruption) {
            'missing' => DB::table('trips')->where('booking_id', $bookingId)->delete(),
            'foreign' => DB::table('trips')->where('booking_id', $bookingId)->update(['organization_id' => $foreignOrganizationId]),
            'resource' => DB::table('trips')->where('booking_id', $bookingId)->update(['boat_id' => $foreignBoatId]),
            'template' => DB::table('trips')->where('booking_id', $bookingId)->update(['trip_template_id' => $foreignTemplateId]),
            'interval' => DB::table('trips')->where('booking_id', $bookingId)->update(['planned_end' => '2026-09-01 13:00:00']),
        };
    }

    private function assertIntegrityFailure(int $status, array $payload): void
    {
        $this->assertSame(409, $status);
        $this->assertSame('INVENTORY_INTEGRITY_FAILED', $payload['code']);
        $this->assertTrue($payload['manual_action_required']);
        $this->assertFalse($payload['retryable']);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $tables = ['organizations', 'holds', 'allocations', 'bookings', 'trips', 'blocks', 'rate_snapshots', 'audit_logs', 'outbox_events', 'idempotency_keys'];

        return collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ])->all();
    }

    /** @return array{int, int, int} */
    private function inventory(string $name): array
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => $name,
            'timezone' => 'UTC',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $boatId = DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $name.' Vessel',
            'status' => 'ACTIVE',
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $templateId = DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'FICTIONAL-'.Str::upper(Str::random(8)),
            'name' => $name.' Trip',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organizationId, $boatId, $templateId];
    }

    private function createHold(
        int $organizationId,
        int $boatId,
        int $templateId,
        string $reference,
        string $key,
        string $expiresAt = '2026-08-01T00:20:00Z',
    ): object {
        return app(CreateHoldAction::class)->execute($organizationId, [
            'external_reference' => $reference,
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-01T10:00:00Z',
            'ends_at' => '2026-09-01T12:00:00Z',
            'expires_at' => $expiresAt,
        ], $key, HoldActor::apiClient(500));
    }

    private function confirmedBooking(int $organizationId, int $boatId, int $templateId, string $reference): int
    {
        $hold = $this->createHold($organizationId, $boatId, $templateId, $reference, 'hold-'.strtolower($reference));
        $confirmed = app(ConfirmBookingAction::class)->execute($organizationId, [
            'hold_id' => $hold->payload['hold_id'],
            'external_reference' => $reference,
            'rate_snapshot' => $this->rateSnapshot(),
        ], 'confirm-'.strtolower($reference), HoldActor::operatorUser(500));
        $this->assertSame(201, $confirmed->status);

        return (int) $confirmed->payload['booking_id'];
    }

    /** @return array<string, mixed> */
    private function amendInput(int $boatId, int $templateId, string $reference): array
    {
        return [
            'external_reference' => $reference,
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'starts_at' => '2026-09-01T14:00:00Z',
            'ends_at' => '2026-09-01T16:00:00Z',
        ];
    }

    /** @return array<string, mixed> */
    private function rateSnapshot(): array
    {
        return [
            'source_reference' => 'FICTIONAL-INTEGRITY-RATE',
            'currency' => 'USD',
            'selling_amount_minor' => 12345,
            'tax_amount_minor' => 0,
            'commission_amount_minor' => 0,
            'quoted_at' => '2026-07-31T23:00:00Z',
            'valid_until' => '2026-08-02T00:00:00Z',
        ];
    }
}
