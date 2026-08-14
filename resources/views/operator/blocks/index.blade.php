@extends('operator.layout')

@section('title', '停用管理')

@section('content')
<h1>{{ $organization->name }} · 停用管理</h1>
<p>输入时间和占用区间均使用组织时区 <strong>{{ $organization->timezone }}</strong>。服务器会把本地时间转换为 UTC，不依赖浏览器时区。</p>
<p><strong>“天气原因”仅为人工原因标签。</strong>自动天气触发条件、证据、时机和覆盖规则仍需船东决策（<strong>OWNER_DECISION_REQUIRED</strong>）；本页面不包含自动天气规则。</p>

<h2>创建停用记录</h2>
<form method="post" action="{{ route('operator.blocks.store') }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $createIdempotencyKey }}">
<label>外部参考号 <input name="external_reference" value="{{ old('external_reference') }}" placeholder="例如：MAINT-20260814-001" required maxlength="255"></label>
<label>船只
<select name="boat_id" required>
<option value="">选择船只</option>
@foreach($boats as $boat)
<option value="{{ $boat->id }}" @selected((string) old('boat_id') === (string) $boat->id)>{{ $boat->name }}</option>
@endforeach
</select>
</label>
<label>开始时间（{{ $organization->timezone }}） <input type="datetime-local" name="starts_at_local" value="{{ old('starts_at_local') }}" required></label>
<label>结束时间（{{ $organization->timezone }}） <input type="datetime-local" name="ends_at_local" value="{{ old('ends_at_local') }}" required></label>
<label>原因类型
<select name="reason_code" required>
@foreach(['MAINTENANCE', 'WEATHER', 'OWNER_USE', 'MANUAL'] as $code)
<option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ \App\Support\OperatorUi::blockReason($code) }}</option>
@endforeach
</select>
</label>
<label>详细原因 <textarea name="reason" maxlength="500" placeholder="可选；填写中性、可审计的原因">{{ old('reason') }}</textarea></label>
<button type="submit">创建停用记录</button>
</form>

<h2>停用记录</h2>
@forelse($blocks as $block)
<article class="card">
<div>停用状态：{{ \App\Support\OperatorUi::status($block->status) }}</div>
<div>船只：{{ $block->resource_name }}</div>
<div>实际占用区间（{{ $organization->timezone }}）：{{ $block->occupied_start_local }} — {{ $block->occupied_end_local }}</div>
<div>原因类型：{{ \App\Support\OperatorUi::blockReason($block->reason_code) }}</div>
<div>详细原因：{{ $block->reason ?: '无' }}</div>
@if($block->status === 'ACTIVE')
<form method="post" action="{{ route('operator.blocks.release', $block->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $block->release_idempotency_key }}">
<label>解除原因 <input name="reason" maxlength="500" placeholder="可选"></label>
<button type="submit">解除停用</button>
</form>
@endif
</article>
@empty
<p>暂无停用记录。</p>
@endforelse
@endsection