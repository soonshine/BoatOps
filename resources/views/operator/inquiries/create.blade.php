@extends('operator.layout')

@section('title', '新建询价')

@section('bodyClass', 'inquiry-layout')

@section('head')
@include('operator.inquiries._styles')
@endsection

@section('content')
<main class="inquiry-page">
<h1>新建询价</h1>
<p>先记录真实出航、客人和接送事实；询价本身不会占用库存，资料可稍后补充。</p>

<form method="post" action="{{ route('operator.inquiries.store') }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

<section class="card">
<h2>出航需求</h2>
<p class="inquiry-help">船只、产品、服务时段和服务日期仍是创建预留前的既有门槛；接客时间不是船只出发时间。</p>
<div class="inquiry-form-grid">
<label class="wide">询价参考号
<input name="reference" value="{{ old('reference') }}" maxlength="100" placeholder="例如：XUNJIA-20260816-001" required>
<span class="inquiry-help">此参考号会继续沿用为后续预留的外部参考号。</span>
</label>
<label>服务日期
<input type="date" name="service_date" value="{{ old('service_date', request()->query('service_date')) }}">
</label>
<label>船只（可暂不选）
<select name="boat_id">
<option value="">暂不选择</option>
@foreach($boats as $boat)
<option value="{{ $boat->id }}" @selected((string) old('boat_id', request()->query('boat_id')) === (string) $boat->id)>{{ $boat->name }}</option>
@endforeach
</select>
</label>
<label>产品 / 出航模板（可暂不选）
<select name="trip_template_id">
<option value="">暂不选择</option>
@foreach($products as $product)
<option value="{{ $product->id }}" @selected((string) old('trip_template_id') === (string) $product->id)>{{ $product->name }}</option>
@endforeach
</select>
</label>
<label>服务时段（可暂不选）
<select name="slot_offering_id">
<option value="">暂不选择</option>
@foreach($slots as $slot)
<option value="{{ $slot->id }}" @selected((string) old('slot_offering_id', request()->query('slot_offering_id')) === (string) $slot->id)>{{ \App\Support\OperatorUi::slotName($slot->name, $slot->code) }}（{{ \App\Support\OperatorUi::wallClockRange($slot->service_start_time, $slot->service_end_time) }} / {{ \App\Support\OperatorUi::durationMinutes((int) $slot->duration_minutes) }}）</option>
@endforeach
</select>
</label>
<label class="wide">路线 / 目的地
<textarea name="route_summary" maxlength="2000" placeholder="简要记录实际路线或目的地，例如：目的地 A + 目的地 B">{{ old('route_summary') }}</textarea>
<span class="inquiry-help">路线与返程 / 下客地点是不同事实。</span>
</label>
</div>
</section>

<section class="card">
<h2>客人信息</h2>
<p class="inquiry-help">客人资料仅在已授权的操作员界面内显示。</p>
<div class="inquiry-form-grid">
<label>客人 / 联系人姓名
<input name="contact_name" value="{{ old('contact_name') }}" maxlength="255">
</label>
<label>联系方式
<select name="contact_method">
<option value="">暂不选择</option>
@foreach(['PHONE', 'WHATSAPP', 'WECHAT', 'LINE', 'EMAIL', 'OTHER'] as $method)
<option value="{{ $method }}" @selected(old('contact_method') === $method)>{{ \App\Support\OperatorUi::contactMethod($method) }}</option>
@endforeach
</select>
</label>
<label class="wide">联系信息
<input name="contact_value" value="{{ old('contact_value') }}" maxlength="255" placeholder="电话号码、账号或邮箱">
</label>
<label>总人数
<input type="number" name="party_size" value="{{ old('party_size') }}" min="1" max="999" step="1">
</label>
<label>成人数
<input type="number" name="adult_count" value="{{ old('adult_count') }}" min="0" max="999" step="1">
</label>
<label>儿童数
<input type="number" name="child_count" value="{{ old('child_count') }}" min="0" max="999" step="1">
</label>
@php($createChildAges = old('child_ages', ''))
@php($createChildAges = is_array($createChildAges) ? implode("\n", $createChildAges) : $createChildAges)
<p class="inquiry-help">儿童年龄可用换行或逗号分隔，系统仍按结构化 JSON 数组保存。</p>
<label>儿童年龄
<textarea name="child_ages" inputmode="numeric" placeholder="每行填写一名儿童的年龄">{{ $createChildAges }}</textarea>
<span class="inquiry-help">可暂不填写；系统不设定统一的成人 / 儿童年龄分界。</span>
</label>
</div>
</section>

<section class="card">
<h2>接送信息</h2>
<div class="inquiry-form-grid">
<label>需要接送
<select name="pickup_required">
<option value="" @selected((string) old('pickup_required', '') === '')>待确认</option>
<option value="1" @selected((string) old('pickup_required', '') === '1')>需要</option>
<option value="0" @selected((string) old('pickup_required', '') === '0')>不需要</option>
</select>
</label>
<label>酒店 / 住宿名称
<input name="hotel_name" value="{{ old('hotel_name') }}" maxlength="255">
</label>
<label>房间号
<input name="room_number" value="{{ old('room_number') }}" maxlength="255">
<span class="inquiry-help">可稍后补充，不是创建预留或确认订单的阻断条件。</span>
</label>
<label>接客时间（{{ $organization->timezone }}）
<input type="time" name="pickup_time" value="{{ old('pickup_time') }}" step="60">
<span class="inquiry-help">这是接送执行时间，不替代服务时段中的船只出发时间。</span>
</label>
<label class="wide">接客 / 集合地点
<textarea name="meeting_point" maxlength="2000">{{ old('meeting_point') }}</textarea>
</label>
<label class="wide">返程 / 下客地点（如不同）
<textarea name="service_location" maxlength="2000">{{ old('service_location') }}</textarea>
</label>
</div>
</section>

<section class="card">
<h2>服务要求</h2>
<label>特殊服务与执行要求
<textarea name="service_notes" maxlength="5000" placeholder="例如：餐食 / BBQ、中文工作人员、钓鱼、浮潜或其他特殊需求">{{ old('service_notes') }}</textarea>
</label>
<p class="inquiry-help">这些示例只用于记录需求，不会建立加购目录、包含规则或价格明细。</p>
</section>

<section class="card">
<h2>来源与内部资料</h2>
<div class="inquiry-form-grid">
<label>销售来源
<input name="sales_source" value="{{ old('sales_source') }}" maxlength="255" placeholder="例如：官网、代理或转介绍">
</label>
<label>代理 / 合作方参考号
<input name="agent_reference" value="{{ old('agent_reference') }}" maxlength="255">
</label>
<label class="wide">询价初步备注
<textarea name="notes" maxlength="1000" placeholder="记录尚未归入执行资料的初步沟通">{{ old('notes') }}</textarea>
</label>
<label class="wide">内部运营备注
<textarea name="internal_notes" maxlength="5000">{{ old('internal_notes') }}</textarea>
</label>
<label>币种
<input name="selling_currency" value="{{ old('selling_currency') }}" maxlength="3" pattern="[A-Z]{3}" placeholder="THB">
</label>
<label>销售金额
<input type="number" name="selling_amount" value="{{ old('selling_amount') }}" min="0" step="0.01" inputmode="decimal" placeholder="1234.56">
</label>
</div>
<p class="inquiry-help">销售总额仅作为运营参考，以两位小数确定性存储；不会创建价格明细、税费、佣金、收款或会计记录。</p>
</section>

<button>创建询价</button>
</form>
</main>
@endsection
