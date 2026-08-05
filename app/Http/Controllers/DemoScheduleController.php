<?php

namespace App\Http\Controllers;

use App\Exceptions\SlotCatalogException;
use App\Services\SlotCatalog\SlotCalendarReadModel;
use App\Services\SlotCatalog\SlotCatalogService;
use App\Services\SlotCatalog\SlotCompatibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class DemoScheduleController extends Controller
{
    public function __construct(
        private readonly SlotCatalogService $catalog,
        private readonly SlotCompatibilityService $compatibility,
        private readonly SlotCalendarReadModel $calendarReadModel,
    ) {}

    public function calendar(Request $request): View
    {
        $input = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'boat_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $organization = $request->attributes->get('organization');
        $boats = $this->demoBoats((int) $organization->id);
        $from = $input['from'] ?? CarbonImmutable::now((string) $organization->timezone)->format('Y-m-d');
        $fromDate = CarbonImmutable::createFromFormat('!Y-m-d', (string) $from, (string) $organization->timezone);

        if (! $fromDate) {
            throw ValidationException::withMessages(['from' => '日历起始日期无效。']);
        }

        $boatId = isset($input['boat_id']) ? (int) $input['boat_id'] : null;

        if ($boatId !== null && ! $boats->pluck('id')->map(static fn (mixed $id): int => (int) $id)->contains($boatId)) {
            throw ValidationException::withMessages(['boat_id' => '只能筛选 DemoSiteSeeder 的虚构 Plan A / Plan B 船只。']);
        }

        try {
            $calendar = $this->calendarReadModel->read(
                $organization,
                (string) $from,
                $fromDate->addDays(6)->format('Y-m-d'),
                $boatId,
            );
        } catch (SlotCatalogException $exception) {
            throw ValidationException::withMessages(['calendar' => $exception->getMessage()]);
        }

        return view('demo.calendar', [
            'organization' => $organization,
            'boats' => $boats,
            'calendar' => $calendar,
            'selectedBoatId' => $boatId,
            'previousFrom' => $fromDate->subDays(7)->format('Y-m-d'),
            'nextFrom' => $fromDate->addDays(7)->format('Y-m-d'),
        ]);
    }

    public function slots(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        $boats = $this->demoBoats((int) $organization->id);

        try {
            $offerings = $this->catalog->listOfferings((int) $organization->id);
            $rules = $this->compatibility->listRules((int) $organization->id);
        } catch (SlotCatalogException $exception) {
            abort($exception->httpStatus);
        }

        return view('demo.slots', [
            'organization' => $organization,
            'boats' => $boats,
            'offerings' => $offerings,
            'templates' => collect($offerings)
                ->whereIn('kind', ['PRESET', 'CUSTOM_TEMPLATE'])
                ->where('status', 'ACTIVE')
                ->values(),
            'rules' => $rules,
            'defaultDate' => CarbonImmutable::now((string) $organization->timezone)->addDays(3)->format('Y-m-d'),
        ]);
    }

    public function createReusable(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9_-]{1,99}$/'],
            'name' => ['required', 'string', 'max:255'],
            'service_start_time' => ['required', 'date_format:H:i'],
            'service_end_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'additional_buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'additional_buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'scope' => ['required', 'in:ALL,SELECTED'],
            'boat_ids' => ['nullable', 'array'],
            'boat_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $boatIds = $this->scopedBoatIds($request, $input);
        $attributes = [
            ...$input,
            'status' => 'DRAFT',
            'operating_time_status' => 'DEMO_DEFAULT_UNVERIFIED',
            'applies_to_all_boats' => $input['scope'] === 'ALL',
        ];
        unset($attributes['scope'], $attributes['boat_ids']);

        return $this->execute(
            $request,
            fn (): int => $this->catalog->createReusableOffering(
                (int) $request->attributes->get('organization')->id,
                $attributes,
                $boatIds,
                (int) $request->attributes->get('api_client_id'),
            ),
            '虚构 reusable custom slot 已创建为 DRAFT。',
        );
    }

    public function createCustomInstance(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'template_slot_offering_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9_-]{1,99}$/'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:DRAFT,ACTIVE'],
            'service_date' => ['required', 'date_format:Y-m-d'],
            'service_start_time' => ['required', 'date_format:H:i'],
            'service_end_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'additional_buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'additional_buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'scope' => ['required', 'in:ALL,SELECTED'],
            'boat_ids' => ['nullable', 'array'],
            'boat_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $boatIds = $this->scopedBoatIds($request, $input);
        $attributes = [
            ...$input,
            'operating_time_status' => 'FICTIONAL_VALIDATION_SCENARIO',
            'applies_to_all_boats' => $input['scope'] === 'ALL',
        ];
        unset($attributes['scope'], $attributes['boat_ids']);

        return $this->execute(
            $request,
            fn (): int => $this->catalog->createCustomInstance(
                (int) $request->attributes->get('organization')->id,
                $attributes,
                $boatIds,
                (int) $request->attributes->get('api_client_id'),
            ),
            '虚构 date-specific custom slot instance 已创建。',
        );
    }

    public function compatibility(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'first_slot_offering_id' => ['required', 'integer', 'min:1', 'different:second_slot_offering_id'],
            'second_slot_offering_id' => ['required', 'integer', 'min:1', 'different:first_slot_offering_id'],
            'policy' => ['required', 'in:ALLOW,DENY'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->execute(
            $request,
            fn (): int => $this->compatibility->setRule(
                (int) $request->attributes->get('organization')->id,
                (int) $input['first_slot_offering_id'],
                (int) $input['second_slot_offering_id'],
                (string) $input['policy'],
                (int) $request->attributes->get('api_client_id'),
                (string) $input['reason'],
            ),
            '虚构 compatibility canonical rule 已保存。',
        );
    }

    public function activate(Request $request, int $id): RedirectResponse
    {
        return $this->execute(
            $request,
            fn (): array => $this->catalog->transitionStatus(
                (int) $request->attributes->get('organization')->id,
                $id,
                'ACTIVE',
                (int) $request->attributes->get('api_client_id'),
            ),
            '虚构档期定义已启用。',
        );
    }

    public function retire(Request $request, int $id): RedirectResponse
    {
        return $this->execute(
            $request,
            fn (): array => $this->catalog->transitionStatus(
                (int) $request->attributes->get('organization')->id,
                $id,
                'RETIRED',
                (int) $request->attributes->get('api_client_id'),
            ),
            '虚构档期定义已停用；历史快照未改变。',
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function scopedBoatIds(Request $request, array $input): array
    {
        if ($input['scope'] === 'ALL') {
            return [];
        }

        $boatIds = array_values(array_unique(array_map('intval', $input['boat_ids'] ?? [])));

        if ($boatIds === []) {
            throw ValidationException::withMessages(['boat_ids' => '选择指定船只范围时，至少选择一艘虚构船。']);
        }

        $allowed = $this->demoBoats((int) $request->attributes->get('organization')->id)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (array_diff($boatIds, $allowed) !== []) {
            throw ValidationException::withMessages(['boat_ids' => '只能选择 DemoSiteSeeder 的虚构 Plan A / Plan B 船只。']);
        }

        return $boatIds;
    }

    private function demoBoats(int $organizationId): Collection
    {
        $expectedNames = config('demo_site.boat_names');
        $boats = DB::table('boats')
            ->where('organization_id', $organizationId)
            ->whereIn('name', $expectedNames)
            ->orderBy('name')
            ->get();

        if (
            $boats->count() !== count($expectedNames)
            || $boats->pluck('name')->sort()->values()->all() !== collect($expectedNames)->sort()->values()->all()
        ) {
            abort(404);
        }

        return $boats;
    }

    private function execute(Request $request, callable $command, string $message): RedirectResponse
    {
        try {
            $command();
        } catch (SlotCatalogException $exception) {
            return back()->withErrors(['schedule' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('demo.slots')->with('status', $message);
    }
}
