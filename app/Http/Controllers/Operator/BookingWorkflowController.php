<?php

namespace App\Http\Controllers\Operator;

use App\Application\Bookings\AmendBookingAction;
use App\Application\Bookings\BookingActionResult;
use App\Application\Bookings\CancelBookingAction;
use App\Application\Bookings\ConfirmBookingAction;
use App\Application\Holds\HoldActor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class BookingWorkflowController extends Controller
{
    public function __construct(
        private readonly ConfirmBookingAction $confirmBooking,
        private readonly AmendBookingAction $amendBooking,
        private readonly CancelBookingAction $cancelBooking,
    ) {}

    public function confirm(Request $request, int $inquiry, int $hold): RedirectResponse
    {
        $input = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $organizationId = $this->organizationId($request);
        $record = $this->scopedInquiry($organizationId, $inquiry);
        $linkedHold = DB::table('holds')
            ->where('organization_id', $organizationId)
            ->where('id', $hold)
            ->where('id', $record->hold_id)
            ->first();
        abort_if(! $linkedHold, 404);
        $result = $this->confirmBooking->execute(
            $organizationId,
            [
                'hold_id' => (int) $linkedHold->id,
                'external_reference' => (string) $linkedHold->external_reference,
            ],
            $input['idempotency_key'],
            HoldActor::operatorUser((int) Auth::id()),
        );

        return $this->redirectInquiryResult($result, $inquiry, 201, 'Booking confirmed without pricing.');
    }

    public function amend(Request $request, int $inquiry, int $booking): RedirectResponse
    {
        $input = $this->amendInput($request);
        $organizationId = $this->organizationId($request);
        $inquiryRecord = $this->scopedInquiry($organizationId, $inquiry);
        $bookingRecord = $this->scopedInquiryBooking($organizationId, $inquiryRecord, $booking);
        $result = $this->executeAmend($organizationId, $bookingRecord, $input);

        return $this->redirectInquiryResult($result, $inquiry, 200, 'Booking amended.');
    }

    public function cancel(Request $request, int $inquiry, int $booking): RedirectResponse
    {
        $input = $this->cancelInput($request);
        $organizationId = $this->organizationId($request);
        $inquiryRecord = $this->scopedInquiry($organizationId, $inquiry);
        $bookingRecord = $this->scopedInquiryBooking($organizationId, $inquiryRecord, $booking);
        $result = $this->executeCancel($organizationId, $bookingRecord, $input);

        return $this->redirectInquiryResult($result, $inquiry, 200, 'Booking cancelled.');
    }

    public function amendFromBooking(Request $request, int $booking): RedirectResponse
    {
        $organizationId = $this->organizationId($request);
        $bookingRecord = $this->scopedBooking($organizationId, $booking);
        $result = $this->executeAmend($organizationId, $bookingRecord, $this->amendInput($request));

        return $this->redirectBookingResult($result, $booking, 200, 'Booking amended.');
    }

    public function cancelFromBooking(Request $request, int $booking): RedirectResponse
    {
        $organizationId = $this->organizationId($request);
        $bookingRecord = $this->scopedBooking($organizationId, $booking);
        $result = $this->executeCancel($organizationId, $bookingRecord, $this->cancelInput($request));

        return $this->redirectBookingResult($result, $booking, 200, 'Booking cancelled.');
    }

    /** @return array<string, mixed> */
    private function amendInput(Request $request): array
    {
        return $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'boat_id' => ['required', 'integer', 'min:1'],
            'trip_template_id' => ['required', 'integer', 'min:1'],
            'slot_offering_id' => ['required', 'integer', 'min:1'],
            'service_date' => ['required', 'date_format:Y-m-d'],
        ]);
    }

    /** @return array<string, mixed> */
    private function cancelInput(Request $request): array
    {
        return $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** @param array<string, mixed> $input */
    private function executeAmend(int $organizationId, object $booking, array $input): BookingActionResult
    {
        foreach ([
            'boats' => 'boat_id',
            'trip_templates' => 'trip_template_id',
            'slot_offerings' => 'slot_offering_id',
        ] as $table => $field) {
            $exists = DB::table($table)
                ->where('organization_id', $organizationId)
                ->where('status', 'ACTIVE')
                ->where('id', $input[$field])
                ->exists();
            abort_if(! $exists, 404);
        }

        return $this->amendBooking->execute(
            $organizationId,
            (int) $booking->id,
            [
                'external_reference' => (string) $booking->external_reference,
                'boat_id' => (int) $input['boat_id'],
                'trip_template_id' => (int) $input['trip_template_id'],
                'slot_offering_id' => (int) $input['slot_offering_id'],
                'service_date' => $input['service_date'],
            ],
            $input['idempotency_key'],
            HoldActor::operatorUser((int) Auth::id()),
        );
    }

    /** @param array<string, mixed> $input */
    private function executeCancel(int $organizationId, object $booking, array $input): BookingActionResult
    {
        $payload = ['external_reference' => (string) $booking->external_reference];
        if (isset($input['reason']) && trim($input['reason']) !== '') {
            $payload['reason'] = trim($input['reason']);
        }

        return $this->cancelBooking->execute(
            $organizationId,
            (int) $booking->id,
            $payload,
            $input['idempotency_key'],
            HoldActor::operatorUser((int) Auth::id()),
        );
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('organization')->id;
    }

    private function scopedInquiry(int $organizationId, int $inquiry): object
    {
        $record = DB::table('inquiries')
            ->where('organization_id', $organizationId)
            ->where('id', $inquiry)
            ->first();
        abort_if(! $record, 404);

        return $record;
    }

    private function scopedInquiryBooking(int $organizationId, object $inquiry, int $booking): object
    {
        abort_if($inquiry->hold_id === null, 404);
        $record = DB::table('bookings')
            ->where('organization_id', $organizationId)
            ->where('hold_id', $inquiry->hold_id)
            ->where('id', $booking)
            ->first();
        abort_if(! $record, 404);

        return $record;
    }

    private function scopedBooking(int $organizationId, int $booking): object
    {
        $record = DB::table('bookings')
            ->where('organization_id', $organizationId)
            ->where('id', $booking)
            ->first();
        abort_if(! $record, 404);

        return $record;
    }

    private function redirectInquiryResult(BookingActionResult $result, int $inquiry, int $successStatus, string $successMessage): RedirectResponse
    {
        $redirect = redirect()->route('operator.inquiries.show', $inquiry, 303);
        if ($result->status === $successStatus) {
            return $redirect->with('status', $successMessage);
        }

        return $redirect->withErrors(['booking' => $result->payload['message']]);
    }

    private function redirectBookingResult(BookingActionResult $result, int $booking, int $successStatus, string $successMessage): RedirectResponse
    {
        $redirect = redirect()->route('operator.bookings.show', $booking, 303);
        if ($result->status === $successStatus) {
            return $redirect->with('status', $successMessage);
        }

        return $redirect->withErrors(['booking' => $result->payload['message']]);
    }
}
