@extends("operator.layout")
@section("content")
<h1>{{$inquiry->reference}}</h1>
<div>Status: {{$inquiry->status}}</div>
<div>Service date: {{$inquiry->service_date ?: "Unselected"}}</div>
<div>Notes: {{$inquiry->notes ?: "None"}}</div>

@if($hold)
<section class="card">
    <h2>Linked HOLD</h2>
    <div>Status: {{$hold->status}}</div>
    <div>Expires at (UTC): {{$hold->expires_at}}</div>
    @if($hold->status === "ACTIVE")
    <form method="post" action="{{route("operator.inquiries.hold.release", $inquiry->id)}}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{$releaseIdempotencyKey}}">
        <button>Release HOLD</button>
    </form>
    @endif
</section>
@elseif(!$holdTtlConfigured)
<section class="card error">
    <h2>HOLD unavailable</h2>
    <p>The organization HOLD TTL policy is not configured. OWNER_DECISION_REQUIRED.</p>
</section>
@elseif(!$inquiry->boat_id || !$inquiry->trip_template_id || !$inquiry->slot_offering_id || !$inquiry->service_date)
<section class="card">
    <h2>HOLD unavailable</h2>
    <p>Boat, product, slot, and service date must all be selected.</p>
</section>
@else
<section class="card">
    <h2>Create HOLD</h2>
    <p>Expiry is resolved by the server from the approved organization policy.</p>
    <form method="post" action="{{route("operator.inquiries.hold.create", $inquiry->id)}}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{$holdIdempotencyKey}}">
        <button>Create HOLD</button>
    </form>
</section>
@endif
@endsection
