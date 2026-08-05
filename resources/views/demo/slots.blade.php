<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BoatOps 虚构档期目录</title>
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:#102a43;background:#f3f7fb;line-height:1.5}*{box-sizing:border-box;min-width:0}html,body{margin:0;max-width:100%;overflow-x:hidden}body{overflow-wrap:anywhere}.banner{padding:16px;background:#8b1e1e;color:#fff;text-align:center;font-weight:850}.wrap{width:min(100%,1180px);margin:auto;padding:clamp(10px,3vw,24px)}.topbar,.nav,.actions,.choice-row{display:flex;flex-wrap:wrap;gap:9px;align-items:center}.topbar{justify-content:space-between}.nav{justify-content:flex-end}.button,button{display:inline-flex;justify-content:center;align-items:center;border:0;border-radius:8px;background:#075985;color:#fff;padding:10px 14px;font:inherit;font-weight:750;text-decoration:none;cursor:pointer}.button.secondary,button.secondary{background:#334e68}.button.danger,button.danger{background:#9f1239}.warning{border:3px solid #b91c1c;background:#fff1f2;color:#7f1d1d;padding:14px;border-radius:10px;font-weight:850;margin:14px 0}.notice,.errors{border-radius:10px;padding:12px;margin:12px 0}.notice{background:#dcfce7}.errors{background:#fee2e2;color:#7f1d1d}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:13px}.card{background:#fff;border:1px solid #d8e2ec;border-radius:12px;padding:15px;margin:13px 0;box-shadow:0 2px 8px #102a4310}.card h2,.card h3{margin-top:0}.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:10px}label{display:block;font-weight:700}input,select,textarea{display:block;width:100%;max-width:100%;padding:10px;margin-top:4px;border:1px solid #9fb3c8;border-radius:8px;background:#fff;font:inherit}textarea{min-height:80px;resize:vertical}.choice-row label{display:flex;gap:7px;align-items:center;font-weight:600}.choice-row input{width:auto;margin:0}.pill{display:inline-flex;border-radius:999px;padding:4px 9px;font-size:.78rem;font-weight:850}.active,.allow{background:#dcfce7;color:#166534}.draft{background:#fef3c7;color:#92400e}.retired,.deny{background:#e5e7eb;color:#374151}.preset{background:#dbeafe;color:#1e40af}.custom_template,.custom_instance{background:#ede9fe;color:#5b21b6}.catalog-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:11px}.catalog-item{background:#fff;border:1px solid #ccd8e3;border-radius:10px;padding:12px}.catalog-item h3{margin:0 0 7px}.catalog-item p{margin:5px 0;font-size:.9rem}.interval{font-variant-numeric:tabular-nums;overflow-wrap:anywhere}.actions form{margin:0}.actions button{padding:7px 10px}.rule{border-left:5px solid #64748b;background:#fff;padding:10px;border-radius:8px;margin:8px 0}.muted{color:#526d82;font-size:.9rem}.section-title{margin-top:28px}.full{grid-column:1/-1}@media(max-width:390px){.wrap{padding:9px}.banner{padding:12px 9px}.topbar,.nav,.actions{display:grid;grid-template-columns:1fr;width:100%}.button,button,.actions form{width:100%}.card,.catalog-item{padding:11px}.form-grid,.grid,.catalog-list{grid-template-columns:1fr}.choice-row{display:grid;grid-template-columns:1fr}}
</style>
</head>
<body data-demo-page="slots" data-verified-mobile-width="390">
<div class="banner">DEMO DATA ONLY · LOCAL ONLY · 档期目录与兼容规则</div>
<main class="wrap">
<header class="topbar"><div><h1>运营端档期目录</h1><p class="muted"><strong>{{ $organization->name }}</strong> · {{ $organization->timezone }} · 仅限 DemoSiteSeeder 虚构组织与虚构船</p></div><nav class="nav" aria-label="演示站导航"><a class="button secondary" href="{{ route('demo.index') }}">财务演示</a><a class="button" href="{{ route('demo.calendar') }}">7 天库存日历</a></nav></header>
<div class="warning">演示默认档期；真实起止时间和周转缓冲尚未冻结。<br>FULL_DAY_8H / FULL_DAY_6H / AM_4H / PM_4H / PM_2_5H 的 seed clock time 仅为 DEMO DEFAULT / UNVERIFIED OPERATING TIME，不是 Ayany、Plan A 或 Plan B 的正式规则。</div>
@if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors"><strong>表单未保存：</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="grid" aria-label="档期创建表单">
<form class="card" method="post" action="{{ route('demo.slots.reusable') }}">@csrf
<h2>创建 reusable custom slot</h2><p class="muted">服务端固定创建为 DRAFT；启用需单独审计动作。</p>
<div class="form-grid">
<label>Code<input name="code" value="{{ old('code','DEMO_NEW_REUSABLE') }}" required pattern="[A-Z0-9][A-Z0-9_-]{1,99}"></label>
<label>Name<input name="name" value="{{ old('name','Fictional Reusable Custom Slot') }}" required></label>
<label>Service start<input type="time" name="service_start_time" value="{{ old('service_start_time','10:00') }}" required></label>
<label>Service end<input type="time" name="service_end_time" value="{{ old('service_end_time','12:00') }}" required></label>
<label>Duration minutes<input type="number" name="duration_minutes" min="1" max="1440" value="{{ old('duration_minutes',120) }}" required></label>
<label>Extra buffer before<input type="number" name="additional_buffer_before_minutes" min="0" max="1440" value="{{ old('additional_buffer_before_minutes',0) }}" required></label>
<label>Extra buffer after<input type="number" name="additional_buffer_after_minutes" min="0" max="1440" value="{{ old('additional_buffer_after_minutes',0) }}" required></label>
<label>Valid from<input type="date" name="valid_from" value="{{ old('valid_from') }}"></label>
<label>Valid until<input type="date" name="valid_until" value="{{ old('valid_until') }}"></label>
</div>
<fieldset><legend>船只范围</legend><div class="choice-row"><label><input type="radio" name="scope" value="ALL" checked>全部虚构船</label><label><input type="radio" name="scope" value="SELECTED">仅勾选船只</label></div><div class="choice-row">@foreach($boats as $boat)<label><input type="checkbox" name="boat_ids[]" value="{{ $boat->id }}" @checked($loop->first)>{{ $boat->name }}</label>@endforeach</div></fieldset>
<button type="submit">创建虚构 DRAFT</button>
</form>

