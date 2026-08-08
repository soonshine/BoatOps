<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BoatOps 虚构库存日历</title>
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:#102a43;background:#f3f7fb;line-height:1.5}*{box-sizing:border-box;min-width:0}html,body{margin:0;max-width:100%;overflow-x:hidden}body{overflow-wrap:anywhere}.banner{padding:16px;background:#8b1e1e;color:#fff;text-align:center;font-weight:850}.wrap{width:min(100%,1240px);margin:auto;padding:clamp(10px,3vw,24px)}.topbar,.filters,.pager,.legend{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.topbar{justify-content:space-between}.nav{display:flex;flex-wrap:wrap;gap:8px}.button,button{display:inline-flex;justify-content:center;align-items:center;border:0;border-radius:8px;background:#075985;color:#fff;padding:10px 14px;font:inherit;font-weight:750;text-decoration:none;cursor:pointer}.button.secondary{background:#334e68}.filters{background:#fff;border:1px solid #d8e2ec;border-radius:12px;padding:14px;margin:14px 0}.filters label{font-weight:700;flex:1 1 220px}.filters select{display:block;width:100%;max-width:100%;padding:10px;border:1px solid #9fb3c8;border-radius:8px;background:#fff;font:inherit}.filters button{flex:0 1 150px}.notice,.errors{border-radius:10px;padding:12px;margin:12px 0}.notice{background:#dcfce7}.errors{background:#fee2e2;color:#7f1d1d}.meta{color:#486581}.legend{margin:14px 0}.pill{display:inline-flex;border-radius:999px;padding:4px 9px;font-size:.78rem;font-weight:850;letter-spacing:.02em}.available{background:#dcfce7;color:#166534}.held{background:#fef3c7;color:#92400e}.confirmed{background:#dbeafe;color:#1e40af}.blocked{background:#fee2e2;color:#991b1b}.unavailable{background:#e5e7eb;color:#374151}.active{background:#dcfce7;color:#166534}.draft{background:#fef3c7;color:#92400e}.retired{background:#e5e7eb;color:#374151}.boat{margin:18px 0}.boat>header{background:#16324f;color:#fff;padding:14px;border-radius:12px 12px 0 0}.calendar-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,330px),1fr));gap:12px;align-items:start;background:#dce6ef;padding:12px;border-radius:0 0 12px 12px}.day{background:#fff;border:1px solid #ccd8e3;border-radius:10px;padding:12px}.day h3{margin:0 0 8px}.allocation{border-left:4px solid #64748b;background:#f8fafc;padding:9px;margin:8px 0;border-radius:6px}.slot{border:1px solid #d8e2ec;border-radius:9px;padding:10px;margin:10px 0}.slot h4{margin:0 0 7px;font-size:1rem}.slot p{margin:5px 0;font-size:.88rem}.interval{font-variant-numeric:tabular-nums;overflow-wrap:anywhere}.warning{border:3px solid #b91c1c;background:#fff1f2;color:#7f1d1d;padding:14px;border-radius:10px;font-weight:850;margin:14px 0}.empty{color:#627d98}.pager{justify-content:space-between}.pager .button{flex:1 1 170px}.footer{margin:20px 0;color:#486581;font-size:.9rem}@media(max-width:390px){.wrap{padding:9px}.banner{padding:12px 9px}.button,button{width:100%}.nav,.filters,.pager{display:grid;grid-template-columns:1fr;width:100%}.calendar-grid{padding:8px}.day{padding:9px}.slot{padding:9px}.legend{gap:6px}}
</style>
</head>
<body data-demo-page="calendar" data-verified-mobile-width="390">
<div class="banner">
@if(config('demo_site.mode') === 'public_read_only')
人工虚构数据 · 公开只读演示 · 非生产库存日历
@else
DEMO DATA ONLY · LOCAL ONLY · 非生产库存日历
@endif
</div>
<main class="wrap">
<header class="topbar">
<div><h1>运营库存日历</h1><p class="meta"><strong>{{ $organization->name }}</strong> · {{ $calendar['business_timezone'] }} · 整船库存（不按座位扣减）</p></div>
<nav class="nav" aria-label="演示站导航"><a class="button secondary" href="{{ route('demo.index') }}">财务演示</a><a class="button" href="{{ route('demo.slots') }}">档期目录</a></nav>
</header>
<div class="warning">演示默认档期；真实起止时间和周转缓冲尚未冻结。<br>下列 preset clock time 不代表 Ayany、Plan A 或 Plan B 的正式运营规则。</div>
@if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors"><strong>日历输入无效：</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form class="filters" method="get" action="{{ route('demo.calendar') }}">
<label>7 天起始日（{{ $calendar['business_timezone'] }}）<input type="date" name="from" value="{{ $calendar['from'] }}" style="display:block;width:100%;max-width:100%;padding:10px;border:1px solid #9fb3c8;border-radius:8px;font:inherit"></label>
<label>Plan A / Plan B 船只筛选<select name="boat_id"><option value="">全部虚构船</option>@foreach($boats as $boat)<option value="{{ $boat->id }}" @selected($selectedBoatId === (int) $boat->id)>{{ $boat->name }}</option>@endforeach</select></label>
<button type="submit">刷新 7 天日历</button>
</form>
<div class="pager"><a class="button secondary" href="{{ route('demo.calendar', array_filter(['from'=>$previousFrom,'boat_id'=>$selectedBoatId])) }}">← 前 7 天</a><a class="button secondary" href="{{ route('demo.calendar', array_filter(['from'=>$nextFrom,'boat_id'=>$selectedBoatId])) }}">后 7 天 →</a></div>
<div class="legend" aria-label="日历状态矩阵"><strong>状态矩阵：</strong>@foreach(['AVAILABLE','HELD','CONFIRMED','BLOCKED','UNAVAILABLE'] as $state)<span class="pill {{ strtolower($state) }}">{{ $state }}</span>@endforeach</div>
<p class="meta">范围 {{ $calendar['from'] }} 至 {{ $calendar['to'] }}（含首尾，共 7 个组织本地日期） · revision {{ $calendar['inventory_revision'] }} · as-of {{ $calendar['as_of'] }}</p>
@foreach($calendar['boats'] as $boat)
<section class="boat" data-boat-id="{{ $boat['boat_id'] }}">
<header><h2>{{ $boat['name'] }}</h2><div>资源单位 {{ $boat['inventory_unit'] }} · 船级 buffer 前 {{ $boat['buffer_before_minutes'] }} 分钟 / 后 {{ $boat['buffer_after_minutes'] }} 分钟</div></header>
<div class="calendar-grid">
@foreach($boat['dates'] as $day)
<article class="day" data-business-date="{{ $day['date'] }}"><h3>{{ $day['date'] }}</h3>
@if($day['allocations'] !== [])
<h4>allocation 权威占用</h4>
@foreach($day['allocations'] as $allocation)
<div class="allocation"><span class="pill {{ strtolower($allocation['status']) }}">{{ $allocation['status'] }}</span> <strong>{{ $allocation['allocation_type'] }}</strong>
<p class="interval">service: {{ $allocation['service_start'] }} → {{ $allocation['service_end'] }}</p>
<p class="interval">actual occupied: {{ $allocation['occupied_start'] }} → {{ $allocation['occupied_end'] }}</p>
@if($allocation['slot_code'])<p>{{ $allocation['slot_code'] }} · {{ $allocation['slot_name'] }}</p>@endif
</div>
@endforeach
@else<p class="empty">当日无 ACTIVE allocation。</p>@endif
<h4>可选档期投影</h4>
@forelse($day['slots'] as $slot)
<article class="slot" data-slot-code="{{ $slot['code'] }}" data-calendar-status="{{ $slot['status'] }}">
<h4>{{ $slot['code'] }} · {{ $slot['name'] }}</h4>
<p><span class="pill {{ strtolower($slot['status']) }}">{{ $slot['status'] }}</span> <span class="pill {{ strtolower($slot['definition_status']) }}">{{ $slot['definition_status'] }}</span> <span class="pill">{{ $slot['kind'] }}</span></p>
<p><strong>identity:</strong> definition #{{ $slot['definition_id'] }} / offering {{ $slot['slot_offering_id'] ?? '—' }} / instance {{ $slot['custom_slot_instance_id'] ?? '—' }}</p>
<p class="interval"><strong>service local:</strong> {{ $slot['service_start_local'] }} → {{ $slot['service_end_local'] }}</p>
<p class="interval"><strong>service UTC:</strong> {{ $slot['service_start'] }} → {{ $slot['service_end'] }}</p>
<p class="interval"><strong>occupied local:</strong> {{ $slot['occupied_start_local'] }} → {{ $slot['occupied_end_local'] }}</p>
@if($slot['buffer_conflict'])<p><span class="pill blocked">BUFFER CONFLICT</span> service interval 不重叠，但 occupied interval 因周转缓冲重叠。</p>@endif
@if($slot['conflict_code'])<p><strong>{{ $slot['conflict_code'] }}</strong> · {{ $slot['conflict_message'] }}</p>@endif
@if($slot['selectable'])<form method="get" action="{{ route('demo.calendar') }}"><input type="hidden" name="from" value="{{ $calendar['from'] }}"><input type="hidden" name="boat_id" value="{{ $boat['boat_id'] }}"><input type="hidden" name="selected_slot" value="{{ $slot['definition_id'] }}"><input type="hidden" name="selected_date" value="{{ $day['date'] }}"><button class="button" type="submit">模拟选择此档期（GET，无写入）</button></form>@elseif($selectedSlotId === $slot['definition_id'] && $selectedDate === $day['date'] && (int) $boat['boat_id'] === $selectedBoatId)<p class="notice"><strong>当前模拟选择</strong>：仅为 GET 预演，不是占位；最终 HOLD / 确认仍由 BoatOps 事务重新裁决。</p>@endif
@if($slot['authority'])
@if((int) $slot['authority']['allocation_id'] === -1)
<p class="interval">simulated selection occupied: {{ $slot['authority']['occupied_start'] }} → {{ $slot['authority']['occupied_end'] }}</p>
@else
<p class="interval">authority allocation #{{ $slot['authority']['allocation_id'] }} actual occupied: {{ $slot['authority']['occupied_start'] }} → {{ $slot['authority']['occupied_end'] }}</p>
@endif
@endif
<p class="meta">{{ $slot['operating_time_notice'] }}</p>
</article>
@empty<p class="empty">本船本日没有适用的档期定义。</p>@endforelse
</article>
@endforeach
</div>
</section>
@endforeach
<p class="footer">此页是 allocation-backed read model，不是新的库存真相。最终可售必须由现有 availability / HOLD 事务重新裁决；页面不包含客户姓名、电话、酒店、房间、价格或付款数据。</p>
</main>
</body>
</html>
