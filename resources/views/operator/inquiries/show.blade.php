@extends('operator.layout')

@section('title', '询价 '.$inquiry->reference)

@section('bodyClass', 'inquiry-layout')

@section('head')
@include('operator.inquiries._styles')
@endsection

@section('content')
<main class="inquiry-page">
<h1>询价 {{ $inquiry->reference }}</h1>
<p>询价状态：{{ \App\Support\OperatorUi::status($inquiry->status) }}</p>
<p class="inquiry-help">询价参考号保持不变，并继续作为后续预留的外部参考号。</p>

@if($missingInformation !== [])
<section class="card inquiry-warning" role="status">
<h2>执行资料待补充</h2>
<ul>
@foreach($missingInformation as $missingItem)
<li>{{ $missingItem }}</li>
@endforeach
</ul>
<p>这是可见性提醒，不会改变现有创建预留或确认订单门槛。</p>
<p>房间号可在客人入住后补充，明确不是确认阻断条件。</p>
</section>
@else
<section class="card inquiry-complete" role="status">
<h2>核心执行资料已记录</h2>
<p>房间号仍可稍后补充，明确不是确认阻断条件。</p>
</section>
@endif

@if($inquiry->hold_id === null)
<form method="post" action="{{ route('operator.inquiries.execution.update', $inquiry->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $executionIdempotencyKey }}">
<section class="card">
<h2>出航执行资料</h2>
<p class="inquiry-help">这里只补全现有询价的日期、船只、产品和服务时段；保存不会占用库存或创建预留。</p>
<div class="inquiry-form-grid">
<label>服务日期
<input type="date" name="service_date" value="{{ old('service_date', $inquiry->service_date) }}">
</label>
<label>船只
<select name="boat_id">
<option value="">暂不选择</option>
@foreach($boats as $boat)
<option value="{{ $boat->id }}" @selected((string) old('boat_id', $inquiry->boat_id) === (string) $boat->id)>{{ $boat->name }}</option>
@endforeach
</select>
</label>
<label>产品 / 出航模板
<select name="trip_template_id">
<option value="">暂不选择</option>
@foreach($products as $product)
<option value="{{ $product->id }}" @selected((string) old('trip_template_id', $inquiry->trip_template_id) === (string) $product->id)>{{ $product->name }}</option>
@endforeach
</select>
</label>
<label>服务时段
<select name="slot_offering_id">
<option value="">暂不选择</option>
@foreach($slots as $slot)
<option value="{{ $slot->id }}" @selected((string) old('slot_offering_id', $inquiry->slot_offering_id) === (string) $slot->id)>{{ \App\Support\OperatorUi::slotName($slot->name, $slot->code) }}（{{ \App\Support\OperatorUi::wallClockRange($slot->service_start_time, $slot->service_end_time) }} / {{ \App\Support\OperatorUi::durationMinutes((int) $slot->duration_minutes) }}）</option>
@endforeach
</select>
</label>
</div>
<button>保存出航资料</button>
</section>
</form>
@else
<section class="card">
<h2>出航执行资料</h2>
<p class="inquiry-help">预留已关联；日期、船只、产品和服务时段由预留流程锁定。</p>
</section>
@endif

<form method="post" action="{{ route('operator.inquiries.dossier.update', $inquiry->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $dossierIdempotencyKey }}">

<section class="card">
<h2>出航需求</h2>
<div class="inquiry-summary-grid">
<div class="inquiry-fact"><strong>服务日期</strong>{{ \App\Support\OperatorUi::date($inquiry->service_date) }}</div>
<div class="inquiry-fact"><strong>船只</strong>{{ $selectedBoat?->name ?: '未选择' }}</div>
<div class="inquiry-fact"><strong>产品 / 出航模板</strong>{{ $selectedProduct?->name ?: '未选择' }}</div>
<div class="inquiry-fact"><strong>服务时段</strong>{{ $selectedSlot ? \App\Support\OperatorUi::slotName($selectedSlot->name, $selectedSlot->code) : '未选择' }}</div>
@if($selectedSlot)
<div class="inquiry-fact"><strong>船只出发 / 服务时间</strong>{{ \App\Support\OperatorUi::wallClockRange($selectedSlot->service_start_time, $selectedSlot->service_end_time) }}</div>
<div class="inquiry-fact"><strong>时长（来自所选服务时段）</strong>{{ \App\Support\OperatorUi::durationMinutes((int) $selectedSlot->duration_minutes) }}</div>
@endif
<label class="wide">路线 / 目的地
<textarea name="route_summary" maxlength="2000" placeholder="简要记录实际路线或目的地">{{ old('route_summary', $inquiry->route_summary) }}</textarea>
<span class="inquiry-help">路线与返程 / 下客地点分开记录；接客时间也不替代船只出发时间。</span>
</label>
</div>
</section>

