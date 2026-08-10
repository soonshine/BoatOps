@extends('operator.layout')

@section('title', 'Booking '.$booking->external_reference)

@section('content')
<h1>Booking {{ $booking->external_reference }}</h1>

@if(session('status'))
<section class="card">
<p>{{ session('status') }}</p>
</section>
@endif

<section class="card">
<h2>Booking</h2>
<div>External reference: {{ $booking->external_reference }}</div>
<div>Booking status: {{ $booking->status }}</div>
<div>Confirmed at: {{ $booking->confirmed_at ?: 'Not recorded' }}</div>
<div>Cancelled at: {{ $booking->cancelled_at ?: 'Not cancelled' }}</div>
<div>Service: {{ \Carbon\CarbonImmutable::parse($booking->business_start, 'UTC')->setTimezone($organization->timezone)->format('Y-m-d H:i') }} – {{ \Carbon\CarbonImmutable::parse($booking->business_end, 'UTC')->setTimezone($organization->timezone)->format('Y-m-d H:i T') }}</div>
<div>Organization timezone: {{ $organization->timezone }}</div>
<div>Boat: {{ $booking->boat_name }}</div>
<div>Product / Trip template: {{ $booking->product_name }}</div>
</section>

<section class="card">
<h2>Operational Dossier</h2>
@if($booking->inquiry_id)
<div>Inquiry reference: {{ $booking->inquiry_reference }}</div>
<div>Contact: {{ $booking->contact_name ?: 'Not provided' }}</div>
<div>Contact method / value: {{ $booking->contact_method ?: 'Not provided' }}{{ $booking->contact_value ? ' / '.$booking->contact_value : '' }}</div>
<div>Party size: {{ $booking->party_size ?: 'Not provided' }}</div>
<div>Meeting point: {{ $booking->meeting_point ?: 'Not provided' }}</div>
<div>Service location: {{ $booking->service_location ?: 'Not provided' }}</div>
<div>Sales source: {{ $booking->sales_source ?: 'Not provided' }}</div>
<div>Agent / partner reference: {{ $booking->agent_reference ?: 'Not provided' }}</div>
<div>Customer / service notes: {{ $booking->service_notes ?: 'None' }}</div>
<div>Internal operations notes: {{ $booking->internal_notes ?: 'None' }}</div>
<div>Selling amount: {{ $booking->selling_currency && $booking->selling_amount_minor !== null ? $booking->selling_currency.' '.$booking->selling_amount_minor.' minor units' : 'Not provided' }}</div>
<p><a href="{{ route('operator.inquiries.show', $booking->inquiry_id) }}">View Inquiry / Edit Operational Dossier</a></p>
@else
<p>No Operator inquiry dossier linked.</p>
@endif
</section>

<section class="card">
<h2>Trip summary</h2>
@if($booking->trip_id)
<div>Trip status: {{ $booking->trip_status }}</div>
<div>Planned start: {{ $booking->planned_start }}</div>
<div>Planned end: {{ $booking->planned_end }}</div>
<div>Actual departed at: {{ $booking->actual_departed_at ?: 'Not recorded' }}</div>
<div>Actual returned at: {{ $booking->actual_returned_at ?: 'Not recorded' }}</div>
<div>Completed at: {{ $booking->completed_at ?: 'Not recorded' }}</div>
<p><a href="{{ route('operator.trips.show', $booking->trip_id) }}">Open Trip Desk</a></p>
@else
<p>No Trip linked.</p>
@endif
</section>

@if($booking->status === 'CONFIRMED' && $booking->trip_status === 'PLANNED')
<section class="card">
<h2>Amend / reschedule</h2>
<p>The existing authoritative booking action re-adjudicates inventory, compatibility, buffers and overlap.</p>
<form method="post" action="{{ route('operator.bookings.amend', $booking->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $amendIdempotencyKey }}">
<label>Active boat
<select name="boat_id" required>
@foreach($boats as $boat)
<option value="{{ $boat->id }}" @selected((int) $boat->id === (int) $booking->boat_id)>{{ $boat->name }}</option>
@endforeach
</select>
</label>
<label>Active product / Trip template
<select name="trip_template_id" required>
@foreach($products as $product)
<option value="{{ $product->id }}" @selected((int) $product->id === (int) $booking->trip_template_id)>{{ $product->name }}</option>
@endforeach
</select>
</label>
<label>Active slot offering
<select name="slot_offering_id" required>
@foreach($slots as $slot)
<option value="{{ $slot->id }}" @selected((int) $slot->id === (int) $booking->slot_offering_id)>{{ $slot->name }}</option>
@endforeach
</select>
</label>
<label>Organization-local service date
<input type="date" name="service_date" value="{{ $booking->service_date }}" required>
</label>
<button>Amend booking</button>
</form>
</section>

<section class="card">
<h2>Cancel</h2>
<form method="post" action="{{ route('operator.bookings.cancel', $booking->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $cancelIdempotencyKey }}">
<label>Optional neutral reason
<textarea name="reason" maxlength="500"></textarea>
</label>
<button>Cancel booking</button>
</form>
</section>
@elseif($booking->status === 'CONFIRMED' && $booking->trip_id && $booking->trip_status !== 'PLANNED')
<section class="card">
<p>Booking changes are unavailable after Trip execution has started.</p>
</section>
@endif
@endsection
