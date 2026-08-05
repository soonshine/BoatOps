<?php

namespace App\Http\Controllers\Api\Internal\V1;

use App\Exceptions\OperationsFinanceException;
use App\Http\Controllers\Concerns\HandlesInternalCommands;
use App\Http\Controllers\Controller;
use App\Services\OperationsFinance\CashPostingService;
use App\Services\OperationsFinance\StockLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsCostController extends Controller
{
    use HandlesInternalCommands;

    public function __construct(
        private readonly StockLedgerService $stockLedger,
        private readonly CashPostingService $cashPostings,
    ) {}

    public function recordFuelLog(Request $request): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.finance.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations finance.', 403);
        }

        $idempotencyKey = $this->idempotencyKey($request);

        if ($idempotencyKey === null) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'boat_id' => ['required', 'integer'],
            'trip_id' => ['nullable', 'integer'],
            'cash_account_id' => ['required', 'integer'],
            'occurred_at' => ['required', 'date'],
            'station_name' => ['required', 'string', 'max:255'],
            'liters' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'price_per_liter_minor' => ['required', 'integer', 'min:0'],
            'total_amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'fuel_level_before_percent' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'fuel_level_after_percent' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'engine_hours' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'handled_by' => ['required', 'string', 'max:255'],
            'receipt_reference' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = $this->scopedOperation($request, 'recordFuelLog');
        $requestHash = $this->requestHash($input);

        try {
            return DB::transaction(function () use ($request, $organization, $input, $idempotencyKey, $operation, $requestHash): JsonResponse {
                DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
                $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

                if ($replayed) {
                    return $replayed;
                }

                if (DB::table('fuel_logs')
                    ->where('organization_id', $organization->id)
                    ->where('external_reference', $input['external_reference'])
                    ->exists()) {
                    return $this->error('DUPLICATE_EXTERNAL_REFERENCE', 'The fuel log external reference already exists.', 409, true);
                }

                $boat = DB::table('boats')
                    ->where('organization_id', $organization->id)
                    ->where('id', $input['boat_id'])
                    ->first();

                if (! $boat) {
                    return $this->error('AUTHORIZATION_FAILED', 'The requested boat is not accessible.', 403);
                }

                if (isset($input['trip_id'])) {
                    $trip = DB::table('trips')
                        ->where('organization_id', $organization->id)
                        ->where('id', $input['trip_id'])
                        ->first();

                    if (! $trip) {
                        return $this->error('AUTHORIZATION_FAILED', 'The requested trip is not accessible.', 403);
                    }

                    if ((int) $trip->boat_id !== (int) $input['boat_id']) {
                        return $this->error('VALIDATION_FAILED', 'The fuel log boat must match the trip boat.', 422);
                    }
                }

                $account = DB::table('cash_accounts')
                    ->where('organization_id', $organization->id)
                    ->where('status', 'ACTIVE')
                    ->where('id', $input['cash_account_id'])
                    ->first();

                if (! $account) {
                    return $this->error('AUTHORIZATION_FAILED', 'The requested cash account is not accessible.', 403);
                }

                if (! hash_equals($account->currency, $input['currency'])) {
                    return $this->error('CURRENCY_MISMATCH', 'The fuel log currency must match the cash account currency.', 422);
                }

                $expectedTotal = (int) round(
                    (float) $input['liters'] * (int) $input['price_per_liter_minor'],
                    0,
                    PHP_ROUND_HALF_UP,
                );

                if (abs($expectedTotal - (int) $input['total_amount_minor']) > 1) {
                    return $this->error('AMOUNT_MISMATCH', 'The fuel total does not match liters multiplied by price per liter.', 422);
                }

                $now = now()->utc();
                $fuelLogId = DB::table('fuel_logs')->insertGetId([
                    'organization_id' => $organization->id,
                    'external_reference' => $input['external_reference'],
                    'boat_id' => $input['boat_id'],
                    'trip_id' => $input['trip_id'] ?? null,
                    'cash_account_id' => $input['cash_account_id'],
                    'occurred_at' => CarbonImmutable::parse($input['occurred_at'])->utc(),
                    'station_name' => $input['station_name'],
                    'liters' => number_format((float) $input['liters'], 3, '.', ''),
                    'price_per_liter_minor' => $input['price_per_liter_minor'],
                    'total_amount_minor' => $input['total_amount_minor'],
                    'currency' => $input['currency'],
                    'fuel_level_before_percent' => isset($input['fuel_level_before_percent'])
                        ? number_format((float) $input['fuel_level_before_percent'], 2, '.', '')
                        : null,
                    'fuel_level_after_percent' => isset($input['fuel_level_after_percent'])
                        ? number_format((float) $input['fuel_level_after_percent'], 2, '.', '')
                        : null,
                    'engine_hours' => isset($input['engine_hours'])
                        ? number_format((float) $input['engine_hours'], 2, '.', '')
                        : null,
                    'handled_by' => $input['handled_by'],
                    'receipt_reference' => $input['receipt_reference'] ?? null,
                    'notes' => $input['notes'] ?? null,
                    'status' => 'POSTED',
                    'recorded_by_api_client_id' => $request->attributes->get('api_client_id'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->cashPostings->postOutflow(
                    (int) $organization->id,
                    (int) $request->attributes->get('api_client_id'),
                    (int) $input['cash_account_id'],
                    'fuel_log',
                    $fuelLogId,
                    $input['external_reference'],
                    'FUEL',
                    CarbonImmutable::parse($input['occurred_at'])->utc(),
                    (int) $input['total_amount_minor'],
                    $input['currency'],
                    'Fuel log '.$input['external_reference'],
                    CarbonImmutable::instance($now),
                );
                $payload = $this->envelope($request, [
                    'idempotency_key' => $idempotencyKey,
                    'fuel_log_id' => $fuelLogId,
                    'external_reference' => $input['external_reference'],
                    'boat_id' => (int) $input['boat_id'],
                    'trip_id' => isset($input['trip_id']) ? (int) $input['trip_id'] : null,
                    'liters' => number_format((float) $input['liters'], 3, '.', ''),
                    'total_amount_minor' => (int) $input['total_amount_minor'],
                    'currency' => $input['currency'],
                    'status' => 'POSTED',
                    'code' => 'FUEL_LOG_RECORDED',
                ]);
                $this->audit($request, 'fuel_log.recorded', 'fuel_log', $fuelLogId, null, [
                    'external_reference' => $input['external_reference'],
                    'boat_id' => (int) $input['boat_id'],
                    'trip_id' => isset($input['trip_id']) ? (int) $input['trip_id'] : null,
                    'liters' => number_format((float) $input['liters'], 3, '.', ''),
                    'total_amount_minor' => (int) $input['total_amount_minor'],
                    'currency' => $input['currency'],
                    'status' => 'POSTED',
                ], $now);
                $this->storeIdempotency($organization->id, $operation, $idempotencyKey, $requestHash, 201, $payload, $now);

                return response()->json($payload, 201);
            }, 3);
        } catch (OperationsFinanceException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus, $exception->manualActionRequired);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'cash_posting')) {
                return $this->error('CASH_POSTING_CONFLICT', 'The cash posting could not be created consistently.', 409, true);
            }
            throw $exception;
        }
    }

    public function recordExpense(Request $request): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.finance.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations finance.', 403);
        }

        $idempotencyKey = $this->idempotencyKey($request);

        if ($idempotencyKey === null) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'boat_id' => ['nullable', 'integer'],
            'trip_id' => ['nullable', 'integer'],
            'cash_account_id' => ['required', 'integer'],
            'occurred_at' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'handled_by' => ['required', 'string', 'max:255'],
            'receipt_reference' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.expense_category_id' => ['required', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.amount_minor' => ['required', 'integer', 'min:1'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = $this->scopedOperation($request, 'recordExpense');
        $requestHash = $this->requestHash($input);

        try {
            return DB::transaction(function () use ($request, $organization, $input, $idempotencyKey, $operation, $requestHash): JsonResponse {
                DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
                $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

                if ($replayed) {
                    return $replayed;
                }

                if (DB::table('expenses')
                    ->where('organization_id', $organization->id)
                    ->where('external_reference', $input['external_reference'])
                    ->exists()) {
                    return $this->error('DUPLICATE_EXTERNAL_REFERENCE', 'The expense external reference already exists.', 409, true);
                }

                $boatId = isset($input['boat_id']) ? (int) $input['boat_id'] : null;
                $tripId = isset($input['trip_id']) ? (int) $input['trip_id'] : null;

                if ($tripId !== null) {
                    $trip = DB::table('trips')
                        ->where('organization_id', $organization->id)
                        ->where('id', $tripId)
                        ->first();

                    if (! $trip) {
                        return $this->error('AUTHORIZATION_FAILED', 'The requested trip is not accessible.', 403);
                    }

                    if ($boatId !== null && $boatId !== (int) $trip->boat_id) {
                        return $this->error('VALIDATION_FAILED', 'The expense boat must match the trip boat.', 422);
                    }

                    $boatId = (int) $trip->boat_id;
                }

                if ($boatId !== null && ! DB::table('boats')
                    ->where('organization_id', $organization->id)
                    ->where('id', $boatId)
                    ->exists()) {
                    return $this->error('AUTHORIZATION_FAILED', 'The requested boat is not accessible.', 403);
                }

                $account = DB::table('cash_accounts')
                    ->where('organization_id', $organization->id)
                    ->where('status', 'ACTIVE')
                    ->where('id', $input['cash_account_id'])
                    ->first();

                if (! $account) {
                    return $this->error('AUTHORIZATION_FAILED', 'The requested cash account is not accessible.', 403);
                }

                if (! hash_equals($account->currency, $input['currency'])) {
                    return $this->error('CURRENCY_MISMATCH', 'The expense currency must match the cash account currency.', 422);
                }

                $categoryIds = collect($input['lines'])
                    ->pluck('expense_category_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values();
                $categories = DB::table('expense_categories')
                    ->where('organization_id', $organization->id)
                    ->where('active', true)
                    ->whereIn('id', $categoryIds->all())
                    ->get()
                    ->keyBy('id');

                if ($categories->count() !== $categoryIds->count()) {
                    return $this->error('AUTHORIZATION_FAILED', 'One or more expense categories are not accessible.', 403);
                }

                $requiresBoat = $categories->contains(fn (object $category): bool => $category->cost_scope !== 'COMMON');

                if ($requiresBoat && $boatId === null) {
                    return $this->error('VALIDATION_FAILED', 'Direct and boat-daily expenses require a boat or trip.', 422);
                }

                $totalAmountMinor = 0;

                foreach ($input['lines'] as $line) {
                    $totalAmountMinor += (int) $line['amount_minor'];
                }

                $now = now()->utc();
                $expenseId = DB::table('expenses')->insertGetId([
                    'organization_id' => $organization->id,
                    'external_reference' => $input['external_reference'],
                    'boat_id' => $boatId,
                    'trip_id' => $tripId,
                    'cash_account_id' => $input['cash_account_id'],
                    'occurred_at' => CarbonImmutable::parse($input['occurred_at'])->utc(),
                    'total_amount_minor' => $totalAmountMinor,
                    'currency' => $input['currency'],
                    'handled_by' => $input['handled_by'],
                    'receipt_reference' => $input['receipt_reference'] ?? null,
                    'notes' => $input['notes'] ?? null,
                    'status' => 'POSTED',
                    'recorded_by_api_client_id' => $request->attributes->get('api_client_id'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($input['lines'] as $line) {
                    DB::table('expense_lines')->insert([
                        'organization_id' => $organization->id,
                        'expense_id' => $expenseId,
                        'expense_category_id' => $line['expense_category_id'],
                        'description' => $line['description'],
                        'amount_minor' => $line['amount_minor'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $this->cashPostings->postOutflow(
                    (int) $organization->id,
                    (int) $request->attributes->get('api_client_id'),
                    (int) $input['cash_account_id'],
                    'expense',
                    $expenseId,
                    $input['external_reference'],
                    'EXPENSE',
                    CarbonImmutable::parse($input['occurred_at'])->utc(),
                    $totalAmountMinor,
                    $input['currency'],
                    'Expense '.$input['external_reference'],
                    CarbonImmutable::instance($now),
                );
                $payload = $this->envelope($request, [
                    'idempotency_key' => $idempotencyKey,
                    'expense_id' => $expenseId,
                    'external_reference' => $input['external_reference'],
                    'boat_id' => $boatId,
                    'trip_id' => $tripId,
                    'line_count' => count($input['lines']),
                    'total_amount_minor' => $totalAmountMinor,
                    'currency' => $input['currency'],
                    'status' => 'POSTED',
                    'code' => 'EXPENSE_RECORDED',
                ]);
                $this->audit($request, 'expense.recorded', 'expense', $expenseId, null, [
                    'external_reference' => $input['external_reference'],
                    'boat_id' => $boatId,
                    'trip_id' => $tripId,
                    'category_ids' => $categoryIds->all(),
                    'line_count' => count($input['lines']),
                    'total_amount_minor' => $totalAmountMinor,
                    'currency' => $input['currency'],
                    'status' => 'POSTED',
                ], $now);
                $this->storeIdempotency($organization->id, $operation, $idempotencyKey, $requestHash, 201, $payload, $now);

                return response()->json($payload, 201);
            }, 3);
        } catch (OperationsFinanceException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus, $exception->manualActionRequired);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'cash_posting')) {
                return $this->error('CASH_POSTING_CONFLICT', 'The cash posting could not be created consistently.', 409, true);
            }
            throw $exception;
        }
    }

    public function recordStockMovement(Request $request): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.finance.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations finance.', 403);
        }

        $idempotencyKey = $this->idempotencyKey($request);

        if ($idempotencyKey === null) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'item_id' => ['required', 'integer'],
            'boat_id' => ['nullable', 'integer'],
            'trip_id' => ['nullable', 'integer'],
            'cash_account_id' => ['nullable', 'integer'],
            'movement_type' => ['required', 'in:PURCHASE,LOAD,CONSUME,RETURN,WASTE,ADJUSTMENT_IN,ADJUSTMENT_OUT'],
            'occurred_at' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'total_cost_amount_minor' => ['nullable', 'integer', 'min:0'],
            'handled_by' => ['required', 'string', 'max:255'],
            'receipt_reference' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = $this->scopedOperation($request, 'recordStockMovement');
        $requestHash = $this->requestHash($input);

        try {
            return DB::transaction(function () use ($request, $organization, $input, $idempotencyKey, $operation, $requestHash): JsonResponse {
                DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
                $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

                if ($replayed) {
                    return $replayed;
                }

                $now = now()->utc();
                $movement = $this->stockLedger->record(
                    $organization->id,
                    (int) $request->attributes->get('api_client_id'),
                    $input,
                    CarbonImmutable::instance($now),
                );
                if ($movement['movement_type'] === 'PURCHASE') {
                    $this->cashPostings->postOutflow(
                        (int) $organization->id,
                        (int) $request->attributes->get('api_client_id'),
                        (int) $movement['cash_account_id'],
                        'stock_movement',
                        (int) $movement['movement_id'],
                        $movement['external_reference'],
                        'STOCK_PURCHASE',
                        CarbonImmutable::parse($input['occurred_at'])->utc(),
                        (int) $movement['total_cost_amount_minor'],
                        $movement['currency'],
                        'Stock purchase '.$movement['external_reference'],
                        CarbonImmutable::instance($now),
                    );
                }

                $payload = $this->envelope($request, [
                    'idempotency_key' => $idempotencyKey,
                    ...$movement,
                    'code' => 'STOCK_MOVEMENT_RECORDED',
                ]);
                $this->audit($request, 'stock_movement.recorded', 'stock_movement', $movement['movement_id'], null, [
                    'external_reference' => $movement['external_reference'],
                    'item_id' => $movement['item_id'],
                    'boat_id' => $movement['boat_id'],
                    'trip_id' => $movement['trip_id'],
                    'cash_account_id' => $movement['cash_account_id'],
                    'movement_type' => $movement['movement_type'],
                    'quantity' => $movement['quantity'],
                    'total_cost_amount_minor' => $movement['total_cost_amount_minor'],
                    'currency' => $movement['currency'],
                    'status' => 'POSTED',
                ], $now, $input['reason'] ?? null);
                $this->storeIdempotency($organization->id, $operation, $idempotencyKey, $requestHash, 201, $payload, $now);

                return response()->json($payload, 201);
            }, 3);
        } catch (OperationsFinanceException $exception) {
            return $this->error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
                $exception->manualActionRequired,
            );
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'cash_posting')) {
                return $this->error('CASH_POSTING_CONFLICT', 'The cash posting could not be created consistently.', 409, true);
            }
            throw $exception;
        }
    }

    public function reverseFuelLog(Request $request, int $id): JsonResponse
    {
        return $this->reverseFinanceRecord($request, 'fuel_log', 'fuel_logs', $id);
    }

    public function reverseExpense(Request $request, int $id): JsonResponse
    {
        return $this->reverseFinanceRecord($request, 'expense', 'expenses', $id);
    }

    public function reverseStockMovement(Request $request, int $id): JsonResponse
    {
        return $this->reverseFinanceRecord($request, 'stock_movement', 'stock_movements', $id);
    }

    private function reverseFinanceRecord(Request $request, string $type, string $table, int $id): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.finance.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations finance.', 403);
        }

        $idempotencyKey = $this->uuidIdempotencyKey($request);
        if ($idempotencyKey === null) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = $this->scopedOperation($request, 'reverse:'.$type.':'.$id);
        $requestHash = $this->requestHash([
            'original_record_type' => $type,
            'original_record_id' => $id,
            ...$input,
        ]);

        try {
            return DB::transaction(function () use ($request, $organization, $type, $table, $id, $input, $idempotencyKey, $operation, $requestHash): JsonResponse {
                DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
                $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);
                if ($replayed) {
                    return $replayed;
                }

                $original = DB::table($table)->where('organization_id', $organization->id)
                    ->where('id', $id)->lockForUpdate()->first();
                if (! $original) {
                    return $this->error('AUTHORIZATION_FAILED', 'The requested finance record is not accessible.', 403);
                }
                if ($original->status !== 'POSTED') {
                    return $this->error('ALREADY_REVERSED', 'The finance record has already been reversed.', 409, true);
                }
                if (DB::table('finance_reversals')->where('organization_id', $organization->id)
                    ->where('external_reference', $input['external_reference'])->exists()) {
                    return $this->error('DUPLICATE_EXTERNAL_REFERENCE', 'The reversal external reference already exists.', 409, true);
                }

                $now = now()->utc();
                $compensation = null;
                if ($type === 'stock_movement') {
                    $compensation = $this->stockLedger->reverse(
                        $organization->id, (int) $request->attributes->get('api_client_id'),
                        (int) $original->id, $input['external_reference'], $input['reason'],
                        CarbonImmutable::instance($now),
                    );
                } else {
                    DB::table($table)->where('id', $original->id)->update(['status' => 'REVERSED', 'updated_at' => $now]);
                }

                $reversalId = DB::table('finance_reversals')->insertGetId([
                    'organization_id' => $organization->id, 'external_reference' => $input['external_reference'],
                    'original_record_type' => $type, 'original_record_id' => $original->id,
                    'reason' => $input['reason'], 'reversed_by_api_client_id' => $request->attributes->get('api_client_id'),
                    'reversed_at' => $now, 'compensating_stock_movement_id' => $compensation['compensating_movement_id'] ?? null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $cashCompensationId = $this->cashPostings->reverse(
                    (int) $organization->id,
                    (int) $request->attributes->get('api_client_id'),
                    $type,
                    (int) $original->id,
                    $type !== 'stock_movement' || $original->movement_type === 'PURCHASE',
                    isset($original->cash_account_id) ? (int) $original->cash_account_id : null,
                    $type === 'stock_movement' ? (int) $original->total_cost_amount_minor : (int) $original->total_amount_minor,
                    $original->currency,
                    match ($type) {
                        'fuel_log' => 'FUEL',
                        'expense' => 'EXPENSE',
                        default => $original->movement_type === 'PURCHASE' ? 'STOCK_PURCHASE' : null,
                    },
                    $reversalId,
                    $input['external_reference'],
                    CarbonImmutable::instance($now),
                    CarbonImmutable::instance($now),
                );
                $this->audit($request, 'finance.reversed', $type, (int) $original->id,
                    ['status' => 'POSTED'], ['status' => 'REVERSED', 'finance_reversal_id' => $reversalId],
                    $now, $input['reason']);
                $payload = $this->envelope($request, [
                    'idempotency_key' => $idempotencyKey, 'finance_reversal_id' => $reversalId,
                    'original_record_type' => $type, 'original_record_id' => (int) $original->id,
                    'compensation' => $compensation, 'cash_compensation_posting_id' => $cashCompensationId,
                    'status' => 'REVERSED', 'code' => 'FINANCE_RECORD_REVERSED',
                ]);
                $this->storeIdempotency($organization->id, $operation, $idempotencyKey, $requestHash, 201, $payload, $now);

                return response()->json($payload, 201);
            }, 3);
        } catch (OperationsFinanceException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus, $exception->manualActionRequired);
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'cash_posting')) {
                return $this->error('CASH_POSTING_CONFLICT', 'The cash posting reversal could not be created consistently.', 409, true);
            }
            if (str_contains($message, 'finance_reversal_original_unique')
                || (str_contains($message, 'original_record_type') && str_contains($message, 'original_record_id'))) {
                return $this->error('ALREADY_REVERSED', 'The finance record has already been reversed.', 409, true);
            }
            if (str_contains($message, 'finance_reversals_organization_id_external_reference_unique')
                || (str_contains($message, 'organization_id') && str_contains($message, 'external_reference'))) {
                return $this->error('DUPLICATE_EXTERNAL_REFERENCE', 'The reversal external reference already exists.', 409, true);
            }

            throw $exception;
        }
    }

    public function tripCostSummary(Request $request, int $id): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.finance.read', 'operations.finance.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot read operations finance.', 403);
        }

        $organization = $request->attributes->get('organization');
        $trip = DB::table('trips')
            ->where('organization_id', $organization->id)
            ->where('id', $id)
            ->first();

        if (! $trip) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested trip is not accessible.', 403);
        }

        $totals = [];
        $this->mergeTotals(
            $totals,
            DB::table('fuel_logs')
                ->select('currency', DB::raw('SUM(total_amount_minor) as total'))
                ->where('organization_id', $organization->id)
                ->where('trip_id', $id)
                ->where('status', 'POSTED')
                ->groupBy('currency')
                ->get(),
            'fuel_amount_minor',
        );
        $this->mergeTotals(
            $totals,
            DB::table('expenses')
                ->join('expense_lines', 'expense_lines.expense_id', '=', 'expenses.id')
                ->join('expense_categories', 'expense_categories.id', '=', 'expense_lines.expense_category_id')
                ->select('expenses.currency', DB::raw('SUM(expense_lines.amount_minor) as total'))
                ->where('expenses.organization_id', $organization->id)
                ->where('expenses.trip_id', $id)
                ->where('expenses.status', 'POSTED')
                ->where('expense_categories.cost_scope', 'DIRECT')
                ->groupBy('expenses.currency')
                ->get(),
            'expense_amount_minor',
        );
        $this->mergeTotals(
            $totals,
            DB::table('stock_movements')
                ->select('currency', DB::raw('SUM(total_cost_amount_minor) as total'))
                ->where('organization_id', $organization->id)
                ->where('trip_id', $id)
                ->whereIn('movement_type', ['CONSUME', 'WASTE'])
                ->where('status', 'POSTED')
                ->groupBy('currency')
                ->get(),
            'stock_consumption_amount_minor',
        );

        return response()->json($this->envelope($request, [
            'trip_id' => (int) $trip->id,
            'boat_id' => (int) $trip->boat_id,
            'costs_by_currency' => $this->costRows($totals),
            'code' => 'TRIP_COST_SUMMARY',
        ]));
    }

    public function boatDailyCostSummary(Request $request, int $id): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.finance.read', 'operations.finance.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot read operations finance.', 403);
        }

        $input = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $organization = $request->attributes->get('organization');
        $boat = DB::table('boats')
            ->where('organization_id', $organization->id)
            ->where('id', $id)
            ->first();

        if (! $boat) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested boat is not accessible.', 403);
        }

        $localStart = CarbonImmutable::createFromFormat('!Y-m-d', $input['date'], $organization->timezone);

        if (! $localStart) {
            return $this->error('VALIDATION_FAILED', 'The business date is invalid.', 422);
        }

        $utcStart = $localStart->utc();
        $utcEnd = $localStart->addDay()->utc();
        $totals = [];
        $this->mergeTotals(
            $totals,
            DB::table('fuel_logs')
                ->select('currency', DB::raw('SUM(total_amount_minor) as total'))
                ->where('organization_id', $organization->id)
                ->where('boat_id', $id)
                ->where('occurred_at', '>=', $utcStart)
                ->where('occurred_at', '<', $utcEnd)
                ->where('status', 'POSTED')
                ->groupBy('currency')
                ->get(),
            'fuel_amount_minor',
        );
        $this->mergeTotals(
            $totals,
            DB::table('expenses')
                ->join('expense_lines', 'expense_lines.expense_id', '=', 'expenses.id')
                ->join('expense_categories', 'expense_categories.id', '=', 'expense_lines.expense_category_id')
                ->select('expenses.currency', DB::raw('SUM(expense_lines.amount_minor) as total'))
                ->where('expenses.organization_id', $organization->id)
                ->where('expenses.boat_id', $id)
                ->where('expenses.occurred_at', '>=', $utcStart)
                ->where('expenses.occurred_at', '<', $utcEnd)
                ->where('expenses.status', 'POSTED')
                ->whereIn('expense_categories.cost_scope', ['DIRECT', 'BOAT_DAILY'])
                ->groupBy('expenses.currency')
                ->get(),
            'expense_amount_minor',
        );
        $this->mergeTotals(
            $totals,
            DB::table('stock_movements')
                ->select('currency', DB::raw('SUM(total_cost_amount_minor) as total'))
                ->where('organization_id', $organization->id)
                ->where('boat_id', $id)
                ->whereIn('movement_type', ['CONSUME', 'WASTE'])
                ->where('occurred_at', '>=', $utcStart)
                ->where('occurred_at', '<', $utcEnd)
                ->where('status', 'POSTED')
                ->groupBy('currency')
                ->get(),
            'stock_consumption_amount_minor',
        );

        return response()->json($this->envelope($request, [
            'boat_id' => (int) $boat->id,
            'business_date' => $input['date'],
            'utc_start' => $utcStart->format('Y-m-d\TH:i:s\Z'),
            'utc_end' => $utcEnd->format('Y-m-d\TH:i:s\Z'),
            'costs_by_currency' => $this->costRows($totals),
            'code' => 'BOAT_DAILY_COST_SUMMARY',
        ]));
    }

    public function stockBalances(Request $request): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.finance.read', 'operations.finance.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot read operations finance.', 403);
        }

        $input = $request->validate([
            'boat_id' => ['nullable', 'integer'],
            'item_id' => ['nullable', 'integer'],
            'location_type' => ['nullable', 'in:WAREHOUSE,BOAT'],
        ]);
        $organization = $request->attributes->get('organization');

        if (isset($input['boat_id']) && ! DB::table('boats')
            ->where('organization_id', $organization->id)
            ->where('id', $input['boat_id'])
            ->exists()) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested boat is not accessible.', 403);
        }

        $query = DB::table('stock_balances as balances')
            ->join('items', 'items.id', '=', 'balances.item_id')
            ->where('balances.organization_id', $organization->id)
            ->select([
                'balances.item_id',
                'items.external_reference',
                'items.name',
                'items.category',
                'items.unit',
                'items.currency',
                'items.minimum_stock_quantity',
                'balances.location_key',
                'balances.location_type',
                'balances.boat_id',
                'balances.quantity',
                'balances.average_unit_cost_minor',
            ]);

        if (isset($input['boat_id'])) {
            $query->where('balances.boat_id', $input['boat_id']);
        }

        if (isset($input['item_id'])) {
            $query->where('balances.item_id', $input['item_id']);
        }

        if (isset($input['location_type'])) {
            $query->where('balances.location_type', $input['location_type']);
        }

        $balances = $query
            ->orderBy('items.name')
            ->orderBy('balances.location_key')
            ->get()
            ->map(function (object $balance): array {
                $quantity = (float) $balance->quantity;
                $averageUnitCost = (float) $balance->average_unit_cost_minor;

                return [
                    'item_id' => (int) $balance->item_id,
                    'external_reference' => $balance->external_reference,
                    'name' => $balance->name,
                    'category' => $balance->category,
                    'unit' => $balance->unit,
                    'currency' => $balance->currency,
                    'location_key' => $balance->location_key,
                    'location_type' => $balance->location_type,
                    'boat_id' => $balance->boat_id === null ? null : (int) $balance->boat_id,
                    'quantity' => number_format($quantity, 3, '.', ''),
                    'average_unit_cost_minor' => number_format($averageUnitCost, 6, '.', ''),
                    'stock_value_amount_minor' => (int) round($quantity * $averageUnitCost, 0, PHP_ROUND_HALF_UP),
                    'below_minimum' => $quantity < (float) $balance->minimum_stock_quantity,
                ];
            })
            ->values()
            ->all();

        return response()->json($this->envelope($request, [
            'balances' => $balances,
            'code' => 'STOCK_BALANCES',
        ]));
    }

    public function cashAccountActivity(Request $request, int $id): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.finance.read', 'operations.finance.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot read operations finance.', 403);
        }
        $input = $request->validate([
            'from' => ['required', 'string', 'max:40', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/'],
            'to' => ['required', 'string', 'max:40', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $organization = $request->attributes->get('organization');
        $account = DB::table('cash_accounts')->where('organization_id', $organization->id)->where('id', $id)->first();
        if (! $account) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested cash account is not accessible.', 403);
        }

        try {
            $from = CarbonImmutable::parse($input['from'])->utc();
            $to = CarbonImmutable::parse($input['to'])->utc();
        } catch (\Throwable) {
            return $this->error('VALIDATION_FAILED', 'The activity interval must contain valid ISO date-times.', 422);
        }
        if ($from->greaterThanOrEqualTo($to)) {
            return $this->error('VALIDATION_FAILED', 'The activity interval from must be before to.', 422);
        }
        if ($from->addDays(31)->lessThan($to)) {
            return $this->error('ACTIVITY_RANGE_TOO_LARGE', 'The activity interval cannot exceed 31 days.', 422);
        }

        $limit = (int) ($input['limit'] ?? 200);
        $postings = DB::table('cash_postings')->where('organization_id', $organization->id)
            ->where('cash_account_id', $account->id)->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)->orderBy('occurred_at')->orderBy('id')->limit($limit)->get()
            ->map(fn (object $posting): array => $this->cashPostingRow($posting))->all();

        return response()->json($this->envelope($request, [
            'cash_account_id' => (int) $account->id,
            'currency' => $account->currency,
            'from' => $from->format('Y-m-d\TH:i:s\Z'),
            'to' => $to->format('Y-m-d\TH:i:s\Z'),
            'limit' => $limit,
            'postings' => $postings,
            'code' => 'CASH_ACCOUNT_ACTIVITY',
        ]));
    }

    public function cashAccountDailySummary(Request $request, int $id): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.finance.read', 'operations.finance.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot read operations finance.', 403);
        }
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $organization = $request->attributes->get('organization');
        $account = DB::table('cash_accounts')->where('organization_id', $organization->id)->where('id', $id)->first();
        if (! $account) {
            return $this->error('AUTHORIZATION_FAILED', 'The requested cash account is not accessible.', 403);
        }
        $localStart = CarbonImmutable::createFromFormat('!Y-m-d', $input['date'], $organization->timezone);
        if (! $localStart) {
            return $this->error('VALIDATION_FAILED', 'The business date is invalid.', 422);
        }
        $utcStart = $localStart->utc();
        $utcEnd = $localStart->addDay()->utc();
        $totals = DB::table('cash_postings')->selectRaw(
            "COUNT(*) as posting_count, SUM(CASE WHEN direction = 'OUTFLOW' THEN amount_minor ELSE 0 END) as total_outflow, SUM(CASE WHEN direction = 'INFLOW' THEN amount_minor ELSE 0 END) as total_inflow"
        )->where('organization_id', $organization->id)->where('cash_account_id', $account->id)
            ->where('occurred_at', '>=', $utcStart)->where('occurred_at', '<', $utcEnd)->first();
        $outflow = (int) ($totals->total_outflow ?? 0);
        $inflow = (int) ($totals->total_inflow ?? 0);

        return response()->json($this->envelope($request, [
            'cash_account_id' => (int) $account->id,
            'business_date' => $input['date'],
            'utc_start' => $utcStart->format('Y-m-d\TH:i:s\Z'),
            'utc_end' => $utcEnd->format('Y-m-d\TH:i:s\Z'),
            'currency' => $account->currency,
            'total_outflow_minor' => $outflow,
            'total_inflow_minor' => $inflow,
            'net_change_minor' => $inflow - $outflow,
            'posting_count' => (int) ($totals->posting_count ?? 0),
            'code' => 'CASH_ACCOUNT_DAILY_SUMMARY',
        ]));
    }

    private function cashPostingRow(object $posting): array
    {
        return [
            'cash_posting_id' => (int) $posting->id,
            'external_reference' => $posting->external_reference,
            'source' => ['type' => $posting->source_type, 'id' => (int) $posting->source_id],
            'posting_kind' => $posting->posting_kind,
            'direction' => $posting->direction,
            'occurred_at' => CarbonImmutable::parse($posting->occurred_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            'amount_minor' => (int) $posting->amount_minor,
            'currency' => $posting->currency,
            'description' => $posting->description,
            'status' => $posting->status,
            'reversal_of_posting_id' => $posting->reversal_of_posting_id === null ? null : (int) $posting->reversal_of_posting_id,
        ];
    }

    private function mergeTotals(array &$totals, iterable $rows, string $field): void
    {
        foreach ($rows as $row) {
            $currency = $row->currency;
            $totals[$currency] ??= [
                'fuel_amount_minor' => 0,
                'expense_amount_minor' => 0,
                'stock_consumption_amount_minor' => 0,
            ];
            $totals[$currency][$field] += (int) $row->total;
        }
    }

    private function costRows(array $totals): array
    {
        ksort($totals);
        $rows = [];

        foreach ($totals as $currency => $values) {
            $values['direct_cost_amount_minor'] = $values['fuel_amount_minor']
                + $values['expense_amount_minor']
                + $values['stock_consumption_amount_minor'];
            $rows[] = ['currency' => $currency, ...$values];
        }

        return $rows;
    }
}
