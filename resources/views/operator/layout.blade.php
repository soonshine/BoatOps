<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', 'BoatOps')</title>
<style>
:root {
    color-scheme: light;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background: #f4f7fa;
    color: #17324d;
}
* { box-sizing: border-box; }
body { margin: 0; background: #f4f7fa; color: #17324d; line-height: 1.5; }
a { color: #075985; }
.operator-nav {
    position: sticky;
    top: 0;
    z-index: 20;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .35rem;
    min-height: 3.75rem;
    padding: .6rem clamp(.7rem, 3vw, 2rem);
    border-bottom: 1px solid #d9e3ec;
    background: rgb(255 255 255 / 96%);
    box-shadow: 0 2px 10px rgb(15 23 42 / 5%);
    backdrop-filter: blur(8px);
}
.operator-brand {
    margin-right: .55rem;
    color: #102a43 !important;
    font-size: 1.02rem !important;
    font-weight: 900 !important;
    letter-spacing: -.02em;
}
.operator-nav a,
.operator-nav button {
    display: inline-flex;
    min-height: 2.4rem;
    align-items: center;
    justify-content: center;
    padding: .44rem .68rem;
    border: 0;
    border-radius: .58rem;
    background: transparent;
    color: #40566d;
    font: inherit;
    font-size: .86rem;
    font-weight: 760;
    text-decoration: none;
    cursor: pointer;
}
.operator-nav a:hover,
.operator-nav button:hover { background: #eef4f8; color: #17324d; }
.operator-nav a[aria-current="page"] { background: #e0f2fe; color: #075985; }
.operator-nav .primary-entry { background: #075985; color: #fff; }
.operator-nav .primary-entry:hover { background: #0c4a6e; color: #fff; }
.operator-nav form { margin: 0 0 0 auto; }
.operator-shell-content { width: 100%; padding: 1rem clamp(.75rem, 3vw, 2rem) 2rem; }
.operator-flash,
.operator-errors {
    width: min(100%, 1200px);
    margin: 1rem auto 0;
    padding: .8rem 1rem;
    border-radius: .7rem;
    background: #fff;
    box-shadow: 0 2px 10px rgb(15 23 42 / 5%);
}
.operator-errors { border: 1px solid #fecaca; color: #991b1b; }
h1, h2, h3 { color: #102a43; }
.card {
    padding: 1rem;
    margin: 1rem 0;
    border: 1px solid #d9e3ec;
    border-radius: .75rem;
    background: #fff;
}
.error { color: #991b1b; }
table { width: 100%; border-collapse: collapse; background: #fff; }
th, td { padding: .58rem; border: 1px solid #d9e3ec; text-align: left; vertical-align: top; }
th { background: #f7fafc; color: #40566d; }
label { display: block; margin: .8rem 0; font-weight: 650; }
input, select, textarea, button { font: inherit; }
input, select, textarea {
    max-width: 100%;
    padding: .55rem .62rem;
    border: 1px solid #b8c7d5;
    border-radius: .5rem;
    background: #fff;
}
textarea { width: 100%; min-height: 5rem; }
button {
    min-height: 2.35rem;
    padding: .48rem .75rem;
    border: 1px solid #075985;
    border-radius: .52rem;
    background: #075985;
    color: #fff;
    font-weight: 760;
    cursor: pointer;
}
fieldset { margin: 1rem 0; padding: 1rem; border: 1px solid #d9e3ec; border-radius: .65rem; }
@media (max-width: 720px) {
    .operator-nav { position: static; gap: .25rem; padding: .55rem .6rem; }
    .operator-brand { width: 100%; margin-right: 0; justify-content: flex-start !important; }
    .operator-nav a, .operator-nav button { min-height: 2.25rem; padding: .4rem .55rem; font-size: .82rem; }
    .operator-nav form { margin-left: 0; }
    .operator-shell-content { padding: .75rem .65rem 1.5rem; }
    table { display: block; overflow-x: auto; }
}
</style>
@yield('head')
</head>
<body class="@yield('bodyClass')">
@auth
@php($operatorMembership = request()->attributes->get('operator_membership'))
@php($operatorHomeRoute = $operatorMembership?->can_booking_workflow ? 'operator.today' : ($operatorMembership?->can_calendar_read ? 'operator.calendar' : 'operator.blocks.index'))
<nav class="operator-nav" aria-label="BoatOps 导航">
<a class="operator-brand" href="{{ route($operatorHomeRoute) }}">BoatOps</a>
@if($operatorMembership?->can_booking_workflow)
<a href="{{ route('operator.today') }}" @if(request()->routeIs('operator.today')) aria-current="page" @endif>今日运营</a>
<a class="primary-entry" href="{{ route('operator.inquiries.create') }}" @if(request()->routeIs('operator.inquiries.create')) aria-current="page" @endif>新建任务</a>
<a href="{{ route('operator.inquiries.index') }}" @if(request()->routeIs('operator.inquiries.*') && !request()->routeIs('operator.inquiries.create')) aria-current="page" @endif>待确认</a>
<a href="{{ route('operator.bookings.index') }}" @if(request()->routeIs('operator.bookings.*')) aria-current="page" @endif>订单</a>
@endif
@if($operatorMembership?->can_calendar_read)
<a href="{{ route('operator.calendar') }}" @if(request()->routeIs('operator.calendar')) aria-current="page" @endif>船期</a>
@endif
@if($operatorMembership?->can_block)
<a href="{{ route('operator.blocks.index') }}" @if(request()->routeIs('operator.blocks.*')) aria-current="page" @endif>船只停用</a>
@endif
<form method="post" action="{{ route('operator.logout') }}">
@csrf
<button type="submit">退出</button>
</form>
</nav>
@endauth
@if(session('status'))
<div class="operator-flash" role="status" aria-live="polite">{{ session('status') }}</div>
@endif
@if($errors->any())
<div class="operator-errors" role="alert">{{ implode(' ', $errors->all()) }}</div>
@endif
<div class="operator-shell-content">
@yield('content')
</div>
</body>
</html>
