<?php

namespace Database\Seeders;

use App\Http\Controllers\Api\Internal\V1\OperationsCostController;
use App\Services\SlotCatalog\SlotCatalogService;
use App\Services\SlotCatalog\SlotCompatibilityService;
use App\Services\SlotCatalog\SlotIntervalResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSiteSeeder extends Seeder
{
    public function run(): void
    {
        $isLocalSeed = app()->environment(['local', 'testing']);
        $isExplicitPublicProductionSeed = app()->environment('production')
            && config('demo_site.enabled') === true
            && config('demo_site.mode') === 'public_read_only'
            && config('demo_site.allow_production_seed') === true;

        if (! $isLocalSeed && ! $isExplicitPublicProductionSeed) {
            throw new RuntimeException('DemoSiteSeeder is allowed only in local/testing, or in production with the enabled public_read_only demo and the one-time production seed flag.');
        }
        $token = getenv('BOATOPS_DEMO_TOKEN');
        if (! is_string($token) || strlen($token) < 24) {
            throw new RuntimeException('BOATOPS_DEMO_TOKEN must be set to at least 24 characters for fictional demo seeding.');
        }

        DB::transaction(function () use ($token): void {
            $now = now()->utc();
            $organizationId = $this->upsertId('organizations', ['name' => config('demo_site.organization_name')], [
                'timezone' => 'Asia/Bangkok',
            ], $now);
            $legacyScopes = [
                'operations.write',
                'operations.finance.read',
                'operations.finance.write',
                'operations.schedule.read',
                'operations.schedule.write',
            ];
            $this->upsertId('api_clients', [
                'organization_id' => $organizationId, 'name' => 'Local Demo API Client',
            ], ['token_hash' => hash('sha256', $token), 'scopes' => json_encode($legacyScopes, JSON_THROW_ON_ERROR), 'active' => true], $now);
            $siteScopes = [
                'operations.finance.read',
                'operations.finance.write',
                'operations.schedule.read',
                'operations.schedule.write',
            ];
            $actorId = $this->upsertId('api_clients', [
                'organization_id' => $organizationId, 'name' => config('demo_site.actor_name'),
            ], ['token_hash' => hash('sha256', 'demo-site-actor:'.$token), 'scopes' => json_encode($siteScopes, JSON_THROW_ON_ERROR), 'active' => true], $now);
            $this->upsertId('api_clients', [
                'organization_id' => $organizationId, 'name' => config('demo_site.public_reader_name'),
            ], ['token_hash' => hash('sha256', 'public-demo-reader:'.$token), 'scopes' => json_encode(config('demo_site.public_reader_scopes'), JSON_THROW_ON_ERROR), 'active' => true], $now);

            $boatIds = [];
            foreach (config('demo_site.boat_names') as $name) {
                $boatIds[$name] = $this->upsertId('boats', ['organization_id' => $organizationId, 'name' => $name], [
                    'status' => 'ACTIVE', 'buffer_before_minutes' => 30, 'buffer_after_minutes' => 30,
                ], $now);
            }
            $this->call(SlotCatalogSeeder::class);
            $templateId = $this->upsertId('trip_templates', ['organization_id' => $organizationId, 'code' => 'DEMO-4H'], [
                'name' => 'Fictional Four Hour Whole-Boat Charter', 'status' => 'ACTIVE',
            ], $now);
            $accountId = $this->upsertId('cash_accounts', ['organization_id' => $organizationId, 'external_reference' => 'DEMO-CASH-THB'], [
                'name' => 'Fictional THB Cash Box', 'account_type' => 'CASH', 'currency' => 'THB', 'status' => 'ACTIVE',
            ], $now);
            $this->upsertId('expense_categories', ['organization_id' => $organizationId, 'code' => 'DEMO-MARINA'], [
                'name' => 'Fictional Marina Fee', 'cost_scope' => 'DIRECT', 'active' => true,
            ], $now);
            $this->upsertId('expense_categories', ['organization_id' => $organizationId, 'code' => 'DEMO-OFFICE'], [
                'name' => 'Fictional Office Cost', 'cost_scope' => 'COMMON', 'active' => true,
            ], $now);
            $itemId = $this->upsertId('items', ['organization_id' => $organizationId, 'external_reference' => 'DEMO-WATER-500ML'], [
                'name' => 'Fictional Bottled Water 500ml', 'category' => 'BEVERAGE', 'unit' => 'BOTTLE',
                'currency' => 'THB', 'minimum_stock_quantity' => '24.000', 'status' => 'ACTIVE',
            ], $now);

            $start = CarbonImmutable::now('Asia/Bangkok')->startOfDay();
            $this->schedule($organizationId, $boatIds[config('demo_site.boat_names')[0]], $templateId, 'DEMO-PLAN-A-DAY-1', $start->addDay()->setTime(9, 0), $now);
            $this->schedule($organizationId, $boatIds[config('demo_site.boat_names')[1]], $templateId, 'DEMO-PLAN-B-DAY-2', $start->addDays(2)->setTime(10, 0), $now);
            $this->schedule($organizationId, $boatIds[config('demo_site.boat_names')[0]], $templateId, 'DEMO-PLAN-A-DAY-4', $start->addDays(4)->setTime(13, 0), $now);
            $this->schedule($organizationId, $boatIds[config('demo_site.boat_names')[1]], $templateId, 'DEMO-PLAN-B-DAY-6', $start->addDays(6)->setTime(8, 0), $now);
            $this->seedScheduleCatalog($organizationId, $actorId, $templateId, $boatIds, $start, $now);
            $this->openingStock($organizationId, $actorId, $itemId, $accountId, array_values($boatIds));
        }, 3);
    }

    private function upsertId(string $table, array $identity, array $values, mixed $now): int
    {
        $existing = DB::table($table)->where($identity)->first();
        if ($existing) {
            DB::table($table)->where('id', $existing->id)->update([...$values, 'updated_at' => $now]);

            return (int) $existing->id;
        }

        return DB::table($table)->insertGetId([...$identity, ...$values, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function schedule(int $organizationId, int $boatId, int $templateId, string $reference, CarbonImmutable $localStart, mixed $now): void
    {
        $businessStart = $localStart->utc();
        $businessEnd = $localStart->addHours(4)->utc();
        $booking = DB::table('bookings')->where('organization_id', $organizationId)
            ->where('external_reference', $reference)->first();

        if (! $booking) {
            $allocationId = DB::table('allocations')->insertGetId([
                'organization_id' => $organizationId, 'boat_id' => $boatId, 'allocation_type' => 'BOOKING',
                'status' => 'ACTIVE', 'business_start' => $businessStart, 'business_end' => $businessEnd,
                'occupied_start' => $businessStart->subMinutes(30), 'occupied_end' => $businessEnd->addMinutes(30),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $bookingId = DB::table('bookings')->insertGetId([
                'organization_id' => $organizationId, 'boat_id' => $boatId, 'trip_template_id' => $templateId,
                'external_reference' => $reference, 'status' => 'CONFIRMED',
                'business_start' => $businessStart, 'business_end' => $businessEnd, 'allocation_id' => $allocationId,
                'confirmed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('allocations')->where('id', $allocationId)->update(['booking_id' => $bookingId]);
            DB::table('trips')->insert([
                'organization_id' => $organizationId, 'booking_id' => $bookingId, 'boat_id' => $boatId,
                'trip_template_id' => $templateId, 'status' => 'PLANNED',
                'planned_start' => $businessStart, 'planned_end' => $businessEnd,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            return;
        }

        $allocationId = (int) $booking->allocation_id;
        DB::table('allocations')->where('organization_id', $organizationId)->where('id', $allocationId)->update([
            'boat_id' => $boatId, 'status' => 'ACTIVE', 'business_start' => $businessStart, 'business_end' => $businessEnd,
            'occupied_start' => $businessStart->subMinutes(30), 'occupied_end' => $businessEnd->addMinutes(30), 'updated_at' => $now,
        ]);
        DB::table('bookings')->where('id', $booking->id)->update([
            'boat_id' => $boatId, 'trip_template_id' => $templateId, 'status' => 'CONFIRMED',
            'business_start' => $businessStart, 'business_end' => $businessEnd, 'updated_at' => $now,
        ]);
        DB::table('trips')->where('organization_id', $organizationId)->where('booking_id', $booking->id)->update([
            'boat_id' => $boatId, 'trip_template_id' => $templateId, 'planned_start' => $businessStart,
            'planned_end' => $businessEnd, 'updated_at' => $now,
        ]);
    }

    private function seedScheduleCatalog(
        int $organizationId,
        int $actorId,
        int $tripTemplateId,
        array $boatIds,
        CarbonImmutable $localStart,
        mixed $now,
    ): void {
        $catalog = app(SlotCatalogService::class);
        $planAId = (int) $boatIds[config('demo_site.boat_names.0')];
        $planBId = (int) $boatIds[config('demo_site.boat_names.1')];
        $draftId = $this->catalogEntryId($organizationId, 'DEMO_REUSABLE_DRAFT');

        if ($draftId === null) {
            $draftId = $catalog->createReusableOffering($organizationId, [
                'code' => 'DEMO_REUSABLE_DRAFT',
                'name' => 'Fictional Reusable Draft Slot',
                'status' => 'DRAFT',
                'operating_time_status' => 'DEMO_DEFAULT_UNVERIFIED',
                'service_start_time' => '10:00',
                'service_end_time' => '12:00',
                'duration_minutes' => 120,
                'applies_to_all_boats' => false,
            ], [$planAId], $actorId);
        }

        $retiredId = $this->catalogEntryId($organizationId, 'DEMO_REUSABLE_RETIRED');

        if ($retiredId === null) {
            $retiredId = $catalog->createReusableOffering($organizationId, [
                'code' => 'DEMO_REUSABLE_RETIRED',
                'name' => 'Fictional Retired Slot',
                'status' => 'DRAFT',
                'operating_time_status' => 'DEMO_DEFAULT_UNVERIFIED',
                'service_start_time' => '15:00',
                'service_end_time' => '17:00',
                'duration_minutes' => 120,
                'applies_to_all_boats' => false,
            ], [$planBId], $actorId);
            $catalog->transitionStatus($organizationId, $retiredId, 'RETIRED', $actorId);
        }

        $instanceDate = $localStart->addDays(3)->format('Y-m-d');
        $instanceCode = 'DEMO_FULL_DAY_6H_'.$localStart->addDays(3)->format('Ymd');
        $instanceId = $this->catalogEntryId($organizationId, $instanceCode);
        $fullDaySixId = (int) $this->catalogEntryId($organizationId, 'FULL_DAY_6H');

        if ($instanceId === null) {
            $instanceId = $catalog->createCustomInstance($organizationId, [
                'template_slot_offering_id' => $fullDaySixId,
                'code' => $instanceCode,
                'name' => 'Fictional FULL_DAY_6H 12:00-18:00 Validation Instance',
                'status' => 'ACTIVE',
                'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
                'service_date' => $instanceDate,
                'service_start_time' => '12:00',
                'service_end_time' => '18:00',
                'duration_minutes' => 360,
                'applies_to_all_boats' => false,
            ], [$planAId], $actorId);
        }

        $this->attachScheduledBookingSlot($organizationId, 'DEMO-PLAN-A-DAY-4', 'PM_4H');
        $this->attachScheduledBookingSlot($organizationId, 'DEMO-PLAN-B-DAY-6', 'AM_4H');
        $this->upsertDemoHold(
            $organizationId,
            $planAId,
            $tripTemplateId,
            'AM_4H',
            'DEMO-CALENDAR-HELD',
            $localStart->addDays(3),
            $now,
        );
        $this->upsertDemoBlock(
            $organizationId,
            $planBId,
            'DEMO-CALENDAR-BLOCKED',
            $localStart->addDays(5)->setTime(13, 0),
            $localStart->addDays(5)->setTime(16, 0),
            $now,
        );

        $amId = (int) $this->catalogEntryId($organizationId, 'AM_4H');
        $pairKey = min($amId, $instanceId).':'.max($amId, $instanceId);
        $policy = DB::table('slot_compatibility_rules')
            ->where('organization_id', $organizationId)
            ->where('pair_key', $pairKey)
            ->value('policy');

        if ($policy !== 'ALLOW') {
            app(SlotCompatibilityService::class)->setRule(
                $organizationId,
                $amId,
                $instanceId,
                'ALLOW',
                $actorId,
                'FICTIONAL_DEMO_BUFFER_CONFLICT_VISUALIZATION',
            );
        }
    }

    private function attachScheduledBookingSlot(
        int $organizationId,
        string $externalReference,
        string $slotCode,
    ): void {
        $booking = DB::table('bookings')
            ->where('organization_id', $organizationId)
            ->where('external_reference', $externalReference)
            ->first();
        $slot = DB::table('slot_offerings')
            ->where('organization_id', $organizationId)
            ->where('code', $slotCode)
            ->first();

        if (! $booking || ! $slot) {
            throw new RuntimeException('Fictional demo booking slot attachment is incomplete.');
        }

        $organization = DB::table('organizations')->find($organizationId);
        $boat = DB::table('boats')
            ->where('organization_id', $organizationId)
            ->find($booking->boat_id);
        $serviceDate = CarbonImmutable::parse((string) $booking->business_start, 'UTC')
            ->setTimezone((string) $organization->timezone)
            ->format('Y-m-d');
        $resolved = app(SlotIntervalResolver::class)->resolveLoadedCatalogEntry(
            $organization,
            $boat,
            $slot,
            $serviceDate,
        );
        $values = [...$resolved->databaseValues(), 'updated_at' => now()->utc()];

        DB::table('bookings')->where('id', $booking->id)->update($values);
        DB::table('allocations')->where('id', $booking->allocation_id)->update($values);
    }

    private function upsertDemoHold(
        int $organizationId,
        int $boatId,
        int $tripTemplateId,
        string $slotCode,
        string $externalReference,
        CarbonImmutable $serviceDay,
        mixed $now,
    ): void {
        $organization = DB::table('organizations')->find($organizationId);
        $boat = DB::table('boats')->where('organization_id', $organizationId)->find($boatId);
        $slot = DB::table('slot_offerings')
            ->where('organization_id', $organizationId)
            ->where('code', $slotCode)
            ->first();
        $serviceDate = $serviceDay->format('Y-m-d');
        $resolved = app(SlotIntervalResolver::class)->resolveLoadedCatalogEntry(
            $organization,
            $boat,
            $slot,
            $serviceDate,
        );
        $expiresAt = $serviceDay->subDay()->endOfDay()->utc();
        $hold = DB::table('holds')
            ->where('organization_id', $organizationId)
            ->where('external_reference', $externalReference)
            ->first();
        $allocationValues = [
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'allocation_type' => 'HOLD',
            'status' => 'ACTIVE',
            ...$resolved->databaseValues(),
            'updated_at' => $now,
        ];
        $holdValues = [
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'trip_template_id' => $tripTemplateId,
            'external_reference' => $externalReference,
            'status' => 'ACTIVE',
            ...$resolved->databaseValues(),
            'expires_at' => $expiresAt,
            'updated_at' => $now,
        ];

        if (! $hold) {
            $allocationId = DB::table('allocations')->insertGetId([
                ...$allocationValues,
                'created_at' => $now,
            ]);
            $holdId = DB::table('holds')->insertGetId([
                ...$holdValues,
                'allocation_id' => $allocationId,
                'created_at' => $now,
            ]);
            DB::table('allocations')->where('id', $allocationId)->update(['hold_id' => $holdId]);
            DB::table('organizations')->where('id', $organizationId)->increment('inventory_revision');

            return;
        }

        DB::table('holds')->where('id', $hold->id)->update($holdValues);
        DB::table('allocations')->where('id', $hold->allocation_id)->update([
            ...$allocationValues,
            'hold_id' => $hold->id,
        ]);
    }

    private function upsertDemoBlock(
        int $organizationId,
        int $boatId,
        string $externalReference,
        CarbonImmutable $localStart,
        CarbonImmutable $localEnd,
        mixed $now,
    ): void {
        $businessStart = $localStart->utc();
        $businessEnd = $localEnd->utc();
        $serviceDate = $localStart->format('Y-m-d');
        $block = DB::table('blocks')
            ->where('organization_id', $organizationId)
            ->where('external_reference', $externalReference)
            ->first();
        $allocationValues = [
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'allocation_type' => 'BLOCKED',
            'status' => 'ACTIVE',
            'service_date' => $serviceDate,
            'service_start' => $businessStart,
            'service_end' => $businessEnd,
            'business_start' => $businessStart,
            'business_end' => $businessEnd,
            'occupied_start' => $businessStart,
            'occupied_end' => $businessEnd,
            'updated_at' => $now,
        ];
        $blockValues = [
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'external_reference' => $externalReference,
            'status' => 'ACTIVE',
            'reason_code' => 'MAINTENANCE',
            'reason' => 'Fictional demo calendar block',
            'business_start' => $businessStart,
            'business_end' => $businessEnd,
            'occupied_start' => $businessStart,
            'occupied_end' => $businessEnd,
            'released_at' => null,
            'updated_at' => $now,
        ];

        if (! $block) {
            $blockId = DB::table('blocks')->insertGetId([
                ...$blockValues,
                'created_at' => $now,
            ]);
            $allocationId = DB::table('allocations')->insertGetId([
                ...$allocationValues,
                'block_id' => $blockId,
                'created_at' => $now,
            ]);
            DB::table('blocks')->where('id', $blockId)->update(['allocation_id' => $allocationId]);
            DB::table('organizations')->where('id', $organizationId)->increment('inventory_revision');

            return;
        }

        DB::table('blocks')->where('id', $block->id)->update($blockValues);
        DB::table('allocations')->where('id', $block->allocation_id)->update([
            ...$allocationValues,
            'block_id' => $block->id,
        ]);
    }

    private function catalogEntryId(int $organizationId, string $code): ?int
    {
        $id = DB::table('slot_offerings')
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function openingStock(int $organizationId, int $actorId, int $itemId, int $accountId, array $boatIds): void
    {
        $this->stockCommand($organizationId, $actorId, 'demo-opening-stock-water-v1', [
            'external_reference' => 'DEMO-OPENING-STOCK-WATER', 'item_id' => $itemId,
            'cash_account_id' => $accountId, 'movement_type' => 'PURCHASE',
            'occurred_at' => '2026-08-04T00:00:00Z', 'quantity' => '120.000',
            'total_cost_amount_minor' => 120000, 'handled_by' => 'Local fictional demo actor',
            'reason' => 'Fictional local demo opening stock',
        ]);
        foreach ($boatIds as $index => $boatId) {
            $number = $index + 1;
            $this->stockCommand($organizationId, $actorId, 'demo-opening-load-boat-'.$number.'-v1', [
                'external_reference' => 'DEMO-OPENING-LOAD-BOAT-'.$number, 'item_id' => $itemId,
                'boat_id' => $boatId, 'movement_type' => 'LOAD',
                'occurred_at' => '2026-08-04T00:10:00Z', 'quantity' => '20.000',
                'handled_by' => 'Local fictional demo actor',
            ]);
        }
    }

    private function stockCommand(int $organizationId, int $actorId, string $key, array $payload): void
    {
        $request = Request::create('/internal/demo-seed', 'POST', $payload);
        $request->headers->set('Idempotency-Key', $key);
        $request->attributes->set('organization', DB::table('organizations')->find($organizationId));
        $request->attributes->set('api_client_id', $actorId);
        $request->attributes->set('api_client_scopes', ['operations.finance.read', 'operations.finance.write']);
        $response = app(OperationsCostController::class)->recordStockMovement($request);
        if ($response->getStatusCode() !== 201) {
            throw new RuntimeException('Demo opening stock could not be recorded through the stock ledger.');
        }
    }
}
