<?php

namespace App\Http\Controllers\Api\Internal\V1;

use App\Exceptions\SlotCatalogException;
use App\Http\Controllers\Concerns\HandlesInternalCommands;
use App\Http\Controllers\Controller;
use App\Services\SlotCatalog\SlotCalendarReadModel;
use App\Services\SlotCatalog\SlotCatalogService;
use App\Services\SlotCatalog\SlotCompatibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ScheduleController extends Controller
{
    use HandlesInternalCommands;

    public function __construct(
        private readonly SlotCatalogService $catalog,
        private readonly SlotCompatibilityService $compatibility,
        private readonly SlotCalendarReadModel $calendar,
    ) {}

    public function slotOfferings(Request $request): JsonResponse
    {
        if (! $this->canRead($request)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot read the schedule catalog.', 403);
        }

        $organization = $request->attributes->get('organization');

        try {
            $offerings = $this->catalog->listOfferings((int) $organization->id);
            $rules = $this->compatibility->listRules((int) $organization->id);
        } catch (SlotCatalogException $exception) {
            return $this->slotError($exception);
        }

        return response()->json($this->envelope($request, [
            'inventory_revision' => $this->inventoryRevision((int) $organization->id),
            'operating_time_notice' => '演示默认档期；真实起止时间和周转缓冲尚未冻结。',
            'slot_offerings' => $offerings,
            'compatibility_rules' => $rules,
            'code' => 'SLOT_OFFERINGS_LISTED',
        ]));
    }

    public function createSlotOffering(Request $request): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.schedule.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify the schedule catalog.', 403);
        }

        $input = $request->validate($this->offeringRules());
        $boatIds = array_map('intval', $input['boat_ids'] ?? []);
        unset($input['boat_ids']);
        $input['operating_time_status'] = 'UNVERIFIED';
        $organization = $request->attributes->get('organization');

        try {
            $offeringId = $this->catalog->createReusableOffering(
                (int) $organization->id,
                $input,
                $boatIds,
                (int) $request->attributes->get('api_client_id'),
            );
            $offering = $this->catalog->offering((int) $organization->id, $offeringId);
        } catch (SlotCatalogException $exception) {
            return $this->slotError($exception);
        }

        return response()->json($this->envelope($request, [
            'slot_offering' => $offering,
            'inventory_revision' => $this->inventoryRevision((int) $organization->id),
            'code' => 'SLOT_OFFERING_CREATED',
        ]), 201);
    }

    public function createCustomSlotInstance(Request $request): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.schedule.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify the schedule catalog.', 403);
        }

        $input = $request->validate([
            'template_slot_offering_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9_-]{1,99}$/'],
            'name' => ['nullable', 'required_without:template_slot_offering_id', 'string', 'max:255'],
            'status' => ['sometimes', 'in:DRAFT,ACTIVE'],
            'service_date' => ['required', 'date_format:Y-m-d'],
            'service_start_time' => ['nullable', 'required_without:template_slot_offering_id', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/'],
            'service_end_time' => ['nullable', 'required_without:template_slot_offering_id', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/'],
            'duration_minutes' => ['nullable', 'required_without:template_slot_offering_id', 'integer', 'min:1', 'max:1440'],
            'additional_buffer_before_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'additional_buffer_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'applies_to_all_boats' => ['sometimes', 'boolean'],
            'boat_ids' => ['sometimes', 'array', 'max:100'],
            'boat_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $boatIds = array_map('intval', $input['boat_ids'] ?? []);
        unset($input['boat_ids']);
        $input['operating_time_status'] = 'UNVERIFIED';
        $organization = $request->attributes->get('organization');

        try {
            $instanceId = $this->catalog->createCustomInstance(
                (int) $organization->id,
                $input,
                $boatIds,
                (int) $request->attributes->get('api_client_id'),
            );
            $instance = $this->catalog->offering((int) $organization->id, $instanceId);
        } catch (SlotCatalogException $exception) {
            return $this->slotError($exception);
        }

        return response()->json($this->envelope($request, [
            'custom_slot_instance' => $instance,
            'inventory_revision' => $this->inventoryRevision((int) $organization->id),
            'code' => 'CUSTOM_SLOT_INSTANCE_CREATED',
        ]), 201);
    }

    public function setCompatibilityRule(Request $request): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.schedule.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify schedule compatibility.', 403);
        }

        $input = $request->validate([
            'first_slot_offering_id' => ['required', 'integer', 'min:1', 'different:second_slot_offering_id'],
            'second_slot_offering_id' => ['required', 'integer', 'min:1', 'different:first_slot_offering_id'],
            'policy' => ['required', 'in:ALLOW,DENY'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $organization = $request->attributes->get('organization');

        try {
            $ruleId = $this->compatibility->setRule(
                (int) $organization->id,
                (int) $input['first_slot_offering_id'],
                (int) $input['second_slot_offering_id'],
                (string) $input['policy'],
                (int) $request->attributes->get('api_client_id'),
                $input['reason'] ?? null,
            );
            $rule = collect($this->compatibility->listRules((int) $organization->id))
                ->firstWhere('id', $ruleId);
        } catch (SlotCatalogException $exception) {
            return $this->slotError($exception);
        }

        return response()->json($this->envelope($request, [
            'compatibility_rule' => $rule,
            'inventory_revision' => $this->inventoryRevision((int) $organization->id),
            'code' => 'SLOT_COMPATIBILITY_RULE_UPSERTED',
        ]));
    }

    public function activateSlotOffering(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'ACTIVE');
    }

    public function retireSlotOffering(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'RETIRED');
    }

    public function calendar(Request $request): JsonResponse
    {
        if (! $this->canRead($request)) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot read the schedule calendar.', 403);
        }

        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'boat_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $organization = $request->attributes->get('organization');

        try {
            $payload = $this->calendar->read(
                $organization,
                (string) $input['from'],
                (string) $input['to'],
                isset($input['boat_id']) ? (int) $input['boat_id'] : null,
            );
        } catch (SlotCatalogException $exception) {
            return $this->slotError($exception);
        }

        return response()->json($payload);
    }

    private function transition(Request $request, int $id, string $status): JsonResponse
    {
        if (! $this->hasScope($request, 'operations.schedule.write')) {
            return $this->error('AUTHORIZATION_FAILED', 'This API client cannot modify the schedule catalog.', 403);
        }

        $organization = $request->attributes->get('organization');

        try {
            $offering = $this->catalog->transitionStatus(
                (int) $organization->id,
                $id,
                $status,
                (int) $request->attributes->get('api_client_id'),
            );
        } catch (SlotCatalogException $exception) {
            return $this->slotError($exception);
        }

        return response()->json($this->envelope($request, [
            'slot_offering' => $offering,
            'inventory_revision' => $this->inventoryRevision((int) $organization->id),
            'code' => $status === 'ACTIVE' ? 'SLOT_OFFERING_ACTIVATED' : 'SLOT_OFFERING_RETIRED',
        ]));
    }

    /**
     * @return array<string, list<string>>
     */
    private function offeringRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9_-]{1,99}$/'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', 'in:DRAFT,ACTIVE'],
            'service_start_time' => ['required', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/'],
            'service_end_time' => ['required', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'additional_buffer_before_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'additional_buffer_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'applies_to_all_boats' => ['sometimes', 'boolean'],
            'boat_ids' => ['sometimes', 'array', 'max:100'],
            'boat_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    private function canRead(Request $request): bool
    {
        return $this->hasScope(
            $request,
            'operations.schedule.read',
            'operations.schedule.write',
        );
    }

    private function inventoryRevision(int $organizationId): int
    {
        return (int) DB::table('organizations')->where('id', $organizationId)->value('inventory_revision');
    }

    private function slotError(SlotCatalogException $exception): JsonResponse
    {
        return $this->error(
            $exception->errorCode,
            $exception->getMessage(),
            $exception->httpStatus,
            $exception->manualActionRequired,
        );
    }
}
