@extends('operator.layout')

@section('title', 'Fleet Inventory')
@section('bodyClass', 'fleet-calendar-body')

@section('head')
<style>
.fleet-calendar-body,
.fleet-calendar-body * { box-sizing: border-box; }
.fleet-calendar-body {
    margin: 0;
    max-width: 100%;
    overflow-x: hidden;
    background: #f4f7fb;
    color: #132238;
    line-height: 1.45;
}
.fleet-calendar-body .operator-nav {
    position: relative;
    z-index: 40;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .35rem;
    padding: .75rem clamp(.75rem, 3vw, 2rem);
    border-bottom: 1px solid #d9e2ec;
    background: #fff;
    box-shadow: 0 1px 2px rgb(15 23 42 / 5%);
}
.fleet-calendar-body .operator-nav a,
.fleet-calendar-body .operator-nav button {
    display: inline-flex;
    align-items: center;
    min-height: 2.25rem;
    padding: .45rem .7rem;
    border: 0;
    border-radius: .5rem;
    background: transparent;
    color: #334e68;
    font: inherit;
    font-size: .875rem;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}
.fleet-calendar-body .operator-nav a:first-child {
    background: #e0f2fe;
    color: #075985;
}
.fleet-calendar-body .operator-nav form { margin: 0 0 0 auto; }
.fleet-calendar-page {
    width: min(100%, 1840px);
    margin: 0 auto;
    padding: clamp(1rem, 2.5vw, 2rem);
}
.fleet-page-header {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.fleet-eyebrow {
    margin: 0 0 .3rem;
    color: #52667a;
    font-size: .78rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}
.fleet-page-header h1 {
    margin: 0;
    color: #102a43;
    font-size: clamp(1.75rem, 4vw, 2.65rem);
    line-height: 1.08;
    letter-spacing: -.035em;
}
.fleet-subtitle {
    max-width: 56rem;
    margin: .65rem 0 0;
    color: #52667a;
}
.fleet-range-label {
    margin: 0;
    padding: .55rem .8rem;
    border: 1px solid #cbd8e6;
    border-radius: .65rem;
    background: #fff;
    color: #334e68;
    font-size: .875rem;
    font-weight: 750;
    white-space: nowrap;
}
.fleet-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(8rem, 1fr));
    gap: .75rem;
    margin: 0 0 1rem;
}
.summary-card {
    position: relative;
    overflow: hidden;
    min-height: 6.2rem;
    padding: .85rem 1rem;
    border: 1px solid #d8e2ec;
    border-radius: .85rem;
    background: #fff;
    box-shadow: 0 4px 14px rgb(15 23 42 / 5%);
}
.summary-card::before {
    position: absolute;
    inset: 0 auto 0 0;
    width: .3rem;
    background: var(--status-color);
    content: '';
}
.summary-card span {
    display: block;
    color: #52667a;
    font-size: .75rem;
    font-weight: 850;
    letter-spacing: .06em;
}
.summary-card strong {
    display: block;
    margin-top: .15rem;
    color: #102a43;
    font-size: 1.8rem;
    line-height: 1.1;
}
.summary-card small { color: #6b7f93; }
.status-available { --status-color: #15803d; --status-soft: #ecfdf3; --status-ink: #166534; }
.status-held { --status-color: #d97706; --status-soft: #fffbeb; --status-ink: #92400e; }
.status-confirmed { --status-color: #2563eb; --status-soft: #eff6ff; --status-ink: #1e40af; }
.status-blocked { --status-color: #dc2626; --status-soft: #fff1f2; --status-ink: #991b1b; }
.status-unavailable { --status-color: #64748b; --status-soft: #f1f5f9; --status-ink: #475569; }
.fleet-toolbar {
    display: grid;
    grid-template-columns: minmax(11rem, 1fr) minmax(12rem, 1.3fr) auto;
    gap: .8rem;
    align-items: end;
    padding: 1rem;
    border: 1px solid #d8e2ec;
    border-radius: .9rem;
    background: #fff;
    box-shadow: 0 4px 14px rgb(15 23 42 / 5%);
}
.fleet-toolbar label {
    margin: 0;
    color: #334e68;
    font-size: .78rem;
    font-weight: 800;
}
.fleet-toolbar input,
.fleet-toolbar select {
    display: block;
    width: 100%;
    min-height: 2.65rem;
    margin-top: .3rem;
    padding: .55rem .7rem;
    border: 1px solid #9fb3c8;
    border-radius: .55rem;
    background: #fff;
    color: #102a43;
    font: inherit;
}
.button,
.fleet-toolbar button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 2.65rem;
    padding: .55rem .9rem;
    border: 1px solid #075985;
    border-radius: .55rem;
    background: #075985;
    color: #fff;
    font: inherit;
    font-size: .875rem;
    font-weight: 800;
    line-height: 1;
    text-decoration: none;
    cursor: pointer;
}
.button.secondary {
    border-color: #c5d2df;
    background: #fff;
    color: #334e68;
}
.fleet-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: .65rem;
    margin: .8rem 0 1rem;
}
.range-switcher,
.date-pager,
.status-legend {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .4rem;
}
.range-switcher .button[aria-current="page"] {
    border-color: #075985;
    background: #e0f2fe;
    color: #075985;
}
.status-legend {
    margin: .25rem 0 .8rem;
    color: #52667a;
    font-size: .8rem;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    width: fit-content;
    padding: .23rem .48rem;
    border-radius: 999px;
    background: var(--status-soft);
    color: var(--status-ink);
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .045em;
    line-height: 1.3;
}
.status-badge::before {
    width: .42rem;
    height: .42rem;
    border-radius: 50%;
    background: var(--status-color);
    content: '';
}
.calendar-scroll {
    max-width: 100%;
    max-height: min(72vh, 780px);
    overflow: auto;
    border: 1px solid #cbd8e6;
    border-radius: 1rem;
    background: #fff;
    box-shadow: 0 10px 28px rgb(15 23 42 / 8%);
    overscroll-behavior-inline: contain;
    scrollbar-gutter: stable;
}
.calendar-scroll:focus-visible {
    outline: 3px solid #38bdf8;
    outline-offset: 2px;
}
.fleet-table {
    width: max-content;
    min-width: 100%;
    border: 0;
    border-collapse: separate;
    border-spacing: 0;
    table-layout: fixed;
}
.fleet-table th,
.fleet-table td {
    border: 0;
    border-right: 1px solid #dce5ee;
    border-bottom: 1px solid #dce5ee;
    padding: .75rem;
    vertical-align: top;
}
.fleet-table thead th {
    position: sticky;
    top: 0;
    z-index: 8;
    min-width: 14rem;
    width: 14rem;
    padding: .65rem .75rem;
    background: #eaf1f8;
    color: #334e68;
    box-shadow: inset 0 -1px 0 #cbd8e6;
    font-size: .75rem;
    text-align: center;
}
.fleet-table thead th time { display: block; }
.fleet-table thead .weekday {
    display: block;
    margin-bottom: .12rem;
    color: #075985;
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .06em;
    text-transform: uppercase;
}
.fleet-table .boat-column {
    position: sticky;
    left: 0;
    z-index: 7;
    width: 12rem;
    min-width: 12rem;
    max-width: 12rem;
    background: #f8fafc;
    box-shadow: 1px 0 0 #cbd8e6;
}
.fleet-table thead .boat-column {
    z-index: 12;
    background: #16324f;
    color: #fff;
    text-align: left;
}
.fleet-table tbody .boat-column {
    padding: .95rem .8rem;
    color: #102a43;
}
.boat-name {
    display: block;
    font-size: 1rem;
    line-height: 1.25;
}
.boat-meta {
    display: block;
    margin-top: .35rem;
    color: #627d98;
    font-size: .72rem;
    font-weight: 650;
}
.date-cell {
    width: 14rem;
    min-width: 14rem;
    background: #fbfdff;
}
.cell-date { display: none; }
.slot-card {
    position: relative;
    margin: 0 0 .65rem;
    padding: .72rem;
    border: 1px solid #d6e0ea;
    border-left: 4px solid var(--status-color);
    border-radius: .7rem;
    background: var(--status-soft);
    color: #25374a;
}
.slot-card:last-of-type { margin-bottom: 0; }
.slot-card.status-unavailable {
    background-color: #f5f7fa;
    background-image: repeating-linear-gradient(135deg, transparent, transparent 8px, rgb(100 116 139 / 5%) 8px, rgb(100 116 139 / 5%) 16px);
}
.slot-card header {
    display: flex;
    align-items: start;
    justify-content: space-between;
    gap: .5rem;
}
.slot-card h3 {
    margin: 0;
    color: #132238;
    font-size: .9rem;
    line-height: 1.25;
}
.slot-time {
    display: flex;
    flex-wrap: wrap;
    gap: .28rem .5rem;
    margin: .45rem 0 0;
    color: #334e68;
    font-size: .78rem;
    font-variant-numeric: tabular-nums;
    font-weight: 750;
}
.slot-duration { color: #6b7f93; font-weight: 650; }
.buffer-indicator,
.slot-reason,
.slot-detail-state {
    margin: .55rem 0 0;
    font-size: .74rem;
    line-height: 1.4;
}
.buffer-indicator {
    padding: .42rem .5rem;
    border: 1px solid #fdba74;
    border-radius: .45rem;
    background: #fff7ed;
    color: #9a3412;
    font-weight: 750;
}
.slot-reason { color: #52667a; }
.slot-action {
    display: inline-flex;
    margin-top: .65rem;
    color: #075985;
    font-size: .76rem;
    font-weight: 850;
    text-underline-offset: .18rem;
}
.slot-detail-state { color: #627d98; font-style: italic; }
.empty-slots {
    margin: 0;
    color: #7b8fa3;
    font-size: .8rem;
}
.occupied-details {
    margin-top: .65rem;
    padding-top: .55rem;
    border-top: 1px dashed #b8c7d6;
    color: #52667a;
    font-size: .72rem;
}
.occupied-details summary {
    font-weight: 800;
    cursor: pointer;
}
.occupied-row {
    display: grid;
    gap: .15rem;
    margin-top: .5rem;
    padding: .45rem;
    border-radius: .45rem;
    background: #fff;
}
.occupied-row time { font-variant-numeric: tabular-nums; }
.fleet-empty {
    padding: 2.5rem !important;
    color: #627d98;
    text-align: center !important;
}
.projection-note {
    display: flex;
    flex-wrap: wrap;
    align-items: start;
    justify-content: space-between;
    gap: .75rem;
    margin-top: .85rem;
    color: #52667a;
    font-size: .78rem;
}
.projection-note p { margin: 0; }
.projection-note details { max-width: 38rem; }
.projection-note summary { color: #334e68; font-weight: 750; cursor: pointer; }
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
@media (max-width: 760px) {
    .fleet-calendar-body .operator-nav { padding: .6rem .75rem; }
    .fleet-calendar-body .operator-nav form { margin-left: 0; }
    .fleet-calendar-page { padding: .85rem .65rem 1.5rem; }
    .fleet-page-header { align-items: start; }
    .fleet-range-label { white-space: normal; }
    .fleet-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
    .summary-card { min-height: 5.2rem; padding: .7rem .8rem; }
    .summary-card strong { font-size: 1.55rem; }
    .fleet-toolbar { grid-template-columns: 1fr; padding: .8rem; }
    .fleet-controls { align-items: stretch; }
    .range-switcher, .date-pager { width: 100%; }
    .range-switcher .button, .date-pager .button { flex: 1 1 auto; }
    .calendar-scroll { max-height: none; border-radius: .75rem; scrollbar-gutter: auto; }
    .fleet-table .boat-column { width: 8.3rem; min-width: 8.3rem; max-width: 8.3rem; }
    .fleet-table thead th, .date-cell { width: 13rem; min-width: 13rem; }
    .fleet-table th, .fleet-table td { padding: .55rem; }
    .cell-date {
        display: block;
        margin: 0 0 .45rem;
        color: #52667a;
        font-size: .7rem;
        font-weight: 850;
    }
    .slot-card { padding: .62rem; }
}
</style>
@endsection

@section('content')
@php
    $operatorMembership = request()->attributes->get('operator_membership');
    $statusLabels = [
        'AVAILABLE' => 'Available',
        'HELD' => 'Held',
        'CONFIRMED' => 'Confirmed',
        'BLOCKED' => 'Blocked',
        'UNAVAILABLE' => 'Unavailable',
    ];
    $calendarQuery = static fn (array $overrides = []): array => array_filter(
        array_merge(['from' => $from, 'range' => $range, 'boat_id' => $selectedBoatId], $overrides),
        static fn (mixed $value): bool => $value !== null,
    );
@endphp
<main class="fleet-calendar-page" data-fleet-calendar data-view-days="{{ $range }}">
<header class="fleet-page-header">
<div>
<p class="fleet-eyebrow">{{ $organization->name }} · {{ $calendar['business_timezone'] }}</p>
<h1>Fleet Inventory</h1>
<p class="fleet-subtitle">See which whole vessels can actually be sold or used for each upcoming service interval. Final HOLD and booking actions are always rechecked by the server.</p>
</div>
<p class="fleet-range-label"><time datetime="{{ $calendar['from'] }}">{{ $calendar['from'] }}</time> → <time datetime="{{ $calendar['to'] }}">{{ $calendar['to'] }}</time></p>
</header>

<section class="fleet-summary" aria-label="Projected service slot summary">
@foreach(['AVAILABLE', 'HELD', 'CONFIRMED', 'BLOCKED'] as $status)
<article class="summary-card status-{{ strtolower($status) }}" data-summary-status="{{ $status }}">
<span>{{ $status }}</span>
<strong>{{ $summary[$status] }}</strong>
<small>service slots in view</small>
</article>
@endforeach
</section>

<form class="fleet-toolbar" method="get" action="{{ route('operator.calendar') }}">
<input type="hidden" name="range" value="{{ $range }}">
<label>Start date
<input type="date" name="from" value="{{ $from }}" required>
</label>
<label>Boat
<select name="boat_id">
<option value="">All active boats</option>
@foreach($boats as $boat)
<option value="{{ $boat->id }}" @selected($selectedBoatId === (int) $boat->id)>{{ $boat->name }}</option>
@endforeach
</select>
</label>
<button type="submit">Apply view</button>
</form>

<div class="fleet-controls">
<nav class="range-switcher" aria-label="Calendar range">
@foreach([7, 14, 30] as $viewRange)
<a class="button secondary" href="{{ route('operator.calendar', $calendarQuery(['range' => $viewRange])) }}" @if($range === $viewRange) aria-current="page" @endif>{{ $viewRange }} days</a>
@endforeach
</nav>
<nav class="date-pager" aria-label="Calendar dates">
<a class="button secondary" href="{{ route('operator.calendar', $calendarQuery(['from' => $previousFrom])) }}">← Previous</a>
<a class="button secondary" href="{{ route('operator.calendar', $calendarQuery(['from' => $todayFrom])) }}">Today</a>
<a class="button secondary" href="{{ route('operator.calendar', $calendarQuery(['from' => $nextFrom])) }}">Next →</a>
</nav>
</div>

<div class="status-legend" aria-label="Inventory status legend">
<strong>Status:</strong>
@foreach(array_keys($statusLabels) as $status)
<span class="status-badge status-{{ strtolower($status) }}">{{ $status }}</span>
@endforeach
</div>

<div class="calendar-scroll" tabindex="0" aria-label="Fleet inventory calendar. Scroll horizontally to view more dates.">
<table class="fleet-table">
<caption class="sr-only">Fleet inventory by boat, date, and service interval</caption>
<thead>
<tr>
<th class="boat-column" scope="col">Boat</th>
@foreach($dateHeaders as $dateHeader)
<th scope="col">
<time datetime="{{ $dateHeader['date'] }}"><span class="weekday">{{ $dateHeader['weekday'] }}</span>{{ $dateHeader['label'] }}</time>
</th>
@endforeach
</tr>
</thead>
<tbody>
@forelse($calendar['boats'] as $boat)
<tr data-boat-id="{{ $boat['boat_id'] }}">
<th class="boat-column" scope="row">
<strong class="boat-name">{{ $boat['name'] }}</strong>
<span class="boat-meta">Whole vessel</span>
@if($boat['buffer_before_minutes'] > 0 || $boat['buffer_after_minutes'] > 0)
<span class="boat-meta">Buffer −{{ $boat['buffer_before_minutes'] }} / +{{ $boat['buffer_after_minutes'] }} min</span>
@endif
</th>
@foreach($boat['dates'] as $day)
<td class="date-cell" data-business-date="{{ $day['date'] }}">
<time class="cell-date" datetime="{{ $day['date'] }}">{{ $day['date'] }}</time>
@forelse($day['slots'] as $slot)
@php
    $status = array_key_exists($slot['status'], $statusLabels) ? $slot['status'] : 'UNAVAILABLE';
    $serviceStart = \Carbon\CarbonImmutable::parse($slot['service_start_local'])->format('H:i');
    $serviceEnd = \Carbon\CarbonImmutable::parse($slot['service_end_local'])->format('H:i');
    $occupiedStart = \Carbon\CarbonImmutable::parse($slot['occupied_start_local'])->format('H:i');
    $occupiedEnd = \Carbon\CarbonImmutable::parse($slot['occupied_end_local'])->format('H:i');
    $allocationId = (int) ($slot['authority']['allocation_id'] ?? 0);
    $actionLink = $allocationActionLinks[$allocationId] ?? null;
@endphp
<article class="slot-card status-{{ strtolower($status) }}" data-calendar-status="{{ $status }}" data-slot-code="{{ $slot['code'] }}">
<header>
<h3>{{ $slot['name'] }}</h3>
<span class="status-badge status-{{ strtolower($status) }}">{{ $status }}</span>
</header>
<p class="slot-time"><time datetime="{{ $slot['service_start_local'] }}">{{ $serviceStart }}</time>–<time datetime="{{ $slot['service_end_local'] }}">{{ $serviceEnd }}</time><span class="slot-duration">{{ $slot['duration_minutes'] }} min</span></p>
@if($slot['buffer_conflict'])
<p class="buffer-indicator">Buffer conflict · occupied {{ $occupiedStart }}–{{ $occupiedEnd }}</p>
@endif
@if($slot['conflict_message'])
<p class="slot-reason">{{ $slot['conflict_message'] }}</p>
@endif

@if($status === 'AVAILABLE' && $operatorMembership?->can_booking_workflow)
@php
    $inquiryQuery = array_filter([
        'boat_id' => $boat['boat_id'],
        'service_date' => $day['date'],
        'slot_offering_id' => $slot['slot_offering_id'],
    ], static fn (mixed $value): bool => $value !== null);
@endphp
<a class="slot-action" href="{{ route('operator.inquiries.create', $inquiryQuery) }}">Start inquiry →</a>
@elseif(in_array($status, ['HELD', 'CONFIRMED'], true) && $operatorMembership?->can_booking_workflow && $actionLink)
<a class="slot-action" href="{{ $actionLink['url'] }}">{{ $actionLink['label'] }} →</a>
@elseif($status === 'BLOCKED' && $operatorMembership?->can_block && $actionLink)
<a class="slot-action" href="{{ $actionLink['url'] }}">{{ $actionLink['label'] }} →</a>
@elseif(in_array($status, ['AVAILABLE', 'HELD', 'CONFIRMED', 'BLOCKED'], true))
<p class="slot-detail-state">No permitted direct action. Inventory detail remains visible.</p>
@endif
</article>
@empty
<p class="empty-slots">No configured service slots.</p>
@endforelse

@if($day['allocations'] !== [])
<details class="occupied-details">
<summary>{{ count($day['allocations']) }} active occupied {{ count($day['allocations']) === 1 ? 'interval' : 'intervals' }}</summary>
@foreach($day['allocations'] as $allocation)
@php
    $allocationStatus = array_key_exists($allocation['status'], $statusLabels) ? $allocation['status'] : 'UNAVAILABLE';
    $allocationStart = \Carbon\CarbonImmutable::parse($allocation['occupied_start_local'])->format('H:i');
    $allocationEnd = \Carbon\CarbonImmutable::parse($allocation['occupied_end_local'])->format('H:i');
@endphp
<div class="occupied-row">
<span class="status-badge status-{{ strtolower($allocationStatus) }}">{{ $allocationStatus }}</span>
<strong>{{ $allocation['slot_name'] ?? ucfirst(strtolower($allocation['allocation_type'])) }}</strong>
<time>{{ $allocationStart }}–{{ $allocationEnd }} occupied</time>
</div>
@endforeach
</details>
@endif
</td>
@endforeach
</tr>
@empty
<tr><td class="fleet-empty" colspan="{{ $range + 1 }}">No active boats match this view.</td></tr>
@endforelse
</tbody>
</table>
</div>

<footer class="projection-note">
<p>Calendar projection only. Final availability, HOLD, and confirmation decisions use the existing occupied-interval authority.</p>
<details>
<summary>Projection details</summary>
<p>{{ $calendar['read_model_notice'] }} Revision {{ $calendar['inventory_revision'] }} · as of {{ $calendar['as_of'] }}.</p>
</details>
</footer>
</main>
@endsection
