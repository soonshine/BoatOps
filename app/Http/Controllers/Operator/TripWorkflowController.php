<?php

namespace App\Http\Controllers\Operator;

use App\Application\Holds\HoldActor;
use App\Application\Trips\CompleteTripAction;
use App\Application\Trips\DepartTripAction;
use App\Application\Trips\PrepareTripAction;
use App\Application\Trips\ReturnTripAction;
use App\Application\Trips\TripActionResult;
use App\Http\Controllers\Controller;
use App\Support\OperatorUi;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class TripWorkflowController extends Controller
{
    public function __construct(
        private readonly PrepareTripAction $prepareTrip,
        private readonly DepartTripAction $departTrip,
        private readonly ReturnTripAction $returnTrip,
        private readonly CompleteTripAction $completeTrip,
    ) {}

    public function prepare(Request $request, int $trip): RedirectResponse
    {
        $organizationId = $this->scopedTripOrganizationId($request, $trip);
        $input = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
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
        $result = $this->prepareTrip->execute(
            $organizationId,
            $trip,
            ['crew' => $input['crew'], 'checklist' => $input['checklist']],
            $input['idempotency_key'],
            $this->actor(),
        );

        return $this->redirectResult($result, $trip, '出航准备已保存。');
    }

    public function depart(Request $request, int $trip): RedirectResponse
    {
        $organizationId = $this->scopedTripOrganizationId($request, $trip);
        $input = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'departed_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);
        $result = $this->departTrip->execute(
            $organizationId,
            $trip,
            ['departed_at' => $this->localToUtc($input['departed_at'], $request)],
            $input['idempotency_key'],
            $this->actor(),
        );

        return $this->redirectResult($result, $trip, '已登记出航。');
    }

    public function return(Request $request, int $trip): RedirectResponse
    {
        $organizationId = $this->scopedTripOrganizationId($request, $trip);
        $input = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'returned_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);
        $result = $this->returnTrip->execute(
            $organizationId,
            $trip,
            ['returned_at' => $this->localToUtc($input['returned_at'], $request)],
            $input['idempotency_key'],
            $this->actor(),
        );

        return $this->redirectResult($result, $trip, '已登记返航。');
    }

    public function complete(Request $request, int $trip): RedirectResponse
    {
        $organizationId = $this->scopedTripOrganizationId($request, $trip);
        $input = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $result = $this->completeTrip->execute(
            $organizationId,
            $trip,
            $input['idempotency_key'],
            $this->actor(),
        );

        return $this->redirectResult($result, $trip, '出航已完成。');
    }

    private function scopedTripOrganizationId(Request $request, int $trip): int
    {
        $organizationId = (int) $request->attributes->get('organization')->id;
        $exists = DB::table('trips')->where('organization_id', $organizationId)->where('id', $trip)->exists();
        abort_if(! $exists, 404);

        return $organizationId;
    }

    private function localToUtc(string $value, Request $request): string
    {
        $timezone = (string) $request->attributes->get('organization')->timezone;
        $local = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
        abort_if($local === false, 422);

        return $local->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function actor(): HoldActor
    {
        return HoldActor::operatorUser((int) Auth::id());
    }

    private function redirectResult(TripActionResult $result, int $trip, string $successMessage): RedirectResponse
    {
        $redirect = redirect()->route('operator.trips.show', $trip, 303);
        if ($result->status === 200) {
            return $redirect->with('status', $successMessage);
        }

        return $redirect->withErrors(['trip' => OperatorUi::actionError($result->payload)]);
    }
}
