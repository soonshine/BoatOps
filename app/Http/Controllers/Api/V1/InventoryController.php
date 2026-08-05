<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SlotCatalogException;
use App\Http\Controllers\Controller;
use App\Services\SlotCatalog\SlotAvailabilityService;
use App\Services\SlotCatalog\SlotIntervalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    public function __construct(
        private readonly SlotIntervalResolver $slotResolver,
        private readonly SlotAvailabilityService $slotAvailability,
    ) {}

    public function availability(Request $request): JsonResponse
    {
        $input = $request->validate([
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
        $boat = DB::table('boats')
            ->where('organization_id', $organization->id)
            ->where('status', 'ACTIVE')
            ->find($input['boat_id']);
        $templateExists = DB::table('trip_templates')
            ->where('organization_id', $organization->id)
            ->where('status', 'ACTIVE')
            ->where('id', $input['trip_template_id'])
            ->exists();

        if (! $boat || ! $templateExists) {
            return $this->error(
                'AUTHORIZATION_FAILED',
                'The requested inventory resource is not accessible.',
                403,
            );
        }

        try {
            $slot = $this->slotResolver->resolve($organization, $boat, $input);
            $decision = $this->slotAvailability->decide(
                (int) $organization->id,
                (int) $boat->id,
                $slot,
            );
        } catch (SlotCatalogException $exception) {
            return $this->error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
                $exception->manualActionRequired,
            );
        }

        $payload = [
            'request_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'boat_id' => $boat->id,
            'available' => $decision['available'],
            ...$slot->responseValues(),
            'inventory_revision' => $organization->inventory_revision,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'business_timezone' => $organization->timezone,
        ];

        if (! $decision['available']) {
            $payload += [
                'code' => $decision['code'],
                'retryable' => false,
                'manual_action_required' => false,
                'message' => $decision['message'],
            ];
        }

        return response()->json($payload);
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
