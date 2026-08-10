@extends('operator.layout')

@section('title', 'Trip '.$trip->id)

@section('content')
<h1>Trip {{ $trip->id }}</h1>
<p>Organization timezone: {{ $organization->timezone }}</p>

@if(session('status'))
<section class="card"><p>{{ session('status') }}</p></section>
@endif

<section class="card">
<h2>Trip</h2>
<div>Trip status: {{ $trip->status }}</div>
<div>Planned: {{ \Carbon\CarbonImmutable::parse($trip->planned_start, 'UTC')->setTimezone($organization->timezone)->format('Y-m-d H:i') }} – {{ \Carbon\CarbonImmutable::parse($trip->planned_end, 'UTC')->setTimezone($organization->timezone)->format('Y-m-d H:i T') }}</div>
<div>Actual departed: {{ $trip->actual_departed_at ? \Carbon\CarbonImmutable::parse($trip->actual_departed_at, 'UTC')->setTimezone($organization->timezone)->format('Y-m-d H:i:s T') : 'Not recorded' }}</div>
<div>Actual returned: {{ $trip->actual_returned_at ? \Carbon\CarbonImmutable::parse($trip->actual_returned_at, 'UTC')->setTimezone($organization->timezone)->format('Y-m-d H:i:s T') : 'Not recorded' }}</div>
<div>Completed at: {{ $trip->completed_at ? \Carbon\CarbonImmutable::parse($trip->completed_at, 'UTC')->setTimezone($organization->timezone)->format('Y-m-d H:i:s T') : 'Not recorded' }}</div>
<div>Boat: {{ $trip->boat_name }}</div>
<div>Product / Trip template: {{ $trip->product_name }}</div>
<div>Derived readiness hint: {{ $ready ? 'Ready for departure' : 'Needs preparation' }}</div>
<div>Crew: {{ $crew->count() }}; required checklist: {{ $completedRequiredCount }}/{{ $requiredCount }} complete.</div>
</section>

<section class="card">
<h2>Booking</h2>
<div>Booking reference: {{ $trip->booking_reference }}</div>
<div>Booking status: {{ $trip->booking_status }}</div>
<p><a href="{{ route('operator.bookings.show', $trip->booking_id) }}">Open Booking</a></p>
</section>

<section class="card">
<h2>Operational Dossier</h2>
@if($trip->inquiry_id)
<div>Contact: {{ $trip->contact_name ?: 'Not provided' }}</div>
<div>Contact method / value: {{ $trip->contact_method ?: 'Not provided' }}{{ $trip->contact_value ? ' / '.$trip->contact_value : '' }}</div>
<div>Party size: {{ $trip->party_size ?: 'Not provided' }}</div>
<div>Meeting point: {{ $trip->meeting_point ?: 'Not provided' }}</div>
<div>Service location: {{ $trip->service_location ?: 'Not provided' }}</div>
<div>Customer / service notes: {{ $trip->service_notes ?: 'None' }}</div>
<div>Internal operations notes: {{ $trip->internal_notes ?: 'None' }}</div>
<div>Sales source: {{ $trip->sales_source ?: 'Not provided' }}</div>
@else
<p>No Operator inquiry dossier linked.</p>
@endif
</section>

<section class="card">
<h2>Crew</h2>
<table><thead><tr><th>External reference</th><th>Display name</th><th>Role</th><th>Duty</th></tr></thead><tbody>
@forelse($crew as $assignment)
<tr><td>{{ $assignment->external_reference }}</td><td>{{ $assignment->display_name }}</td><td>{{ $assignment->role }}</td><td>{{ $assignment->duty }}</td></tr>
@empty
<tr><td colspan="4">No crew assigned.</td></tr>
@endforelse
</tbody></table>
</section>

<section class="card">
<h2>Checklist</h2>
<table><thead><tr><th>Code</th><th>Label</th><th>Required</th><th>Completed</th><th>Completed at</th></tr></thead><tbody>
@forelse($checklist as $item)
<tr><td>{{ $item->code }}</td><td>{{ $item->label }}</td><td>{{ $item->required ? 'Yes' : 'No' }}</td><td>{{ $item->completed ? 'Yes' : 'No' }}</td><td>{{ $item->completed_at ?: 'Not completed' }}</td></tr>
@empty
<tr><td colspan="5">No checklist items.</td></tr>
@endforelse
</tbody></table>
</section>

