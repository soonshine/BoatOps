@extends('operator.layout')

@section('title', '询价 '.$inquiry->reference)

@section('content')
<h1>询价 {{ $inquiry->reference }}</h1>
<div>询价状态：{{ \App\Support\OperatorUi::status($inquiry->status) }}</div>
<div>服务日期：{{ \App\Support\OperatorUi::date($inquiry->service_date) }}</div>
<div>询价备注：{{ $inquiry->notes ?: '无' }}</div>

<section class="card">
<h2>运营资料</h2>
<div>联系人：{{ $inquiry->contact_name ?: '未提供' }}</div>
<div>联系方式：{{ \App\Support\OperatorUi::contactMethod($inquiry->contact_method) }}{{ $inquiry->contact_value ? ' / '.$inquiry->contact_value : '' }}</div>
<div>人数：{{ $inquiry->party_size ?: '未提供' }}</div>
<div>集合地点：{{ $inquiry->meeting_point ?: '未提供' }}</div>
<div>服务地点 / 下客点：{{ $inquiry->service_location ?: '未提供' }}</div>
<div>销售来源：{{ $inquiry->sales_source ?: '未提供' }}</div>
<div>代理 / 合作方参考号：{{ $inquiry->agent_reference ?: '未提供' }}</div>
<div>客户 / 服务备注：{{ $inquiry->service_notes ?: '无' }}</div>
<div>内部运营备注：{{ $inquiry->internal_notes ?: '无' }}</div>
<div>销售金额：{{ $inquiry->selling_currency && $inquiry->selling_amount_minor !== null ? $inquiry->selling_currency.' '.$inquiry->selling_amount_minor.'（最小货币单位）' : '未提供' }}</div>
<p>创建预留或确认订单后，这些资料仍可编辑；更新资料不会改变库存或订单 / 出航生命周期状态。</p>

<form method="post" action="{{ route('operator.inquiries.dossier.update', $inquiry->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $dossierIdempotencyKey }}">
<label>联系人姓名
<input name="contact_name" value="{{ old('contact_name', $inquiry->contact_name) }}" maxlength="255">
</label>
<label>联系方式
<select name="contact_method">
<option value="">暂不选择</option>
@foreach(['PHONE', 'WHATSAPP', 'WECHAT', 'LINE', 'EMAIL', 'OTHER'] as $method)
<option value="{{ $method }}" @selected(old('contact_method', $inquiry->contact_method) === $method)>{{ \App\Support\OperatorUi::contactMethod($method) }}</option>
@endforeach
</select>
</label>
<label>联系信息
<input name="contact_value" value="{{ old('contact_value', $inquiry->contact_value) }}" maxlength="255">
</label>
<label>人数
<input type="number" name="party_size" value="{{ old('party_size', $inquiry->party_size) }}" min="1" max="999" step="1">
</label>
<label>集合地点
<textarea name="meeting_point" maxlength="2000">{{ old('meeting_point', $inquiry->meeting_point) }}</textarea>
</label>
<label>服务地点 / 下客点
<textarea name="service_location" maxlength="2000">{{ old('service_location', $inquiry->service_location) }}</textarea>
</label>
<label>销售来源
<input name="sales_source" value="{{ old('sales_source', $inquiry->sales_source) }}" maxlength="255">
</label>
<label>代理 / 合作方参考号
<input name="agent_reference" value="{{ old('agent_reference', $inquiry->agent_reference) }}" maxlength="255">
</label>
<label>客户 / 服务备注
<textarea name="service_notes" maxlength="5000">{{ old('service_notes', $inquiry->service_notes) }}</textarea>
</label>
<label>内部运营备注
<textarea name="internal_notes" maxlength="5000">{{ old('internal_notes', $inquiry->internal_notes) }}</textarea>
</label>
<label>销售币种
<input name="selling_currency" value="{{ old('selling_currency', $inquiry->selling_currency) }}" maxlength="3" pattern="[A-Z]{3}" placeholder="THB">
</label>
<label>销售金额（最小货币单位）
<input type="number" name="selling_amount_minor" value="{{ old('selling_amount_minor', $inquiry->selling_amount_minor) }}" min="0" step="1">
</label>
<button>更新运营资料</button>
</form>
</section>

