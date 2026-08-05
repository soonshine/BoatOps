<?php

namespace App\Http\Controllers\Api\Internal\V1;

use App\Http\Controllers\Concerns\HandlesInternalCommands;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceSetupController extends Controller
{
    use HandlesInternalCommands;

    public function createCashAccount(Request $request): JsonResponse
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
            'name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'in:CASH,BANK,COMPANY_CARD,EMPLOYEE_ADVANCE,OTHER'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = $this->scopedOperation($request, 'createCashAccount');
        $requestHash = $this->requestHash($input);

        return DB::transaction(function () use ($request, $organization, $input, $idempotencyKey, $operation, $requestHash): JsonResponse {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

            if ($replayed) {
                return $replayed;
            }

            if (DB::table('cash_accounts')
                ->where('organization_id', $organization->id)
                ->where('external_reference', $input['external_reference'])
                ->exists()) {
                return $this->error('DUPLICATE_EXTERNAL_REFERENCE', 'The cash account external reference already exists.', 409, true);
            }

            $now = now()->utc();
            $accountId = DB::table('cash_accounts')->insertGetId([
                'organization_id' => $organization->id,
                'external_reference' => $input['external_reference'],
                'name' => $input['name'],
                'account_type' => $input['account_type'],
                'currency' => $input['currency'],
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $payload = $this->envelope($request, [
                'idempotency_key' => $idempotencyKey,
                'cash_account_id' => $accountId,
                'external_reference' => $input['external_reference'],
                'account_type' => $input['account_type'],
                'currency' => $input['currency'],
                'status' => 'ACTIVE',
                'code' => 'CASH_ACCOUNT_CREATED',
            ]);
            $this->audit($request, 'cash_account.created', 'cash_account', $accountId, null, [
                'external_reference' => $input['external_reference'],
                'name' => $input['name'],
                'account_type' => $input['account_type'],
                'currency' => $input['currency'],
                'status' => 'ACTIVE',
            ], $now);
            $this->storeIdempotency($organization->id, $operation, $idempotencyKey, $requestHash, 201, $payload, $now);

            return response()->json($payload, 201);
        }, 3);
    }

    public function createExpenseCategory(Request $request): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.finance.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations finance.', 403);
        }

        $idempotencyKey = $this->idempotencyKey($request);

        if ($idempotencyKey === null) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'cost_scope' => ['required', 'in:DIRECT,BOAT_DAILY,COMMON'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = $this->scopedOperation($request, 'createExpenseCategory');
        $requestHash = $this->requestHash($input);

        return DB::transaction(function () use ($request, $organization, $input, $idempotencyKey, $operation, $requestHash): JsonResponse {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

            if ($replayed) {
                return $replayed;
            }

            if (DB::table('expense_categories')
                ->where('organization_id', $organization->id)
                ->where('code', $input['code'])
                ->exists()) {
                return $this->error('DUPLICATE_EXTERNAL_REFERENCE', 'The expense category code already exists.', 409, true);
            }

            $now = now()->utc();
            $categoryId = DB::table('expense_categories')->insertGetId([
                'organization_id' => $organization->id,
                'code' => $input['code'],
                'name' => $input['name'],
                'cost_scope' => $input['cost_scope'],
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $payload = $this->envelope($request, [
                'idempotency_key' => $idempotencyKey,
                'expense_category_id' => $categoryId,
                'category_code' => $input['code'],
                'cost_scope' => $input['cost_scope'],
                'status' => 'ACTIVE',
                'code' => 'EXPENSE_CATEGORY_CREATED',
            ]);
            $this->audit($request, 'expense_category.created', 'expense_category', $categoryId, null, [
                'code' => $input['code'],
                'name' => $input['name'],
                'cost_scope' => $input['cost_scope'],
                'active' => true,
            ], $now);
            $this->storeIdempotency($organization->id, $operation, $idempotencyKey, $requestHash, 201, $payload, $now);

            return response()->json($payload, 201);
        }, 3);
    }

    public function createStockItem(Request $request): JsonResponse
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
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:BEVERAGE,FOOD,CLEANING,SAFETY,MAINTENANCE,OTHER'],
            'unit' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'minimum_stock_quantity' => ['sometimes', 'numeric', 'min:0', 'decimal:0,3'],
        ]);
        $organization = $request->attributes->get('organization');
        $operation = $this->scopedOperation($request, 'createStockItem');
        $requestHash = $this->requestHash($input);

        return DB::transaction(function () use ($request, $organization, $input, $idempotencyKey, $operation, $requestHash): JsonResponse {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $replayed = $this->replayIdempotency($organization->id, $operation, $idempotencyKey, $requestHash);

            if ($replayed) {
                return $replayed;
            }

            if (DB::table('items')
                ->where('organization_id', $organization->id)
                ->where('external_reference', $input['external_reference'])
                ->exists()) {
                return $this->error('DUPLICATE_EXTERNAL_REFERENCE', 'The stock item external reference already exists.', 409, true);
            }

            $minimumStockQuantity = number_format((float) ($input['minimum_stock_quantity'] ?? 0), 3, '.', '');
            $now = now()->utc();
            $itemId = DB::table('items')->insertGetId([
                'organization_id' => $organization->id,
                'external_reference' => $input['external_reference'],
                'name' => $input['name'],
                'category' => $input['category'],
                'unit' => $input['unit'],
                'currency' => $input['currency'],
                'minimum_stock_quantity' => $minimumStockQuantity,
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $payload = $this->envelope($request, [
                'idempotency_key' => $idempotencyKey,
                'item_id' => $itemId,
                'external_reference' => $input['external_reference'],
                'category' => $input['category'],
                'unit' => $input['unit'],
                'currency' => $input['currency'],
                'minimum_stock_quantity' => $minimumStockQuantity,
                'status' => 'ACTIVE',
                'code' => 'STOCK_ITEM_CREATED',
            ]);
            $this->audit($request, 'stock_item.created', 'item', $itemId, null, [
                'external_reference' => $input['external_reference'],
                'name' => $input['name'],
                'category' => $input['category'],
                'unit' => $input['unit'],
                'currency' => $input['currency'],
                'minimum_stock_quantity' => $minimumStockQuantity,
                'status' => 'ACTIVE',
            ], $now);
            $this->storeIdempotency($organization->id, $operation, $idempotencyKey, $requestHash, 201, $payload, $now);

            return response()->json($payload, 201);
        }, 3);
    }
}