@if($trip->status === 'PLANNED')
@php($formCrewRows = old('crew', $crewRows))
@php($formChecklistRows = old('checklist', $checklistRows))
<section class="card">
<h2>Prepare / Re-Prepare</h2>
<p>Saving preparation replaces the Trip's current crew assignments and checklist readiness.</p>
<p>Departure requires at least one crew assignment and all required checklist items completed.</p>
<form method="post" action="{{ route('operator.trips.prepare', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $prepareIdempotencyKey }}">
<h3>Crew</h3>
<div data-rows="crew">
@foreach($formCrewRows as $index => $row)
<fieldset data-row>
<label>External reference <input name="crew[{{ $index }}][external_reference]" value="{{ $row['external_reference'] ?? '' }}" maxlength="255" required></label>
<label>Display name <input name="crew[{{ $index }}][display_name]" value="{{ $row['display_name'] ?? '' }}" maxlength="255" required></label>
<label>Role <input name="crew[{{ $index }}][role]" value="{{ $row['role'] ?? '' }}" maxlength="100" required></label>
<label>Duty <input name="crew[{{ $index }}][duty]" value="{{ $row['duty'] ?? '' }}" maxlength="100" required></label>
<button type="button" data-remove-row>Remove crew row</button>
</fieldset>
@endforeach
</div>
<button type="button" data-add-row="crew">Add crew row</button>

<h3>Checklist</h3>
<div data-rows="checklist">
@foreach($formChecklistRows as $index => $row)
<fieldset data-row>
<label>Code <input name="checklist[{{ $index }}][code]" value="{{ $row['code'] ?? '' }}" maxlength="100" pattern="[A-Z0-9_-]+" required></label>
<label>Label <input name="checklist[{{ $index }}][label]" value="{{ $row['label'] ?? '' }}" maxlength="255" required></label>
<input type="hidden" name="checklist[{{ $index }}][required]" value="0"><label><input type="checkbox" name="checklist[{{ $index }}][required]" value="1" @checked((bool) ($row['required'] ?? false))> Required</label>
<input type="hidden" name="checklist[{{ $index }}][completed]" value="0"><label><input type="checkbox" name="checklist[{{ $index }}][completed]" value="1" @checked((bool) ($row['completed'] ?? false))> Completed</label>
<button type="button" data-remove-row>Remove checklist row</button>
</fieldset>
@endforeach
</div>
<button type="button" data-add-row="checklist">Add checklist row</button>
<p><button>Save preparation</button></p>
</form>
</section>

<section class="card">
<h2>Depart</h2>
<p>{{ $ready ? 'Ready for departure.' : 'Crew/checklist incomplete; the authoritative action will reject departure.' }}</p>
<form method="post" action="{{ route('operator.trips.depart', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $departIdempotencyKey }}">
<label>Actual departure ({{ $organization->timezone }}) <input type="datetime-local" name="departed_at" value="{{ $localNow }}" required></label>
<button>Depart Trip</button>
</form>
</section>
@elseif($trip->status === 'DEPARTED')
<section class="card">
<h2>Return</h2>
<form method="post" action="{{ route('operator.trips.return', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $returnIdempotencyKey }}">
<label>Actual return ({{ $organization->timezone }}) <input type="datetime-local" name="returned_at" value="{{ $localNow }}" required></label>
<button>Return Trip</button>
</form>
</section>
@elseif($trip->status === 'RETURNED')
<section class="card">
<h2>Complete</h2>
<p>Completion uses the current server time.</p>
<form method="post" action="{{ route('operator.trips.complete', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $completeIdempotencyKey }}">
<button>Complete Trip</button>
</form>
</section>
@endif

@if($trip->status === 'PLANNED')
<template data-template="crew"><fieldset data-row>
<label>External reference <input name="crew[__INDEX__][external_reference]" maxlength="255" required></label>
<label>Display name <input name="crew[__INDEX__][display_name]" maxlength="255" required></label>
<label>Role <input name="crew[__INDEX__][role]" maxlength="100" required></label>
<label>Duty <input name="crew[__INDEX__][duty]" maxlength="100" required></label>
<button type="button" data-remove-row>Remove crew row</button>
</fieldset></template>
<template data-template="checklist"><fieldset data-row>
<label>Code <input name="checklist[__INDEX__][code]" maxlength="100" pattern="[A-Z0-9_-]+" required></label>
<label>Label <input name="checklist[__INDEX__][label]" maxlength="255" required></label>
<input type="hidden" name="checklist[__INDEX__][required]" value="0"><label><input type="checkbox" name="checklist[__INDEX__][required]" value="1" checked> Required</label>
<input type="hidden" name="checklist[__INDEX__][completed]" value="0"><label><input type="checkbox" name="checklist[__INDEX__][completed]" value="1"> Completed</label>
<button type="button" data-remove-row>Remove checklist row</button>
</fieldset></template>
<script>
document.addEventListener('click', function (event) {
    const addButton = event.target.closest('[data-add-row]');
    if (addButton) {
        const type = addButton.dataset.addRow;
        const container = document.querySelector('[data-rows="' + type + '"]');
        const template = document.querySelector('[data-template="' + type + '"]');
        const index = container.querySelectorAll('[data-row]').length;
        container.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)));
    }
    const removeButton = event.target.closest('[data-remove-row]');
    if (removeButton) {
        removeButton.closest('[data-row]').remove();
    }
});
</script>
@endif
@endsection
