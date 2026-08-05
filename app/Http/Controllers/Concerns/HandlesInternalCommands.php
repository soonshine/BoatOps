<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait HandlesInternalCommands
{
    protected function hasScope(Request $request, string ...$scopes): bool
    {
        $granted = $request->attributes->get('api_client_scopes', []);

        return collect($scopes)->contains(fn (string $scope): bool => in_array($scope, $granted, true));
    }

    protected function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) && strlen($key) >= 8 && strlen($key) <= 255 ? $key : null;
    }

    protected function uuidIdempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) && Str::isUuid($key) ? $key : null;
    }

    protected function scopedOperation(Request $request, string $operation): string
    {
        return 'api-client:'.$request->attributes->get('api_client_id').':'.$operation;
    }

    protected function requestHash(array $input): string
    {
        return hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));
    }

    protected function replayIdempotency(int $organizationId, string $operation, string $idempotencyKey, string $requestHash): ?JsonResponse
    {
        $existing = DB::table('idempotency_keys')
            ->where('organization_id', $organizationId)
            ->where('operation', $operation)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $existing) {
            return null;
        }

        if (! hash_equals($existing->request_hash, $requestHash)) {
            return $this->error('IDEMPOTENCY_CONFLICT', 'The idempotency key was used with another payload.', 409, true);
        }

        return response()->json(json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR), $existing->response_status);
    }

    protected function storeIdempotency(
        int $organizationId,
        string $operation,
        string $idempotencyKey,
        string $requestHash,
        int $responseStatus,
        array $responseBody,
        mixed $now,
    ): void {
        DB::table('idempotency_keys')->insert([
            'organization_id' => $organizationId,
            'operation' => $operation,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'response_status' => $responseStatus,
            'response_body' => json_encode($responseBody, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function audit(
        Request $request,
        string $action,
        string $objectType,
        int $objectId,
        ?array $before,
        ?array $after,
        mixed $now,
        ?string $reason = null,
    ): void {
        $organization = $request->attributes->get('organization');
        DB::table('audit_logs')->insert([
            'organization_id' => $organization->id,
            'actor_type' => 'api_client',
            'actor_id' => $request->attributes->get('api_client_id'),
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function envelope(Request $request, array $payload): array
    {
        $organization = $request->attributes->get('organization');

        return [
            'request_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            ...$payload,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'business_timezone' => $organization->timezone,
        ];
    }

    protected function error(string $code, string $message, int $status, bool $manualActionRequired = false): JsonResponse
    {
        return response()->json([
            'request_id' => (string) Str::uuid(),
            'code' => $code,
            'retryable' => false,
            'manual_action_required' => $manualActionRequired,
            'message' => $message,
        ], $status);
    }

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
}
