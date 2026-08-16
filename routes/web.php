<?php

use App\Http\Controllers\DemoScheduleController;
use App\Http\Controllers\DemoSiteController;
use App\Http\Controllers\Operator\AuditController;
use App\Http\Controllers\Operator\BlockController;
use App\Http\Controllers\Operator\BookingWorkbenchController;
use App\Http\Controllers\Operator\BookingWorkflowController;
use App\Http\Controllers\Operator\InquiryController;
use App\Http\Controllers\Operator\OperatorCalendarController;
use App\Http\Controllers\Operator\OperatorSessionController;
use App\Http\Controllers\Operator\TodayOperationsController;
use App\Http\Controllers\Operator\TripDeskController;
use App\Http\Controllers\Operator\TripWorkflowController;
use App\Http\Middleware\ResolveDemoSiteContext;
use App\Http\Middleware\UseChineseOperatorUi;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home');

Route::prefix('operator')->middleware(UseChineseOperatorUi::class)->name('operator.')->group(function (): void {
    Route::get('/login', [OperatorSessionController::class, 'create'])->name('login');
    Route::post('/login', [OperatorSessionController::class, 'store'])->middleware('throttle:operator-login')->name('login.store');
    Route::post('/logout', [OperatorSessionController::class, 'destroy'])->name('logout');
    Route::get('/today', [TodayOperationsController::class, 'index'])->middleware('operator.membership:booking_workflow')->name('today');
    Route::get('/calendar', [OperatorCalendarController::class, 'index'])->middleware('operator.membership:calendar_read')->name('calendar');
    Route::get('/audit', [AuditController::class, 'index'])->middleware('operator.membership:calendar_read')->name('audit');
    Route::get('/blocks', [BlockController::class, 'index'])->middleware('operator.membership:block')->name('blocks.index');
    Route::post('/blocks', [BlockController::class, 'store'])->middleware('operator.membership:block')->name('blocks.store');
    Route::post('/blocks/{block}/release', [BlockController::class, 'release'])->whereNumber('block')->middleware('operator.membership:block')->name('blocks.release');
    Route::get('/inquiries', [InquiryController::class, 'index'])->middleware('operator.membership:booking_workflow')->name('inquiries.index');
    Route::get('/inquiries/create', [InquiryController::class, 'create'])->middleware('operator.membership:booking_workflow')->name('inquiries.create');
    Route::post('/inquiries', [InquiryController::class, 'store'])->middleware('operator.membership:booking_workflow')->name('inquiries.store');
    Route::get('/inquiries/{inquiry}', [InquiryController::class, 'show'])->whereNumber('inquiry')->middleware('operator.membership:booking_workflow')->name('inquiries.show');
    Route::post('/inquiries/{inquiry}/dossier', [InquiryController::class, 'updateDossier'])->whereNumber('inquiry')->middleware('operator.membership:booking_workflow')->name('inquiries.dossier.update');
    Route::post('/inquiries/{inquiry}/execution', [InquiryController::class, 'updateExecution'])->whereNumber('inquiry')->middleware('operator.membership:booking_workflow')->name('inquiries.execution.update');
    Route::post('/inquiries/{inquiry}/hold', [InquiryController::class, 'createHold'])->whereNumber('inquiry')->middleware('operator.membership:booking_workflow')->name('inquiries.hold.create');
    Route::post('/inquiries/{inquiry}/hold/release', [InquiryController::class, 'releaseHold'])->whereNumber('inquiry')->middleware('operator.membership:booking_workflow')->name('inquiries.hold.release');
    Route::post('/inquiries/{inquiry}/holds/{hold}/confirm', [BookingWorkflowController::class, 'confirm'])->whereNumber(['inquiry', 'hold'])->middleware('operator.membership:booking_workflow')->name('inquiries.booking.confirm');
    Route::post('/inquiries/{inquiry}/bookings/{booking}/amend', [BookingWorkflowController::class, 'amend'])->whereNumber(['inquiry', 'booking'])->middleware('operator.membership:booking_workflow')->name('inquiries.booking.amend');
    Route::post('/inquiries/{inquiry}/bookings/{booking}/cancel', [BookingWorkflowController::class, 'cancel'])->whereNumber(['inquiry', 'booking'])->middleware('operator.membership:booking_workflow')->name('inquiries.booking.cancel');
    Route::get('/bookings', [BookingWorkbenchController::class, 'index'])->middleware('operator.membership:booking_workflow')->name('bookings.index');
    Route::get('/bookings/{booking}', [BookingWorkbenchController::class, 'show'])->whereNumber('booking')->middleware('operator.membership:booking_workflow')->name('bookings.show');
    Route::post('/bookings/{booking}/amend', [BookingWorkflowController::class, 'amendFromBooking'])->whereNumber('booking')->middleware('operator.membership:booking_workflow')->name('bookings.amend');
    Route::post('/bookings/{booking}/cancel', [BookingWorkflowController::class, 'cancelFromBooking'])->whereNumber('booking')->middleware('operator.membership:booking_workflow')->name('bookings.cancel');
    Route::get('/trips', [TripDeskController::class, 'index'])->middleware('operator.membership:booking_workflow')->name('trips.index');
    Route::get('/trips/{trip}', [TripDeskController::class, 'show'])->whereNumber('trip')->middleware('operator.membership:booking_workflow')->name('trips.show');
    Route::post('/trips/{trip}/prepare', [TripWorkflowController::class, 'prepare'])->whereNumber('trip')->middleware('operator.membership:booking_workflow')->name('trips.prepare');
    Route::post('/trips/{trip}/depart', [TripWorkflowController::class, 'depart'])->whereNumber('trip')->middleware('operator.membership:booking_workflow')->name('trips.depart');
    Route::post('/trips/{trip}/return', [TripWorkflowController::class, 'return'])->whereNumber('trip')->middleware('operator.membership:booking_workflow')->name('trips.return');
    Route::post('/trips/{trip}/complete', [TripWorkflowController::class, 'complete'])->whereNumber('trip')->middleware('operator.membership:booking_workflow')->name('trips.complete');
});

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
