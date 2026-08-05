<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\Internal\V1\OperationsCostController;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DemoSiteController extends Controller
{
    public function __construct(private readonly OperationsCostController $operationsCosts) {}

    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        $boatNames = config('demo_site.boat_names');
        $boats = DB::table('boats')->where('organization_id', $organization->id)
            ->whereIn('name', $boatNames)->orderBy('name')->get();
        if ($boats->count() !== count($boatNames) || $boats->pluck('name')->sort()->values()->all() !== collect($boatNames)->sort()->values()->all()) {
            abort(404);
        }

        $localNow = CarbonImmutable::now($organization->timezone);
        $localStart = $localNow->startOfDay();
        $localEnd = $localStart->addDays(7);
        $boatIds = $boats->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $schedule = DB::table('allocations as allocations')
            ->leftJoin('bookings as bookings', function ($join): void {
                $join->on('bookings.id', '=', 'allocations.booking_id')
                    ->on('bookings.organization_id', '=', 'allocations.organization_id');
            })->leftJoin('trips as trips', function ($join): void {
                $join->on('trips.booking_id', '=', 'bookings.id')
                    ->on('trips.organization_id', '=', 'allocations.organization_id');
            })->where('allocations.organization_id', $organization->id)
            ->whereIn('allocations.boat_id', $boatIds)->where('allocations.status', 'ACTIVE')
            ->where('allocations.business_start', '>=', $localStart->utc())
            ->where('allocations.business_start', '<', $localEnd->utc())
            ->orderBy('allocations.business_start')
            ->select(['allocations.id', 'allocations.boat_id', 'allocations.allocation_type',
                'allocations.business_start', 'allocations.business_end', 'bookings.external_reference',
                'trips.id as trip_id', 'trips.status as trip_status'])->get()
            ->map(function (object $slot) use ($organization): object {
                $slot->business_start_local = CarbonImmutable::parse($slot->business_start, 'UTC')
                    ->setTimezone($organization->timezone);
                $slot->business_end_local = CarbonImmutable::parse($slot->business_end, 'UTC')
                    ->setTimezone($organization->timezone);

                return $slot;
            });

        $balances = $this->json($this->operationsCosts->stockBalances($this->internalRequest($request)))['balances'] ?? [];
        $cashAccounts = DB::table('cash_accounts')->where('organization_id', $organization->id)
            ->where('status', 'ACTIVE')->orderBy('name')->get();
        $cashActivity = [];
        $activityFrom = $localStart->subDays(6);
        $activityTo = $localStart->addDay();
        foreach ($cashAccounts as $account) {
            $summary = $this->successfulJson($this->operationsCosts->cashAccountDailySummary(
                $this->internalRequest($request, ['date' => $localStart->format('Y-m-d')]),
                (int) $account->id,
            ));
            $activity = $this->successfulJson($this->operationsCosts->cashAccountActivity(
                $this->internalRequest($request, [
                    'from' => $activityFrom->toIso8601String(),
                    'to' => $activityTo->toIso8601String(),
                    'limit' => 200,
                ]),
                (int) $account->id,
            ));
            $postings = collect($activity['postings'] ?? [])->map(function (array $posting) use ($organization): array {
                $posting['occurred_at_local'] = CarbonImmutable::parse($posting['occurred_at'], 'UTC')
                    ->setTimezone($organization->timezone);

                return $posting;
            })->all();
            $compensationByOriginal = [];
            foreach ($postings as $posting) {
                if ($posting['reversal_of_posting_id'] !== null) {
                    $compensationByOriginal[(int) $posting['reversal_of_posting_id']] = (int) $posting['cash_posting_id'];
                }
            }
            $cashActivity[] = [
                'account' => $account,
                'summary' => $summary,
                'activity' => $activity,
                'postings' => $postings,
                'compensation_by_original' => $compensationByOriginal,
            ];
        }
        $dailyCosts = [];
        foreach ($boats as $boat) {
            $internal = $this->internalRequest($request, ['date' => $localStart->format('Y-m-d')]);
            $dailyCosts[(int) $boat->id] = $this->json($this->operationsCosts->boatDailyCostSummary($internal, (int) $boat->id));
        }
        $tripCosts = [];
        foreach ($schedule->pluck('trip_id')->filter()->unique() as $tripId) {
            $tripCosts[(int) $tripId] = $this->json($this->operationsCosts->tripCostSummary($this->internalRequest($request), (int) $tripId));
        }

        $recentFuel = DB::table('fuel_logs as records')
            ->leftJoin('finance_reversals as reversals', function ($join): void {
                $join->on('reversals.original_record_id', '=', 'records.id')
                    ->on('reversals.organization_id', '=', 'records.organization_id')
                    ->where('reversals.original_record_type', '=', 'fuel_log');
            })
            ->where('records.organization_id', $organization->id)
            ->latest('records.id')->limit(10)
            ->get(['records.id', 'records.external_reference', 'records.occurred_at', 'records.liters',
                'records.total_amount_minor', 'records.currency', 'records.status',
                'reversals.reason as reversal_reason', 'reversals.reversed_at',
                'reversals.compensating_stock_movement_id']);

        $recentExpenses = DB::table('expenses as records')
            ->leftJoin('finance_reversals as reversals', function ($join): void {
                $join->on('reversals.original_record_id', '=', 'records.id')
                    ->on('reversals.organization_id', '=', 'records.organization_id')
                    ->where('reversals.original_record_type', '=', 'expense');
            })
            ->where('records.organization_id', $organization->id)
            ->latest('records.id')->limit(10)
            ->get(['records.id', 'records.external_reference', 'records.occurred_at',
                'records.total_amount_minor', 'records.currency', 'records.status',
                'reversals.reason as reversal_reason', 'reversals.reversed_at',
                'reversals.compensating_stock_movement_id']);

        $recentStock = DB::table('stock_movements as records')
            ->leftJoin('finance_reversals as reversals', function ($join): void {
                $join->on('reversals.original_record_id', '=', 'records.id')
                    ->on('reversals.organization_id', '=', 'records.organization_id')
                    ->where('reversals.original_record_type', '=', 'stock_movement');
            })
            ->where('records.organization_id', $organization->id)
            ->latest('records.id')->limit(10)
            ->get(['records.id', 'records.external_reference', 'records.occurred_at',
                'records.movement_type', 'records.quantity', 'records.total_cost_amount_minor',
                'records.currency', 'records.status', 'records.reversal_of_movement_id',
                'reversals.reason as reversal_reason', 'reversals.reversed_at',
                'reversals.compensating_stock_movement_id']);

        foreach ([$recentFuel, $recentExpenses, $recentStock] as $rows) {
            foreach ($rows as $row) {
                $row->occurred_at_local = CarbonImmutable::parse($row->occurred_at, 'UTC')
                    ->setTimezone($organization->timezone);
                $row->reversed_at_local = $row->reversed_at === null ? null : CarbonImmutable::parse($row->reversed_at, 'UTC')
                    ->setTimezone($organization->timezone);
                $row->command_id = (string) Str::uuid();
            }
        }

        return view('demo.index', [
            'organization' => $organization, 'boats' => $boats, 'schedule' => $schedule,
            'balances' => $balances, 'dailyCosts' => $dailyCosts, 'tripCosts' => $tripCosts,
            'cashAccounts' => $cashAccounts, 'cashActivity' => $cashActivity,
            'categories' => DB::table('expense_categories')->where('organization_id', $organization->id)->where('active', true)->orderBy('name')->get(),
            'items' => DB::table('items')->where('organization_id', $organization->id)->where('status', 'ACTIVE')->orderBy('name')->get(),
            'localNow' => $localNow, 'localStart' => $localStart, 'localEnd' => $localEnd,
            'recentFuel' => $recentFuel,
            'recentExpenses' => $recentExpenses,
            'recentStock' => $recentStock,
            'commandIds' => ['fuel' => (string) Str::uuid(), 'expense' => (string) Str::uuid(), 'stock' => (string) Str::uuid()],
        ]);
    }

    public function fuel(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'command_id' => ['required', 'uuid'], 'boat_id' => ['required', 'integer'],
            'trip_id' => ['nullable', 'integer'], 'cash_account_id' => ['required', 'integer'],
            'occurred_at' => ['required', 'string', 'date_format:Y-m-d\TH:i'], 'station_name' => ['required', 'string', 'max:255'],
            'liters' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'price_per_liter_minor' => ['required', 'integer', 'min:0'], 'total_amount_minor' => ['required', 'integer', 'min:0'],
        ]);
        $input['occurred_at'] = $this->utcOccurredAt($input['occurred_at'], $request);
        $input += ['external_reference' => 'DEMO-FUEL-'.$input['command_id'], 'currency' => 'THB', 'handled_by' => 'Local fictional demo actor'];
        unset($input['command_id']);

        return $this->execute($request, $input, fn (Request $internal) => $this->operationsCosts->recordFuelLog($internal), '虚构加油记录已保存。');
    }

    public function expense(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'command_id' => ['required', 'uuid'], 'boat_id' => ['nullable', 'integer'],
            'trip_id' => ['nullable', 'integer'], 'cash_account_id' => ['required', 'integer'],
            'expense_category_id' => ['required', 'integer'], 'occurred_at' => ['required', 'string', 'date_format:Y-m-d\TH:i'],
            'description' => ['required', 'string', 'max:500'], 'amount_minor' => ['required', 'integer', 'min:0'],
        ]);
        $input['occurred_at'] = $this->utcOccurredAt($input['occurred_at'], $request);
        $payload = [
            'external_reference' => 'DEMO-EXPENSE-'.$input['command_id'], 'boat_id' => $input['boat_id'] ?? null,
            'trip_id' => $input['trip_id'] ?? null, 'cash_account_id' => $input['cash_account_id'],
            'occurred_at' => $input['occurred_at'], 'currency' => 'THB', 'handled_by' => 'Local fictional demo actor',
            'lines' => [['expense_category_id' => $input['expense_category_id'], 'description' => $input['description'], 'amount_minor' => $input['amount_minor']]],
        ];

        return $this->execute($request, $payload, fn (Request $internal) => $this->operationsCosts->recordExpense($internal), '虚构分类费用已保存。');
    }

    public function stock(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'command_id' => ['required', 'uuid'], 'item_id' => ['required', 'integer'],
            'boat_id' => ['nullable', 'integer'], 'trip_id' => ['nullable', 'integer'], 'cash_account_id' => ['nullable', 'integer'],
            'movement_type' => ['required', 'in:PURCHASE,LOAD,CONSUME,RETURN,WASTE'],
            'occurred_at' => ['required', 'string', 'date_format:Y-m-d\TH:i'], 'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'total_cost_amount_minor' => ['nullable', 'integer', 'min:0'], 'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $input['occurred_at'] = $this->utcOccurredAt($input['occurred_at'], $request);
        $input['external_reference'] = 'DEMO-STOCK-'.$input['command_id'];
        $input['handled_by'] = 'Local fictional demo actor';
        unset($input['command_id']);

        return $this->execute($request, $input, fn (Request $internal) => $this->operationsCosts->recordStockMovement($internal), '虚构库存流水已保存。');
    }

    public function reverse(Request $request): RedirectResponse
    {
        $input = $request->validate(['command_id' => ['required', 'uuid'], 'original_record_type' => ['required', 'in:fuel_log,expense,stock_movement'], 'original_record_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $id = (int) $input['original_record_id'];
        $payload = [
            'external_reference' => 'DEMO-REVERSAL-'.$input['command_id'],
            'reason' => $input['reason'],
        ];
        $command = match ($input['original_record_type']) {
            'fuel_log' => fn (Request $internal) => $this->operationsCosts->reverseFuelLog($internal, $id),
            'expense' => fn (Request $internal) => $this->operationsCosts->reverseExpense($internal, $id),
            'stock_movement' => fn (Request $internal) => $this->operationsCosts->reverseStockMovement($internal, $id),
        };

        return $this->execute($request, $payload, $command, '虚构记录已冲销。');
    }

    private function utcOccurredAt(string $value, Request $request): string
    {
        $timezone = $request->attributes->get('organization')->timezone;

        try {
            $local = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['occurred_at' => '发生时间格式无效。']);
        }

        if (! $local || $local->format('Y-m-d\TH:i') !== $value) {
            throw ValidationException::withMessages(['occurred_at' => '发生时间格式无效。']);
        }

        return $local->utc()->toIso8601String();
    }

    private function execute(Request $source, array $payload, callable $command, string $message): RedirectResponse
    {
        $internal = $this->internalRequest($source, $payload);
        $internal->headers->set('Idempotency-Key', (string) $source->input('command_id'));
        try {
            $response = $command($internal);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }
        $body = $this->json($response);
        if ($response->getStatusCode() >= 400) {
            return back()->withErrors(['command' => $body['message'] ?? '虚构命令未保存。'])->withInput();
        }

        return redirect()->route('demo.index')->with('status', $message);
    }

    private function internalRequest(Request $source, array $input = []): Request
    {
        $request = Request::create('/internal/demo-command', 'POST', $input);
        $request->attributes->set('organization', $source->attributes->get('organization'));
        $request->attributes->set('api_client_id', $source->attributes->get('api_client_id'));
        $request->attributes->set('api_client_scopes', $source->attributes->get('api_client_scopes'));

        return $request;
    }

    private function successfulJson(mixed $response): array
    {
        if ($response->getStatusCode() !== 200) {
            abort(404);
        }

        return $this->json($response);
    }

    private function json(mixed $response): array
    {
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
