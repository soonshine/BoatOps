<?php

namespace App\Application\Holds;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait HoldActionSupport
{
    /** @param array<string, mixed> $value */
    private function canonicalHash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        ksort($value);

        return $value;
    }

    private function replay(
        int $organizationId,
        string $operation,
        string $idempotencyKey,
        string $requestHash,
    ): ?HoldActionResult {
        $existing = DB::table('idempotency_keys')
            ->where('organization_id', $organizationId)
            ->where('operation', $operation)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $existing) {
            return null;
        }

        if (! hash_equals($existing->request_hash, $requestHash)) {
            return $this->error(
                'IDEMPOTENCY_CONFLICT',
                'The idempotency key was used with another payload.',
                409,
                true,
            );
        }

        return new HoldActionResult(
            (int) $existing->response_status,
            json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function error(
        string $code,
        string $message,
        int $status,
        bool $manualActionRequired = false,
    ): HoldActionResult {
        return new HoldActionResult($status, [
            'request_id' => (string) Str::uuid(),
            'code' => $code,
            'retryable' => false,
            'manual_action_required' => $manualActionRequired,
            'message' => $message,
        ]);
    }
}
