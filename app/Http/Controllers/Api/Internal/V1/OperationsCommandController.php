<?php

namespace App\Http\Controllers\Api\Internal\V1;

use App\Application\Blocks\CreateBlockAction;
use App\Application\Blocks\ReleaseBlockAction;
use App\Application\Holds\HoldActor;
use App\Application\Trips\CompleteTripAction;
use App\Application\Trips\DepartTripAction;
use App\Application\Trips\PrepareTripAction;
use App\Application\Trips\ReturnTripAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OperationsCommandController extends Controller
{
    public function __construct(
        private readonly CreateBlockAction $createBlockAction,
        private readonly ReleaseBlockAction $releaseBlockAction,
        private readonly PrepareTripAction $prepareTripAction,
        private readonly DepartTripAction $departTripAction,
        private readonly ReturnTripAction $returnTripAction,
        private readonly CompleteTripAction $completeTripAction,
    ) {}

    public function createBlock(Request $request): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }
        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'boat_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason_code' => ['required', 'in:MAINTENANCE,WEATHER,OWNER_USE,MANUAL'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $organization = $request->attributes->get('organization');
        $result = $this->createBlockAction->execute(
            (int) $organization->id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return response()->json($result->payload, $result->status);
    }

    public function prepareTrip(Request $request, int $id): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }
        $input = $request->validate([
            'crew' => ['required', 'array', 'min:1', 'max:20'],
            'crew.*.external_reference' => ['required', 'string', 'max:255', 'distinct'],
            'crew.*.display_name' => ['required', 'string', 'max:255'],
            'crew.*.role' => ['required', 'string', 'max:100'],
            'crew.*.duty' => ['required', 'string', 'max:100'],
            'checklist' => ['required', 'array', 'min:1', 'max:100'],
            'checklist.*.code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9_-]+$/', 'distinct'],
            'checklist.*.label' => ['required', 'string', 'max:255'],
            'checklist.*.required' => ['required', 'boolean'],
            'checklist.*.completed' => ['required', 'boolean'],
        ]);
        $organization = $request->attributes->get('organization');
        $result = $this->prepareTripAction->execute(
            (int) $organization->id,
            $id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return response()->json($result->payload, $result->status);
    }

    public function departTrip(Request $request, int $id): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }
        $input = $request->validate(['departed_at' => ['required', 'date']]);
        $organization = $request->attributes->get('organization');
        $result = $this->departTripAction->execute(
            (int) $organization->id,
            $id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return response()->json($result->payload, $result->status);
    }

    public function returnTrip(Request $request, int $id): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }
        $input = $request->validate(['returned_at' => ['required', 'date']]);
        $organization = $request->attributes->get('organization');
        $result = $this->returnTripAction->execute(
            (int) $organization->id,
            $id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return response()->json($result->payload, $result->status);
    }

    public function completeTrip(Request $request, int $id): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }
        $organization = $request->attributes->get('organization');
        $result = $this->completeTripAction->execute(
            (int) $organization->id,
            $id,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return response()->json($result->payload, $result->status);
    }

    public function releaseBlock(Request $request, int $id): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify operations.', 403);
        }
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }
        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $organization = $request->attributes->get('organization');
        $result = $this->releaseBlockAction->execute(
            (int) $organization->id,
            $id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return response()->json($result->payload, $result->status);
    }

    private function canWrite(Request $request): bool
    {
        return in_array('operations.write', $request->attributes->get('api_client_scopes', []), true);
    }

    private function error(string $code, string $message, int $status, bool $manualActionRequired = false): JsonResponse
    {
        return response()->json([
            'request_id' => (string) Str::uuid(),
            'code' => $code,
            'retryable' => false,
            'manual_action_required' => $manualActionRequired,
            'message' => $message,
        ], $status);
    }
}
