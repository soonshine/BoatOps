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

<form id="inquiry-create-form" method="post" action="{{ route('operator.inquiries.store') }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

<section class="card" aria-labelledby="quick-paste-title">
<h2 id="quick-paste-title">快速粘贴</h2>
<p class="inquiry-help">把 LINE、微信、表格或聊天里的零碎订单文字整段粘贴。系统只填能明确识别的字段，识别不到的保持为空；创建前仍由操作员检查。</p>
<label>订单原文
<textarea id="quick-paste-input" maxlength="10000" placeholder="例如：8月22日 4小时收入 14450 THB；Plan C；4人；10:30 接客；酒店……"></textarea>
</label>
<div>
<button type="button" id="quick-paste-clipboard">一键粘贴并识别</button>
<button type="button" id="quick-paste-parse">识别并填充</button>
</div>
<p class="inquiry-help" id="quick-paste-status" role="status" aria-live="polite">不会自动提交，也不会覆盖你已经填写的字段。</p>
</section>

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

<script>
(() => {
    const form = document.getElementById('inquiry-create-form');
    const input = document.getElementById('quick-paste-input');
    const parseButton = document.getElementById('quick-paste-parse');
    const clipboardButton = document.getElementById('quick-paste-clipboard');
    const status = document.getElementById('quick-paste-status');
    if (!form || !input || !parseButton || !clipboardButton || !status) return;

    const normalize = (value) => value.toLowerCase().replace(/\s+/g, '');
    const field = (name) => form.elements.namedItem(name);
    const filled = [];

    function setField(name, value, label) {
        const element = field(name);
        if (!element || value === null || value === undefined || value === '' || element.value !== '') return false;
        element.value = String(value);
        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));
        filled.push(label);
        return true;
    }

    function validDate(year, month, day) {
        const candidate = new Date(year, month - 1, day);
        return candidate.getFullYear() === year && candidate.getMonth() === month - 1 && candidate.getDate() === day;
    }

    function parseDate(text) {
        let match = text.match(/\b(20\d{2})[-/.](\d{1,2})[-/.](\d{1,2})\b/);
        let year;
        let month;
        let day;
        if (match) {
            [, year, month, day] = match;
        } else {
            match = text.match(/(?:(20\d{2})年)?(\d{1,2})月(\d{1,2})日?/);
            if (match) {
                year = match[1] || String(new Date().getFullYear());
                month = match[2];
                day = match[3];
            } else {
                match = text.match(/(?:^|[^\d])(\d{1,2})[/.](\d{1,2})(?:[^\d]|$)/);
                if (!match) return null;
                year = String(new Date().getFullYear());
                month = match[1];
                day = match[2];
            }
        }
        const numericYear = Number(year);
        const numericMonth = Number(month);
        const numericDay = Number(day);
        if (!validDate(numericYear, numericMonth, numericDay)) return null;
        return `${numericYear}-${String(numericMonth).padStart(2, '0')}-${String(numericDay).padStart(2, '0')}`;
    }

    function labelledValue(text, labels) {
        const pattern = new RegExp(`(?:${labels.join('|')})\\s*[:：]\\s*([^\\n,，;；]+)`, 'i');
        const match = text.match(pattern);
        return match ? match[1].trim() : null;
    }

    function matchNamedOption(name, text, label) {
        const select = field(name);
        if (!select || select.value !== '') return;
        const haystack = normalize(text);
        const matches = Array.from(select.options).filter((option) => option.value && normalize(option.textContent).length >= 3 && haystack.includes(normalize(option.textContent)));
        if (matches.length === 1) setField(name, matches[0].value, label);
    }

    function matchDurationSlot(hours) {
        const select = field('slot_offering_id');
        if (!select || select.value !== '' || !hours) return;
        const needle = `${hours}小时`;
        const matches = Array.from(select.options).filter((option) => option.value && normalize(option.textContent).includes(needle));
        if (matches.length === 1) setField('slot_offering_id', matches[0].value, '服务时段');
    }

    function parse(text) {
        filled.length = 0;
        const source = text.trim();
        if (!source) {
            status.textContent = '没有可识别的内容。';
            return;
        }

        const serviceDate = parseDate(source);
        if (serviceDate) setField('service_date', serviceDate, '服务日期');

        const explicitReference = source.match(/(?:订单号|订单编号|参考号|reference|ref)\s*[:：#]?\s*([A-Za-z0-9][A-Za-z0-9._-]{0,99})/i);
        if (explicitReference) {
            setField('reference', explicitReference[1], '参考号');
        } else if (field('reference') && field('reference').value === '') {
            const datePart = (serviceDate || new Date().toISOString().slice(0, 10)).replaceAll('-', '');
            const now = new Date();
            const timePart = [now.getHours(), now.getMinutes(), now.getSeconds()].map((part) => String(part).padStart(2, '0')).join('');
            const suffix = Math.random().toString(36).slice(2, 5).toUpperCase();
            setField('reference', `REAL-${datePart}-${timePart}-${suffix}`, '参考号');
        }

        const duration = source.match(/(\d+(?:\.\d+)?)\s*(?:小时|小時|hours?|hrs?)/i);
        if (duration) {
            setField('service_notes', `${duration[1]}小时`, '服务时长');
            matchDurationSlot(duration[1]);
        }

        let currency = null;
        if (/(?:\bTHB\b|泰铢|泰銖|บาท|฿)/i.test(source)) currency = 'THB';
        if (/(?:\bCNY\b|\bRMB\b|人民币|人民幣)/i.test(source)) currency = 'CNY';
        const amountMatch = source.match(/(?:收入|销售金额|售卖金额|金额|总价|价格|price|amount)\s*[:：]?\s*(?:THB|CNY|RMB|฿|泰铢|泰銖|บาท)?\s*([\d,]+(?:\.\d{1,2})?)/i)
            || source.match(/(?:THB|CNY|RMB|฿|泰铢|泰銖|บาท)\s*([\d,]+(?:\.\d{1,2})?)/i)
            || source.match(/([\d,]+(?:\.\d{1,2})?)\s*(?:THB|CNY|RMB|฿|泰铢|泰銖|บาท)/i);
        if (!currency && amountMatch && /(?:收入|销售|售卖|总价|价格)/.test(source)) currency = 'THB';
        if (amountMatch && currency) {
            setField('selling_currency', currency, '币种');
            setField('selling_amount', amountMatch[1].replaceAll(',', ''), '销售金额');
        }

        const adultMatch = source.match(/(?:成人|大人|adult)\s*[:：]?\s*(\d{1,3})/i);
        const childMatch = source.match(/(?:儿童|小孩|孩子|child)\s*[:：]?\s*(\d{1,3})/i);
        if (adultMatch) setField('adult_count', adultMatch[1], '成人数');
        if (childMatch) setField('child_count', childMatch[1], '儿童数');
        const partyMatch = source.match(/(?:总人数|人数|pax)\s*[:：]?\s*(\d{1,3})/i) || source.match(/(?:^|[^\d])(\d{1,3})\s*(?:人|位|pax)(?:[^\d]|$)/i);
        if (partyMatch) {
            setField('party_size', partyMatch[1], '总人数');
        } else if (adultMatch && childMatch) {
            setField('party_size', Number(adultMatch[1]) + Number(childMatch[1]), '总人数');
        }

        const contactName = labelledValue(source, ['客人', '联系人', '姓名', 'guest', 'name']);
        if (contactName && !/^\d/.test(contactName)) setField('contact_name', contactName, '联系人');

        const email = source.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
        const phoneCandidates = source.match(/(?:\+?66[\s-]?)?(?:\d{9,10}|0\d{2}[\s-]?\d{3}[\s-]?\d{3,4})/gi) || [];
        const phone = phoneCandidates.map((candidate) => candidate.trim()).find((candidate) => !/^\d{4}[-/.]\d{1,2}[-/.]/.test(candidate));
        const whatsapp = labelledValue(source, ['WhatsApp', 'WA']);
        const wechat = labelledValue(source, ['微信', 'WeChat']);
        const line = labelledValue(source, ['LINE']);
        if (email) {
            setField('contact_method', 'EMAIL', '联系方式');
            setField('contact_value', email[0], '联系信息');
        } else if (whatsapp) {
            setField('contact_method', 'WHATSAPP', '联系方式');
            setField('contact_value', whatsapp, '联系信息');
        } else if (wechat) {
            setField('contact_method', 'WECHAT', '联系方式');
            setField('contact_value', wechat, '联系信息');
        } else if (line) {
            setField('contact_method', 'LINE', '联系方式');
            setField('contact_value', line, '联系信息');
        } else if (phone) {
            setField('contact_method', 'PHONE', '联系方式');
            setField('contact_value', phone, '联系信息');
        }

        const hotel = labelledValue(source, ['酒店', '住宿', 'hotel']);
        if (hotel) setField('hotel_name', hotel, '酒店');
        const meetingPoint = labelledValue(source, ['接客地点', '集合地点', '集合', '接送地点', 'meeting point']);
        if (meetingPoint) setField('meeting_point', meetingPoint, '集合地点');
        const route = labelledValue(source, ['路线', '行程', '目的地', 'route']);
        if (route) setField('route_summary', route, '路线');
        const serviceLocation = labelledValue(source, ['下客地点', '返程地点', '送回', 'dropoff', 'drop-off']);
        if (serviceLocation) setField('service_location', serviceLocation, '下客地点');

        let pickupTime = source.match(/(?:接客|接送|pickup|接)\D{0,8}([01]?\d|2[0-3])[:：.]([0-5]\d)/i);
        if (!pickupTime) pickupTime = source.match(/([01]?\d|2[0-3])[:：.]([0-5]\d)\s*(?:接客|接送|pickup|接)/i);
        if (/(?:不接送|无需接送|不需要接送|no pickup)/i.test(source)) {
            setField('pickup_required', '0', '接送');
        } else if (pickupTime || meetingPoint || /(?:接客|接送|pickup)/i.test(source)) {
            setField('pickup_required', '1', '接送');
        }
        if (pickupTime) setField('pickup_time', `${String(Number(pickupTime[1])).padStart(2, '0')}:${pickupTime[2]}`, '接客时间');

        matchNamedOption('boat_id', source, '船只');
        matchNamedOption('trip_template_id', source, '产品');

        if (field('notes') && field('notes').value === '') {
            setField('notes', `原始粘贴：\n${source}`.slice(0, 1000), '原始记录');
        }

        if (filled.length === 0) {
            status.textContent = '没有明确识别到字段；原文仍保留在上方，现有表单不会被改动。';
            return;
        }
        status.textContent = `已填入 ${filled.length} 项：${filled.join('、')}。未识别内容保持为空，请检查后再创建。`;
    }

    parseButton.addEventListener('click', () => parse(input.value));
    input.addEventListener('paste', () => window.setTimeout(() => parse(input.value), 0));
    clipboardButton.addEventListener('click', async () => {
        try {
            if (!navigator.clipboard?.readText) throw new Error('clipboard unavailable');
            input.value = await navigator.clipboard.readText();
            parse(input.value);
        } catch (error) {
            status.textContent = '浏览器未允许直接读取剪贴板。请在上方粘贴内容，再点「识别并填充」。';
            input.focus();
        }
    });
})();
</script>
</main>
@endsection
