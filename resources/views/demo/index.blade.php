<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BoatOps 虚构演示站</title>
<style>
:root{font-family:system-ui,sans-serif;color:#14213d;background:#f4f7fa}*{box-sizing:border-box}body{margin:0}.wrap{max-width:1180px;margin:auto;padding:16px}.banner{background:#8b1e1e;color:#fff;padding:18px;text-align:center;font-weight:800}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:14px}.card{background:#fff;border:1px solid #d8e0e8;border-radius:10px;padding:16px;margin:14px 0;box-shadow:0 2px 8px #0001}h1,h2,h3{margin-top:0}label{display:block;font-weight:650;margin-top:10px}input,select,textarea,button{width:100%;font:inherit;padding:10px;border:1px solid #aab6c2;border-radius:7px;background:#fff}button{margin-top:14px;background:#075985;color:#fff;border:0;font-weight:700}.notice{padding:12px;border-radius:7px;background:#dcfce7}.errors{padding:12px;border-radius:7px;background:#fee2e2;color:#7f1d1d}table{width:100%;border-collapse:collapse;font-size:.92rem}th,td{text-align:left;padding:8px;border-bottom:1px solid #e5e7eb}.scroll{overflow-x:auto}.muted{color:#52606d;font-size:.9rem}.pill{display:inline-block;padding:3px 8px;border-radius:99px;background:#e0f2fe;margin:2px}.reverse-form{display:flex;gap:6px;min-width:300px}.reverse-form input{min-width:170px}.reverse-form button{margin:0;width:auto;white-space:nowrap}@media(max-width:600px){.wrap{padding:10px}.card{padding:12px}th,td{white-space:nowrap}}
</style></head><body>
<div class="banner">
@if(config('demo_site.mode') === 'public_read_only')
虚构演示 / 非生产数据 · 人工虚构数据 · 公开只读演示 · 不连接真实运营数据、Google Sheet、OTA 或 WordPress
@else
虚构演示 / 非生产数据 · LOCAL ONLY · 不连接 Google Sheet、生产库、OTA 或 WordPress
@endif
</div>
<main class="wrap">
<h1>{{ config('demo_site.mode') === 'public_read_only' ? 'BoatOps 公开只读演示站' : 'BoatOps 本地测试站' }}</h1><p><strong>{{ $organization->name }}</strong> · 排期时区：<strong>{{ $organization->timezone }}</strong> · 未来 7 天：{{ $localStart->format('Y-m-d') }} 至 {{ $localEnd->subDay()->format('Y-m-d') }}</p>
<p><a href="{{ route('demo.calendar') }}">运营库存日历</a> · <a href="{{ route('demo.slots') }}">档期目录与兼容规则</a></p>
<div class="errors"><strong>演示默认档期；真实起止时间和周转缓冲尚未冻结。</strong> Preset clock time 不代表 Ayany、Plan A 或 Plan B 的正式运营规则。</div>
@if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors"><strong>命令未保存：</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="grid">@foreach($boats as $boat)<article class="card"><h2>{{ $boat->name }}</h2><span class="pill">{{ $boat->status }}</span><p>整船资源（非按座位库存）</p>
<h3>今日本船成本</h3>@forelse($dailyCosts[(int) $boat->id]['costs_by_currency'] as $cost)<p>{{ $cost['currency'] }} {{ number_format($cost['direct_cost_amount_minor']/100, 2) }}（燃油 {{ number_format($cost['fuel_amount_minor']/100,2) }} / 费用 {{ number_format($cost['expense_amount_minor']/100,2) }} / 消耗 {{ number_format($cost['stock_consumption_amount_minor']/100,2) }}）</p>@empty<p class="muted">暂无虚构成本。</p>@endforelse
</article>@endforeach</section>

<section class="card" id="cash-activity"><h2>每日现金活动（只读派生）</h2>
<p><strong>不可手工编辑：</strong>现金活动仅由正式燃油、费用、库存 PURCHASE 和冲销命令派生；LOAD / CONSUME / RETURN / WASTE 等非现金库存移动不会产生现金流水。本页不提供手工现金记账或修改入口。</p>
@forelse($cashActivity as $cash)
<article><h3>{{ $cash['account']->name }}</h3>
<p><span class="pill">账户类型 {{ $cash['account']->account_type }}</span> <span class="pill">币种 {{ $cash['account']->currency }}</span> <span class="pill">营业日 {{ $cash['summary']['business_date'] }}（{{ $organization->timezone }}）</span></p>
<div class="grid"><div><strong>今日流出</strong><br>{{ $cash['summary']['currency'] }} {{ number_format($cash['summary']['total_outflow_minor']/100, 2) }}</div><div><strong>今日流入</strong><br>{{ $cash['summary']['currency'] }} {{ number_format($cash['summary']['total_inflow_minor']/100, 2) }}</div><div><strong>今日净变动</strong><br>{{ $cash['summary']['currency'] }} {{ number_format($cash['summary']['net_change_minor']/100, 2) }}</div><div><strong>今日记账笔数</strong><br>{{ $cash['summary']['posting_count'] }}</div></div>
<p class="muted">最近 7 个组织本地营业日：{{ $localStart->subDays(6)->format('Y-m-d') }} 至 {{ $localStart->format('Y-m-d') }}；最多 200 条，顺序与正式现金账户 activity API 完全一致。</p>
<div class="scroll"><table><thead><tr><th>本地发生时间</th><th>方向</th><th>记账种类</th><th>金额 / 币种</th><th>状态</th><th>来源类型 / ID</th><th>冲销关系</th></tr></thead><tbody>
@forelse($cash['postings'] as $posting)<tr><td>{{ $posting['occurred_at_local']->format('Y-m-d H:i:s') }}</td><td>{{ $posting['direction'] === 'OUTFLOW' ? '流出' : '流入' }}（{{ $posting['direction'] }}）</td><td>{{ ['FUEL' => '燃油', 'EXPENSE' => '费用', 'STOCK_PURCHASE' => '库存采购', 'REVERSAL' => '冲销补偿'][$posting['posting_kind']] ?? $posting['posting_kind'] }}（{{ $posting['posting_kind'] }}）</td><td>{{ $posting['currency'] }} {{ number_format($posting['amount_minor']/100, 2) }}</td><td>{{ $posting['status'] === 'POSTED' ? '已记账' : '已冲销' }}（{{ $posting['status'] }}）</td><td>{{ ['fuel_log' => '燃油记录', 'expense' => '费用记录', 'stock_movement' => '库存流水', 'finance_reversal' => '财务冲销'][$posting['source']['type']] ?? $posting['source']['type'] }}（{{ $posting['source']['type'] }}）#{{ $posting['source']['id'] }}</td><td>@if($posting['reversal_of_posting_id'] !== null)冲销原现金流水 #{{ $posting['reversal_of_posting_id'] }}@elseif(isset($cash['compensation_by_original'][$posting['cash_posting_id']]))补偿现金流水 #{{ $cash['compensation_by_original'][$posting['cash_posting_id']] }}@else—@endif</td></tr>
@empty<tr><td colspan="7">最近 7 个营业日暂无派生现金流水。</td></tr>@endforelse
</tbody></table></div></article>
@empty<p>没有启用的虚构现金账户。</p>@endforelse
</section>

<section class="card"><h2>近期燃油流水</h2><p class="muted">更正方式：冲销原记录，再使用下方正常创建表单重录；不修改或删除原金额。</p><div class="scroll"><table><thead><tr><th>类型 / ID</th><th>外部引用</th><th>发生时间（{{ $organization->timezone }}）</th><th>金额 / 数量</th><th>状态 / 冲销关系</th><th>操作</th></tr></thead><tbody>
@forelse($recentFuel as $row)<tr><td>燃油 #{{ $row->id }}</td><td>{{ $row->external_reference }}</td><td>{{ $row->occurred_at_local->format('Y-m-d H:i') }}</td><td>{{ $row->currency }} {{ number_format($row->total_amount_minor/100, 2) }} / {{ $row->liters }} L</td><td><strong>{{ $row->status }}</strong>@if($row->reversal_reason)<br>原因：{{ $row->reversal_reason }}<br>服务端冲销：{{ $row->reversed_at_local->format('Y-m-d H:i') }}@endif</td><td>@if(config('demo_site.mode') === 'local_write' && $row->status === 'POSTED')<form class="reverse-form" method="post" action="{{ route('demo.reverse') }}">@csrf<input type="hidden" name="command_id" value="{{ $row->command_id }}"><input type="hidden" name="original_record_type" value="fuel_log"><input type="hidden" name="original_record_id" value="{{ $row->id }}"><input name="reason" placeholder="必填冲销原因" required minlength="3"><button>冲销</button></form>@else — @endif</td></tr>@empty<tr><td colspan="6">暂无燃油流水。</td></tr>@endforelse
</tbody></table></div></section>

<section class="card"><h2>近期费用流水</h2><div class="scroll"><table><thead><tr><th>类型 / ID</th><th>外部引用</th><th>发生时间（{{ $organization->timezone }}）</th><th>金额 / 币种</th><th>状态 / 冲销关系</th><th>操作</th></tr></thead><tbody>
@forelse($recentExpenses as $row)<tr><td>费用 #{{ $row->id }}</td><td>{{ $row->external_reference }}</td><td>{{ $row->occurred_at_local->format('Y-m-d H:i') }}</td><td>{{ $row->currency }} {{ number_format($row->total_amount_minor/100, 2) }}</td><td><strong>{{ $row->status }}</strong>@if($row->reversal_reason)<br>原因：{{ $row->reversal_reason }}<br>服务端冲销：{{ $row->reversed_at_local->format('Y-m-d H:i') }}@endif</td><td>@if(config('demo_site.mode') === 'local_write' && $row->status === 'POSTED')<form class="reverse-form" method="post" action="{{ route('demo.reverse') }}">@csrf<input type="hidden" name="command_id" value="{{ $row->command_id }}"><input type="hidden" name="original_record_type" value="expense"><input type="hidden" name="original_record_id" value="{{ $row->id }}"><input name="reason" placeholder="必填冲销原因" required minlength="3"><button>冲销</button></form>@else — @endif</td></tr>@empty<tr><td colspan="6">暂无费用流水。</td></tr>@endforelse
</tbody></table></div></section>

<section class="card"><h2>近期库存流水</h2><div class="scroll"><table><thead><tr><th>类型 / ID</th><th>外部引用</th><th>发生时间（{{ $organization->timezone }}）</th><th>数量 / 成本币种</th><th>状态 / 冲销关系</th><th>操作</th></tr></thead><tbody>
@forelse($recentStock as $row)<tr><td>库存 {{ $row->movement_type }} #{{ $row->id }}@if($row->movement_type === 'REVERSAL')<br><span class="pill">补偿流水，不可再次冲销</span>@endif</td><td>{{ $row->external_reference }}</td><td>{{ $row->occurred_at_local->format('Y-m-d H:i') }}</td><td>{{ $row->quantity }} / {{ $row->currency }} {{ number_format($row->total_cost_amount_minor/100, 2) }}</td><td><strong>{{ $row->status }}</strong>@if($row->reversal_reason)<br>原因：{{ $row->reversal_reason }}<br>服务端冲销：{{ $row->reversed_at_local->format('Y-m-d H:i') }}<br>补偿 movement ID：#{{ $row->compensating_stock_movement_id }}@elseif($row->reversal_of_movement_id)<br>原 movement ID：#{{ $row->reversal_of_movement_id }}@endif</td><td>@if(config('demo_site.mode') === 'local_write' && $row->status === 'POSTED' && $row->movement_type !== 'REVERSAL' && $row->reversal_of_movement_id === null)<form class="reverse-form" method="post" action="{{ route('demo.reverse') }}">@csrf<input type="hidden" name="command_id" value="{{ $row->command_id }}"><input type="hidden" name="original_record_type" value="stock_movement"><input type="hidden" name="original_record_id" value="{{ $row->id }}"><input name="reason" placeholder="必填冲销原因" required minlength="3"><button>冲销</button></form>@else — @endif</td></tr>@empty<tr><td colspan="6">暂无库存流水。</td></tr>@endforelse
</tbody></table></div></section>

<section class="card"><h2>未来 7 天实际排期（allocations / trips）</h2><div class="scroll"><table><thead><tr><th>船</th><th>开始</th><th>结束</th><th>类型</th><th>Trip</th><th>成本摘要</th></tr></thead><tbody>
@forelse($schedule as $slot)<tr><td>{{ $boats->firstWhere('id', $slot->boat_id)->name }}</td><td>{{ $slot->business_start_local->format('Y-m-d H:i') }}</td><td>{{ $slot->business_end_local->format('Y-m-d H:i') }}</td><td>{{ $slot->allocation_type }}</td><td>{{ $slot->external_reference }} / #{{ $slot->trip_id }} {{ $slot->trip_status }}</td><td>@foreach(($tripCosts[(int) $slot->trip_id]['costs_by_currency'] ?? []) as $cost) {{ $cost['currency'] }} {{ number_format($cost['direct_cost_amount_minor']/100,2) }} @endforeach</td></tr>@empty<tr><td colspan="6">未来 7 天没有虚构排期。</td></tr>@endforelse
</tbody></table></div></section>

@if(config('demo_site.mode') === 'local_write')<section class="grid">
<form class="card" method="post" action="{{ route('demo.fuel') }}">@csrf<h2>加油</h2><input type="hidden" name="command_id" value="{{ old('command_id', $commandIds['fuel']) }}">
<label>船<select name="boat_id" required>@foreach($boats as $boat)<option value="{{ $boat->id }}">{{ $boat->name }}</option>@endforeach</select></label>
<label>Trip（可选）<select name="trip_id"><option value="">不关联</option>@foreach($schedule->whereNotNull('trip_id') as $slot)<option value="{{ $slot->trip_id }}">#{{ $slot->trip_id }}</option>@endforeach</select></label>
<label>THB 账户<select name="cash_account_id" required>@foreach($cashAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
<label>发生时间<input type="datetime-local" name="occurred_at" value="{{ old('occurred_at', $localNow->format('Y-m-d\TH:i')) }}" required></label>
<label>虚构加油站<input name="station_name" value="虚构演示码头油站" required></label><label>升数<input type="number" step="0.001" name="liters" value="10.000" required></label>
<label>每升金额（satang）<input type="number" name="price_per_liter_minor" value="3500" required></label><label>总额（satang）<input type="number" name="total_amount_minor" value="35000" required></label><button>保存虚构加油记录</button></form>

<form class="card" method="post" action="{{ route('demo.expense') }}">@csrf<h2>分类费用</h2><input type="hidden" name="command_id" value="{{ old('command_id', $commandIds['expense']) }}">
<label>船<select name="boat_id"><option value="">公共费用</option>@foreach($boats as $boat)<option value="{{ $boat->id }}">{{ $boat->name }}</option>@endforeach</select></label>
<label>Trip（可选）<select name="trip_id"><option value="">不关联</option>@foreach($schedule->whereNotNull('trip_id') as $slot)<option value="{{ $slot->trip_id }}">#{{ $slot->trip_id }}</option>@endforeach</select></label>
<label>分类<select name="expense_category_id" required>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }} / {{ $category->cost_scope }}</option>@endforeach</select></label>
<label>THB 账户<select name="cash_account_id" required>@foreach($cashAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
<label>发生时间<input type="datetime-local" name="occurred_at" value="{{ old('occurred_at', $localNow->format('Y-m-d\TH:i')) }}" required></label><label>说明<input name="description" value="虚构演示码头费用" required></label>
<label>金额（satang）<input type="number" name="amount_minor" value="10000" required></label><button>保存虚构分类费用</button></form>
</section>@endif

@if(config('demo_site.mode') === 'local_write')<section class="card"><h2>库存 PURCHASE / LOAD / CONSUME / RETURN / WASTE</h2><form method="post" action="{{ route('demo.stock') }}" id="stock-form">@csrf<input type="hidden" name="command_id" value="{{ old('command_id', $commandIds['stock']) }}"><div class="grid">
<label>动作<select name="movement_type" id="movement-type">@foreach(['PURCHASE','LOAD','CONSUME','RETURN','WASTE'] as $type)<option>{{ $type }}</option>@endforeach</select></label>
<label>物资<select name="item_id">@foreach($items as $item)<option value="{{ $item->id }}">{{ $item->name }} / {{ $item->unit }}</option>@endforeach</select></label>
<label>船（PURCHASE 留空）<select name="boat_id"><option value="">仓库</option>@foreach($boats as $boat)<option value="{{ $boat->id }}">{{ $boat->name }}</option>@endforeach</select></label>
<label>Trip（可选）<select name="trip_id"><option value="">不关联</option>@foreach($schedule->whereNotNull('trip_id') as $slot)<option value="{{ $slot->trip_id }}">#{{ $slot->trip_id }}</option>@endforeach</select></label>
<label>账户（仅 PURCHASE）<select name="cash_account_id"><option value="">不适用</option>@foreach($cashAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
<label>数量<input type="number" step="0.001" name="quantity" value="1.000" required></label><label>采购总成本 satang（仅 PURCHASE）<input type="number" name="total_cost_amount_minor"></label>
<label>发生时间<input type="datetime-local" name="occurred_at" value="{{ old('occurred_at', $localNow->format('Y-m-d\TH:i')) }}" required></label><label>原因（WASTE 必填）<input name="reason"></label></div><p id="stock-hint" class="muted"></p><button>保存虚构库存流水</button></form></section>@endif

<section class="card"><h2>仓库及船上库存余额</h2><div class="scroll"><table><thead><tr><th>位置</th><th>物资</th><th>数量</th><th>移动平均成本</th><th>库存价值</th></tr></thead><tbody>@forelse($balances as $balance)<tr><td>{{ $balance['location_key'] }}</td><td>{{ $balance['name'] }}</td><td>{{ $balance['quantity'] }} {{ $balance['unit'] }}</td><td>{{ $balance['currency'] }} {{ number_format($balance['average_unit_cost_minor']/100, 2) }}</td><td>{{ $balance['currency'] }} {{ number_format($balance['stock_value_amount_minor']/100, 2) }}</td></tr>@empty<tr><td colspan="5">暂无虚构库存。</td></tr>@endforelse</tbody></table></div></section>
<p class="muted">本页面只使用服务端定位的最小权限虚构 actor；浏览器不接收 API Token 或 Bearer 凭据。</p></main>
<script>const type=document.getElementById("movement-type"),hint=document.getElementById("stock-hint");if(type&&hint){function explain(){hint.textContent={PURCHASE:"入仓；需账户和采购总成本，不选船/Trip。",LOAD:"从仓库装船；必须选船。",CONSUME:"从船上消耗；必须选船。",RETURN:"从船退回仓库；必须选船。",WASTE:"从仓库或船报损；必须填写原因。"}[type.value]}type.addEventListener("change",explain);explain();}</script>
</body></html>
