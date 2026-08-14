@extends('operator.layout')

@section('title', '船期日历')
@section('bodyClass', 'fleet-calendar-body')

@section('head')
<style>
.fleet-calendar-body,
.fleet-calendar-body * { box-sizing: border-box; }
.fleet-calendar-body {
    margin: 0;
    max-width: 100%;
    overflow-x: hidden;
    background: #f4f7fb;
    color: #132238;
    line-height: 1.45;
}
.fleet-calendar-body .operator-nav {
    position: relative;
    z-index: 40;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .35rem;
    padding: .75rem clamp(.75rem, 3vw, 2rem);
    border-bottom: 1px solid #d9e2ec;
    background: #fff;
    box-shadow: 0 1px 2px rgb(15 23 42 / 5%);
}
.fleet-calendar-body .operator-nav a,
.fleet-calendar-body .operator-nav button {
    display: inline-flex;
    align-items: center;
    min-height: 2.25rem;
    padding: .45rem .7rem;
    border: 0;
    border-radius: .5rem;
    background: transparent;
    color: #334e68;
    font: inherit;
    font-size: .875rem;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}
.fleet-calendar-body .operator-nav a:first-child {
    background: #e0f2fe;
    color: #075985;
}
.fleet-calendar-body .operator-nav form { margin: 0 0 0 auto; }
.fleet-calendar-page {
    width: min(100%, 1840px);
    margin: 0 auto;
    padding: clamp(1rem, 2.5vw, 2rem);
}
.fleet-page-header {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.fleet-eyebrow {
    margin: 0 0 .3rem;
    color: #52667a;
    font-size: .78rem;
    font-weight: 800;
    letter-spacing: .03em;
}
.fleet-page-header h1 {
    margin: 0;
    color: #102a43;
    font-size: clamp(1.75rem, 4vw, 2.65rem);
    line-height: 1.08;
    letter-spacing: -.035em;
}
.fleet-subtitle {
    max-width: 56rem;
    margin: .65rem 0 0;
    color: #52667a;
}
.fleet-range-label {
    margin: 0;
    padding: .55rem .8rem;
    border: 1px solid #cbd8e6;
    border-radius: .65rem;
    background: #fff;
    color: #334e68;
    font-size: .875rem;
    font-weight: 750;
    white-space: nowrap;
}
.fleet-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(8rem, 1fr));
    gap: .75rem;
    margin: 0 0 1rem;
}
.summary-card {
    position: relative;
    overflow: hidden;
    min-height: 6.2rem;
    padding: .85rem 1rem;
    border: 1px solid #d8e2ec;
    border-radius: .85rem;
    background: #fff;
    box-shadow: 0 4px 14px rgb(15 23 42 / 5%);
}
.summary-card::before {
    position: absolute;
    inset: 0 auto 0 0;
    width: .3rem;
    background: var(--status-color);
    content: '';
}
.summary-card span {
    display: block;
    color: #52667a;
    font-size: .75rem;
    font-weight: 850;
    letter-spacing: .06em;
}
.summary-card strong {
    display: block;
    margin-top: .15rem;
    color: #102a43;
    font-size: 1.8rem;
    line-height: 1.1;
}
.summary-card small { color: #6b7f93; }
.summary-card.status-available {
    background: #f8fafc;
    box-shadow: none;
}
.status-available { --status-color: #15803d; --status-soft: #ecfdf3; --status-ink: #166534; }
.status-held { --status-color: #d97706; --status-soft: #fffbeb; --status-ink: #92400e; }
.status-confirmed { --status-color: #2563eb; --status-soft: #eff6ff; --status-ink: #1e40af; }
.status-blocked { --status-color: #dc2626; --status-soft: #fff1f2; --status-ink: #991b1b; }
.status-unavailable { --status-color: #64748b; --status-soft: #f1f5f9; --status-ink: #475569; }
.fleet-toolbar {
    display: grid;
    grid-template-columns: minmax(11rem, 1fr) minmax(12rem, 1.3fr) auto;
    gap: .8rem;
    align-items: end;
    padding: 1rem;
    border: 1px solid #d8e2ec;
    border-radius: .9rem;
    background: #fff;
    box-shadow: 0 4px 14px rgb(15 23 42 / 5%);
}
.fleet-toolbar label {
    margin: 0;
    color: #334e68;
    font-size: .78rem;
    font-weight: 800;
}
.fleet-toolbar input,
.fleet-toolbar select {
    display: block;
    width: 100%;
    min-height: 2.65rem;
    margin-top: .3rem;
    padding: .55rem .7rem;
    border: 1px solid #9fb3c8;
    border-radius: .55rem;
    background: #fff;
    color: #102a43;
    font: inherit;
}
.button,
.fleet-toolbar button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 2.65rem;
    padding: .55rem .9rem;
    border: 1px solid #075985;
    border-radius: .55rem;
    background: #075985;
    color: #fff;
    font: inherit;
    font-size: .875rem;
    font-weight: 800;
    line-height: 1;
    text-decoration: none;
    cursor: pointer;
}
.button.secondary {
    border-color: #c5d2df;
    background: #fff;
    color: #334e68;
}
.fleet-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: .65rem;
    margin: .8rem 0 1rem;
}
.range-switcher,
.date-pager,
.status-legend {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .4rem;
}
.range-switcher .button[aria-current="page"] {
    border-color: #075985;
    background: #e0f2fe;
    color: #075985;
}
.status-legend {
    margin: .25rem 0 .8rem;
    color: #52667a;
    font-size: .8rem;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    width: fit-content;
    padding: .23rem .48rem;
    border-radius: 999px;
    background: var(--status-soft);
    color: var(--status-ink);
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .02em;
    line-height: 1.3;
}
.status-badge::before {
    width: .42rem;
    height: .42rem;
    border-radius: 50%;
    background: var(--status-color);
    content: '';
}
.calendar-scroll {
    max-width: 100%;
    max-height: min(72vh, 780px);
    overflow: auto;
    border: 1px solid #cbd8e6;
    border-radius: 1rem;
    background: #fff;
    box-shadow: 0 10px 28px rgb(15 23 42 / 8%);
    overscroll-behavior-inline: contain;
    scrollbar-gutter: stable;
}
.calendar-scroll:focus-visible {
    outline: 3px solid #38bdf8;
    outline-offset: 2px;
}
.fleet-table {
    width: max-content;
    min-width: 100%;
    border: 0;
    border-collapse: separate;
    border-spacing: 0;
    table-layout: fixed;
}
.fleet-table th,
.fleet-table td {
    border: 0;
    border-right: 1px solid #dce5ee;
    border-bottom: 1px solid #dce5ee;
    padding: .75rem;
    vertical-align: top;
}
.fleet-table thead th {
    position: sticky;
    top: 0;
    z-index: 8;
    min-width: 14rem;
    width: 14rem;
    padding: .65rem .75rem;
    background: #eaf1f8;
    color: #334e68;
    box-shadow: inset 0 -1px 0 #cbd8e6;
    font-size: .75rem;
    text-align: center;
}
.fleet-table thead th time { display: block; }
.fleet-table thead .weekday {
    display: block;
    margin-bottom: .12rem;
    color: #075985;
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .06em;
    text-transform: uppercase;
}
.fleet-table .boat-column {
    position: sticky;
    left: 0;
    z-index: 7;
    width: 12rem;
    min-width: 12rem;
    max-width: 12rem;
    background: #f8fafc;
    box-shadow: 1px 0 0 #cbd8e6;
}
.fleet-table thead .boat-column {
    z-index: 12;
    background: #16324f;
    color: #fff;
    text-align: left;
}
.fleet-table tbody .boat-column {
    padding: .95rem .8rem;
    color: #102a43;
}
.boat-name {
    display: block;
    font-size: 1rem;
    line-height: 1.25;
}
.boat-meta {
    display: block;
    margin-top: .35rem;
    color: #627d98;
    font-size: .72rem;
    font-weight: 650;
}
.date-cell {
    width: 14rem;
    min-width: 14rem;
    background: #fbfdff;
}
.date-cell[data-availability-mode="quiet"] { background: #fdfefe; }
.cell-date { display: none; }
.slot-card {
    position: relative;
    margin: 0 0 .65rem;
    padding: .72rem;
    border: 1px solid #d6e0ea;
    border-left: 4px solid var(--status-color);
    border-radius: .7rem;
    background: var(--status-soft);
    color: #25374a;
    box-shadow: 0 3px 10px rgb(15 23 42 / 7%);
}
.slot-card:last-of-type { margin-bottom: 0; }
.slot-card.status-unavailable {
    background-color: #f5f7fa;
    background-image: repeating-linear-gradient(135deg, transparent, transparent 8px, rgb(100 116 139 / 5%) 8px, rgb(100 116 139 / 5%) 16px);
}
.slot-card header {
    display: flex;
    align-items: start;
    justify-content: space-between;
    gap: .5rem;
}
.slot-card h3 {
    margin: 0;
    color: #132238;
    font-size: .9rem;
    line-height: 1.25;
}
.slot-time {
    display: flex;
    flex-wrap: wrap;
    gap: .28rem .5rem;
    margin: .45rem 0 0;
    color: #334e68;
    font-size: .78rem;
    font-variant-numeric: tabular-nums;
    font-weight: 750;
}
.slot-duration { color: #6b7f93; font-weight: 650; }
.buffer-indicator,
.slot-reason,
.slot-detail-state {
    margin: .55rem 0 0;
    font-size: .74rem;
    line-height: 1.4;
}
.buffer-indicator {
    padding: .42rem .5rem;
    border: 1px solid #fdba74;
    border-radius: .45rem;
    background: #fff7ed;
    color: #9a3412;
    font-weight: 750;
}
.slot-reason { color: #52667a; }
.slot-action {
    display: inline-flex;
    margin-top: .65rem;
    color: #075985;
    font-size: .76rem;
    font-weight: 850;
    text-underline-offset: .18rem;
}
.slot-detail-state { color: #627d98; font-style: italic; }
.available-slots {
    margin: 0;
    border: 1px dashed #a9bdcf;
    border-radius: .7rem;
    background: #fff;
    color: #52667a;
}
.available-slots[open] {
    border-style: solid;
    border-color: #86b79a;
    box-shadow: 0 5px 16px rgb(15 23 42 / 8%);
}
.available-slots > summary {
    display: flex;
    min-height: 5.4rem;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    padding: .8rem;
    color: #527060;
    font-size: .78rem;
    font-weight: 800;
    text-align: center;
    cursor: pointer;
    list-style: none;
}
.available-slots > summary::-webkit-details-marker { display: none; }
.available-slots > summary::before {
    width: .48rem;
    height: .48rem;
    flex: 0 0 auto;
    border-radius: 50%;
    background: #5b9270;
    content: '';
}
.available-slots > summary::after {
    color: #6b7f93;
    content: '＋';
    font-size: 1rem;
}
.available-slots[open] > summary {
    min-height: 0;
    justify-content: space-between;
    border-bottom: 1px solid #dce7df;
    color: #285a3d;
    text-align: left;
}
.available-slots[open] > summary::after { content: '−'; }
.available-slots.is-partial { margin-top: .65rem; }
.available-slots.is-partial > summary { min-height: 3.6rem; }
.duration-first-chooser { padding: .6rem; }
.duration-step-label {
    display: flex;
    align-items: center;
    gap: .4rem;
    margin: 0 0 .5rem;
    color: #52667a;
    font-size: .7rem;
    font-weight: 850;
}
.duration-step-label span {
    display: inline-flex;
    width: 1.25rem;
    height: 1.25rem;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #e3f1e8;
    color: #285a3d;
    font-size: .66rem;
}
.duration-choice {
    overflow: hidden;
    margin-top: .4rem;
    border: 1px solid #d5e3da;
    border-radius: .55rem;
    background: #f8fbf9;
}
.duration-choice:first-of-type { margin-top: 0; }
.duration-choice > summary {
    display: flex;
    min-height: 0;
    align-items: center;
    justify-content: space-between;
    gap: .35rem;
    padding: .58rem .65rem;
    border: 0;
    color: #183b29;
    text-align: left;
    cursor: pointer;
    list-style: none;
}
.duration-choice > summary::-webkit-details-marker { display: none; }
.duration-choice > summary::before { display: none; }
.duration-choice > summary::after {
    margin-left: auto;
    color: #6b7f93;
    content: '选择 ＋';
    font-size: .68rem;
}
.duration-choice[open] > summary {
    border-bottom: 1px solid #d5e3da;
    background: #edf7f0;
}
.duration-choice[open] > summary::after { content: '收起 −'; }
.duration-choice-label {
    color: #183b29;
    font-size: .86rem;
    font-weight: 900;
    white-space: nowrap;
}
.duration-slot-panel { padding: .55rem; }
.duration-slot-panel .duration-step-label { margin-bottom: .4rem; }
.available-slot-list {
    display: grid;
    gap: .45rem;
    margin: 0;
    padding: 0;
    list-style: none;
}
.available-slot-option {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: .35rem .6rem;
    align-items: center;
    padding: .55rem;
    border-radius: .5rem;
    background: #f4faf6;
}
.available-slot-option strong {
    display: block;
    color: #183b29;
    font-size: .8rem;
    line-height: 1.3;
}
.available-slot-option time,
.available-slot-option small {
    color: #60786a;
    font-size: .7rem;
    font-variant-numeric: tabular-nums;
}
.available-slot-option .default-departure-note {
    display: block;
    width: fit-content;
    margin-top: .3rem;
    padding: .2rem .4rem;
    border-radius: .35rem;
    background: #fff4d8;
    color: #805000;
    font-weight: 850;
}
.available-slot-option .slot-action {
    grid-row: 1 / span 2;
    grid-column: 2;
    margin: 0;
    white-space: nowrap;
}
.duration-slot-panel .available-slot-option { grid-template-columns: 1fr; }
.duration-slot-panel .available-slot-option .slot-action {
    grid-row: auto;
    grid-column: auto;
    width: 100%;
    min-height: 2.25rem;
    justify-content: center;
    padding: .42rem .55rem;
    border: 1px solid #b6cfbf;
    border-radius: .45rem;
    background: #fff;
    text-decoration: none;
}
.available-read-only {
    margin: 0;
    padding: 0 .65rem .65rem;
    color: #6b7f93;
    font-size: .72rem;
}
.empty-slots {
    margin: 0;
    color: #7b8fa3;
    font-size: .8rem;
}
.occupied-details {
    margin-top: .65rem;
    padding-top: .55rem;
    border-top: 1px dashed #b8c7d6;
    color: #52667a;
    font-size: .72rem;
}
.occupied-details summary {
    font-weight: 800;
    cursor: pointer;
}
.occupied-row {
    display: grid;
    gap: .15rem;
    margin-top: .5rem;
    padding: .45rem;
    border-radius: .45rem;
    background: #fff;
}
.occupied-row time { font-variant-numeric: tabular-nums; }
.fleet-empty {
    padding: 2.5rem !important;
    color: #627d98;
    text-align: center !important;
}
.projection-note {
    display: flex;
    flex-wrap: wrap;
    align-items: start;
    justify-content: space-between;
    gap: .75rem;
    margin-top: .85rem;
    color: #52667a;
    font-size: .78rem;
}
.projection-note p { margin: 0; }
.projection-note details { max-width: 38rem; }
.projection-note summary { color: #334e68; font-weight: 750; cursor: pointer; }
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
@media (max-width: 760px) {
    .fleet-calendar-body .operator-nav { padding: .6rem .75rem; }
    .fleet-calendar-body .operator-nav form { margin-left: 0; }
    .fleet-calendar-page { padding: .85rem .65rem 1.5rem; }
    .fleet-page-header { align-items: start; }
    .fleet-range-label { white-space: normal; }
    .fleet-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
    .summary-card { min-height: 5.2rem; padding: .7rem .8rem; }
    .summary-card strong { font-size: 1.55rem; }
    .fleet-toolbar { grid-template-columns: 1fr; padding: .8rem; }
    .fleet-controls { align-items: stretch; }
    .range-switcher, .date-pager { width: 100%; }
    .range-switcher .button, .date-pager .button { flex: 1 1 auto; }
    .calendar-scroll { max-height: none; border-radius: .75rem; scrollbar-gutter: auto; }
    .fleet-table .boat-column { width: 8.3rem; min-width: 8.3rem; max-width: 8.3rem; }
    .fleet-table thead th, .date-cell { width: 13rem; min-width: 13rem; }
    .fleet-table th, .fleet-table td { padding: .55rem; }
    .cell-date {
        display: block;
        margin: 0 0 .45rem;
        color: #52667a;
        font-size: .7rem;
        font-weight: 850;
    }
    .slot-card { padding: .62rem; }
    .available-slots > summary { min-height: 4.8rem; }
    .duration-choice > summary { min-height: 2.65rem; }
    .available-slot-option { grid-template-columns: 1fr; }
    .available-slot-option .slot-action {
        grid-row: auto;
        grid-column: auto;
        min-height: 2.35rem;
        justify-content: center;
        padding: .45rem .6rem;
        border: 1px solid #b6cfbf;
        border-radius: .45rem;
        background: #fff;
        text-decoration: none;
    }
}
</style>
@endsection

@section('content')
@php
    $operatorMembership = request()->attributes->get('operator_membership');
    $statusLabels = collect(['AVAILABLE', 'HELD', 'CONFIRMED', 'BLOCKED', 'UNAVAILABLE'])
        ->mapWithKeys(static fn (string $status): array => [$status => \App\Support\OperatorUi::status($status)])
        ->all();
    $allocationTypeLabels = [
        'HOLD' => '预留',
        'BOOKING' => '订单',
        'BLOCK' => '停用',
    ];
    $calendarQuery = static fn (array $overrides = []): array => array_filter(
        array_merge(['from' => $from, 'range' => $range, 'boat_id' => $selectedBoatId], $overrides),
        static fn (mixed $value): bool => $value !== null,
    );
    $displaySlotName = static function (string $slotName, string $boatName, string $slotCode = ''): string {
        $displayName = trim($slotName);
        $trimmedBoatName = trim($boatName);

        if ($trimmedBoatName !== '') {
            $withoutBoat = preg_replace('/^'.preg_quote($trimmedBoatName, '/').'\s*/iu', '', $displayName);
            if (is_string($withoutBoat) && $withoutBoat !== '') {
                $displayName = $withoutBoat;
            }
        }

        $displayName = \App\Support\OperatorUi::slotName($displayName, $slotCode);
        $displayName = preg_replace('/(\d+)\s*小时/u', '$1 小时', $displayName) ?? $displayName;
        $displayName = preg_replace('/^(\d+\s*小时)\s*(上午|下午)/u', '$2 $1', $displayName) ?? $displayName;

        if (preg_match('/(?:^|[-_])AM$/i', $slotCode) === 1 && ! str_contains($displayName, '上午')) {
            $displayName = '上午 '.$displayName;
        } elseif (preg_match('/(?:^|[-_])PM$/i', $slotCode) === 1 && ! str_contains($displayName, '下午')) {
            $displayName = '下午 '.$displayName;
        }

        return trim($displayName);
    };
    $durationLabel = static fn (int $minutes): string => $minutes % 60 === 0
        ? (int) ($minutes / 60).' 小时'
        : $minutes.' 分钟';
    $conflictReason = static function (array $slot): ?string {
        if (! $slot['conflict_message']) {
            return null;
        }

        return match ($slot['conflict_code'] ?? null) {
            'SLOT_COMPATIBILITY_CONFLICT' => '同一船只、同一服务日期下，这些时段不能组合。',
            'SIMULATED_SELECTION' => (string) $slot['conflict_message'],
            'SLOT_UNAVAILABLE' => match ($slot['status']) {
                'HELD' => '该时段已被预留。',
                'CONFIRMED' => '该时段已有已确认订单。',
                'BLOCKED' => '该时段已停用。',
                default => '该服务时段当前不可用于后续选择。',
            },
            default => '该服务时段当前不可用，请刷新页面后重试。',
        };
    };
@endphp
<main class="fleet-calendar-page" data-fleet-calendar data-view-days="{{ $range }}">
<header class="fleet-page-header">
<div>
<p class="fleet-eyebrow">{{ $organization->name }}</p>
<h1>船期日历</h1>
<p class="fleet-subtitle">按船只、日期和服务时段查看可销售或可使用的船期。预留和确认时仍由服务器重新校验实际占用。</p>
</div>
<p class="fleet-range-label"><time datetime="{{ $calendar['from'] }}">{{ $rangeStartLabel }}</time> → <time datetime="{{ $calendar['to'] }}">{{ $rangeEndLabel }}</time></p>
</header>

<section class="fleet-summary" aria-label="当前视图服务时段汇总">
@foreach(['AVAILABLE', 'HELD', 'CONFIRMED', 'BLOCKED'] as $status)
<article class="summary-card status-{{ strtolower($status) }}" data-summary-status="{{ $status }}">
<span>{{ $statusLabels[$status] }}</span>
<strong>{{ $summary[$status] }}</strong>
<small>当前视图服务时段</small>
</article>
@endforeach
</section>

<form class="fleet-toolbar" method="get" action="{{ route('operator.calendar') }}">
<input type="hidden" name="range" value="{{ $range }}">
<label>开始日期
<input type="date" name="from" value="{{ $from }}" required>
</label>
<label>船只
<select name="boat_id">
<option value="">全部在用船只</option>
@foreach($boats as $boat)
<option value="{{ $boat->id }}" @selected($selectedBoatId === (int) $boat->id)>{{ $boat->name }}</option>
@endforeach
</select>
</label>
<button type="submit">应用</button>
</form>

<div class="fleet-controls">
<nav class="range-switcher" aria-label="日历范围">
@foreach([7, 14, 30] as $viewRange)
<a class="button secondary" href="{{ route('operator.calendar', $calendarQuery(['range' => $viewRange])) }}" @if($range === $viewRange) aria-current="page" @endif>{{ $viewRange }} 天</a>
@endforeach
</nav>
<nav class="date-pager" aria-label="日历翻页">
<a class="button secondary" href="{{ route('operator.calendar', $calendarQuery(['from' => $previousFrom])) }}">← 上一页</a>
<a class="button secondary" href="{{ route('operator.calendar', $calendarQuery(['from' => $todayFrom])) }}">今天</a>
<a class="button secondary" href="{{ route('operator.calendar', $calendarQuery(['from' => $nextFrom])) }}">下一页 →</a>
</nav>
</div>

<div class="status-legend" aria-label="库存状态说明">
<strong>状态：</strong>
@foreach(array_keys($statusLabels) as $status)
<span class="status-badge status-{{ strtolower($status) }}">{{ $statusLabels[$status] }}</span>
@endforeach
</div>

<div class="calendar-scroll" tabindex="0" aria-label="船期日历，可横向滚动查看更多日期。">
<table class="fleet-table">
<caption class="sr-only">按船只、日期和服务时段显示船期日历</caption>
<thead>
<tr>
<th class="boat-column" scope="col">船只</th>
@foreach($dateHeaders as $dateHeader)
<th scope="col">
<time datetime="{{ $dateHeader['date'] }}"><span class="weekday">{{ $dateHeader['weekday'] }}</span>{{ $dateHeader['label'] }}</time>
</th>
@endforeach
</tr>
</thead>
<tbody>
@forelse($calendar['boats'] as $boat)
<tr data-boat-id="{{ $boat['boat_id'] }}">
<th class="boat-column" scope="row">
<strong class="boat-name">{{ $boat['name'] }}</strong>
<span class="boat-meta">整船</span>
@if($boat['buffer_before_minutes'] > 0 || $boat['buffer_after_minutes'] > 0)
@if($boat['buffer_before_minutes'] === $boat['buffer_after_minutes'])
<span class="boat-meta">前后缓冲 {{ $boat['buffer_before_minutes'] }} 分钟</span>
@else
<span class="boat-meta">前缓冲 {{ $boat['buffer_before_minutes'] }} 分钟 · 后缓冲 {{ $boat['buffer_after_minutes'] }} 分钟</span>
@endif
@endif
</th>
@foreach($boat['dates'] as $dateIndex => $day)
@php
    $availableSlots = collect($day['slots'])->filter(
        static fn (array $slot): bool => $slot['status'] === 'AVAILABLE',
    )->values();
    $exceptionSlots = collect($day['slots'])->reject(
        static fn (array $slot): bool => $slot['status'] === 'AVAILABLE',
    )->values();
    $availabilityMode = $availableSlots->isNotEmpty() && $exceptionSlots->isEmpty()
        ? 'quiet'
        : ($availableSlots->isNotEmpty() ? 'partial' : 'exceptions');
    $availableSlotsByDuration = $availableSlots
        ->groupBy(static fn (array $slot): int => (int) $slot['duration_minutes'])
        ->sortKeys();
    $dayHeader = $dateHeaders[$dateIndex];
@endphp
<td class="date-cell" data-business-date="{{ $day['date'] }}" data-availability-mode="{{ $availabilityMode }}">
<time class="cell-date" datetime="{{ $day['date'] }}">{{ $dayHeader['weekday'] }} · {{ $dayHeader['label'] }}</time>
@foreach($exceptionSlots as $slot)
@php
    $status = array_key_exists($slot['status'], $statusLabels) ? $slot['status'] : 'UNAVAILABLE';
    $serviceStart = \Carbon\CarbonImmutable::parse($slot['service_start_local'])->format('H:i');
    $serviceEnd = \Carbon\CarbonImmutable::parse($slot['service_end_local'])->format('H:i');
    $occupiedStart = \Carbon\CarbonImmutable::parse($slot['occupied_start_local'])->format('H:i');
    $occupiedEnd = \Carbon\CarbonImmutable::parse($slot['occupied_end_local'])->format('H:i');
    $allocationId = (int) ($slot['authority']['allocation_id'] ?? 0);
    $actionLink = $allocationActionLinks[$allocationId] ?? null;
    $slotDisplayName = $displaySlotName($slot['name'], $boat['name'], $slot['code']);
    $slotConflictReason = $conflictReason($slot);
@endphp
<article class="slot-card exception-card status-{{ strtolower($status) }}" data-calendar-status="{{ $status }}" data-exception-status="{{ $status }}" data-conflict-code="{{ $slot['conflict_code'] }}" data-slot-code="{{ $slot['code'] }}">
<header>
<h3>{{ $slotDisplayName }}</h3>
<span class="status-badge status-{{ strtolower($status) }}">{{ $statusLabels[$status] }}</span>
</header>
<p class="slot-time"><time datetime="{{ $slot['service_start_local'] }}">{{ $serviceStart }}</time>–<time datetime="{{ $slot['service_end_local'] }}">{{ $serviceEnd }}</time><span class="slot-duration">{{ $durationLabel((int) $slot['duration_minutes']) }}</span></p>
@if($slot['buffer_conflict'])
<p class="buffer-indicator">缓冲时间冲突 · 占用 {{ $occupiedStart }}–{{ $occupiedEnd }}</p>
@endif
@if($slotConflictReason)
<p class="slot-reason">{{ $slotConflictReason }}</p>
@endif

@if(in_array($status, ['HELD', 'CONFIRMED'], true) && $operatorMembership?->can_booking_workflow && $actionLink)
<a class="slot-action" href="{{ $actionLink['url'] }}">{{ $actionLink['label'] }} →</a>
@elseif($status === 'BLOCKED' && $operatorMembership?->can_block && $actionLink)
<a class="slot-action" href="{{ $actionLink['url'] }}">{{ $actionLink['label'] }} →</a>
@elseif(in_array($status, ['HELD', 'CONFIRMED', 'BLOCKED'], true))
<p class="slot-detail-state">当前账号无可执行操作，库存详情仍可查看。</p>
@endif
</article>
@endforeach

@if($availableSlots->isNotEmpty())
<details class="available-slots {{ $exceptionSlots->isNotEmpty() ? 'is-partial' : '' }}" data-available-trigger data-derived-available-count="{{ $availableSlots->count() }}" data-inquiry-entry-sequence="duration-slot-inquiry">
<summary>{{ $exceptionSlots->isNotEmpty() ? '还有 ' : '' }}{{ $availableSlots->count() }} 个可用时段 · 选择时长</summary>
<div class="duration-first-chooser" data-duration-first>
<p class="duration-step-label"><span>1</span>先选择客人需要的时长</p>
@foreach($availableSlotsByDuration as $durationMinutes => $durationSlots)
<details class="duration-choice" data-duration-choice="{{ $durationMinutes }}">
<summary><span class="duration-choice-label">{{ $durationLabel((int) $durationMinutes) }}</span></summary>
<div class="duration-slot-panel" data-duration-panel="{{ $durationMinutes }}">
<p class="duration-step-label"><span>2</span>再选择实际出发时段</p>
<ul class="available-slot-list">
@foreach($durationSlots as $slot)
@php
    $serviceStart = \Carbon\CarbonImmutable::parse($slot['service_start_local'])->format('H:i');
    $serviceEnd = \Carbon\CarbonImmutable::parse($slot['service_end_local'])->format('H:i');
    $slotDisplayName = $displaySlotName($slot['name'], $boat['name'], $slot['code']);
    $inquiryQuery = array_filter([
        'boat_id' => $boat['boat_id'],
        'service_date' => $day['date'],
        'slot_offering_id' => $slot['slot_offering_id'],
    ], static fn (mixed $value): bool => $value !== null);
@endphp
<li class="available-slot-option" data-available-slot-option data-calendar-status="AVAILABLE" data-duration-minutes="{{ $durationMinutes }}" data-slot-code="{{ $slot['code'] }}">
<span>
<strong>{{ $slotDisplayName }}</strong>
<time datetime="{{ $slot['service_start_local'] }}">{{ $serviceStart }}</time>–<time datetime="{{ $slot['service_end_local'] }}">{{ $serviceEnd }}</time>
@if((int) $slot['duration_minutes'] === 480)
<small class="default-departure-note" data-default-departure>默认 {{ $serviceStart }} 出航 · 固定至 {{ $serviceEnd }}</small>
@endif
</span>
@if($operatorMembership?->can_booking_workflow)
<a class="slot-action" data-available-action href="{{ route('operator.inquiries.create', $inquiryQuery) }}">创建询价 →</a>
@endif
</li>
@endforeach
</ul>
</div>
</details>
@endforeach
</div>
@unless($operatorMembership?->can_booking_workflow)
<p class="available-read-only">当前账号仅可查看可用时段。</p>
@endunless
</details>
@elseif($day['slots'] === [])
<p class="empty-slots">暂无已配置服务时段。</p>
@endif

@if($day['allocations'] !== [])
<details class="occupied-details">
<summary>{{ count($day['allocations']) }} 个当前占用区间</summary>
@foreach($day['allocations'] as $allocation)
@php
    $allocationStatus = array_key_exists($allocation['status'], $statusLabels) ? $allocation['status'] : 'UNAVAILABLE';
    $allocationStart = \Carbon\CarbonImmutable::parse($allocation['occupied_start_local'])->format('H:i');
    $allocationEnd = \Carbon\CarbonImmutable::parse($allocation['occupied_end_local'])->format('H:i');
    $allocationName = \App\Support\OperatorUi::slotName($allocation['slot_name'] ?? ($allocationTypeLabels[$allocation['allocation_type']] ?? '占用'));
@endphp
<div class="occupied-row">
<span class="status-badge status-{{ strtolower($allocationStatus) }}">{{ $statusLabels[$allocationStatus] }}</span>
<strong>{{ $displaySlotName((string) $allocationName, $boat['name']) }}</strong>
<time>{{ $allocationStart }}–{{ $allocationEnd }} 占用</time>
</div>
@endforeach
</details>
@endif
</td>
@endforeach
</tr>
@empty
<tr><td class="fleet-empty" colspan="{{ $range + 1 }}">当前视图没有匹配的在用船只。</td></tr>
@endforelse
</tbody>
</table>
</div>

<footer class="projection-note">
<p>日历仅为只读投影。最终可用性、预留和确认仍使用既有占用区间权威重新校验。</p>
<details>
<summary>技术信息</summary>
<p>库存修订 {{ $calendar['inventory_revision'] }} · 生成时间 {{ \App\Support\OperatorUi::dateTime($calendar['as_of'], $organization->timezone) }}</p>
</details>
</footer>
</main>
@endsection