<section class="card error">
<h2>G1 商业边界</h2>
<p>定价和收款不在 G1 范围内。运营资料中的销售金额仅供参考。操作员确认仍会明确创建未定价订单，不包含价格快照、税费或佣金；尚未达到生产商业就绪。</p>
</section>

@if($errors->has('booking'))
<section class="card error" role="alert">
<p>{{ $errors->first('booking') }}</p>
</section>
@endif

@if($hold)
<section class="card">
<h2>关联预留</h2>
<div>预留状态：{{ \App\Support\OperatorUi::status($hold->status) }}</div>
<div>到期时间（{{ $organization->timezone }}）：{{ \App\Support\OperatorUi::dateTime($hold->expires_at, $organization->timezone) }}</div>
@if($hold->status === 'ACTIVE' && \Carbon\CarbonImmutable::parse($hold->expires_at)->isFuture())
<form method="post" action="{{ route('operator.inquiries.booking.confirm', [$inquiry->id, $hold->id]) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $confirmIdempotencyKey }}">
<button>确认未定价订单</button>
</form>
<form method="post" action="{{ route('operator.inquiries.hold.release', $inquiry->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $releaseIdempotencyKey }}">
<button>释放预留</button>
</form>
@elseif($hold->status === 'ACTIVE')
<p>该预留已超过页面显示的到期时间；确认时仍由权威订单操作处理过期状态。</p>
@endif
</section>
@elseif(!$holdTtlConfigured)
<section class="card error">
<h2>暂时无法创建预留</h2>
<p>组织尚未配置预留有效期策略。需要船东决策（OWNER_DECISION_REQUIRED）。</p>
</section>
@elseif(!$inquiry->boat_id || !$inquiry->trip_template_id || !$inquiry->slot_offering_id || !$inquiry->service_date)
<section class="card">
<h2>暂时无法创建预留</h2>
<p>必须先选择船只、产品、服务时段和服务日期。</p>
</section>
@else
<section class="card">
<h2>创建预留</h2>
<p>到期时间由服务器根据已批准的组织策略计算。</p>
<form method="post" action="{{ route('operator.inquiries.hold.create', $inquiry->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $holdIdempotencyKey }}">
<button>创建预留</button>
</form>
</section>
@endif

@if($booking)
<section class="card">
<h2>关联订单</h2>
<div>订单状态：{{ \App\Support\OperatorUi::status($booking->status) }}</div>
<div>商业就绪状态：{{ $booking->rate_snapshot_id === null ? '未定价 / 尚未达到生产商业就绪' : '已在此 G1 界面之外记录价格快照' }}</div>
<div>出航状态：{{ $trip?->status ? \App\Support\OperatorUi::status($trip->status) : '未关联出航记录' }}</div>
@if($booking->status === 'CONFIRMED')
<h3>修改 / 改期</h3>
<p>共享订单操作会统一处理兼容规则、缓冲时间、重叠、锁、库存、出航、审计、修订和发件箱事件。</p>
<form method="post" action="{{ route('operator.inquiries.booking.amend', [$inquiry->id, $booking->id]) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $amendIdempotencyKey }}">
<label>船只
<select name="boat_id" required>
@foreach($boats as $boat)
<option value="{{ $boat->id }}" @selected($boat->id === $booking->boat_id)>{{ $boat->name }}</option>
@endforeach
</select>
</label>
<label>产品 / 出航模板
<select name="trip_template_id" required>
@foreach($products as $product)
<option value="{{ $product->id }}" @selected($product->id === $booking->trip_template_id)>{{ $product->name }}</option>
@endforeach
</select>
</label>
<label>服务时段
<select name="slot_offering_id" required>
@foreach($slots as $slot)
<option value="{{ $slot->id }}" @selected($slot->id === $booking->slot_offering_id)>{{ \App\Support\OperatorUi::slotName($slot->name) }}</option>
@endforeach
</select>
</label>
<label>服务日期
<input type="date" name="service_date" value="{{ $booking->service_date }}" required>
</label>
<button>保存订单修改</button>
</form>
<h3>取消订单</h3>
<form method="post" action="{{ route('operator.inquiries.booking.cancel', [$inquiry->id, $booking->id]) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $cancelIdempotencyKey }}">
<label>取消原因（可选）
<textarea name="reason" maxlength="500" placeholder="填写中性、可审计的取消原因"></textarea>
</label>
<button>取消订单</button>
</form>
@endif
</section>
@endif
@endsection