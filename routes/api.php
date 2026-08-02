<?php

use App\Http\Controllers\Api\Internal\V1\OperationsCommandController;
use App\Http\Controllers\Api\V1\InventoryCommandController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Middleware\AuthenticateApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::middleware(AuthenticateApiClient::class)->group(function (): void {
    Route::post('/v1/availability:check', [InventoryController::class, 'availability']);
    Route::post('/v1/holds', [InventoryCommandController::class, 'createHold']);
    Route::post('/v1/holds/{id}:release', [InventoryCommandController::class, 'releaseHold']);
    Route::post('/v1/bookings:confirm', [InventoryCommandController::class, 'confirmBooking']);
    Route::post('/v1/bookings/{id}:amend', [InventoryCommandController::class, 'amendBooking']);
    Route::post('/v1/bookings/{id}:cancel', [InventoryCommandController::class, 'cancelBooking']);
    Route::get('/v1/inventory/revision', function (Request $request) {
        $organization = $request->attributes->get('organization');

        return response()->json([
            'request_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'inventory_revision' => $organization->inventory_revision,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'business_timezone' => $organization->timezone,
        ]);
    });

    Route::post('/internal/v1/blocks', [OperationsCommandController::class, 'createBlock']);
    Route::post('/internal/v1/blocks/{id}:release', [OperationsCommandController::class, 'releaseBlock']);
    Route::post('/internal/v1/trips/{id}:prepare', [OperationsCommandController::class, 'prepareTrip']);
    Route::post('/internal/v1/trips/{id}:depart', [OperationsCommandController::class, 'departTrip']);
    Route::post('/internal/v1/trips/{id}:return', [OperationsCommandController::class, 'returnTrip']);
    Route::post('/internal/v1/trips/{id}:complete', [OperationsCommandController::class, 'completeTrip']);
});
