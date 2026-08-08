@extends('operator.layout')
@section('content')
<h1>{{ $inquiry->reference }}
</h1>
<div>Status: {{ $inquiry->status }}
</div>
<div>Service date: {{ $inquiry->service_date ?: 'Unselected' }}
</div>
<div>Notes: {{ $inquiry->notes ?: 'None' }}
</div>
<section class="card error">
<h2>G1 commercial boundary
</h2>
<p>Pricing and payment are outside G1. Operator confirmation creates an explicitly unpriced booking with no rate snapshot. It is not production-commercial ready.
</p>
</section>
@if(session('status'))
<section class="card">
<p>{{ session('status') }}
</p>
</section>
@endif
@if($errors->has('booking'))
<section class="card error">
<p>{{ $errors->first('booking') }}
</p>
</section>
@endif
@if($hold)
<section class="card">
<h2>Linked HOLD
</h2>
<div>Status: {{ $hold->status }}
</div>
<div>Expires at (UTC): {{ $hold->expires_at }}
</div>
@if($hold->status === 'ACTIVE' && \Carbon\CarbonImmutable::parse($hold->expires_at)->isFuture())
<form method="post" action="{{ route('operator.inquiries.booking.confirm', [$inquiry->id, $hold->id]) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $confirmIdempotencyKey }}">
<button>Confirm unpriced booking
</button>
</form>
<form method="post" action="{{ route('operator.inquiries.hold.release', $inquiry->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $releaseIdempotencyKey }}">
<button>Release HOLD
</button>
</form>
@elseif($hold->status === 'ACTIVE')
<p>This HOLD is past its displayed expiry. Confirmation delegates expiry resolution to the booking action.
</p>
@endif
</section>
@elseif(!$holdTtlConfigured)
<section class="card error">
<h2>HOLD unavailable
</h2>
<p>The organization HOLD TTL policy is not configured. OWNER_DECISION_REQUIRED.
</p>
</section>
@elseif(!$inquiry->boat_id || !$inquiry->trip_template_id || !$inquiry->slot_offering_id || !$inquiry->service_date)
<section class="card">
<h2>HOLD unavailable
</h2>
<p>Boat, product, slot, and service date must all be selected.
</p>
</section>
@else
<section class="card">
<h2>Create HOLD
</h2>
<p>Expiry is resolved by the server from the approved organization policy.
</p>
<form method="post" action="{{ route('operator.inquiries.hold.create', $inquiry->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $holdIdempotencyKey }}">
<button>Create HOLD
</button>
</form>
</section>
@endif
@if($booking)
<section class="card">
<h2>Associated booking
</h2>
<div>Status: {{ $booking->status }}
</div>
<div>Commercial readiness: {{ $booking->rate_snapshot_id === null ? 'UNPRICED / NOT PRODUCTION-COMMERCIAL READY' : 'Rate snapshot recorded outside this G1 UI' }}
</div>
<div>Trip status: {{ $trip?->status ?: 'No associated trip' }}
</div>
@if($booking->status === 'CONFIRMED')
<h3>Amend / reschedule
</h3>
<p>The shared booking action resolves compatibility, buffers, overlap, locking, inventory, trip, audit, revision, and outbox effects.
</p>
<form method="post" action="{{ route('operator.inquiries.booking.amend', [$inquiry->id, $booking->id]) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $amendIdempotencyKey }}">
<label>Active resource
<select name="boat_id" required>
@foreach($boats as $boat)
<option value="{{ $boat->id }}"
@selected($boat->id === $booking->boat_id)>{{ $boat->name }}
</option>
@endforeach
</select>
</label>
<label>Active product / template
<select name="trip_template_id" required>
@foreach($products as $product)
<option value="{{ $product->id }}"
@selected($product->id === $booking->trip_template_id)>{{ $product->name }}
</option>
@endforeach
</select>
</label>
<label>Active slot offering
<select name="slot_offering_id" required>
@foreach($slots as $slot)
<option value="{{ $slot->id }}"
@selected($slot->id === $booking->slot_offering_id)>{{ $slot->name }}
</option>
@endforeach
</select>
</label>
<label>Service date
<input type="date" name="service_date" value="{{ $booking->service_date }}" required>
</label>
<button>Amend booking
</button>
</form>
<h3>Cancel
</h3>
<form method="post" action="{{ route('operator.inquiries.booking.cancel', [$inquiry->id, $booking->id]) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $cancelIdempotencyKey }}">
<label>Optional neutral reason
<textarea name="reason" maxlength="500">
</textarea>
</label>
<button>Cancel booking
</button>
</form>
@endif
</section>
@endif
@endsection
