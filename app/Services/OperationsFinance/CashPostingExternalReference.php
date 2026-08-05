<?php

namespace App\Services\OperationsFinance;

final class CashPostingExternalReference
{
    public static function source(int $organizationId, string $sourceType, int $sourceId,
        string $postingKind, string $sourceExternalReference): string
    {
        return self::make('SOURCE', $organizationId, $sourceType, $sourceId, $postingKind, $sourceExternalReference);
    }

    public static function reversal(int $organizationId, int $financeReversalId,
        string $reversalExternalReference): string
    {
        return self::make('REVERSAL', $organizationId, 'finance_reversal', $financeReversalId,
            'REVERSAL', $reversalExternalReference);
    }

    private static function make(string $kind, int $organizationId, string $sourceType, int $sourceId,
        string $postingKind, string $externalReference): string
    {
        $identity = '';
        foreach ([
            'boatops-cash-posting-reference-v1',
            $kind,
            (string) $organizationId,
            $sourceType,
            (string) $sourceId,
            $postingKind,
            $externalReference,
        ] as $component) {
            $identity .= pack('N', strlen($component)).$component;
        }

        return 'cash:v1:'.$kind.':'.hash('sha256', $identity);
    }
}
