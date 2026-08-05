<?php

use App\Http\Controllers\DemoScheduleController;
use App\Http\Controllers\DemoSiteController;
use App\Http\Middleware\ResolveDemoSiteContext;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home');

Route::get('/api-docs', function () {
    return response()->file(public_path('api-docs.html'), [
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive',
    ]);
});

Route::get('/operations-api-docs', function () {
    return response()->file(public_path('operations-api-docs.html'), [
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive',
    ]);
});

Route::prefix('demo')->name('demo.')->middleware(ResolveDemoSiteContext::class)->group(function (): void {
    Route::get('/', [DemoSiteController::class, 'index'])->name('index');
    Route::get('/calendar', [DemoScheduleController::class, 'calendar'])->name('calendar');
    Route::get('/slots', [DemoScheduleController::class, 'slots'])->name('slots');
    Route::post('/slots/reusable', [DemoScheduleController::class, 'createReusable'])->name('slots.reusable');
    Route::post('/slots/instances', [DemoScheduleController::class, 'createCustomInstance'])->name('slots.instances');
    Route::post('/slots/compatibility', [DemoScheduleController::class, 'compatibility'])->name('slots.compatibility');
    Route::post('/slots/{id}:activate', [DemoScheduleController::class, 'activate'])->name('slots.activate');
    Route::post('/slots/{id}:retire', [DemoScheduleController::class, 'retire'])->name('slots.retire');
    Route::post('/fuel', [DemoSiteController::class, 'fuel'])->name('fuel');
    Route::post('/expenses', [DemoSiteController::class, 'expense'])->name('expense');

    Route::post('/stock', [DemoSiteController::class, 'stock'])->name('stock');
    Route::post('/reversals', [DemoSiteController::class, 'reverse'])->name('reverse');
});
