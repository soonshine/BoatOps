<?php

namespace App\Services\OperationsFinance;

use App\Exceptions\OperationsFinanceException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CashPostingService
{
    public function postOutflow(int $organizationId, int $apiClientId, int $cashAccountId,
        string $sourceType, int $sourceId, string $sourceExternalReference, string $postingKind,
        CarbonImmutable $occurredAt, int $amountMinor, string $currency, string $description,
        CarbonImmutable $now): int
    {
        if (! in_array($postingKind, ['FUEL', 'EXPENSE', 'STOCK_PURCHASE'], true)) {
            throw $this->conflict('CASH_POSTING_INVALID_STATE', 'The original cash posting kind is invalid.');
        }
        $this->assertPositiveAmount($amountMinor);
        $account = $this->account($organizationId, $cashAccountId);
        $this->assertCurrency($account->currency, $currency);
        if (DB::table('cash_postings')->where('organization_id', $organizationId)
            ->where('source_type', $sourceType)->where('source_id', $sourceId)->exists()) {
            throw $this->conflict('CASH_POSTING_DUPLICATE', 'The source record already has a cash posting.');
        }

        return DB::table('cash_postings')->insertGetId([
            'organization_id' => $organizationId,
            'external_reference' => CashPostingExternalReference::source(
                $organizationId, $sourceType, $sourceId, $postingKind, $sourceExternalReference
            ),
            'cash_account_id' => $cashAccountId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'posting_kind' => $postingKind,
            'direction' => 'OUTFLOW',
            'occurred_at' => $occurredAt->utc(),
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'description' => $description,
            'status' => 'POSTED',
            'reversal_of_posting_id' => null,
            'recorded_by_api_client_id' => $apiClientId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function reverse(int $organizationId, int $apiClientId, string $sourceType, int $sourceId,
        bool $cashPostingExpected, ?int $expectedAccountId, ?int $expectedAmountMinor,
        ?string $expectedCurrency, ?string $expectedKind, int $financeReversalId,
        string $reversalExternalReference, CarbonImmutable $reversedAt, CarbonImmutable $now): ?int
    {
        $postings = DB::table('cash_postings')->where('organization_id', $organizationId)
            ->where('source_type', $sourceType)->where('source_id', $sourceId)->lockForUpdate()->get();
        if (! $cashPostingExpected) {
            if ($postings->isNotEmpty()) {
                throw $this->conflict('CASH_POSTING_UNEXPECTED', 'A non-cash source record has an unexpected cash posting.');
            }

            return null;
        }
        if ($postings->count() === 0) {
            throw $this->conflict('CASH_POSTING_MISSING', 'The source record cash posting is missing.');
        }
        if ($postings->count() !== 1) {
            throw $this->conflict('CASH_POSTING_DUPLICATE', 'The source record has duplicate cash postings.');
        }

        $original = $postings->first();
        if ($original->direction !== 'OUTFLOW' || $original->status !== 'POSTED'
            || $original->posting_kind === 'REVERSAL' || $original->reversal_of_posting_id !== null) {
            throw $this->conflict('CASH_POSTING_INVALID_STATE', 'The source cash posting cannot be reversed from its current state.');
        }
        $this->assertPositiveAmount((int) $original->amount_minor);
        if ((int) $original->cash_account_id !== $expectedAccountId
            || (int) $original->amount_minor !== $expectedAmountMinor
            || $original->currency !== $expectedCurrency
            || $original->posting_kind !== $expectedKind) {
            throw $this->conflict('CASH_POSTING_SOURCE_MISMATCH', 'The cash posting does not match its source record.');
        }
        $account = $this->account($organizationId, (int) $original->cash_account_id);
        $this->assertCurrency($account->currency, $original->currency);
        if (DB::table('cash_postings')->where('organization_id', $organizationId)
            ->where('reversal_of_posting_id', $original->id)->exists()) {
            throw $this->conflict('CASH_POSTING_ALREADY_REVERSED', 'The cash posting already has a compensating posting.');
        }

        $compensationId = DB::table('cash_postings')->insertGetId([
            'organization_id' => $organizationId,
            'external_reference' => CashPostingExternalReference::reversal(
                $organizationId, $financeReversalId, $reversalExternalReference
            ),
            'cash_account_id' => $original->cash_account_id,
            'source_type' => 'finance_reversal',
            'source_id' => $financeReversalId,
            'posting_kind' => 'REVERSAL',
            'direction' => 'INFLOW',
            'occurred_at' => $reversedAt->utc(),
            'amount_minor' => $original->amount_minor,
            'currency' => $original->currency,
            'description' => 'Reversal of cash posting '.$original->id,
            'status' => 'POSTED',
            'reversal_of_posting_id' => $original->id,
            'recorded_by_api_client_id' => $apiClientId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $updated = DB::table('cash_postings')->where('id', $original->id)->where('status', 'POSTED')
            ->update(['status' => 'REVERSED', 'updated_at' => $now]);
        if ($updated !== 1) {
            throw $this->conflict('CASH_POSTING_INVALID_STATE', 'The original cash posting state changed during reversal.');
        }

        return $compensationId;
    }

    private function account(int $organizationId, int $cashAccountId): object
    {
        $account = DB::table('cash_accounts')->where('organization_id', $organizationId)
            ->where('id', $cashAccountId)->lockForUpdate()->first();
        if (! $account) {
            throw new OperationsFinanceException('AUTHORIZATION_FAILED', 'The cash posting account is not accessible.', 403, false);
        }

        return $account;
    }

    private function assertPositiveAmount(int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw new OperationsFinanceException('CASH_POSTING_INVALID_AMOUNT', 'Cash posting amount must be a positive integer in minor units.', 422, false);
        }
    }

    private function assertCurrency(string $accountCurrency, string $currency): void
    {
        if (! hash_equals($accountCurrency, $currency)) {
            throw new OperationsFinanceException('CASH_POSTING_CURRENCY_MISMATCH', 'Cash posting currency must match the cash account currency.', 422, false);
        }
    }

    private function conflict(string $code, string $message): OperationsFinanceException
    {
        return new OperationsFinanceException($code, $message, 409, true);
    }
}