<section class="card">
<h2>客人信息</h2>
<div class="inquiry-form-grid">
<label>客人 / 联系人姓名
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
<label class="wide">联系信息
<input name="contact_value" value="{{ old('contact_value', $inquiry->contact_value) }}" maxlength="255">
</label>
<label>总人数
<input type="number" name="party_size" value="{{ old('party_size', $inquiry->party_size) }}" min="1" max="999" step="1">
</label>
<label>成人数
<input type="number" name="adult_count" value="{{ old('adult_count', $inquiry->adult_count) }}" min="0" max="999" step="1">
</label>
<label>儿童数
<input type="number" name="child_count" value="{{ old('child_count', $inquiry->child_count) }}" min="0" max="999" step="1">
</label>
@php($editChildAges = old('child_ages', $childAgesText))
@php($editChildAges = is_array($editChildAges) ? implode("\n", $editChildAges) : $editChildAges)
<p class="inquiry-help">儿童年龄可用换行或逗号分隔，系统仍按结构化 JSON 数组保存。</p>
<label>儿童年龄
<textarea name="child_ages" inputmode="numeric" placeholder="每行填写一名儿童的年龄">{{ $editChildAges }}</textarea>
<span class="inquiry-help">可暂不填写；系统不设定统一的成人 / 儿童年龄分界。</span>
</label>
</div>
</section>

<section class="card">
<h2>接送信息</h2>
@php($pickupRequiredValue = old('pickup_required', $inquiry->pickup_required === null ? '' : ((bool) $inquiry->pickup_required ? '1' : '0')))
<div class="inquiry-form-grid">
<label>需要接送
<select name="pickup_required">
<option value="" @selected((string) $pickupRequiredValue === '')>待确认</option>
<option value="1" @selected((string) $pickupRequiredValue === '1')>需要</option>
<option value="0" @selected((string) $pickupRequiredValue === '0')>不需要</option>
</select>
</label>
<label>酒店 / 住宿名称
<input name="hotel_name" value="{{ old('hotel_name', $inquiry->hotel_name) }}" maxlength="255">
</label>
<label>房间号
<input name="room_number" value="{{ old('room_number', $inquiry->room_number) }}" maxlength="255">
<span class="inquiry-help">可稍后补充，不是创建预留或确认订单的阻断条件。</span>
</label>
<label>接客时间（{{ $organization->timezone }}）
<input type="time" name="pickup_time" value="{{ old('pickup_time', $inquiry->pickup_time ? substr($inquiry->pickup_time, 0, 5) : '') }}" step="60">
<span class="inquiry-help">接客时间是单独的执行事实，不是船只出发时间。</span>
</label>
<label class="wide">接客 / 集合地点
<textarea name="meeting_point" maxlength="2000">{{ old('meeting_point', $inquiry->meeting_point) }}</textarea>
</label>
<label class="wide">返程 / 下客地点（如不同）
<textarea name="service_location" maxlength="2000">{{ old('service_location', $inquiry->service_location) }}</textarea>
</label>
</div>
</section>

<section class="card">
<h2>服务要求</h2>
<label>特殊服务与执行要求
<textarea name="service_notes" maxlength="5000" placeholder="例如：餐食 / BBQ、中文工作人员、钓鱼、浮潜或其他特殊需求">{{ old('service_notes', $inquiry->service_notes) }}</textarea>
</label>
<p class="inquiry-help">这些示例只用于记录需求，不会建立加购目录、包含规则或价格明细。</p>
</section>

<section class="card">
<h2>来源与内部资料</h2>
<div class="inquiry-form-grid">
<label>销售来源
<input name="sales_source" value="{{ old('sales_source', $inquiry->sales_source) }}" maxlength="255">
</label>
<label>代理 / 合作方参考号
<input name="agent_reference" value="{{ old('agent_reference', $inquiry->agent_reference) }}" maxlength="255">
</label>
<div class="inquiry-fact wide"><strong>询价初步备注</strong>{{ $inquiry->notes ?: '无' }}</div>
<label class="wide">内部运营备注
<textarea name="internal_notes" maxlength="5000">{{ old('internal_notes', $inquiry->internal_notes) }}</textarea>
</label>
<label>币种
<input name="selling_currency" value="{{ old('selling_currency', $inquiry->selling_currency) }}" maxlength="3" pattern="[A-Z]{3}" placeholder="THB">
</label>
<label>销售金额
<input type="number" name="selling_amount" value="{{ old('selling_amount', $sellingAmountDecimal) }}" min="0" step="0.01" inputmode="decimal" placeholder="1234.56">
</label>
</div>
<p class="inquiry-help">销售总额仅作为运营参考，以两位小数确定性存储；不会创建价格明细、税费、佣金、收款或会计记录。</p>
</section>

<p>创建预留或确认订单后，这些资料仍可编辑；更新资料不会改变库存或订单 / 出航生命周期状态。</p>
<button>更新运营资料</button>
</form>

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
<option value="{{ $slot->id }}" @selected($slot->id === $booking->slot_offering_id)>{{ \App\Support\OperatorUi::slotName($slot->name, $slot->code) }}</option>
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
</main>
@endsection
