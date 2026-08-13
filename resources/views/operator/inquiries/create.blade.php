@extends('operator.layout')

@section('content')
<h1>New inquiry</h1>
<p>Creating an inquiry does not allocate inventory. Operational dossier fields are optional and can be completed later.</p>

<form method="post" action="{{ route('operator.inquiries.store') }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

<section class="card">
<h2>Inquiry</h2>
<label>Neutral reference
<input name="reference" value="{{ old('reference') }}" maxlength="100" placeholder="SAMPLE-INQUIRY-001" required>
</label>
<label>Date
<input type="date" name="service_date" value="{{ old('service_date', request()->query('service_date')) }}">
</label>
<label>Resource
<select name="boat_id">
<option value="">None</option>
@foreach($boats as $x)
<option value="{{ $x->id }}" @selected((string) old('boat_id', request()->query('boat_id')) === (string) $x->id)>{{ $x->name }}</option>
@endforeach
</select>
</label>
<label>Product
<select name="trip_template_id">
<option value="">None</option>
@foreach($products as $x)
<option value="{{ $x->id }}" @selected((string) old('trip_template_id') === (string) $x->id)>{{ $x->name }}</option>
@endforeach
</select>
</label>
<label>Slot
<select name="slot_offering_id">
<option value="">None</option>
@foreach($slots as $x)
<option value="{{ $x->id }}" @selected((string) old('slot_offering_id', request()->query('slot_offering_id')) === (string) $x->id)>{{ $x->name }}</option>
@endforeach
</select>
</label>
<label>General inquiry notes
<textarea name="notes" maxlength="1000">{{ old('notes') }}</textarea>
</label>
</section>

<section class="card">
<h2>Operational Dossier</h2>
<p>Customer and execution details are restricted to the authorized Operator context.</p>
<label>Contact name
<input name="contact_name" value="{{ old('contact_name') }}" maxlength="255">
</label>
<label>Contact method
<select name="contact_method">
<option value="">None</option>
@foreach(['PHONE', 'WHATSAPP', 'WECHAT', 'LINE', 'EMAIL', 'OTHER'] as $method)
<option value="{{ $method }}" @selected(old('contact_method') === $method)>{{ $method }}</option>
@endforeach
</select>
</label>
<label>Contact value
<input name="contact_value" value="{{ old('contact_value') }}" maxlength="255">
</label>
<label>Party size
<input type="number" name="party_size" value="{{ old('party_size') }}" min="1" max="999" step="1">
</label>
<label>Meeting point
<textarea name="meeting_point" maxlength="2000">{{ old('meeting_point') }}</textarea>
</label>
<label>Service location / dropoff
<textarea name="service_location" maxlength="2000">{{ old('service_location') }}</textarea>
</label>
<label>Sales source
<input name="sales_source" value="{{ old('sales_source') }}" maxlength="255">
</label>
<label>Agent / partner reference
<input name="agent_reference" value="{{ old('agent_reference') }}" maxlength="255">
</label>
<label>Customer / service notes
<textarea name="service_notes" maxlength="5000">{{ old('service_notes') }}</textarea>
</label>
<label>Internal operations notes
<textarea name="internal_notes" maxlength="5000">{{ old('internal_notes') }}</textarea>
</label>
<label>Selling currency
<input name="selling_currency" value="{{ old('selling_currency') }}" maxlength="3" pattern="[A-Z]{3}" placeholder="THB">
</label>
<label>Selling amount (minor units)
<input type="number" name="selling_amount_minor" value="{{ old('selling_amount_minor') }}" min="0" step="1">
</label>
<p>This optional amount is operational dossier information only; it does not create a rate snapshot, tax, commission, payment, or accounting record.</p>
</section>

<button>Create</button>
</form>
@endsection
