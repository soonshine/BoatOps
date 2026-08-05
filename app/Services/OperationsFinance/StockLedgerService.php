<?php

namespace App\Services\OperationsFinance;

use App\Exceptions\OperationsFinanceException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class StockLedgerService
{
    public function record(int $organizationId, int $apiClientId, array $input, CarbonImmutable $now): array
    {
        $item = DB::table('items')
            ->where('organization_id', $organizationId)
            ->where('status', 'ACTIVE')
            ->where('id', $input['item_id'])
            ->lockForUpdate()
            ->first();

        if (! $item) {
            throw new OperationsFinanceException(
                'AUTHORIZATION_FAILED',
                'The requested stock item is not accessible.',
                403,
                false,
            );
        }

        if (DB::table('stock_movements')
            ->where('organization_id', $organizationId)
            ->where('external_reference', $input['external_reference'])
            ->exists()) {
            throw new OperationsFinanceException(
                'DUPLICATE_EXTERNAL_REFERENCE',
                'The stock movement external reference already exists.',
            );
        }

        $movementType = $input['movement_type'];
        $boatId = isset($input['boat_id']) ? (int) $input['boat_id'] : null;
        $tripId = isset($input['trip_id']) ? (int) $input['trip_id'] : null;
        $cashAccountId = isset($input['cash_account_id']) ? (int) $input['cash_account_id'] : null;

        if ($tripId !== null) {
            if (in_array($movementType, ['PURCHASE', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT'], true)) {
                throw new OperationsFinanceException(
                    'VALIDATION_FAILED',
                    'This stock movement type cannot be assigned to a trip.',
                    422,
                    false,
                );
            }

            $trip = DB::table('trips')
                ->where('organization_id', $organizationId)
                ->where('id', $tripId)
                ->first();

            if (! $trip) {
                throw new OperationsFinanceException(
                    'AUTHORIZATION_FAILED',
                    'The requested trip is not accessible.',
                    403,
                    false,
                );
            }

            if ($boatId !== null && $boatId !== (int) $trip->boat_id) {
                throw new OperationsFinanceException(
                    'VALIDATION_FAILED',
                    'The stock movement boat must match the trip boat.',
                    422,
                    false,
                );
            }

            $boatId = (int) $trip->boat_id;
        }

        if ($boatId !== null && ! DB::table('boats')
            ->where('organization_id', $organizationId)
            ->where('id', $boatId)
            ->exists()) {
            throw new OperationsFinanceException(
                'AUTHORIZATION_FAILED',
                'The requested boat is not accessible.',
                403,
                false,
            );
        }

        if (in_array($movementType, ['LOAD', 'CONSUME', 'RETURN'], true) && $boatId === null) {
            throw new OperationsFinanceException(
                'VALIDATION_FAILED',
                'This stock movement type requires a boat.',
                422,
                false,
            );
        }

        if ($movementType === 'PURCHASE' && $boatId !== null) {
            throw new OperationsFinanceException(
                'VALIDATION_FAILED',
                'Purchases enter the warehouse before being loaded onto a boat.',
                422,
                false,
            );
        }

        if ($movementType === 'PURCHASE' && $cashAccountId === null) {
            throw new OperationsFinanceException(
                'VALIDATION_FAILED',
                'Purchases require a cash account.',
                422,
                false,
            );
        }

        if ($movementType !== 'PURCHASE' && $cashAccountId !== null) {
            throw new OperationsFinanceException(
                'VALIDATION_FAILED',
                'A cash account is accepted only for stock purchases.',
                422,
                false,
            );
        }

        if ($cashAccountId !== null) {
            $cashAccount = DB::table('cash_accounts')
                ->where('organization_id', $organizationId)
                ->where('status', 'ACTIVE')
                ->where('id', $cashAccountId)
                ->first();

            if (! $cashAccount) {
                throw new OperationsFinanceException(
                    'AUTHORIZATION_FAILED',
                    'The requested cash account is not accessible.',
                    403,
                    false,
                );
            }

            if (! hash_equals($cashAccount->currency, $item->currency)) {
                throw new OperationsFinanceException(
                    'CURRENCY_MISMATCH',
                    'The stock purchase currency must match the cash account currency.',
                    422,
                    false,
                );
            }
        }

        if (in_array($movementType, ['WASTE', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT'], true)
            && empty($input['reason'])) {
            throw new OperationsFinanceException(
                'VALIDATION_FAILED',
                'A reason is required for waste and stock adjustments.',
                422,
                false,
            );
        }

        $quantity = (float) $input['quantity'];
        $providedTotalCost = array_key_exists('total_cost_amount_minor', $input)
            && $input['total_cost_amount_minor'] !== null
                ? (int) $input['total_cost_amount_minor']
                : null;

        if (in_array($movementType, ['PURCHASE', 'ADJUSTMENT_IN'], true) && $providedTotalCost === null) {
            throw new OperationsFinanceException(
                'VALIDATION_FAILED',
                'A total cost amount is required for incoming stock.',
                422,
                false,
            );
        }

        if (! in_array($movementType, ['PURCHASE', 'ADJUSTMENT_IN'], true) && $providedTotalCost !== null) {
            throw new OperationsFinanceException(
                'VALIDATION_FAILED',
                'The total cost is calculated from the source moving-average cost for this movement type.',
                422,
                false,
            );
        }

        $warehouseKey = 'WAREHOUSE';
        $boatKey = $boatId === null ? null : 'BOAT:'.$boatId;
        $fromKey = null;
        $toKey = null;
        $fromBalanceQuantity = null;
        $toBalanceQuantity = null;
        $unitCostMinor = 0.0;

        if ($movementType === 'PURCHASE') {
            $toKey = $warehouseKey;
            $unitCostMinor = $providedTotalCost / $quantity;
            $toBalance = $this->creditBalance(
                $organizationId,
                (int) $item->id,
                $warehouseKey,
                null,
                $quantity,
                $unitCostMinor,
                $now,
            );
            $toBalanceQuantity = (float) $toBalance->quantity;
        } elseif ($movementType === 'LOAD') {
            $fromKey = $warehouseKey;
            $toKey = $boatKey;
            $source = $this->debitBalance($organizationId, (int) $item->id, $warehouseKey, null, $quantity, $now);
            $unitCostMinor = $source['unit_cost_minor'];
            $fromBalanceQuantity = $source['quantity'];
            $destination = $this->creditBalance(
                $organizationId,
                (int) $item->id,
                $boatKey,
                $boatId,
                $quantity,
                $unitCostMinor,
                $now,
            );
            $toBalanceQuantity = (float) $destination->quantity;
        } elseif ($movementType === 'CONSUME') {
            $fromKey = $boatKey;
            $source = $this->debitBalance($organizationId, (int) $item->id, $boatKey, $boatId, $quantity, $now);
            $unitCostMinor = $source['unit_cost_minor'];
            $fromBalanceQuantity = $source['quantity'];
        } elseif ($movementType === 'RETURN') {
            $fromKey = $boatKey;
            $toKey = $warehouseKey;
            $source = $this->debitBalance($organizationId, (int) $item->id, $boatKey, $boatId, $quantity, $now);
            $unitCostMinor = $source['unit_cost_minor'];
            $fromBalanceQuantity = $source['quantity'];
            $destination = $this->creditBalance(
                $organizationId,
                (int) $item->id,
                $warehouseKey,
                null,
                $quantity,
                $unitCostMinor,
                $now,
            );
            $toBalanceQuantity = (float) $destination->quantity;
        } elseif ($movementType === 'WASTE') {
            $fromKey = $boatKey ?? $warehouseKey;
            $source = $this->debitBalance(
                $organizationId,
                (int) $item->id,
                $fromKey,
                $boatId,
                $quantity,
                $now,
            );
            $unitCostMinor = $source['unit_cost_minor'];
            $fromBalanceQuantity = $source['quantity'];
        } elseif ($movementType === 'ADJUSTMENT_IN') {
            $toKey = $boatKey ?? $warehouseKey;
            $unitCostMinor = $providedTotalCost / $quantity;
            $destination = $this->creditBalance(
                $organizationId,
                (int) $item->id,
                $toKey,
                $boatId,
                $quantity,
                $unitCostMinor,
                $now,
            );
            $toBalanceQuantity = (float) $destination->quantity;
        } else {
            $fromKey = $boatKey ?? $warehouseKey;
            $source = $this->debitBalance(
                $organizationId,
                (int) $item->id,
                $fromKey,
                $boatId,
                $quantity,
                $now,
            );
            $unitCostMinor = $source['unit_cost_minor'];
            $fromBalanceQuantity = $source['quantity'];
        }

        $totalCostAmountMinor = in_array($movementType, ['PURCHASE', 'ADJUSTMENT_IN'], true)
            ? $providedTotalCost
            : (int) round($quantity * $unitCostMinor, 0, PHP_ROUND_HALF_UP);

        $movementId = DB::table('stock_movements')->insertGetId([
            'organization_id' => $organizationId,
            'external_reference' => $input['external_reference'],
            'item_id' => $item->id,
            'boat_id' => $boatId,
            'trip_id' => $tripId,
            'cash_account_id' => $cashAccountId,
            'movement_type' => $movementType,
            'occurred_at' => CarbonImmutable::parse($input['occurred_at'])->utc(),
            'quantity' => $this->quantity($quantity),
            'unit_cost_minor' => $this->unitCost($unitCostMinor),
            'total_cost_amount_minor' => $totalCostAmountMinor,
            'currency' => $item->currency,
            'from_location_key' => $fromKey,
            'to_location_key' => $toKey,
            'handled_by' => $input['handled_by'],
            'receipt_reference' => $input['receipt_reference'] ?? null,
            'reason' => $input['reason'] ?? null,
            'status' => 'POSTED',
            'recorded_by_api_client_id' => $apiClientId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'movement_id' => $movementId,
            'external_reference' => $input['external_reference'],
            'item_id' => (int) $item->id,
            'boat_id' => $boatId,
            'trip_id' => $tripId,
            'cash_account_id' => $cashAccountId,
            'movement_type' => $movementType,
            'quantity' => $this->quantity($quantity),
            'unit_cost_minor' => $this->unitCost($unitCostMinor),
            'total_cost_amount_minor' => $totalCostAmountMinor,
            'currency' => $item->currency,
            'from_location_key' => $fromKey,
            'to_location_key' => $toKey,
            'from_balance_quantity' => $fromBalanceQuantity === null ? null : $this->quantity($fromBalanceQuantity),
            'to_balance_quantity' => $toBalanceQuantity === null ? null : $this->quantity($toBalanceQuantity),
            'status' => 'POSTED',
        ];
    }

    public function reverse(
        int $organizationId,
        int $apiClientId,
        int $movementId,
        string $externalReference,
        string $reason,
        CarbonImmutable $now,
    ): array {
        $original = DB::table('stock_movements')
            ->where('organization_id', $organizationId)
            ->where('id', $movementId)
            ->lockForUpdate()
            ->first();

        if (! $original) {
            throw new OperationsFinanceException(
                'AUTHORIZATION_FAILED',
                'The requested stock movement is not accessible.',
                403,
                false,
            );
        }

        if ($original->movement_type === 'REVERSAL' || $original->reversal_of_movement_id !== null) {
            throw new OperationsFinanceException(
                'REVERSAL_NOT_ALLOWED',
                'A compensating stock movement cannot be reversed.',
            );
        }

        if ($original->status !== 'POSTED') {
            throw new OperationsFinanceException(
                'ALREADY_REVERSED',
                'The stock movement has already been reversed.',
            );
        }

        if (DB::table('stock_movements')->where('organization_id', $organizationId)
            ->where('external_reference', $externalReference)->exists()) {
            throw new OperationsFinanceException(
                'DUPLICATE_EXTERNAL_REFERENCE',
                'The reversal external reference already exists.',
            );
        }

        $quantity = (float) $original->quantity;
        $unitCost = (float) $original->unit_cost_minor;
        $totalCost = (int) $original->total_cost_amount_minor;
        $fromQuantity = null;
        $toQuantity = null;

        // Reverse the original locations exactly. Debits remove the historical value
        // carried by the original movement, rather than today's moving average.
        if ($original->to_location_key !== null) {
            $boatId = str_starts_with($original->to_location_key, 'BOAT:') ? (int) $original->boat_id : null;
            $toQuantity = $this->debitExactValue(
                $organizationId, (int) $original->item_id, $original->to_location_key,
                $boatId, $quantity, $totalCost, $now,
            );
        }

        if ($original->from_location_key !== null) {
            $boatId = str_starts_with($original->from_location_key, 'BOAT:') ? (int) $original->boat_id : null;
            $source = $this->creditBalance(
                $organizationId, (int) $original->item_id, $original->from_location_key,
                $boatId, $quantity, $unitCost, $now,
            );
            $fromQuantity = (float) $source->quantity;
        }

        $compensationId = DB::table('stock_movements')->insertGetId([
            'organization_id' => $organizationId,
            'external_reference' => $externalReference,
            'item_id' => $original->item_id,
            'boat_id' => $original->boat_id,
            'trip_id' => $original->trip_id,
            'cash_account_id' => null,
            'movement_type' => 'REVERSAL',
            'occurred_at' => $now,
            'quantity' => $this->quantity($quantity),
            'unit_cost_minor' => $this->unitCost($unitCost),
            'total_cost_amount_minor' => $totalCost,
            'currency' => $original->currency,
            'from_location_key' => $original->to_location_key,
            'to_location_key' => $original->from_location_key,
            'handled_by' => 'API client '.$apiClientId,
            'receipt_reference' => $original->receipt_reference,
            'reason' => $reason,
            'status' => 'POSTED',
            'reversal_of_movement_id' => $original->id,
            'recorded_by_api_client_id' => $apiClientId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('stock_movements')->where('id', $original->id)->update([
            'status' => 'REVERSED',
            'updated_at' => $now,
        ]);

        return [
            'original_movement_id' => (int) $original->id,
            'compensating_movement_id' => $compensationId,
            'external_reference' => $externalReference,
            'movement_type' => 'REVERSAL',
            'quantity' => $this->quantity($quantity),
            'unit_cost_minor' => $this->unitCost($unitCost),
            'total_cost_amount_minor' => $totalCost,
            'currency' => $original->currency,
            'from_location_key' => $original->to_location_key,
            'to_location_key' => $original->from_location_key,
            'from_balance_quantity' => $toQuantity === null ? null : $this->quantity($toQuantity),
            'to_balance_quantity' => $fromQuantity === null ? null : $this->quantity($fromQuantity),
        ];
    }

    private function debitExactValue(
        int $organizationId,
        int $itemId,
        string $locationKey,
        ?int $boatId,
        float $quantity,
        int $valueMinor,
        CarbonImmutable $now,
    ): float {
        $balance = $this->balance($organizationId, $itemId, $locationKey, $boatId, $now);
        $currentQuantity = (float) $balance->quantity;
        $currentValue = $currentQuantity * (float) $balance->average_unit_cost_minor;

        if ($currentQuantity + 0.000_001 < $quantity) {
            throw new OperationsFinanceException(
                'INSUFFICIENT_STOCK_FOR_REVERSAL',
                'The reversal cannot remove stock that is no longer available.',
            );
        }

        $newQuantity = max(0.0, $currentQuantity - $quantity);
        $newValue = $currentValue - $valueMinor;
        if ($newValue < -0.5 || ($newQuantity < 0.000_001 && abs($newValue) > 0.5)) {
            throw new OperationsFinanceException(
                'STOCK_REVERSAL_VALUE_CONFLICT',
                'The reversal would create an impossible stock value.',
            );
        }

        $newAverage = $newQuantity < 0.000_001 ? 0.0 : max(0.0, $newValue) / $newQuantity;
        DB::table('stock_balances')->where('id', $balance->id)->update([
            'quantity' => $this->quantity($newQuantity),
            'average_unit_cost_minor' => $this->unitCost($newAverage),
            'updated_at' => $now,
        ]);

        return $newQuantity;
    }

    private function debitBalance(
        int $organizationId,
        int $itemId,
        string $locationKey,
        ?int $boatId,
        float $quantity,
        CarbonImmutable $now,
    ): array {
        $balance = $this->balance($organizationId, $itemId, $locationKey, $boatId, $now);
        $currentQuantity = (float) $balance->quantity;

        if ($currentQuantity + 0.000_001 < $quantity) {
            throw new OperationsFinanceException(
                'INSUFFICIENT_STOCK',
                'The stock movement would create a negative balance.',
            );
        }

        $newQuantity = max(0, $currentQuantity - $quantity);
        $unitCostMinor = (float) $balance->average_unit_cost_minor;
        DB::table('stock_balances')->where('id', $balance->id)->update([
            'quantity' => $this->quantity($newQuantity),
            'average_unit_cost_minor' => $newQuantity === 0.0 ? '0.000000' : $this->unitCost($unitCostMinor),
            'updated_at' => $now,
        ]);

        return [
            'quantity' => $newQuantity,
            'unit_cost_minor' => $unitCostMinor,
        ];
    }

    private function creditBalance(
        int $organizationId,
        int $itemId,
        string $locationKey,
        ?int $boatId,
        float $quantity,
        float $unitCostMinor,
        CarbonImmutable $now,
    ): object {
        $balance = $this->balance($organizationId, $itemId, $locationKey, $boatId, $now);
        $currentQuantity = (float) $balance->quantity;
        $currentAverage = (float) $balance->average_unit_cost_minor;
        $newQuantity = $currentQuantity + $quantity;
        $newAverage = (($currentQuantity * $currentAverage) + ($quantity * $unitCostMinor)) / $newQuantity;

        DB::table('stock_balances')->where('id', $balance->id)->update([
            'quantity' => $this->quantity($newQuantity),
            'average_unit_cost_minor' => $this->unitCost($newAverage),
            'updated_at' => $now,
        ]);

        return DB::table('stock_balances')->find($balance->id);
    }

    private function balance(
        int $organizationId,
        int $itemId,
        string $locationKey,
        ?int $boatId,
        CarbonImmutable $now,
    ): object {
        DB::table('stock_balances')->insertOrIgnore([
            'organization_id' => $organizationId,
            'item_id' => $itemId,
            'location_key' => $locationKey,
            'location_type' => $boatId === null ? 'WAREHOUSE' : 'BOAT',
            'boat_id' => $boatId,
            'quantity' => '0.000',
            'average_unit_cost_minor' => '0.000000',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('stock_balances')
            ->where('organization_id', $organizationId)
            ->where('item_id', $itemId)
            ->where('location_key', $locationKey)
            ->lockForUpdate()
            ->first();
    }

    private function quantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private function unitCost(float $value): string
    {
        return number_format($value, 6, '.', '');
    }
}
