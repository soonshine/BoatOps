@extends('operator.layout')
@section('title', 'Operator BLOCKs')
@section('content')
<h1>{{ $organization->name }} BLOCKs</h1>
<p>All entered date-times and displayed occupied intervals use organization timezone: <strong>{{ $organization->timezone }}</strong>. The server converts local input to UTC; browser timezone interpretation is not used.</p>
<p><strong>WEATHER is a manual reason label only.</strong> Automated weather trigger, evidence, timing, and override rules remain <strong>OWNER_DECISION_REQUIRED</strong>. This page exposes no automated weather rule.</p>
<h2>Create BLOCK</h2>
<form method="post" action="{{ route('operator.blocks.store') }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $createIdempotencyKey }}">
<label>External reference <input name="external_reference" value="{{ old('external_reference') }}" required maxlength="255"></label>
<label>Resource <select name="boat_id" required><option value="">Choose resource</option>@foreach($boats as $boat)<option value="{{ $boat->id }}" @selected((string) old('boat_id') === (string) $boat->id)>{{ $boat->name }}</option>@endforeach</select></label>
<label>Start ({{ $organization->timezone }}) <input type="datetime-local" name="starts_at_local" value="{{ old('starts_at_local') }}" required></label>
<label>End ({{ $organization->timezone }}) <input type="datetime-local" name="ends_at_local" value="{{ old('ends_at_local') }}" required></label>
<label>Reason code <select name="reason_code" required>@foreach(['MAINTENANCE', 'WEATHER', 'OWNER_USE', 'MANUAL'] as $code)<option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ $code }}</option>@endforeach</select></label>
<label>Reason <textarea name="reason" maxlength="500">{{ old('reason') }}</textarea></label>
<button type="submit">Create BLOCK</button>
</form>
<h2>Organization BLOCK list</h2>
@forelse($blocks as $block)
<article class="card">
<div><strong>Status:</strong> {{ $block->status }}</div>
<div><strong>Resource:</strong> {{ $block->resource_name }}</div>
<div><strong>Exact occupied interval ({{ $organization->timezone }}):</strong> {{ $block->occupied_start_local }} — {{ $block->occupied_end_local }}</div>
<div><strong>Reason code:</strong> {{ $block->reason_code }}</div>
<div><strong>Reason:</strong> {{ $block->reason ?: '—' }}</div>
@if($block->status === 'ACTIVE')
<form method="post" action="{{ route('operator.blocks.release', $block->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $block->release_idempotency_key }}">
<label>Release reason <input name="reason" maxlength="500"></label>
<button type="submit">Release BLOCK</button>
</form>
@endif
</article>
@empty
<p>No BLOCK records.</p>
@endforelse
@endsection
