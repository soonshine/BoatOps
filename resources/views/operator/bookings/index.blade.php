@extends('operator.layout')

@section('title', 'Bookings')

@section('content')
<h1>Bookings</h1>
<p>Service dates and times use the organization timezone: <strong>{{ $organization->timezone }}</strong>.</p>

<nav aria-label="Booking time views">
<a href="{{ route('operator.bookings.index', ['view' => 'today']) }}" @if($selectedView === 'today') aria-current="page" @endif>Today</a>
<a href="{{ route('operator.bookings.index', ['view' => 'upcoming']) }}" @if($selectedView === 'upcoming') aria-current="page" @endif>Upcoming</a>
<a href="{{ route('operator.bookings.index', ['view' => 'all']) }}" @if($selectedView === 'all') aria-current="page" @endif>All</a>
</nav>

<form method="get" action="{{ route('operator.bookings.index') }}" class="card">
<input type="hidden" name="view" value="{{ $selectedView }}">
<label>Organization-local service date
<input type="date" name="date" value="{{ request('date') }}">
</label>
<label>Booking status
<select name="status">
<option value="">Any status</option>
@foreach($bookingStatuses as $status)
<option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
@endforeach
</select>
</label>
<label>Reference or customer name
<input name="q" value="{{ request('q') }}" maxlength="100">
</label>
<button>Filter bookings</button>
<a href="{{ route('operator.bookings.index', ['view' => $selectedView]) }}">Clear filters</a>
</form>

@if($bookings->isEmpty())
<p>No bookings match these filters.</p>
@else
<table>
<thead>
<tr>
<th>Service date/time</th>
<th>Booking reference</th>
<th>Boat</th>
<th>Product / Trip template</th>
<th>Booking status</th>
<th>Customer</th>
<th>Party size</th>
<th>Trip status</th>
<th>Sales source</th>
</tr>
</thead>
<tbody>
@foreach($bookings as $booking)
<tr data-booking-id="{{ $booking->id }}">
<td>{{ \Carbon\CarbonImmutable::parse($booking->business_start, 'UTC')->setTimezone($organization->timezone)->format('Y-m-d H:i') }} – {{ \Carbon\CarbonImmutable::parse($booking->business_end, 'UTC')->setTimezone($organization->timezone)->format('H:i T') }}</td>
<td><a href="{{ route('operator.bookings.show', $booking->id) }}">{{ $booking->external_reference }}</a></td>
<td>{{ $booking->boat_name }}</td>
<td>{{ $booking->product_name }}</td>
<td>{{ $booking->status }}</td>
<td>{{ $booking->contact_name ?: 'No linked dossier' }}</td>
<td>{{ $booking->party_size ?: 'Not provided' }}</td>
<td>{{ $booking->trip_status ?: 'No trip' }}</td>
<td>{{ $booking->sales_source ?: 'Not provided' }}</td>
</tr>
@endforeach
</tbody>
</table>

{{ $bookings->links() }}
@endif
@endsection