@php($fullDaySix = collect($templates)->firstWhere('code','FULL_DAY_6H'))
<form class="card" method="post" action="{{ route('demo.slots.instances') }}">@csrf
<h2>创建 date-specific custom slot instance</h2><p class="muted">虚构验证预填：FULL_DAY_6H、{{ $defaultDate }} 12:00–18:00。它只是一笔 date-specific 场景，不会改写所有 6 小时 preset。</p>
<div class="form-grid">
<label>Template<select name="template_slot_offering_id"><option value="">无 template</option>@foreach($templates as $template)<option value="{{ $template['id'] }}" @selected(($fullDaySix['id'] ?? null)===$template['id'])>{{ $template['code'] }} / {{ $template['status'] }}</option>@endforeach</select></label>
<label>Code<input name="code" value="{{ old('code','DEMO_DATE_'.str_replace('-','',$defaultDate).'_1200') }}" required></label>
<label>Name<input name="name" value="{{ old('name','Fictional Date-Specific 12:00-18:00 Slot') }}" required></label>
<label>Definition status<select name="status"><option>DRAFT</option><option selected>ACTIVE</option></select></label>
<label>Service date<input type="date" name="service_date" value="{{ old('service_date',$defaultDate) }}" required></label>
<label>Service start<input type="time" name="service_start_time" value="{{ old('service_start_time','12:00') }}" required></label>
<label>Service end<input type="time" name="service_end_time" value="{{ old('service_end_time','18:00') }}" required></label>
<label>Duration minutes<input type="number" name="duration_minutes" value="{{ old('duration_minutes',360) }}" min="1" max="1440" required></label>
<label>Extra buffer before<input type="number" name="additional_buffer_before_minutes" value="0" min="0" required></label>
<label>Extra buffer after<input type="number" name="additional_buffer_after_minutes" value="0" min="0" required></label>
</div>
<fieldset><legend>船只范围</legend><div class="choice-row"><label><input type="radio" name="scope" value="ALL">全部虚构船</label><label><input type="radio" name="scope" value="SELECTED" checked>仅勾选船只</label></div><div class="choice-row">@foreach($boats as $boat)<label><input type="checkbox" name="boat_ids[]" value="{{ $boat->id }}" @checked($loop->first)>{{ $boat->name }}</label>@endforeach</div></fieldset>
<button type="submit">创建虚构日期实例</button>
</form>

