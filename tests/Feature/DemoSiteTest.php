<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Database\Seeders\DemoSiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoSiteTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'fictional-demo-site-token-for-tests-only';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        putenv('BOATOPS_DEMO_TOKEN');
        parent::tearDown();
    }

    public function test_demo_site_is_closed_by_default(): void
    {
        $this->get('/demo')->assertNotFound();
    }

    public function test_demo_site_is_closed_outside_local_and_testing(): void
    {
        config(['demo_site.enabled' => true]);
        $this->app->detectEnvironment(fn (): string => 'production');
        try {
            $this->get('/demo')->assertNotFound();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_demo_site_fails_closed_without_exact_organization_or_actor(): void
    {
        config(['demo_site.enabled' => true]);
        $this->get('/demo')->assertNotFound();
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => config('demo_site.organization_name'), 'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get('/demo')->assertNotFound();
        DB::table('api_clients')->insert([
            'organization_id' => $organizationId, 'name' => 'Wrong demo actor',
            'token_hash' => hash('sha256', Str::random(48)),
            'scopes' => json_encode(['operations.finance.read', 'operations.finance.write']),
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get('/demo')->assertNotFound();
    }

    public function test_dashboard_reads_only_exact_fictional_organization_and_real_seven_day_schedule(): void
    {
        $this->enableAndSeed();
        $otherId = DB::table('organizations')->insertGetId([
            'name' => 'Other Fictional Organization', 'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('boats')->insert([
            'organization_id' => $otherId, 'name' => 'Isolated Secret Boat', 'status' => 'ACTIVE',
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $purchaseId = (int) DB::table('stock_movements')->where('movement_type', 'PURCHASE')->value('id');
        $response = $this->get('/demo')->assertOk()
            ->assertSee('Plan A（虚构演示船）')->assertSee('Plan B（虚构演示船）')
            ->assertSee('DEMO-PLAN-A-DAY-1')->assertSee('DEMO-PLAN-B-DAY-6')
            ->assertSee('虚构演示 / 非生产数据')->assertDontSee('Isolated Secret Boat')
            ->assertSee('每日现金活动（只读派生）')->assertSee('不可手工编辑')
            ->assertSee('今日流出')->assertSee('今日流入')->assertSee('今日净变动')->assertSee('今日记账笔数')
            ->assertSee('营业日 2026-08-04')->assertSee('THB 1,200.00')
            ->assertSee('流出（OUTFLOW）')->assertSee('库存采购（STOCK_PURCHASE）')
            ->assertSee('库存流水（stock_movement）#'.$purchaseId);
        $this->assertSame(4, substr_count($response->getContent(), 'DEMO-PLAN-'));
        $this->assertStringContainsString('排期时区：<strong>Asia/Bangkok</strong>', $response->getContent());
        $this->assertSame(3, substr_count($response->getContent(), 'value="2026-08-04T08:00"'));
        $response->assertSee('2026-08-05 09:00')->assertDontSee('2026-08-05 02:00');
        $this->assertStringNotContainsString($this->token, $response->getContent());
        $this->assertStringNotContainsString('Bearer '.$this->token, $response->getContent());
    }

    public function test_demo_routes_are_web_csrf_routes_and_html_contains_csrf_fields(): void
    {
        $this->enableAndSeed();
        $route = app('router')->getRoutes()->getByName('demo.fuel');
        $this->assertContains('web', $route->gatherMiddleware());
        $this->get('/demo')->assertOk()->assertSee('name="_token"', false);
        $this->app->detectEnvironment(fn (): string => 'local');
        try {
            $this->post('/demo/fuel', [])->assertStatus(419);
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_fuel_and_expense_commands_succeed_are_idempotent_and_audited(): void
    {
        $this->enableAndSeed();
        $boat = DB::table('boats')->where('name', config('demo_site.boat_names.0'))->first();
        $trip = DB::table('trips')->where('boat_id', $boat->id)->first();
        $accountId = (int) DB::table('cash_accounts')->value('id');
        $categoryId = (int) DB::table('expense_categories')->where('cost_scope', 'DIRECT')->value('id');
        $fuelCommand = (string) Str::uuid();
        $fuel = [
            'command_id' => $fuelCommand, 'boat_id' => $boat->id, 'trip_id' => $trip->id,
            'cash_account_id' => $accountId, 'occurred_at' => '2026-08-05T10:00',
            'station_name' => 'Fictional Test Fuel', 'liters' => '10.000',
            'price_per_liter_minor' => 3500, 'total_amount_minor' => 35000,
        ];
        $this->postDemo('/demo/fuel', $fuel)->assertRedirect('/demo');
        $this->postDemo('/demo/fuel', $fuel)->assertRedirect('/demo');
        $this->assertDatabaseCount('fuel_logs', 1);
        $this->assertDatabaseHas('fuel_logs', ['occurred_at' => '2026-08-05 03:00:00']);

        $this->postDemo('/demo/expenses', [
            'command_id' => (string) Str::uuid(), 'boat_id' => $boat->id, 'trip_id' => $trip->id,
            'cash_account_id' => $accountId, 'expense_category_id' => $categoryId,
            'occurred_at' => '2026-08-05T11:00', 'description' => 'Fictional marina test', 'amount_minor' => 10000,
        ])->assertRedirect('/demo');
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_lines', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fuel_log.recorded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.recorded']);
        $this->get('/demo')->assertOk()->assertSee('THB 450.00');
    }

    public function test_all_five_stock_actions_use_the_ledger_and_idempotent_replay_does_not_duplicate(): void
    {
        $this->enableAndSeed();
        $itemId = (int) DB::table('items')->value('id');
        $accountId = (int) DB::table('cash_accounts')->value('id');
        $boatId = (int) DB::table('boats')->where('name', config('demo_site.boat_names.0'))->value('id');
        $tripId = (int) DB::table('trips')->where('boat_id', $boatId)->value('id');
        $purchase = $this->stockPayload('PURCHASE', $itemId, ['cash_account_id' => $accountId, 'total_cost_amount_minor' => 10000, 'quantity' => '10.000']);
        $this->postDemo('/demo/stock', $purchase)->assertRedirect('/demo');
        $this->postDemo('/demo/stock', $purchase)->assertRedirect('/demo');
        $this->postDemo('/demo/stock', $this->stockPayload('LOAD', $itemId, ['boat_id' => $boatId, 'trip_id' => $tripId, 'quantity' => '8.000']))->assertRedirect('/demo');
        $this->postDemo('/demo/stock', $this->stockPayload('CONSUME', $itemId, ['boat_id' => $boatId, 'trip_id' => $tripId, 'quantity' => '2.000']))->assertRedirect('/demo');
        $this->postDemo('/demo/stock', $this->stockPayload('RETURN', $itemId, ['boat_id' => $boatId, 'trip_id' => $tripId, 'quantity' => '1.000']))->assertRedirect('/demo');
        $this->postDemo('/demo/stock', $this->stockPayload('WASTE', $itemId, ['boat_id' => $boatId, 'trip_id' => $tripId, 'quantity' => '1.000', 'reason' => 'Fictional damaged bottle']))->assertRedirect('/demo');

        $this->assertDatabaseCount('stock_movements', 8);
        $this->assertSame(5, DB::table('stock_movements')->where('external_reference', 'like', 'DEMO-STOCK-%')->count());
        $this->assertSame(8, DB::table('audit_logs')->where('action', 'stock_movement.recorded')->count());
        $this->assertDatabaseHas('stock_balances', ['location_key' => 'BOAT:'.$boatId, 'quantity' => '24.000']);
    }

    public function test_three_cash_backed_demo_commands_add_exact_outflows_and_render_correct_daily_totals(): void
    {
        $this->enableAndSeed();
        CarbonImmutable::setTestNow('2026-08-05 05:00:00 UTC');
        $boatId = (int) DB::table('boats')->where('name', config('demo_site.boat_names.0'))->value('id');
        $accountId = (int) DB::table('cash_accounts')->value('id');
        $categoryId = (int) DB::table('expense_categories')->where('cost_scope', 'DIRECT')->value('id');
        $itemId = (int) DB::table('items')->value('id');

        $this->postDemo('/demo/fuel', $this->fuelPayload($boatId, $accountId, '2026-08-05T10:00', 35000))->assertRedirect('/demo');
        $this->postDemo('/demo/expenses', [
            'command_id' => (string) Str::uuid(), 'boat_id' => $boatId,
            'cash_account_id' => $accountId, 'expense_category_id' => $categoryId,
            'occurred_at' => '2026-08-05T11:00', 'description' => 'Fictional cash dashboard expense',
            'amount_minor' => 10000,
        ])->assertRedirect('/demo');
        $this->postDemo('/demo/stock', $this->stockPayload('PURCHASE', $itemId, [
            'cash_account_id' => $accountId, 'total_cost_amount_minor' => 10000,
            'quantity' => '10.000', 'occurred_at' => '2026-08-05T12:00',
        ]))->assertRedirect('/demo');

        $this->assertDatabaseCount('cash_postings', 4);
        $this->assertSame(3, DB::table('cash_postings')->where('occurred_at', '>=', '2026-08-04 17:00:00')
            ->where('occurred_at', '<', '2026-08-05 17:00:00')->where('direction', 'OUTFLOW')->count());
        $this->assertSame(['EXPENSE', 'FUEL', 'STOCK_PURCHASE'], DB::table('cash_postings')
            ->where('occurred_at', '>=', '2026-08-04 17:00:00')->pluck('posting_kind')->sort()->values()->all());
        $response = $this->get('/demo')->assertOk()->assertSee('营业日 2026-08-05')
            ->assertSee('THB 550.00')->assertSee('今日记账笔数</strong><br>3', false)
            ->assertSee('燃油（FUEL）')->assertSee('费用（EXPENSE）')->assertSee('库存采购（STOCK_PURCHASE）');
        $cashHtml = $this->cashSection($response->getContent());
        $this->assertSame(4, substr_count($cashHtml, '<tr><td>2026-'));
    }

    public function test_cash_and_non_cash_reversals_render_exact_compensation_relationship(): void
    {
        $this->enableAndSeed();
        CarbonImmutable::setTestNow('2026-08-05 05:00:00 UTC');
        $boatId = (int) DB::table('boats')->where('name', config('demo_site.boat_names.0'))->value('id');
        $accountId = (int) DB::table('cash_accounts')->value('id');
        $fuelCommand = (string) Str::uuid();
        $fuelPayload = $this->fuelPayload($boatId, $accountId, '2026-08-05T10:00', 35000);
        $fuelPayload['command_id'] = $fuelCommand;
        $this->postDemo('/demo/fuel', $fuelPayload)->assertRedirect('/demo');
        $fuelId = (int) DB::table('fuel_logs')->where('external_reference', 'DEMO-FUEL-'.$fuelCommand)->value('id');
        $this->postDemo('/demo/reversals', [
            'command_id' => (string) Str::uuid(), 'original_record_type' => 'fuel_log',
            'original_record_id' => $fuelId, 'reason' => 'Fictional cash dashboard correction',
        ])->assertRedirect('/demo');
        $countAfterCashReversal = DB::table('cash_postings')->count();
        $loadId = (int) DB::table('stock_movements')->where('movement_type', 'LOAD')->value('id');
        $this->postDemo('/demo/reversals', [
            'command_id' => (string) Str::uuid(), 'original_record_type' => 'stock_movement',
            'original_record_id' => $loadId, 'reason' => 'Fictional non-cash correction',
        ])->assertRedirect('/demo');

        $this->assertSame(3, $countAfterCashReversal);
        $this->assertSame($countAfterCashReversal, DB::table('cash_postings')->count());
        $original = DB::table('cash_postings')->where('source_type', 'fuel_log')->where('source_id', $fuelId)->first();
        $compensation = DB::table('cash_postings')->where('reversal_of_posting_id', $original->id)->first();
        $this->assertSame('REVERSED', $original->status);
        $this->assertSame('INFLOW', $compensation->direction);
        $this->assertSame((int) $original->amount_minor, (int) $compensation->amount_minor);
        $this->get('/demo')->assertOk()->assertSee('THB 350.00')
            ->assertSee('今日净变动</strong><br>THB 0.00', false)
            ->assertSee('补偿现金流水 #'.$compensation->id)
            ->assertSee('冲销原现金流水 #'.$original->id)
            ->assertSee('已冲销（REVERSED）')->assertSee('冲销补偿（REVERSAL）');
    }

    public function test_cash_dashboard_uses_bangkok_half_open_day_and_seven_day_activity_window(): void
    {
        $this->enableAndSeed();
        CarbonImmutable::setTestNow('2026-09-25 16:30:00 UTC');
        $boatId = (int) DB::table('boats')->where('name', config('demo_site.boat_names.0'))->value('id');
        $accountId = (int) DB::table('cash_accounts')->value('id');
        foreach (['2026-09-24T23:59', '2026-09-25T00:00', '2026-09-25T23:59', '2026-09-26T00:00'] as $occurredAt) {
            $this->postDemo('/demo/fuel', $this->fuelPayload($boatId, $accountId, $occurredAt, 1000))->assertRedirect('/demo');
        }

        $response = $this->get('/demo')->assertOk()->assertSee('营业日 2026-09-25（Asia/Bangkok）')
            ->assertSee('今日流出</strong><br>THB 20.00', false)
            ->assertSee('今日记账笔数</strong><br>2', false);
        $cashHtml = $this->cashSection($response->getContent());
        $this->assertStringContainsString('2026-09-24 23:59:00', $cashHtml);
        $this->assertStringContainsString('2026-09-25 00:00:00', $cashHtml);
        $this->assertStringContainsString('2026-09-25 23:59:00', $cashHtml);
        $this->assertStringNotContainsString('2026-09-26 00:00:00', $cashHtml);
        $this->assertSame(3, substr_count($cashHtml, '<tr><td>2026-'));
    }

    public function test_cash_dashboard_never_renders_another_organization_account_or_posting(): void
    {
        $this->enableAndSeed();
        $otherOrganizationId = DB::table('organizations')->insertGetId([
            'name' => 'Other Cash Dashboard Organization', 'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherAccountId = DB::table('cash_accounts')->insertGetId([
            'organization_id' => $otherOrganizationId, 'external_reference' => 'OTHER-CASH-SECRET',
            'name' => 'Other Secret Cash Account', 'account_type' => 'BANK', 'currency' => 'THB',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cash_postings')->insert([
            'organization_id' => $otherOrganizationId, 'external_reference' => 'OTHER-POSTING-SECRET',
            'cash_account_id' => $otherAccountId, 'source_type' => 'expense', 'source_id' => 999999,
            'posting_kind' => 'EXPENSE', 'direction' => 'OUTFLOW', 'occurred_at' => '2026-08-04 01:00:00',
            'amount_minor' => 987654, 'currency' => 'THB', 'description' => 'Other secret posting',
            'status' => 'POSTED', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('/demo')->assertOk()->assertSee('Fictional THB Cash Box')
            ->assertDontSee('Other Secret Cash Account')->assertDontSee('Other secret posting')
            ->assertDontSee('THB 9,876.54')->assertDontSee('expense）#999999');
    }

    public function test_demo_datetime_rejects_timezone_bearing_input_as_normal_form_validation(): void
    {
        $this->enableAndSeed();
        $boatId = (int) DB::table('boats')->where('name', config('demo_site.boat_names.0'))->value('id');
        $accountId = (int) DB::table('cash_accounts')->value('id');

        $this->postDemo('/demo/fuel', [
            'command_id' => (string) Str::uuid(), 'boat_id' => $boatId,
            'cash_account_id' => $accountId, 'occurred_at' => '2026-08-05T10:00:00+07:00',
            'station_name' => 'Fictional offset station', 'liters' => '10.000',
            'price_per_liter_minor' => 3500, 'total_amount_minor' => 35000,
        ])->assertRedirect('/demo')->assertSessionHasErrors('occurred_at');
        $this->assertDatabaseCount('fuel_logs', 0);
    }

    public function test_validation_and_negative_stock_fail_without_partial_writes(): void
    {
        $this->enableAndSeed();
        $boatId = (int) DB::table('boats')->where('name', config('demo_site.boat_names.0'))->value('id');
        $tripId = (int) DB::table('trips')->where('boat_id', $boatId)->value('id');
        $itemId = (int) DB::table('items')->value('id');
        $accountId = (int) DB::table('cash_accounts')->value('id');
        $movementCount = DB::table('stock_movements')->count();
        $balance = DB::table('stock_balances')->where('location_key', 'WAREHOUSE')->value('quantity');

        $this->postDemo('/demo/fuel', [
            'command_id' => (string) Str::uuid(), 'boat_id' => $boatId, 'trip_id' => $tripId,
            'cash_account_id' => $accountId, 'occurred_at' => '2026-08-05T10:00',
            'station_name' => 'Fictional mismatch station', 'liters' => '10.000',
            'price_per_liter_minor' => 3500, 'total_amount_minor' => 1,
        ])->assertRedirect('/demo')->assertSessionHasErrors('command');
        $this->assertDatabaseCount('fuel_logs', 0);

        $this->postDemo('/demo/stock', $this->stockPayload('LOAD', $itemId, [
            'boat_id' => $boatId, 'trip_id' => $tripId, 'quantity' => '9999.000',
        ]))->assertRedirect('/demo')->assertSessionHasErrors('command');
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame((float) $balance, (float) DB::table('stock_balances')->where('location_key', 'WAREHOUSE')->value('quantity'));
    }

    public function test_recent_ledgers_show_posted_and_reversed_rows_with_inline_csrf_without_secrets(): void
    {
        $this->enableAndSeed();
        $movementId = (int) DB::table('stock_movements')->where('movement_type', 'LOAD')->value('id');
        $commandId = (string) Str::uuid();
        $this->postDemo('/demo/reversals', [
            'command_id' => $commandId, 'original_record_type' => 'stock_movement',
            'original_record_id' => $movementId, 'reason' => 'Fictional stock correction',
        ])->assertRedirect('/demo')->assertSessionHas('status', '虚构记录已冲销。');

        $response = $this->get('/demo')->assertOk()
            ->assertSee('近期燃油流水')->assertSee('近期费用流水')->assertSee('近期库存流水')
            ->assertSee('DEMO-OPENING-STOCK-WATER')->assertSee('POSTED')->assertSee('REVERSED')
            ->assertSee('Fictional stock correction')->assertSee('补偿 movement ID')
            ->assertSee('补偿流水，不可再次冲销')->assertSee('name="_token"', false)
            ->assertSee('name="original_record_id"', false);
        $html = $response->getContent();
        $this->assertStringNotContainsString($this->token, $html);
        preg_match_all('/name="command_id" value="([0-9a-f-]{36})"/', $html, $matches);
        $this->assertGreaterThanOrEqual(5, count($matches[1]));
        $this->assertCount(count($matches[1]), array_unique($matches[1]));
        $this->assertSame(2, substr_count($html, 'value="stock_movement"'));
    }

    public function test_demo_dispatches_all_three_reversals_through_formal_domain_commands(): void
    {
        $this->enableAndSeed();
        $boatId = (int) DB::table('boats')->where('name', config('demo_site.boat_names.0'))->value('id');
        $tripId = (int) DB::table('trips')->where('boat_id', $boatId)->value('id');
        $accountId = (int) DB::table('cash_accounts')->value('id');
        $categoryId = (int) DB::table('expense_categories')->where('cost_scope', 'DIRECT')->value('id');
        $fuelCommand = (string) Str::uuid();
        $expenseCommand = (string) Str::uuid();
        $this->postDemo('/demo/fuel', [
            'command_id' => $fuelCommand, 'boat_id' => $boatId, 'trip_id' => $tripId,
            'cash_account_id' => $accountId, 'occurred_at' => '2026-08-05T10:00',
            'station_name' => 'Fictional reversal fuel', 'liters' => '10.000',
            'price_per_liter_minor' => 3500, 'total_amount_minor' => 35000,
        ])->assertRedirect('/demo');
        $this->postDemo('/demo/expenses', [
            'command_id' => $expenseCommand, 'boat_id' => $boatId, 'trip_id' => $tripId,
            'cash_account_id' => $accountId, 'expense_category_id' => $categoryId,
            'occurred_at' => '2026-08-05T11:00', 'description' => 'Fictional reversal expense',
            'amount_minor' => 10000,
        ])->assertRedirect('/demo');

        $fuelId = (int) DB::table('fuel_logs')->where('external_reference', 'DEMO-FUEL-'.$fuelCommand)->value('id');
        $expenseId = (int) DB::table('expenses')->where('external_reference', 'DEMO-EXPENSE-'.$expenseCommand)->value('id');
        $stockId = (int) DB::table('stock_movements')->where('movement_type', 'LOAD')->where('boat_id', $boatId)->value('id');
        $commands = [
            ['fuel_log', $fuelId, 'Fictional fuel correction'],
            ['expense', $expenseId, 'Fictional expense correction'],
            ['stock_movement', $stockId, 'Fictional stock correction'],
        ];
        foreach ($commands as [$type, $id, $reason]) {
            $payload = ['command_id' => (string) Str::uuid(), 'original_record_type' => $type,
                'original_record_id' => $id, 'reason' => $reason];
            $this->postDemo('/demo/reversals', $payload)->assertRedirect('/demo')->assertSessionHas('status');
            $this->postDemo('/demo/reversals', $payload)->assertRedirect('/demo')->assertSessionHas('status');
        }

        $this->assertDatabaseHas('fuel_logs', ['id' => $fuelId, 'status' => 'REVERSED']);
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'REVERSED']);
        $this->assertDatabaseHas('stock_movements', ['id' => $stockId, 'status' => 'REVERSED']);
        $this->assertDatabaseCount('finance_reversals', 3);
        $compensationId = (int) DB::table('finance_reversals')->where('original_record_type', 'stock_movement')->value('compensating_stock_movement_id');
        $this->assertDatabaseHas('stock_movements', ['id' => $compensationId, 'movement_type' => 'REVERSAL',
            'reversal_of_movement_id' => $stockId, 'status' => 'POSTED']);
        $this->assertDatabaseHas('stock_balances', ['location_key' => 'BOAT:'.$boatId, 'quantity' => '0.000']);
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'finance.reversed')->count());
        $this->assertSame(3, DB::table('idempotency_keys')->where('operation', 'like', '%reverse:%')->count());
        $this->get('/demo')->assertOk()->assertSee('Fictional fuel correction')
            ->assertSee('Fictional expense correction')->assertSee('Fictional stock correction')
            ->assertSee('补偿 movement ID：#'.$compensationId);
    }

    public function test_compensation_and_cross_organization_ids_fail_without_partial_writes(): void
    {
        $this->enableAndSeed();
        $stockId = (int) DB::table('stock_movements')->where('movement_type', 'LOAD')->value('id');
        $this->postDemo('/demo/reversals', ['command_id' => (string) Str::uuid(),
            'original_record_type' => 'stock_movement', 'original_record_id' => $stockId,
            'reason' => 'Fictional first correction'])->assertRedirect('/demo');
        $compensationId = (int) DB::table('finance_reversals')->value('compensating_stock_movement_id');
        $snapshot = [DB::table('finance_reversals')->count(), DB::table('stock_movements')->count(),
            DB::table('audit_logs')->count(), DB::table('idempotency_keys')->count()];

        $this->postDemo('/demo/reversals', ['command_id' => (string) Str::uuid(),
            'original_record_type' => 'stock_movement', 'original_record_id' => $compensationId,
            'reason' => 'Fictional forbidden compensation'])->assertRedirect('/demo')->assertSessionHasErrors('command');
        $this->assertSame($snapshot, [DB::table('finance_reversals')->count(), DB::table('stock_movements')->count(),
            DB::table('audit_logs')->count(), DB::table('idempotency_keys')->count()]);

        $otherOrganizationId = DB::table('organizations')->insertGetId([
            'name' => 'Other isolated fictional organization', 'timezone' => 'Asia/Bangkok',
            'inventory_revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $crossId = DB::table('fuel_logs')->insertGetId([
            'organization_id' => $otherOrganizationId, 'external_reference' => 'OTHER-FICTIONAL-FUEL',
            'boat_id' => DB::table('boats')->value('id'), 'cash_account_id' => DB::table('cash_accounts')->value('id'),
            'occurred_at' => now(), 'station_name' => 'Other fictional station', 'liters' => '1.000',
            'price_per_liter_minor' => 100, 'total_amount_minor' => 100, 'currency' => 'THB',
            'handled_by' => 'Other fictional actor', 'status' => 'POSTED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postDemo('/demo/reversals', ['command_id' => (string) Str::uuid(),
            'original_record_type' => 'fuel_log', 'original_record_id' => $crossId,
            'reason' => 'Fictional cross organization attempt'])->assertRedirect('/demo')->assertSessionHasErrors('command');
        $this->assertDatabaseHas('fuel_logs', ['id' => $crossId, 'status' => 'POSTED']);
        $this->assertSame($snapshot, [DB::table('finance_reversals')->count(), DB::table('stock_movements')->count(),
            DB::table('audit_logs')->count(), DB::table('idempotency_keys')->count()]);
    }

    private function fuelPayload(int $boatId, int $accountId, string $occurredAt, int $amountMinor): array
    {
        return [
            'command_id' => (string) Str::uuid(), 'boat_id' => $boatId, 'cash_account_id' => $accountId,
            'occurred_at' => $occurredAt, 'station_name' => 'Fictional cash dashboard fuel',
            'liters' => '1.000', 'price_per_liter_minor' => $amountMinor, 'total_amount_minor' => $amountMinor,
        ];
    }

    private function cashSection(string $html): string
    {
        $start = strpos($html, '<section class="card" id="cash-activity">');
        $end = strpos($html, '<section class="card"><h2>近期燃油流水</h2>', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function postDemo(string $uri, array $payload): mixed
    {
        $token = 'fictional-csrf-token';

        return $this->from('/demo')->withSession(['_token' => $token])
            ->post($uri, ['_token' => $token, ...$payload]);
    }

    private function enableAndSeed(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 01:00:00 UTC');
        config(['demo_site.enabled' => true]);
        putenv('BOATOPS_DEMO_TOKEN='.$this->token);
        $this->seed(DemoSiteSeeder::class);
    }

    private function stockPayload(string $type, int $itemId, array $extra = []): array
    {
        return [
            'command_id' => (string) Str::uuid(), 'item_id' => $itemId, 'movement_type' => $type,
            'occurred_at' => '2026-08-05T12:00', 'quantity' => '1.000',
            ...$extra,
        ];
    }
}
