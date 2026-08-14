@extends('operator.layout')

@section('title', '订单 '.$booking->external_reference)

@section('content')
<h1>订单 {{ $booking->external_reference }}</h1>

<section class="card">
<h2>订单信息</h2>
<div>外部参考号：{{ $booking->external_reference }}</div>
<div>订单状态：{{ \App\Support\OperatorUi::status($booking->status) }}</div>
<div>确认时间：{{ \App\Support\OperatorUi::dateTime($booking->confirmed_at, $organization->timezone) }}</div>
<div>取消时间：{{ \App\Support\OperatorUi::dateTime($booking->cancelled_at, $organization->timezone) }}</div>
<div>服务时间：{{ \App\Support\OperatorUi::dateTimeRange($booking->business_start, $booking->business_end, $organization->timezone) }}</div>
<div>组织时区：{{ $organization->timezone }}</div>
<div>船只：{{ $booking->boat_name }}</div>
<div>产品 / 出航模板：{{ $booking->product_name }}</div>
</section>

<section class="card">
<h2>运营资料</h2>
@if($booking->inquiry_id)
<div>询价参考号：{{ $booking->inquiry_reference }}</div>
<div>联系人：{{ $booking->contact_name ?: '未提供' }}</div>
<div>联系方式：{{ \App\Support\OperatorUi::contactMethod($booking->contact_method) }}{{ $booking->contact_value ? ' / '.$booking->contact_value : '' }}</div>
<div>人数：{{ $booking->party_size ?: '未提供' }}</div>
<div>集合地点：{{ $booking->meeting_point ?: '未提供' }}</div>
<div>服务地点 / 下客点：{{ $booking->service_location ?: '未提供' }}</div>
<div>销售来源：{{ $booking->sales_source ?: '未提供' }}</div>
<div>代理 / 合作方参考号：{{ $booking->agent_reference ?: '未提供' }}</div>
<div>客户 / 服务备注：{{ $booking->service_notes ?: '无' }}</div>
<div>内部运营备注：{{ $booking->internal_notes ?: '无' }}</div>
<div>销售金额：{{ $booking->selling_currency && $booking->selling_amount_minor !== null ? $booking->selling_currency.' '.$booking->selling_amount_minor.'（最小货币单位）' : '未提供' }}</div>
<p><a href="{{ route('operator.inquiries.show', $booking->inquiry_id) }}">查看询价 / 编辑运营资料</a></p>
@else
<p>未关联操作员询价资料。</p>
@endif
</section>

<section class="card">
<h2>出航摘要</h2>
@if($booking->trip_id)
<div>出航状态：{{ \App\Support\OperatorUi::status($booking->trip_status) }}</div>
<div>计划时间：{{ \App\Support\OperatorUi::dateTimeRange($booking->planned_start, $booking->planned_end, $organization->timezone) }}</div>
<div>实际出航：{{ \App\Support\OperatorUi::dateTime($booking->actual_departed_at, $organization->timezone) }}</div>
<div>实际返航：{{ \App\Support\OperatorUi::dateTime($booking->actual_returned_at, $organization->timezone) }}</div>
<div>完成时间：{{ \App\Support\OperatorUi::dateTime($booking->completed_at, $organization->timezone) }}</div>
<p><a href="{{ route('operator.trips.show', $booking->trip_id) }}">打开出航工作台</a></p>
@else
<p>未关联出航记录。</p>
@endif
</section>

@if($booking->status === 'CONFIRMED' && $booking->trip_status === 'PLANNED')
<section class="card">
<h2>修改 / 改期</h2>
<p>提交后仍由既有权威订单操作重新校验库存、兼容规则、缓冲时间和重叠占用。</p>
<form method="post" action="{{ route('operator.bookings.amend', $booking->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $amendIdempotencyKey }}">
<label>船只
<select name="boat_id" required>
@foreach($boats as $boat)
<option value="{{ $boat->id }}" @selected((int) $boat->id === (int) $booking->boat_id)>{{ $boat->name }}</option>
@endforeach
</select>
</label>
<label>产品 / 出航模板
<select name="trip_template_id" required>
@foreach($products as $product)
<option value="{{ $product->id }}" @selected((int) $product->id === (int) $booking->trip_template_id)>{{ $product->name }}</option>
@endforeach
</select>
</label>
<label>服务时段
<select name="slot_offering_id" required>
@foreach($slots as $slot)
<option value="{{ $slot->id }}" @selected((int) $slot->id === (int) $booking->slot_offering_id)>{{ \App\Support\OperatorUi::slotName($slot->name) }}</option>
@endforeach
</select>
</label>
<label>服务日期（组织时区）
<input type="date" name="service_date" value="{{ $booking->service_date }}" required>
</label>
<button>保存订单修改</button>
</form>
</section>

<section class="card">
<h2>取消订单</h2>
<form method="post" action="{{ route('operator.bookings.cancel', $booking->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $cancelIdempotencyKey }}">
<label>取消原因（可选）
<textarea name="reason" maxlength="500" placeholder="填写中性、可审计的取消原因"></textarea>
</label>
<button>取消订单</button>
</form>
</section>
@elseif($booking->status === 'CONFIRMED' && $booking->trip_id && $booking->trip_status !== 'PLANNED')
<section class="card">
<p>出航执行开始后不能再修改订单。</p>
</section>
@endif
@endsection