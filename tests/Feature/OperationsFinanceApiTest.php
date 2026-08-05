<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsFinanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_finance_requires_an_explicit_scope(): void
    {
        $organizationId = $this->createOrganization();
        $token = $this->createApiClient($organizationId, ['operations.write']);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/finance/accounts', [
                'external_reference' => 'CASH-THB',
                'name' => 'Fictional cash box',
                'account_type' => 'CASH',
                'currency' => 'THB',
            ])->assertForbidden()->assertJson([
                'code' => 'AUTHORIZATION_FAILED',
                'retryable' => false,
                'manual_action_required' => false,
            ]);

        $this->assertDatabaseCount('cash_accounts', 0);
    }

    public function test_finance_setup_commands_are_idempotent_and_audited(): void
    {
        $organizationId = $this->createOrganization();
        $token = $this->createApiClient($organizationId, ['operations.finance.write']);
        $accountKey = (string) Str::uuid();
        $accountPayload = [
            'external_reference' => 'CASH-THB',
            'name' => 'Fictional cash box',
            'account_type' => 'CASH',
            'currency' => 'THB',
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withToken($token)->withHeader('Idempotency-Key', $accountKey)
                ->postJson('/api/internal/v1/finance/accounts', $accountPayload)
                ->assertCreated()->assertJson([
                    'organization_id' => $organizationId,
                    'external_reference' => 'CASH-THB',
                    'account_type' => 'CASH',
                    'currency' => 'THB',
                    'status' => 'ACTIVE',
                    'code' => 'CASH_ACCOUNT_CREATED',
                ]);
        }

        $conflictingPayload = $accountPayload;
        $conflictingPayload['name'] = 'Different fictional cash box';
        $this->withToken($token)->withHeader('Idempotency-Key', $accountKey)
            ->postJson('/api/internal/v1/finance/accounts', $conflictingPayload)
            ->assertConflict()->assertJson([
                'code' => 'IDEMPOTENCY_CONFLICT',
                'retryable' => false,
                'manual_action_required' => true,
            ]);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/finance/expense-categories', [
                'code' => 'MARINA',
                'name' => 'Fictional marina fee',
                'cost_scope' => 'DIRECT',
            ])->assertCreated()->assertJson([
                'category_code' => 'MARINA',
                'cost_scope' => 'DIRECT',
                'code' => 'EXPENSE_CATEGORY_CREATED',
            ]);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/stock/items', [
                'external_reference' => 'WATER-500ML',
                'name' => 'Fictional water 500 ml',
                'category' => 'BEVERAGE',
                'unit' => 'BOTTLE',
                'currency' => 'THB',
                'minimum_stock_quantity' => '24.000',
            ])->assertCreated()->assertJson([
                'external_reference' => 'WATER-500ML',
                'category' => 'BEVERAGE',
                'minimum_stock_quantity' => '24.000',
                'code' => 'STOCK_ITEM_CREATED',
            ]);

        $this->assertDatabaseCount('cash_accounts', 1);
        $this->assertDatabaseCount('expense_categories', 1);
        $this->assertDatabaseCount('items', 1);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertDatabaseCount('idempotency_keys', 3);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_fuel_log_validates_amount_and_contributes_to_trip_cost(): void
    {
        $organizationId = $this->createOrganization();
        $token = $this->createApiClient($organizationId, ['operations.finance.write']);
        $boatId = $this->createBoat($organizationId);
        $tripId = $this->createTrip($organizationId, $boatId);
        $accountId = $this->createCashAccount($organizationId);
        $payload = [
            'external_reference' => 'FUEL-2026-001',
            'boat_id' => $boatId,
            'trip_id' => $tripId,
            'cash_account_id' => $accountId,
            'occurred_at' => '2026-09-25T13:00:00Z',
            'station_name' => 'Fictional Samui Fuel Pier',
            'liters' => '50.000',
            'price_per_liter_minor' => 3200,
            'total_amount_minor' => 159000,
            'currency' => 'THB',
            'fuel_level_before_percent' => '20.00',
            'fuel_level_after_percent' => '85.00',
            'engine_hours' => '1240.50',
            'handled_by' => 'Fictional Operations One',
            'receipt_reference' => 'receipts/fuel-2026-001.jpg',
        ];

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/fuel-logs', $payload)
            ->assertStatus(422)->assertJson([
                'code' => 'AMOUNT_MISMATCH',
                'retryable' => false,
            ]);
        $this->assertDatabaseCount('fuel_logs', 0);

        $payload['total_amount_minor'] = 160000;
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/fuel-logs', $payload)
            ->assertCreated()->assertJson([
                'organization_id' => $organizationId,
                'boat_id' => $boatId,
                'trip_id' => $tripId,
                'liters' => '50.000',
                'total_amount_minor' => 160000,
                'currency' => 'THB',
                'status' => 'POSTED',
                'code' => 'FUEL_LOG_RECORDED',
            ]);

        $this->withToken($token)->getJson('/api/internal/v1/trips/'.$tripId.'/cost-summary')
            ->assertOk()->assertJson([
                'trip_id' => $tripId,
                'boat_id' => $boatId,
                'costs_by_currency' => [[
                    'currency' => 'THB',
                    'fuel_amount_minor' => 160000,
                    'expense_amount_minor' => 0,
                    'stock_consumption_amount_minor' => 0,
                    'direct_cost_amount_minor' => 160000,
                ]],
                'code' => 'TRIP_COST_SUMMARY',
            ]);
        $this->withToken($token)->getJson('/api/internal/v1/boats/'.$boatId.'/daily-cost-summary?date=2026-09-25')
            ->assertOk()->assertJson([
                'boat_id' => $boatId,
                'business_date' => '2026-09-25',
                'utc_start' => '2026-09-24T17:00:00Z',
                'utc_end' => '2026-09-25T17:00:00Z',
                'costs_by_currency' => [[
                    'currency' => 'THB',
                    'fuel_amount_minor' => 160000,
                    'direct_cost_amount_minor' => 160000,
                ]],
                'code' => 'BOAT_DAILY_COST_SUMMARY',
            ]);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_expense_lines_require_boat_for_direct_cost_and_sum_atomically(): void
    {
        $organizationId = $this->createOrganization();
        $token = $this->createApiClient($organizationId, ['operations.finance.write']);
        $boatId = $this->createBoat($organizationId);
        $tripId = $this->createTrip($organizationId, $boatId);
        $accountId = $this->createCashAccount($organizationId);
        $marinaCategoryId = $this->createExpenseCategory($organizationId, 'MARINA', 'DIRECT');
        $officeCategoryId = $this->createExpenseCategory($organizationId, 'OFFICE', 'COMMON');
        $payload = [
            'external_reference' => 'EXP-2026-001',
            'cash_account_id' => $accountId,
            'occurred_at' => '2026-09-25T13:30:00Z',
            'currency' => 'THB',
            'handled_by' => 'Fictional Operations Two',
            'receipt_reference' => 'receipts/expense-2026-001.jpg',
            'lines' => [[
                'expense_category_id' => $marinaCategoryId,
                'description' => 'Fictional marina fee',
                'amount_minor' => 30000,
            ], [
                'expense_category_id' => $officeCategoryId,
                'description' => 'Fictional printing share',
                'amount_minor' => 5000,
            ]],
        ];

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/expenses', $payload)
            ->assertStatus(422)->assertJson(['code' => 'VALIDATION_FAILED']);
        $this->assertDatabaseCount('expenses', 0);

        $payload['trip_id'] = $tripId;
        $response = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/expenses', $payload)
            ->assertCreated()->assertJson([
                'organization_id' => $organizationId,
                'boat_id' => $boatId,
                'trip_id' => $tripId,
                'line_count' => 2,
                'total_amount_minor' => 35000,
                'currency' => 'THB',
                'status' => 'POSTED',
                'code' => 'EXPENSE_RECORDED',
            ]);

        $this->assertDatabaseHas('expenses', [
            'id' => $response->json('expense_id'),
            'boat_id' => $boatId,
            'trip_id' => $tripId,
            'total_amount_minor' => 35000,
        ]);
        $this->assertDatabaseCount('expense_lines', 2);
        $this->withToken($token)->getJson('/api/internal/v1/trips/'.$tripId.'/cost-summary')
            ->assertOk()
            ->assertJsonPath('costs_by_currency.0.expense_amount_minor', 30000)
            ->assertJsonPath('costs_by_currency.0.direct_cost_amount_minor', 30000);
    }

    public function test_stock_ledger_tracks_purchase_load_consume_and_return(): void
    {
        $organizationId = $this->createOrganization();
        $token = $this->createApiClient($organizationId, ['operations.finance.write']);
        $boatId = $this->createBoat($organizationId);
        $tripId = $this->createTrip($organizationId, $boatId);
        $itemId = $this->createStockItem($organizationId);
        $accountId = $this->createCashAccount($organizationId);

        $purchase = $this->stockMovementPayload('STOCK-PURCHASE-001', $itemId, 'PURCHASE', '100.000');
        $purchase['total_cost_amount_minor'] = 100000;
        $purchase['cash_account_id'] = $accountId;
        $this->postStockMovement($token, $purchase)->assertCreated()->assertJson([
            'movement_type' => 'PURCHASE',
            'quantity' => '100.000',
            'unit_cost_minor' => '1000.000000',
            'total_cost_amount_minor' => 100000,
            'to_location_key' => 'WAREHOUSE',
            'to_balance_quantity' => '100.000',
        ]);

        $load = $this->stockMovementPayload('STOCK-LOAD-001', $itemId, 'LOAD', '30.000');
        $load['boat_id'] = $boatId;
        $load['trip_id'] = $tripId;
        $this->postStockMovement($token, $load)->assertCreated()->assertJson([
            'movement_type' => 'LOAD',
            'from_location_key' => 'WAREHOUSE',
            'to_location_key' => 'BOAT:'.$boatId,
            'from_balance_quantity' => '70.000',
            'to_balance_quantity' => '30.000',
        ]);

        $consume = $this->stockMovementPayload('STOCK-CONSUME-001', $itemId, 'CONSUME', '10.000');
        $consume['boat_id'] = $boatId;
        $consume['trip_id'] = $tripId;
        $consumeKey = (string) Str::uuid();
        $this->postStockMovement($token, $consume, $consumeKey)->assertCreated()->assertJson([
            'movement_type' => 'CONSUME',
            'total_cost_amount_minor' => 10000,
            'from_balance_quantity' => '20.000',
        ]);
        $this->postStockMovement($token, $consume, $consumeKey)->assertCreated();

        $return = $this->stockMovementPayload('STOCK-RETURN-001', $itemId, 'RETURN', '5.000');
        $return['boat_id'] = $boatId;
        $return['trip_id'] = $tripId;
        $this->postStockMovement($token, $return)->assertCreated()->assertJson([
            'movement_type' => 'RETURN',
            'from_balance_quantity' => '15.000',
            'to_balance_quantity' => '75.000',
        ]);

        $warehouse = DB::table('stock_balances')->where('location_key', 'WAREHOUSE')->first();
        $boat = DB::table('stock_balances')->where('location_key', 'BOAT:'.$boatId)->first();
        $this->assertSame(75.0, (float) $warehouse->quantity);
        $this->assertSame(15.0, (float) $boat->quantity);
        $this->assertDatabaseCount('stock_movements', 4);

        $this->withToken($token)->getJson('/api/internal/v1/stock/balances?boat_id='.$boatId)
            ->assertOk()->assertJson([
                'balances' => [[
                    'item_id' => $itemId,
                    'category' => 'BEVERAGE',
                    'currency' => 'THB',
                    'location_key' => 'BOAT:'.$boatId,
                    'location_type' => 'BOAT',
                    'boat_id' => $boatId,
                    'quantity' => '15.000',
                    'average_unit_cost_minor' => '1000.000000',
                    'stock_value_amount_minor' => 15000,
                    'below_minimum' => false,
                ]],
                'code' => 'STOCK_BALANCES',
            ]);

        $this->withToken($token)->getJson('/api/internal/v1/trips/'.$tripId.'/cost-summary')
            ->assertOk()->assertJsonPath('costs_by_currency.0.stock_consumption_amount_minor', 10000)
            ->assertJsonPath('costs_by_currency.0.direct_cost_amount_minor', 10000);
    }

    public function test_stock_ledger_prevents_negative_balance_and_uses_moving_average_cost(): void
    {
        $organizationId = $this->createOrganization();
        $token = $this->createApiClient($organizationId, ['operations.finance.write']);
        $boatId = $this->createBoat($organizationId);
        $tripId = $this->createTrip($organizationId, $boatId);
        $itemId = $this->createStockItem($organizationId);
        $accountId = $this->createCashAccount($organizationId);
        $usdAccountId = $this->createCashAccount($organizationId, 'USD');

        $currencyMismatch = $this->stockMovementPayload('AVG-PURCHASE-CURRENCY-MISMATCH', $itemId, 'PURCHASE', '1.000');
        $currencyMismatch['total_cost_amount_minor'] = 1000;
        $currencyMismatch['cash_account_id'] = $usdAccountId;
        $this->postStockMovement($token, $currencyMismatch)
            ->assertStatus(422)->assertJson(['code' => 'CURRENCY_MISMATCH']);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('stock_movements', 0);

        $emptyLoad = $this->stockMovementPayload('AVG-LOAD-EMPTY', $itemId, 'LOAD', '1.000');
        $emptyLoad['boat_id'] = $boatId;
        $this->postStockMovement($token, $emptyLoad)
            ->assertStatus(409)->assertJson(['code' => 'INSUFFICIENT_STOCK']);
        $this->assertDatabaseCount('stock_balances', 0);

        $firstPurchase = $this->stockMovementPayload('AVG-PURCHASE-001', $itemId, 'PURCHASE', '10.000');
        $firstPurchase['total_cost_amount_minor'] = 10000;
        $firstPurchase['cash_account_id'] = $accountId;
        $this->postStockMovement($token, $firstPurchase)->assertCreated();

        $secondPurchase = $this->stockMovementPayload('AVG-PURCHASE-002', $itemId, 'PURCHASE', '10.000');
        $secondPurchase['total_cost_amount_minor'] = 20000;
        $secondPurchase['cash_account_id'] = $accountId;
        $this->postStockMovement($token, $secondPurchase)->assertCreated()
            ->assertJsonPath('unit_cost_minor', '2000.000000')
            ->assertJsonPath('to_balance_quantity', '20.000');

        $invalidLoad = $this->stockMovementPayload('AVG-LOAD-INVALID', $itemId, 'LOAD', '21.000');
        $invalidLoad['boat_id'] = $boatId;
        $this->postStockMovement($token, $invalidLoad)
            ->assertStatus(409)->assertJson([
                'code' => 'INSUFFICIENT_STOCK',
                'manual_action_required' => true,
            ]);
        $this->assertDatabaseCount('stock_movements', 2);

        $load = $this->stockMovementPayload('AVG-LOAD-001', $itemId, 'LOAD', '4.000');
        $load['boat_id'] = $boatId;
        $load['trip_id'] = $tripId;
        $this->postStockMovement($token, $load)->assertCreated()
            ->assertJsonPath('unit_cost_minor', '1500.000000');

        $consume = $this->stockMovementPayload('AVG-CONSUME-001', $itemId, 'CONSUME', '2.000');
        $consume['boat_id'] = $boatId;
        $consume['trip_id'] = $tripId;
        $this->postStockMovement($token, $consume)->assertCreated()
            ->assertJsonPath('total_cost_amount_minor', 3000);
    }

    public function test_operations_finance_rejects_cross_organization_references(): void
    {
        $organizationA = $this->createOrganization();
        $organizationB = $this->createOrganization();
        $tokenA = $this->createApiClient($organizationA, ['operations.finance.write']);
        $boatA = $this->createBoat($organizationA);
        $accountA = $this->createCashAccount($organizationA);
        $boatB = $this->createBoat($organizationB);
        $accountB = $this->createCashAccount($organizationB);
        $itemB = $this->createStockItem($organizationB);
        $categoryB = $this->createExpenseCategory($organizationB, 'CROSS_ORG', 'DIRECT');

        $this->withToken($tokenA)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/fuel-logs', [
                'external_reference' => 'CROSS-ORG-FUEL',
                'boat_id' => $boatB,
                'cash_account_id' => $accountB,
                'occurred_at' => '2026-09-25T13:00:00Z',
                'station_name' => 'Fictional Cross Org Station',
                'liters' => '1.000',
                'price_per_liter_minor' => 3000,
                'total_amount_minor' => 3000,
                'currency' => 'THB',
                'handled_by' => 'Fictional Operator',
            ])->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);

        $purchase = $this->stockMovementPayload('CROSS-ORG-PURCHASE', $itemB, 'PURCHASE', '1.000');
        $purchase['cash_account_id'] = $accountB;
        $purchase['total_cost_amount_minor'] = 1000;
        $this->postStockMovement($tokenA, $purchase)
            ->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);

        $this->withToken($tokenA)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/expenses', [
                'external_reference' => 'CROSS-ORG-EXPENSE',
                'boat_id' => $boatA,
                'cash_account_id' => $accountA,
                'occurred_at' => '2026-09-25T13:30:00Z',
                'currency' => 'THB',
                'handled_by' => 'Fictional Operator',
                'lines' => [[
                    'expense_category_id' => $categoryB,
                    'description' => 'Fictional cross-org line',
                    'amount_minor' => 1000,
                ]],
            ])->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);

        $this->withToken($tokenA)->getJson('/api/internal/v1/stock/balances?boat_id='.$boatB)
            ->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);

        $this->assertDatabaseCount('fuel_logs', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_fuel_and_expense_reversals_are_idempotent_audited_and_removed_from_costs(): void
    {
        $org = $this->createOrganization();
        $token = $this->createApiClient($org, ['operations.finance.write']);
        $boat = $this->createBoat($org);
        $trip = $this->createTrip($org, $boat);
        $account = $this->createCashAccount($org);
        $category = $this->createExpenseCategory($org, 'REV-DIRECT', 'DIRECT');
        $fuel = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/fuel-logs', [
                'external_reference' => 'REV-FUEL', 'boat_id' => $boat, 'trip_id' => $trip,
                'cash_account_id' => $account, 'occurred_at' => '2026-09-25T13:00:00Z',
                'station_name' => 'Fictional', 'liters' => '1.000', 'price_per_liter_minor' => 1000,
                'total_amount_minor' => 1000, 'currency' => 'THB', 'handled_by' => 'Tester',
            ])->assertCreated();
        $expense = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/internal/v1/expenses', [
                'external_reference' => 'REV-EXP', 'trip_id' => $trip, 'cash_account_id' => $account,
                'occurred_at' => '2026-09-25T13:00:00Z', 'currency' => 'THB', 'handled_by' => 'Tester',
                'lines' => [['expense_category_id' => $category, 'description' => 'Fictional', 'amount_minor' => 2000]],
            ])->assertCreated();
        $reversals = [
            ['/api/internal/v1/fuel-logs/'.$fuel->json('fuel_log_id').':reverse', 'REVERSAL-FUEL'],
            ['/api/internal/v1/expenses/'.$expense->json('expense_id').':reverse', 'REVERSAL-EXP'],
        ];

        foreach ($reversals as [$uri, $reference]) {
            $key = (string) Str::uuid();
            $payload = ['external_reference' => $reference, 'reason' => 'Fictional correction'];
            $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson($uri, $payload)
                ->assertCreated()->assertJson(['status' => 'REVERSED', 'code' => 'FINANCE_RECORD_REVERSED']);
            $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson($uri, $payload)->assertCreated();
        }

        $this->withToken($token)->getJson('/api/internal/v1/trips/'.$trip.'/cost-summary')
            ->assertOk()->assertJsonPath('costs_by_currency', []);
        $this->assertDatabaseCount('finance_reversals', 2);
        $this->assertDatabaseCount('audit_logs', 4);
    }

    public function test_stock_reversal_compensates_all_movement_types_and_cannot_reverse_compensation(): void
    {
        $org = $this->createOrganization();
        $token = $this->createApiClient($org, ['operations.finance.write']);
        $boat = $this->createBoat($org);
        $item = $this->createStockItem($org);
        $account = $this->createCashAccount($org);
        $purchase = $this->stockMovementPayload('R-P', $item, 'PURCHASE', '100.000');
        $purchase['total_cost_amount_minor'] = 100000;
        $purchase['cash_account_id'] = $account;
        $this->postStockMovement($token, $purchase)->assertCreated();
        $cases = [['LOAD', '10.000', $boat], ['CONSUME', '2.000', $boat], ['RETURN', '2.000', $boat], ['WASTE', '1.000', null], ['ADJUSTMENT_IN', '3.000', null], ['ADJUSTMENT_OUT', '1.000', null]];
        $ids = [];
        foreach ($cases as $i => [$type,$qty,$boatId]) {
            $p = $this->stockMovementPayload("R-$type", $item, $type, $qty);
            if ($boatId) {
                $p['boat_id'] = $boatId;
            } if (in_array($type, ['WASTE', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT'])) {
                $p['reason'] = 'Fictional correction';
            } if ($type === 'ADJUSTMENT_IN') {
                $p['total_cost_amount_minor'] = 4500;
            } $ids[] = $this->postStockMovement($token, $p)->assertCreated()->json('movement_id');
        }
        foreach (array_reverse($ids) as $i => $id) {
            $this->reverseFinance($token, 'stock_movement', $id, "RR-$i")->assertCreated()->assertJsonPath('compensation.movement_type', 'REVERSAL');
        }
        $pId = DB::table('stock_movements')->where('external_reference', 'R-P')->value('id');
        $rev = $this->reverseFinance($token, 'stock_movement', $pId, 'RR-P')->assertCreated();
        $this->reverseFinance($token, 'stock_movement', $rev->json('compensation.compensating_movement_id'), 'RR-COMP')->assertStatus(409)->assertJson(['code' => 'REVERSAL_NOT_ALLOWED']);
        $this->assertDatabaseCount('finance_reversals', 7);
    }

    public function test_reversal_public_paths_require_scope_uuid_and_valid_reason_without_writes(): void
    {
        $org = $this->createOrganization();
        $writer = $this->createApiClient($org, ['operations.finance.write']);
        $noScope = $this->createApiClient($org, ['operations.write']);
        $boat = $this->createBoat($org);
        $account = $this->createCashAccount($org);
        $fuelId = $this->recordFuel($writer, $boat, $account, 'GATE-FUEL')->json('fuel_log_id');
        $before = $this->financeState($org);
        $uri = '/api/internal/v1/fuel-logs/'.$fuelId.':reverse';
        $valid = ['external_reference' => 'GATE-REV', 'reason' => 'Valid correction'];
        $this->withToken($noScope)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($uri, $valid)->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);
        $this->withToken($writer)->withHeader('Idempotency-Key', '')->postJson($uri, $valid)->assertStatus(422)->assertJson(['code' => 'VALIDATION_FAILED']);
        foreach (['12345678', 'not-a-real-uuid'] as $key) {
            $this->withToken($writer)->withHeader('Idempotency-Key', $key)->postJson($uri, $valid)->assertStatus(422)->assertJson(['code' => 'VALIDATION_FAILED']);
        }        foreach ([[], ['reason' => ''], ['reason' => 'ab']] as $reason) {
            $this->withToken($writer)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($uri, ['external_reference' => 'GATE-REV', ...$reason])->assertStatus(422)->assertJson(['code' => 'VALIDATION_FAILED']);
        }        $this->assertSame($before, $this->financeState($org));
        $this->assertDatabaseHas('fuel_logs', ['id' => $fuelId, 'status' => 'POSTED']);
    }

    public function test_reversal_cross_organization_ids_return_forbidden_without_leaking_or_writing(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $tokenA = $this->createApiClient($orgA, ['operations.finance.write']);
        $tokenB = $this->createApiClient($orgB, ['operations.finance.write']);
        $boatB = $this->createBoat($orgB);
        $accountB = $this->createCashAccount($orgB);
        $fuelB = $this->recordFuel($tokenB, $boatB, $accountB, 'CROSS-REV-FUEL')->json('fuel_log_id');
        $beforeA = $this->financeState($orgA);
        $beforeB = $this->financeState($orgB);
        $hidden = $this->withToken($tokenA)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/internal/v1/fuel-logs/'.$fuelB.':reverse', ['external_reference' => 'CROSS-REV', 'reason' => 'Cross organization correction'])->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED'])->json();
        $missing = $this->withToken($tokenA)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/internal/v1/fuel-logs/999999:reverse', ['external_reference' => 'CROSS-MISSING', 'reason' => 'Cross organization correction'])->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED'])->json();
        $this->assertSame($missing['code'], $hidden['code']);
        $this->assertSame($missing['message'], $hidden['message']);
        $this->assertSame($beforeA, $this->financeState($orgA));
        $this->assertSame($beforeB, $this->financeState($orgB));
    }

    public function test_each_reversal_endpoint_replays_same_key_and_rejects_changed_payload(): void
    {
        $org = $this->createOrganization();
        $token = $this->createApiClient($org, ['operations.finance.write']);
        $boat = $this->createBoat($org);
        $account = $this->createCashAccount($org);
        $category = $this->createExpenseCategory($org, 'IDEM-REV', 'COMMON');
        $item = $this->createStockItem($org);
        $fuelId = $this->recordFuel($token, $boat, $account, 'IDEM-FUEL')->json('fuel_log_id');
        $expenseId = $this->recordExpense($token, $account, $category, 'IDEM-EXPENSE')->json('expense_id');
        $purchase = $this->stockMovementPayload('IDEM-STOCK', $item, 'PURCHASE', '5.000');
        $purchase['cash_account_id'] = $account;
        $purchase['total_cost_amount_minor'] = 5000;
        $stockId = $this->postStockMovement($token, $purchase)->assertCreated()->json('movement_id');
        $cases = [['fuel_log', '/api/internal/v1/fuel-logs/'.$fuelId.':reverse', $fuelId, 'IDEM-REV-FUEL'], ['expense', '/api/internal/v1/expenses/'.$expenseId.':reverse', $expenseId, 'IDEM-REV-EXPENSE'], ['stock_movement', '/api/internal/v1/stock/movements/'.$stockId.':reverse', $stockId, 'IDEM-REV-STOCK']];
        foreach ($cases as [$type, $uri, $id, $reference]) {
            $key = (string) Str::uuid();
            $payload = ['external_reference' => $reference, 'reason' => 'Idempotent correction'];
            $first = $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson($uri, $payload)->assertCreated()->assertJson(['original_record_type' => $type, 'original_record_id' => $id]);
            $afterFirst = $this->financeState($org);
            $replay = $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson($uri, $payload)->assertCreated();
            $this->assertSame($first->json(), $replay->json());
            $this->assertSame($afterFirst, $this->financeState($org));
            $changed = $payload;
            $changed['reason'] = 'A different correction';
            $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson($uri, $changed)->assertConflict()->assertJson(['code' => 'IDEMPOTENCY_CONFLICT']);
            $this->assertSame($afterFirst, $this->financeState($org));
        }
    }

    public function test_same_numeric_id_and_key_do_not_replay_across_reversal_resources(): void
    {
        $org = $this->createOrganization();
        $token = $this->createApiClient($org, ['operations.finance.write']);
        $boat = $this->createBoat($org);
        $account = $this->createCashAccount($org);
        $category = $this->createExpenseCategory($org, 'IDENTITY', 'COMMON');
        $item = $this->createStockItem($org);
        $fuelId = $this->recordFuel($token, $boat, $account, 'IDENTITY-FUEL')->json('fuel_log_id');
        $expenseId = $this->recordExpense($token, $account, $category, 'IDENTITY-EXPENSE')->json('expense_id');
        $purchase = $this->stockMovementPayload('IDENTITY-STOCK', $item, 'PURCHASE', '2.000');
        $purchase['cash_account_id'] = $account;
        $purchase['total_cost_amount_minor'] = 2000;
        $stockId = $this->postStockMovement($token, $purchase)->assertCreated()->json('movement_id');
        $this->assertSame($fuelId, $expenseId);
        $this->assertSame($fuelId, $stockId);
        $key = (string) Str::uuid();
        $cases = [['fuel_log', '/api/internal/v1/fuel-logs/'.$fuelId.':reverse', $fuelId, 'IDENTITY-REV-FUEL'], ['expense', '/api/internal/v1/expenses/'.$expenseId.':reverse', $expenseId, 'IDENTITY-REV-EXPENSE'], ['stock_movement', '/api/internal/v1/stock/movements/'.$stockId.':reverse', $stockId, 'IDENTITY-REV-STOCK']];
        foreach ($cases as [$type, $uri, $id, $reference]) {
            $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson($uri, ['external_reference' => $reference, 'reason' => 'Same logical payload'])->assertCreated()->assertJson(['original_record_type' => $type, 'original_record_id' => $id]);
            $this->assertDatabaseHas('finance_reversals', ['organization_id' => $org, 'original_record_type' => $type, 'original_record_id' => $id, 'external_reference' => $reference]);
        }        $operations = DB::table('idempotency_keys')->where('organization_id', $org)->where('idempotency_key', $key)->orderBy('operation')->pluck('operation')->all();
        $this->assertCount(3, $operations);
        $this->assertStringContainsString('reverse:expense:'.$expenseId, $operations[0]);
        $this->assertStringContainsString('reverse:fuel_log:'.$fuelId, $operations[1]);
        $this->assertStringContainsString('reverse:stock_movement:'.$stockId, $operations[2]);
    }

    public function test_unique_original_reversal_conflict_maps_stably_and_rolls_back_every_write(): void
    {
        $org = $this->createOrganization();
        $token = $this->createApiClient($org, ['operations.finance.write']);
        $boat = $this->createBoat($org);
        $account = $this->createCashAccount($org);
        $fuelId = $this->recordFuel($token, $boat, $account, 'RACE-FUEL')->json('fuel_log_id');
        $clientId = DB::table('api_clients')->where('token_hash', hash('sha256', $token))->value('id');
        DB::table('finance_reversals')->insert(['organization_id' => $org, 'external_reference' => 'RACE-PREEXISTING', 'original_record_type' => 'fuel_log', 'original_record_id' => $fuelId, 'reason' => 'Simulated concurrent winner', 'reversed_by_api_client_id' => $clientId, 'reversed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->financeState($org);
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/internal/v1/fuel-logs/'.$fuelId.':reverse', ['external_reference' => 'RACE-LOSER', 'reason' => 'Simulated concurrent loser'])->assertConflict()->assertJson(['code' => 'ALREADY_REVERSED']);
        $this->assertSame($before, $this->financeState($org));
        $this->assertDatabaseHas('fuel_logs', ['id' => $fuelId, 'status' => 'POSTED']);
        $this->assertDatabaseMissing('finance_reversals', ['external_reference' => 'RACE-LOSER']);
    }

    public function test_reversing_one_of_two_different_cost_batches_restores_other_batch_average(): void
    {
        $org = $this->createOrganization();
        $token = $this->createApiClient($org, ['operations.finance.write']);
        $item = $this->createStockItem($org);
        $account = $this->createCashAccount($org);
        $first = $this->stockMovementPayload('BATCH-LOW', $item, 'PURCHASE', '10.000');
        $first['cash_account_id'] = $account;
        $first['total_cost_amount_minor'] = 10000;
        $this->postStockMovement($token, $first)->assertCreated();
        $second = $this->stockMovementPayload('BATCH-HIGH', $item, 'PURCHASE', '10.000');
        $second['cash_account_id'] = $account;
        $second['total_cost_amount_minor'] = 20000;
        $secondId = $this->postStockMovement($token, $second)->assertCreated()->json('movement_id');
        $this->assertSame(['WAREHOUSE' => ['quantity' => '20.000', 'average_unit_cost_minor' => '1500.000000']], $this->balanceSnapshot($org, $item, ['WAREHOUSE']));
        $this->reverseFinance($token, 'stock_movement', $secondId, 'BATCH-HIGH-REV')->assertCreated();
        $this->assertSame(['WAREHOUSE' => ['quantity' => '10.000', 'average_unit_cost_minor' => '1000.000000']], $this->balanceSnapshot($org, $item, ['WAREHOUSE']));
    }

    public function test_stock_reversal_insufficient_quantity_rolls_back_complete_transaction(): void
    {
        $org = $this->createOrganization();
        $token = $this->createApiClient($org, ['operations.finance.write']);
        $boat = $this->createBoat($org);
        $item = $this->createStockItem($org);
        $account = $this->createCashAccount($org);
        $purchase = $this->stockMovementPayload('ROLLBACK-Q-P', $item, 'PURCHASE', '10.000');
        $purchase['cash_account_id'] = $account;
        $purchase['total_cost_amount_minor'] = 10000;
        $purchaseId = $this->postStockMovement($token, $purchase)->assertCreated()->json('movement_id');
        $load = $this->stockMovementPayload('ROLLBACK-Q-L', $item, 'LOAD', '1.000');
        $load['boat_id'] = $boat;
        $this->postStockMovement($token, $load)->assertCreated();
        $before = $this->financeState($org);
        $this->reverseFinance($token, 'stock_movement', $purchaseId, 'ROLLBACK-Q-REV')->assertConflict()->assertJson(['code' => 'INSUFFICIENT_STOCK_FOR_REVERSAL']);
        $this->assertSame($before, $this->financeState($org));
        $this->assertDatabaseHas('stock_movements', ['id' => $purchaseId, 'status' => 'POSTED']);
    }

    public function test_stock_reversal_exact_value_conflict_rolls_back_complete_transaction(): void
    {
        $org = $this->createOrganization();
        $token = $this->createApiClient($org, ['operations.finance.write']);
        $item = $this->createStockItem($org);
        $account = $this->createCashAccount($org);
        $high = $this->stockMovementPayload('ROLLBACK-V-HIGH', $item, 'PURCHASE', '10.000');
        $high['cash_account_id'] = $account;
        $high['total_cost_amount_minor'] = 20000;
        $highId = $this->postStockMovement($token, $high)->assertCreated()->json('movement_id');
        $free = $this->stockMovementPayload('ROLLBACK-V-FREE', $item, 'ADJUSTMENT_IN', '10.000');
        $free['total_cost_amount_minor'] = 0;
        $free['reason'] = 'Fictional zero-value correction';
        $this->postStockMovement($token, $free)->assertCreated();
        $out = $this->stockMovementPayload('ROLLBACK-V-OUT', $item, 'ADJUSTMENT_OUT', '10.000');
        $out['reason'] = 'Create exact value conflict';
        $this->postStockMovement($token, $out)->assertCreated();
        $before = $this->financeState($org);
        $this->reverseFinance($token, 'stock_movement', $highId, 'ROLLBACK-V-REV')->assertConflict()->assertJson(['code' => 'STOCK_REVERSAL_VALUE_CONFLICT']);
        $this->assertSame($before, $this->financeState($org));
        $this->assertDatabaseHas('stock_movements', ['id' => $highId, 'status' => 'POSTED']);
    }

    public function test_each_stock_movement_reversal_restores_relevant_balance_snapshot(): void
    {
        foreach (['PURCHASE', 'LOAD', 'CONSUME', 'RETURN', 'WASTE', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT'] as $type) {
            $org = $this->createOrganization();
            $token = $this->createApiClient($org, ['operations.finance.write']);
            $boat = $this->createBoat($org);
            $item = $this->createStockItem($org);
            $account = $this->createCashAccount($org);
            if (! in_array($type, ['PURCHASE', 'ADJUSTMENT_IN'], true)) {
                $seed = $this->stockMovementPayload('SNAP-'.$type.'-SEED', $item, 'PURCHASE', '20.000');
                $seed['cash_account_id'] = $account;
                $seed['total_cost_amount_minor'] = 20000;
                $this->postStockMovement($token, $seed)->assertCreated();
            }            if (in_array($type, ['CONSUME', 'RETURN'], true)) {
                $seedLoad = $this->stockMovementPayload('SNAP-'.$type.'-LOAD', $item, 'LOAD', '10.000');
                $seedLoad['boat_id'] = $boat;
                $this->postStockMovement($token, $seedLoad)->assertCreated();
            }            $payload = $this->stockMovementPayload('SNAP-'.$type, $item, $type, '2.000');
            if (in_array($type, ['LOAD', 'CONSUME', 'RETURN'], true)) {
                $payload['boat_id'] = $boat;
            }            if ($type === 'PURCHASE') {
                $payload['cash_account_id'] = $account;
                $payload['total_cost_amount_minor'] = 2000;
            }            if ($type === 'ADJUSTMENT_IN') {
                $payload['total_cost_amount_minor'] = 3000;
            }            if (in_array($type, ['WASTE', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT'], true)) {
                $payload['reason'] = 'Snapshot correction';
            }            $locations = ['WAREHOUSE', 'BOAT:'.$boat];
            $before = $this->balanceSnapshot($org, $item, $locations);
            $movementId = $this->postStockMovement($token, $payload)->assertCreated()->json('movement_id');
            $afterMovement = $this->balanceSnapshot($org, $item, $locations);
            $this->assertNotSame($before, $afterMovement, $type.' must change a relevant balance');
            $this->reverseFinance($token, 'stock_movement', $movementId, 'SNAP-'.$type.'-REV')->assertCreated()->assertJsonPath('compensation.movement_type', 'REVERSAL');
            $this->assertSame($before, $this->balanceSnapshot($org, $item, $locations), $type.' reversal must restore balances');
            $this->assertDatabaseHas('stock_movements', ['id' => $movementId, 'status' => 'REVERSED']);
        }
    }

    public function test_cash_postings_are_derived_and_creation_replay_does_not_duplicate(): void
    {
        $org = $this->createOrganization();
        $token = $this->createApiClient($org, ['operations.finance.write']);
        $boat = $this->createBoat($org);
        $account = $this->createCashAccount($org);
        $category = $this->createExpenseCategory($org, 'POST_COMMON', 'COMMON');
        $item = $this->createStockItem($org);
        $payload = ['external_reference' => 'POST-FUEL', 'boat_id' => $boat, 'cash_account_id' => $account,
            'occurred_at' => '2026-09-25T13:00:00Z', 'station_name' => 'Fictional station', 'liters' => '1.000',
            'price_per_liter_minor' => 1000, 'total_amount_minor' => 1000, 'currency' => 'THB', 'handled_by' => 'Fictional operator'];
        $key = (string) Str::uuid();
        $fuel = $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/internal/v1/fuel-logs', $payload)->assertCreated();
        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/internal/v1/fuel-logs', $payload)->assertCreated();
        $changed = $payload;
        $changed['notes'] = 'Changed fictional payload';
        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/internal/v1/fuel-logs', $changed)->assertConflict()->assertJson(['code' => 'IDEMPOTENCY_CONFLICT']);
        $expense = $this->recordExpense($token, $account, $category, 'POST-EXPENSE');
        $purchase = $this->stockMovementPayload('POST-PURCHASE', $item, 'PURCHASE', '2.000');
        $purchase['cash_account_id'] = $account;
        $purchase['total_cost_amount_minor'] = 2000;
        $movement = $this->postStockMovement($token, $purchase)->assertCreated();
        $load = $this->stockMovementPayload('POST-LOAD', $item, 'LOAD', '1.000');
        $load['boat_id'] = $boat;
        $this->postStockMovement($token, $load)->assertCreated();

        $this->assertDatabaseCount('cash_postings', 3);
        foreach ([['fuel_log', $fuel->json('fuel_log_id'), 'FUEL', 1000],
            ['expense', $expense->json('expense_id'), 'EXPENSE', 1000],
            ['stock_movement', $movement->json('movement_id'), 'STOCK_PURCHASE', 2000]] as [$type, $id, $kind, $amount]) {
            $this->assertDatabaseHas('cash_postings', ['organization_id' => $org, 'cash_account_id' => $account,
                'source_type' => $type, 'source_id' => $id, 'posting_kind' => $kind, 'direction' => 'OUTFLOW',
                'amount_minor' => $amount, 'currency' => 'THB', 'status' => 'POSTED']);
        }
        $this->assertSame(1, (int) $fuel->json('fuel_log_id'));
        $this->assertSame(1, (int) $expense->json('expense_id'));
        $this->assertSame(1, (int) $movement->json('movement_id'));
    }

    public function test_cash_posting_references_are_bounded_and_traceable_for_maximum_source_and_reversal_references(): void
    {
        $org = $this->createOrganization();
        $token = $this->createApiClient($org, ['operations.finance.write']);
        $boat = $this->createBoat($org);
        $account = $this->createCashAccount($org);
        $category = $this->createExpenseCategory($org, 'BOUNDED_COMMON', 'COMMON');
        $item = $this->createStockItem($org);
        $longReference = str_repeat('X', 255);

        $fuelKey = (string) Str::uuid();
        $fuel = $this->recordFuel($token, $boat, $account, $longReference, $fuelKey);
        $this->recordFuel($token, $boat, $account, $longReference, $fuelKey);
        $expense = $this->recordExpense($token, $account, $category, $longReference);
        $purchase = $this->stockMovementPayload($longReference, $item, 'PURCHASE', '2.000');
        $purchase['cash_account_id'] = $account;
        $purchase['total_cost_amount_minor'] = 2000;
        $movement = $this->postStockMovement($token, $purchase)->assertCreated();

        $sourceRows = DB::table('cash_postings')->where('organization_id', $org)->where('direction', 'OUTFLOW')->orderBy('id')->get();
        $this->assertCount(3, $sourceRows);
        $this->assertCount(3, $sourceRows->unique('external_reference'));
        foreach ([
            ['fuel_log', (int) $fuel->json('fuel_log_id')],
            ['expense', (int) $expense->json('expense_id')],
            ['stock_movement', (int) $movement->json('movement_id')],
        ] as [$sourceType, $sourceId]) {
            $this->assertTrue($sourceRows->contains(fn (object $posting): bool => $posting->source_type === $sourceType
                && (int) $posting->source_id === $sourceId));
        }
        foreach ($sourceRows as $posting) {
            $this->assertLessThanOrEqual(255, strlen($posting->external_reference));
            $this->assertStringStartsWith('cash:v1:SOURCE:', $posting->external_reference);
        }

        $reversalReference = str_repeat('R', 255);
        $compensation = $this->reverseFinance($token, 'fuel_log', $fuel->json('fuel_log_id'), $reversalReference)->assertCreated();
        $compensationPosting = DB::table('cash_postings')->where('id', $compensation->json('cash_compensation_posting_id'))->first();
        $this->assertNotNull($compensationPosting);
        $this->assertLessThanOrEqual(255, strlen($compensationPosting->external_reference));
        $this->assertStringStartsWith('cash:v1:REVERSAL:', $compensationPosting->external_reference);
        $this->assertSame('finance_reversal', $compensationPosting->source_type);
        $this->assertSame((int) DB::table('finance_reversals')->where('external_reference', $reversalReference)->value('id'), (int) $compensationPosting->source_id);
        $this->assertSame((int) $sourceRows[0]->id, (int) $compensationPosting->reversal_of_posting_id);
        $this->assertNotSame($sourceRows[0]->external_reference, $compensationPosting->external_reference);
        $this->assertSame(4, DB::table('cash_postings')->where('organization_id', $org)->count());
        $this->assertNotNull($expense->json('expense_id'));
        $this->assertNotNull($movement->json('movement_id'));
    }

    public function test_cash_reversals_compensate_exactly_and_non_cash_stock_reversal_does_not_post(): void
    {
        CarbonImmutable::setTestNow('2026-09-25T15:00:00Z');
        try {
            $org = $this->createOrganization();
            $token = $this->createApiClient($org, ['operations.finance.write']);
            $boat = $this->createBoat($org);
            $account = $this->createCashAccount($org);
            $category = $this->createExpenseCategory($org, 'REV_COMMON', 'COMMON');
            $item = $this->createStockItem($org);
            $fuel = $this->recordFuel($token, $boat, $account, 'REV-FUEL');
            $expense = $this->recordExpense($token, $account, $category, 'REV-EXPENSE');
            $purchase = $this->stockMovementPayload('REV-PURCHASE', $item, 'PURCHASE', '2.000');
            $purchase['cash_account_id'] = $account;
            $purchase['total_cost_amount_minor'] = 2000;
            $purchaseId = $this->postStockMovement($token, $purchase)->assertCreated()->json('movement_id');
            $load = $this->stockMovementPayload('REV-LOAD', $item, 'LOAD', '1.000');
            $load['boat_id'] = $boat;
            $loadId = $this->postStockMovement($token, $load)->assertCreated()->json('movement_id');
            $this->reverseFinance($token, 'stock_movement', $loadId, 'REV-LOAD-COMP')->assertCreated()
                ->assertJsonPath('cash_compensation_posting_id', null);
            $this->assertDatabaseCount('cash_postings', 3);
            foreach ([['fuel_log', $fuel->json('fuel_log_id'), 'REV-FUEL-COMP'],
                ['expense', $expense->json('expense_id'), 'REV-EXPENSE-COMP'],
                ['stock_movement', $purchaseId, 'REV-PURCHASE-COMP']] as [$type, $id, $reference]) {
                $this->reverseFinance($token, $type, $id, $reference)->assertCreated();
            }
            $rows = DB::table('cash_postings')->where('organization_id', $org)->orderBy('id')->get();
            $this->assertCount(6, $rows);
            foreach ($rows->where('direction', 'OUTFLOW') as $original) {
                $this->assertSame('REVERSED', $original->status);
                $compensation = $rows->firstWhere('reversal_of_posting_id', $original->id);
                $this->assertNotNull($compensation);
                $this->assertSame('INFLOW', $compensation->direction);
                $this->assertSame('REVERSAL', $compensation->posting_kind);
                $this->assertSame((int) $original->amount_minor, (int) $compensation->amount_minor);
            }
            $this->withToken($token)->getJson('/api/internal/v1/finance/accounts/'.$account.'/daily-summary?date=2026-09-25')
                ->assertOk()->assertJson(['total_outflow_minor' => 4000, 'total_inflow_minor' => 4000,
                    'net_change_minor' => 0, 'posting_count' => 6, 'business_timezone' => 'Asia/Bangkok']);
            $activity = $this->withToken($token)->getJson('/api/internal/v1/finance/accounts/'.$account.
                '/activity?from=2026-09-25T12:00:00Z&to=2026-09-25T16:00:00Z&limit=20')->assertOk();
            $this->assertCount(6, $activity->json('postings'));
            $keys = collect($activity->json('postings'))->map(fn (array $row): string => $row['occurred_at'].'-'.$row['cash_posting_id'])->all();
            $sorted = $keys;
            sort($sorted);
            $this->assertSame($sorted, $keys);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_cash_read_is_tenant_isolated_and_bangkok_day_boundary_is_half_open(): void
    {
        $org = $this->createOrganization();
        $otherOrg = $this->createOrganization();
        $readToken = $this->createApiClient($org, ['operations.finance.read']);
        $wrongScope = $this->createApiClient($org, ['operations.write']);
        $boat = $this->createBoat($org);
        $account = $this->createCashAccount($org);
        $other = $this->createCashAccount($otherOrg);
        foreach ([['BOUNDARY-BEFORE', '2026-09-24T16:59:59Z'], ['BOUNDARY-START', '2026-09-24T17:00:00Z'],
            ['BOUNDARY-END-IN', '2026-09-25T16:59:59Z'], ['BOUNDARY-AFTER', '2026-09-25T17:00:00Z']] as [$reference, $at]) {
            $writer = $this->createApiClient($org, ['operations.finance.write']);
            $this->withToken($writer)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/internal/v1/fuel-logs', [
                'external_reference' => $reference, 'boat_id' => $boat, 'cash_account_id' => $account, 'occurred_at' => $at,
                'station_name' => 'Fictional boundary station', 'liters' => '1.000', 'price_per_liter_minor' => 1000,
                'total_amount_minor' => 1000, 'currency' => 'THB', 'handled_by' => 'Fictional operator'])->assertCreated();
        }
        $url = '/api/internal/v1/finance/accounts/'.$account.'/daily-summary?date=2026-09-25';
        $this->withToken($readToken)->getJson($url)->assertOk()->assertJson(['total_outflow_minor' => 2000,
            'total_inflow_minor' => 0, 'net_change_minor' => -2000, 'posting_count' => 2, 'utc_start' => '2026-09-24T17:00:00Z',
            'utc_end' => '2026-09-25T17:00:00Z']);
        $this->withToken($wrongScope)->getJson($url)->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);
        $this->withToken($readToken)->getJson('/api/internal/v1/finance/accounts/'.$other.'/daily-summary?date=2026-09-25')
            ->assertForbidden()->assertJson(['code' => 'AUTHORIZATION_FAILED']);
        $base = '/api/internal/v1/finance/accounts/'.$account.'/activity';
        $this->withToken($readToken)->getJson($base)->assertStatus(422);
        $this->withToken($readToken)->getJson($base.'?from=nope&to=2026-09-25T00:00:00Z')->assertStatus(422);
        $this->withToken($readToken)->getJson($base.'?from=2026-09-25T00:00:00Z&to=2026-09-25T00:00:00Z')->assertStatus(422);
        $this->withToken($readToken)->getJson($base.'?from=2026-01-01T00:00:00Z&to=2026-03-01T00:00:00Z')->assertStatus(422)
            ->assertJson(['code' => 'ACTIVITY_RANGE_TOO_LARGE']);
        $this->withToken($readToken)->getJson($base.'?from=2026-09-24T00:00:00Z&to=2026-09-26T00:00:00Z&limit=501')->assertStatus(422);
    }

    public function test_missing_cash_posting_rolls_back_reversal_business_audit_and_idempotency(): void
    {
        $org = $this->createOrganization();
        $token = $this->createApiClient($org, ['operations.finance.write']);
        $boat = $this->createBoat($org);
        $account = $this->createCashAccount($org);
        $fuel = $this->recordFuel($token, $boat, $account, 'MISSING-POSTING');
        DB::table('cash_postings')->where('source_type', 'fuel_log')->where('source_id', $fuel->json('fuel_log_id'))->delete();
        $before = $this->financeState($org);
        $this->reverseFinance($token, 'fuel_log', $fuel->json('fuel_log_id'), 'MISSING-POSTING-REV')->assertConflict()
            ->assertJson(['code' => 'CASH_POSTING_MISSING']);
        $this->assertSame($before, $this->financeState($org));
        $this->assertDatabaseHas('fuel_logs', ['id' => $fuel->json('fuel_log_id'), 'status' => 'POSTED']);
    }

    private function recordFuel(string $token, int $boatId, int $accountId, string $reference, ?string $idempotencyKey = null): mixed
    {
        return $this->withToken($token)->withHeader('Idempotency-Key', $idempotencyKey ?? (string) Str::uuid())->postJson('/api/internal/v1/fuel-logs', ['external_reference' => $reference, 'boat_id' => $boatId, 'cash_account_id' => $accountId, 'occurred_at' => '2026-09-25T13:00:00Z', 'station_name' => 'Fictional', 'liters' => '1.000', 'price_per_liter_minor' => 1000, 'total_amount_minor' => 1000, 'currency' => 'THB', 'handled_by' => 'Tester'])->assertCreated();
    }

    private function recordExpense(string $token, int $accountId, int $categoryId, string $reference): mixed
    {
        return $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/internal/v1/expenses', ['external_reference' => $reference, 'cash_account_id' => $accountId, 'occurred_at' => '2026-09-25T13:00:00Z', 'currency' => 'THB', 'handled_by' => 'Tester', 'lines' => [['expense_category_id' => $categoryId, 'description' => 'Fictional', 'amount_minor' => 1000]]])->assertCreated();
    }

    private function balanceSnapshot(int $organizationId, int $itemId, array $locations): array
    {
        $snapshot = [];
        foreach ($locations as $location) {
            $row = DB::table('stock_balances')->where('organization_id', $organizationId)->where('item_id', $itemId)->where('location_key', $location)->first();
            $snapshot[$location] = ['quantity' => $row ? number_format((float) $row->quantity, 3, '.', '') : '0.000', 'average_unit_cost_minor' => $row ? number_format((float) $row->average_unit_cost_minor, 6, '.', '') : '0.000000'];
        }

        return $snapshot;
    }

    private function financeState(int $organizationId): array
    {
        $tables = ['fuel_logs', 'expenses', 'expense_lines', 'stock_balances', 'stock_movements', 'cash_postings', 'finance_reversals', 'audit_logs', 'idempotency_keys'];
        $state = [];
        foreach ($tables as $table) {
            $query = DB::table($table);
            if ($table === 'expense_lines') {
                $query->whereIn('expense_id', DB::table('expenses')->where('organization_id', $organizationId)->select('id'));
            } else {
                $query->where('organization_id', $organizationId);
            }            $state[$table] = $query->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $state;
    }

    private function reverseFinance(string $token, string $type, int $id, string $reference): mixed
    {
        $paths = [
            'fuel_log' => '/api/internal/v1/fuel-logs/'.$id.':reverse',
            'expense' => '/api/internal/v1/expenses/'.$id.':reverse',
            'stock_movement' => '/api/internal/v1/stock/movements/'.$id.':reverse',
        ];

        return $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($paths[$type], ['external_reference' => $reference, 'reason' => 'Fictional correction']);
    }

    private function stockMovementPayload(string $reference, int $itemId, string $type, string $quantity): array
    {
        return [
            'external_reference' => $reference,
            'item_id' => $itemId,
            'movement_type' => $type,
            'occurred_at' => '2026-09-25T14:00:00Z',
            'quantity' => $quantity,
            'handled_by' => 'Fictional Storekeeper',
            'receipt_reference' => null,
        ];
    }

    private function postStockMovement(string $token, array $payload, ?string $idempotencyKey = null): mixed
    {
        return $this->withToken($token)
            ->withHeader('Idempotency-Key', $idempotencyKey ?? (string) Str::uuid())
            ->postJson('/api/internal/v1/stock/movements', $payload);
    }

    private function createOrganization(): int
    {
        return DB::table('organizations')->insertGetId([
            'name' => 'Fictional Operations Finance '.Str::random(8),
            'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createApiClient(int $organizationId, array $scopes): string
    {
        $token = Str::random(48);
        DB::table('api_clients')->insert([
            'organization_id' => $organizationId,
            'name' => 'Fictional Finance Client',
            'token_hash' => hash('sha256', $token),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function createBoat(int $organizationId): int
    {
        return DB::table('boats')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Fictional Finance Boat '.Str::random(6),
            'status' => 'ACTIVE',
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTrip(int $organizationId, int $boatId): int
    {
        $templateId = DB::table('trip_templates')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'FINANCE-'.Str::upper(Str::random(8)),
            'name' => 'Fictional Finance Trip',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $allocationId = DB::table('allocations')->insertGetId([
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'allocation_type' => 'BOOKING',
            'status' => 'ACTIVE',
            'business_start' => '2026-09-25 10:00:00',
            'business_end' => '2026-09-25 12:00:00',
            'occupied_start' => '2026-09-25 10:00:00',
            'occupied_end' => '2026-09-25 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bookingId = DB::table('bookings')->insertGetId([
            'organization_id' => $organizationId,
            'boat_id' => $boatId,
            'trip_template_id' => $templateId,
            'external_reference' => 'FINANCE-BOOKING-'.Str::upper(Str::random(8)),
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
            'trip_template_id' => $templateId,
            'status' => 'PLANNED',
            'planned_start' => '2026-09-25 10:00:00',
            'planned_end' => '2026-09-25 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCashAccount(int $organizationId, string $currency = 'THB'): int
    {
        return DB::table('cash_accounts')->insertGetId([
            'organization_id' => $organizationId,
            'external_reference' => 'CASH-'.Str::upper(Str::random(8)),
            'name' => 'Fictional cash account',
            'account_type' => 'CASH',
            'currency' => $currency,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createExpenseCategory(int $organizationId, string $code, string $scope): int
    {
        return DB::table('expense_categories')->insertGetId([
            'organization_id' => $organizationId,
            'code' => $code,
            'name' => 'Fictional '.$code,
            'cost_scope' => $scope,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createStockItem(int $organizationId): int
    {
        return DB::table('items')->insertGetId([
            'organization_id' => $organizationId,
            'external_reference' => 'ITEM-'.Str::upper(Str::random(8)),
            'name' => 'Fictional bottled water',
            'category' => 'BEVERAGE',
            'unit' => 'BOTTLE',
            'currency' => 'THB',
            'minimum_stock_quantity' => '10.000',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
