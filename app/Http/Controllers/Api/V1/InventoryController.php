<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    public function availability(Request $request): JsonResponse
    {
        $input = $request->validate([
            'boat_id' => ['required', 'integer'],
            'trip_template_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
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
            return response()->json([
                'request_id' => (string) Str::uuid(),
                'code' => 'AUTHORIZATION_FAILED',
                'retryable' => false,
                'manual_action_required' => false,
                'message' => 'The requested inventory resource is not accessible.',
            ], 403);
        }

        $businessStart = CarbonImmutable::parse($input['starts_at'])->utc();
        $businessEnd = CarbonImmutable::parse($input['ends_at'])->utc();
        $occupiedStart = $businessStart->subMinutes($boat->buffer_before_minutes);
        $occupiedEnd = $businessEnd->addMinutes($boat->buffer_after_minutes);
        $overlapExists = DB::table('allocations')
            ->where('organization_id', $organization->id)
            ->where('boat_id', $boat->id)
            ->where('status', 'ACTIVE')
            ->where('occupied_start', '<', $occupiedEnd)
            ->where('occupied_end', '>', $occupiedStart)
            ->exists();

        $payload = [
            'request_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'boat_id' => $boat->id,
            'available' => ! $overlapExists,
            'occupied_start' => $occupiedStart->format('Y-m-d\TH:i:s\Z'),
            'occupied_end' => $occupiedEnd->format('Y-m-d\TH:i:s\Z'),
            'inventory_revision' => $organization->inventory_revision,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'business_timezone' => $organization->timezone,
        ];

        if ($overlapExists) {
            $payload += [
                'code' => 'SLOT_UNAVAILABLE',
                'retryable' => false,
                'manual_action_required' => false,
                'message' => 'The requested slot is unavailable.',
            ];
        }

        return response()->json($payload);
    }
}
