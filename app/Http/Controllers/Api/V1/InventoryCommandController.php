<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Bookings\AmendBookingAction;
use App\Application\Bookings\CancelBookingAction;
use App\Application\Bookings\ConfirmBookingAction;
use App\Application\Holds\CreateHoldAction;
use App\Application\Holds\HoldActionResult;
use App\Application\Holds\HoldActor;
use App\Application\Holds\ReleaseHoldAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InventoryCommandController extends Controller
{
    public function __construct(
        private readonly CreateHoldAction $createHold,
        private readonly ReleaseHoldAction $releaseHold,
        private readonly ConfirmBookingAction $confirmBookingAction,
        private readonly AmendBookingAction $amendBookingAction,
        private readonly CancelBookingAction $cancelBookingAction,
    ) {}

    public function createHold(Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'boat_id' => ['required', 'integer'],
            'trip_template_id' => ['required', 'integer'],
            'slot_offering_id' => ['nullable', 'integer', 'min:1', 'prohibits:custom_slot_instance_id'],
            'custom_slot_instance_id' => ['nullable', 'integer', 'min:1', 'prohibits:slot_offering_id'],
            'service_date' => ['nullable', 'date_format:Y-m-d', 'required_with:slot_offering_id'],
            'starts_at' => ['nullable', 'date', 'required_without_all:slot_offering_id,custom_slot_instance_id'],
            'ends_at' => [
                'nullable',
                'date',
                'after:starts_at',
                'required_with:starts_at',
                'required_without_all:slot_offering_id,custom_slot_instance_id',
            ],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);
        $organization = $request->attributes->get('organization');
        $result = $this->createHold->execute(
            (int) $organization->id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return $this->actionResponse($result);
    }

    public function releaseHold(Request $request, int $id): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
        ]);
        $organization = $request->attributes->get('organization');
        $result = $this->releaseHold->execute(
            (int) $organization->id,
            $id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return $this->actionResponse($result);
    }

    public function confirmBooking(Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'hold_id' => ['required', 'integer'],
            'external_reference' => ['required', 'string', 'max:255'],
            'rate_snapshot' => ['required', 'array'],
            'rate_snapshot.source_reference' => ['required', 'string', 'max:255'],
            'rate_snapshot.currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'rate_snapshot.selling_amount_minor' => ['required', 'integer', 'min:0'],
            'rate_snapshot.tax_amount_minor' => ['required', 'integer', 'min:0'],
            'rate_snapshot.commission_amount_minor' => ['required', 'integer', 'min:0'],
            'rate_snapshot.fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'rate_snapshot.fx_base_currency' => ['nullable', 'required_with:rate_snapshot.fx_rate', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'rate_snapshot.fx_quote_currency' => ['nullable', 'required_with:rate_snapshot.fx_rate', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'rate_snapshot.quoted_at' => ['required', 'date'],
            'rate_snapshot.valid_until' => ['nullable', 'date', 'after:rate_snapshot.quoted_at'],
        ]);
        $organization = $request->attributes->get('organization');
        $result = $this->confirmBookingAction->execute(
            (int) $organization->id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return response()->json($result->payload, $result->status);
    }

    public function amendBooking(Request $request, int $id): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'boat_id' => ['required', 'integer'],
            'trip_template_id' => ['required', 'integer'],
            'slot_offering_id' => ['nullable', 'integer', 'min:1', 'prohibits:custom_slot_instance_id'],
            'custom_slot_instance_id' => ['nullable', 'integer', 'min:1', 'prohibits:slot_offering_id'],
            'service_date' => ['nullable', 'date_format:Y-m-d', 'required_with:slot_offering_id'],
            'starts_at' => ['nullable', 'date', 'required_without_all:slot_offering_id,custom_slot_instance_id'],
            'ends_at' => [
                'nullable',
                'date',
                'after:starts_at',
                'required_with:starts_at',
                'required_without_all:slot_offering_id,custom_slot_instance_id',
            ],
        ]);
        $organization = $request->attributes->get('organization');
        $result = $this->amendBookingAction->execute(
            (int) $organization->id,
            $id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return response()->json($result->payload, $result->status);
    }

    public function cancelBooking(Request $request, int $id): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8) {
            return $this->error('VALIDATION_FAILED', 'A valid Idempotency-Key header is required.', 422);
        }

        $input = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $organization = $request->attributes->get('organization');
        $result = $this->cancelBookingAction->execute(
            (int) $organization->id,
            $id,
            $input,
            $idempotencyKey,
            HoldActor::apiClient((int) $request->attributes->get('api_client_id')),
        );

        return response()->json($result->payload, $result->status);
    }

    private function actionResponse(HoldActionResult $result): JsonResponse
    {
        return response()->json($result->payload, $result->status);
    }

    private function error(
        string $code,
        string $message,
        int $status,
        bool $manualActionRequired = false,
    ): JsonResponse {
        return response()->json([
            'request_id' => (string) Str::uuid(),
            'code' => $code,
            'retryable' => false,
            'manual_action_required' => $manualActionRequired,
            'message' => $message,
        ], $status);
    }
}
