@extends('operator.layout')

@section('title', "Today's Trips")

@section('content')
<h1>Today's Trips</h1>
<p>Operational date is interpreted in the organization timezone: {{ $organization->timezone }}.</p>

<section class="card">
<form method="get" action="{{ route('operator.trips.index') }}">
<label>Organization-local date
<input type="date" name="date" value="{{ $date }}" required>
</label>
<button>View Trips</button>
</form>
</section>

<table>
<thead><tr><th>Planned time</th><th>Booking</th><th>Boat / Product</th><th>Contact / Party</th><th>Trip status</th><th>Readiness</th><th></th></tr></thead>
<tbody>
@forelse($trips as $trip)
@php
$crewCount = (int) $trip->crew_count;
$requiredCount = (int) $trip->required_checklist_count;
$completedRequiredCount = (int) $trip->completed_required_count;
$ready = $crewCount > 0 && $requiredCount > 0 && $requiredCount === $completedRequiredCount;
@endphp
<tr>
<td>{{ \Carbon\CarbonImmutable::parse($trip->planned_start, 'UTC')->setTimezone($organization->timezone)->format('Y-m-d H:i') }} – {{ \Carbon\CarbonImmutable::parse($trip->planned_end, 'UTC')->setTimezone($organization->timezone)->format('H:i T') }}</td>
<td>{{ $trip->booking_reference }}</td>
<td>{{ $trip->boat_name }}<br>{{ $trip->product_name }}</td>
<td>{{ $trip->contact_name ?: 'Not provided' }}<br>Party: {{ $trip->party_size ?: 'Not provided' }}</td>
<td>{{ $trip->status }}</td>
<td>Crew: {{ $crewCount }}<br>Checklist: {{ $completedRequiredCount }}/{{ $requiredCount }} required complete<br>Derived hint: {{ $ready ? 'Ready for departure' : 'Needs preparation' }}</td>
<td><a href="{{ route('operator.trips.show', $trip->id) }}">Open Trip</a></td>
</tr>
@empty
<tr><td colspan="7">No Trips are planned for this organization-local date.</td></tr>
@endforelse
</tbody>
</table>

{{ $trips->links() }}
@endsection
