@extends('operator.layout')

@section('title', '新建询价')

@section('content')
<h1>新建询价</h1>
<p>创建询价不会占用库存。运营资料均可稍后补充。</p>

<form method="post" action="{{ route('operator.inquiries.store') }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

<section class="card">
<h2>询价信息</h2>
<label>询价参考号
<input name="reference" value="{{ old('reference') }}" maxlength="100" placeholder="例如：XUNJIA-20260814-001" required>
</label>
<label>服务日期
<input type="date" name="service_date" value="{{ old('service_date', request()->query('service_date')) }}">
</label>
<label>船只
<select name="boat_id">
<option value="">暂不选择</option>
@foreach($boats as $boat)
<option value="{{ $boat->id }}" @selected((string) old('boat_id', request()->query('boat_id')) === (string) $boat->id)>{{ $boat->name }}</option>
@endforeach
</select>
</label>
<label>产品 / 出航模板
<select name="trip_template_id">
<option value="">暂不选择</option>
@foreach($products as $product)
<option value="{{ $product->id }}" @selected((string) old('trip_template_id') === (string) $product->id)>{{ $product->name }}</option>
@endforeach
</select>
</label>
<label>服务时段
<select name="slot_offering_id">
<option value="">暂不选择</option>
@foreach($slots as $slot)
<option value="{{ $slot->id }}" @selected((string) old('slot_offering_id', request()->query('slot_offering_id')) === (string) $slot->id)>{{ \App\Support\OperatorUi::slotName($slot->name) }}</option>
@endforeach
</select>
</label>
<label>询价备注
<textarea name="notes" maxlength="1000" placeholder="记录客人的初步需求">{{ old('notes') }}</textarea>
</label>
</section>

<section class="card">
<h2>运营资料</h2>
<p>客人和执行信息仅在已授权的操作员界面内显示。</p>
<label>联系人姓名
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
<label>联系信息
<input name="contact_value" value="{{ old('contact_value') }}" maxlength="255" placeholder="电话号码、账号或邮箱">
</label>
<label>人数
<input type="number" name="party_size" value="{{ old('party_size') }}" min="1" max="999" step="1">
</label>
<label>集合地点
<textarea name="meeting_point" maxlength="2000">{{ old('meeting_point') }}</textarea>
</label>
<label>服务地点 / 下客点
<textarea name="service_location" maxlength="2000">{{ old('service_location') }}</textarea>
</label>
<label>销售来源
<input name="sales_source" value="{{ old('sales_source') }}" maxlength="255" placeholder="例如：官网、代理或转介绍">
</label>
<label>代理 / 合作方参考号
<input name="agent_reference" value="{{ old('agent_reference') }}" maxlength="255">
</label>
<label>客户 / 服务备注
<textarea name="service_notes" maxlength="5000">{{ old('service_notes') }}</textarea>
</label>
<label>内部运营备注
<textarea name="internal_notes" maxlength="5000">{{ old('internal_notes') }}</textarea>
</label>
<label>销售币种
<input name="selling_currency" value="{{ old('selling_currency') }}" maxlength="3" pattern="[A-Z]{3}" placeholder="THB">
</label>
<label>销售金额（最小货币单位）
<input type="number" name="selling_amount_minor" value="{{ old('selling_amount_minor') }}" min="0" step="1">
</label>
<p>该金额仅作为运营资料，不会创建价格快照、税费、佣金、收款或会计记录。</p>
</section>

<button>创建询价</button>
</form>
@endsection