<form class="card full" method="post" action="{{ route('demo.slots.compatibility') }}">@csrf
<h2>配置 ALLOW / DENY compatibility</h2><div class="form-grid">
<label>First slot<select name="first_slot_offering_id" required>@foreach($offerings as $offering)<option value="{{ $offering['id'] }}">{{ $offering['code'] }} / {{ $offering['status'] }}</option>@endforeach</select></label>
<label>Second slot<select name="second_slot_offering_id" required>@foreach($offerings as $offering)<option value="{{ $offering['id'] }}" @selected($loop->index===1)>{{ $offering['code'] }} / {{ $offering['status'] }}</option>@endforeach</select></label>
<label>Policy<select name="policy"><option>ALLOW</option><option>DENY</option></select></label>
<label>Reason<input name="reason" value="Fictional demo compatibility decision" minlength="3" maxlength="500" required></label>
</div><button type="submit">保存 canonical rule</button>
</form>
</section>

<h2 class="section-title">档期定义（ACTIVE / DRAFT / RETIRED）</h2>
<section class="catalog-list">
@foreach($offerings as $offering)
<article class="catalog-item" data-definition-status="{{ $offering['status'] }}" data-slot-code="{{ $offering['code'] }}">
<h3>{{ $offering['code'] }}</h3><p>{{ $offering['name'] }}</p>
<p><span class="pill {{ strtolower($offering['status']) }}">{{ $offering['status'] }}</span> <span class="pill {{ strtolower($offering['kind']) }}">{{ $offering['kind'] }}</span>@if($offering['has_historical_usage']) <span class="pill">USED / SNAPSHOT FROZEN</span>@endif</p>
<p class="interval">service {{ $offering['service_date'] ?? 'reusable' }} · {{ $offering['service_start_time'] }} → {{ $offering['service_end_time'] }} · {{ $offering['duration_minutes'] }} min</p>
<p>extra buffer {{ $offering['additional_buffer_before_minutes'] }} / {{ $offering['additional_buffer_after_minutes'] }} min</p>
<p>scope: @if($offering['applies_to_all_boats']) ALL DEMO BOATS @else boat IDs {{ implode(', ', $offering['boat_ids']) }} @endif</p>
<p class="muted"><strong>{{ $offering['operating_time_notice'] }}</strong></p>
<div class="actions">@if($offering['status']==='DRAFT')<form method="post" action="{{ route('demo.slots.activate',$offering['id']) }}">@csrf<button>启用 ACTIVE</button></form>@endif @if(in_array($offering['status'],['DRAFT','ACTIVE'],true))<form method="post" action="{{ route('demo.slots.retire',$offering['id']) }}">@csrf<button class="danger">停用 RETIRED</button></form>@endif</div>
</article>
@endforeach
</section>

<section class="card"><h2>Canonical compatibility rules</h2><p class="muted">同一无序 pair 只有一条规则。兼容 ALLOW 仍不能绕过 occupied interval 重叠；未配置 pair fail closed。</p>
@forelse($rules as $rule)<div class="rule"><span class="pill {{ strtolower($rule['policy']) }}">{{ $rule['policy'] }}</span> <strong>{{ $rule['first_slot_code'] }}</strong> ↔ <strong>{{ $rule['second_slot_code'] }}</strong><br><span class="muted">pair {{ $rule['pair_key'] }} · {{ $rule['reason'] ?? '无原因' }}</span></div>@empty<p>暂无规则。</p>@endforelse
</section>
<p class="muted">本页没有 organization_id 切换器；所有 ID 均由服务端再次限定到 DemoSiteSeeder 的虚构组织和 Plan A / Plan B 虚构船。表单使用 Laravel web CSRF 与服务端 validation。</p>
</main>
</body>
</html>
