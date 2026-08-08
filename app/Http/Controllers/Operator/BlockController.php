<?php

namespace App\Http\Controllers\Operator;

use App\Application\Blocks\BlockActionResult;
use App\Application\Blocks\CreateBlockAction;
use App\Application\Blocks\ReleaseBlockAction;
use App\Application\Holds\HoldActor;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class BlockController extends Controller
{
    public function __construct(
        private readonly CreateBlockAction $createBlock,
        private readonly ReleaseBlockAction $releaseBlock,
    ) {}

    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        $blocks = DB::table('blocks')
            ->join('boats', function ($join): void {
                $join->on('boats.id', '=', 'blocks.boat_id')
                    ->on('boats.organization_id', '=', 'blocks.organization_id');
            })
            ->where('blocks.organization_id', $organization->id)
            ->orderByDesc('blocks.occupied_start')
            ->select('blocks.*', 'boats.name as resource_name')
            ->get()
            ->map(fn (object $block): object => (object) [
                ...((array) $block),
                'occupied_start_local' => $this->formatLocal((string) $block->occupied_start, (string) $organization->timezone),
                'occupied_end_local' => $this->formatLocal((string) $block->occupied_end, (string) $organization->timezone),
                'release_idempotency_key' => (string) Str::uuid(),
            ]);

        return view('operator.blocks.index', [
            'organization' => $organization,
            'blocks' => $blocks,
            'boats' => DB::table('boats')
                ->where('organization_id', $organization->id)
                ->where('status', 'ACTIVE')
                ->orderBy('name')
                ->get(),
            'createIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'external_reference' => ['required', 'string', 'max:255'],
            'boat_id' => ['required', 'integer', 'min:1'],
            'starts_at_local' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at_local' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at_local'],
            'reason_code' => ['required', 'in:MAINTENANCE,WEATHER,OWNER_USE,MANUAL'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $organization = $request->attributes->get('organization');
        abort_unless(DB::table('boats')
            ->where('organization_id', $organization->id)
            ->where('status', 'ACTIVE')
            ->where('id', $input['boat_id'])
            ->exists(), 404);

        $actionInput = [
            'external_reference' => $input['external_reference'],
            'boat_id' => (int) $input['boat_id'],
            'starts_at' => $this->localToUtc($input['starts_at_local'], (string) $organization->timezone),
            'ends_at' => $this->localToUtc($input['ends_at_local'], (string) $organization->timezone),
            'reason_code' => $input['reason_code'],
            'reason' => $input['reason'] ?? null,
        ];
        $result = $this->createBlock->execute(
            (int) $organization->id,
            $actionInput,
            $input['idempotency_key'],
            HoldActor::operatorUser((int) Auth::id()),
        );

        return $this->mutationResponse($result, 'block');
    }

    public function release(Request $request, int $block): RedirectResponse
    {
        $input = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $organization = $request->attributes->get('organization');
        $record = DB::table('blocks')
            ->where('organization_id', $organization->id)
            ->where('id', $block)
            ->first();
        abort_if(! $record, 404);

        $result = $this->releaseBlock->execute(
            (int) $organization->id,
            $block,
            [
                'external_reference' => (string) $record->external_reference,
                'reason' => $input['reason'] ?? null,
            ],
            $input['idempotency_key'],
            HoldActor::operatorUser((int) Auth::id()),
        );

        return $this->mutationResponse($result, 'release');
    }

    private function localToUtc(string $value, string $timezone): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone)
            ->utc()
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function formatLocal(string $value, string $timezone): string
    {
        return CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i T');
    }

    private function mutationResponse(BlockActionResult $result, string $field): RedirectResponse
    {
        $response = redirect()->route('operator.blocks.index', status: 303);

        if (! in_array($result->status, [200, 201], true)) {
            $response->withErrors([$field => $result->payload['message']]);
        }

        return $response;
    }
}
