<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', '船务操作台')</title>
<style>
* { box-sizing: border-box; }
body { font-family: system-ui, sans-serif; margin: 2rem; overflow-wrap: anywhere; }
nav { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; }
label { display: block; margin: 1rem 0; }
.card { padding: 1rem; background: #f4f4f4; margin: 1rem 0; }
.error { color: #b00; }
input, select, textarea { max-width: 100%; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #ccc; padding: .5rem; text-align: left; vertical-align: top; }
fieldset { margin: 1rem 0; padding: 1rem; }
@media (max-width: 480px) {
    body { margin: 1rem; }
    nav form { width: 100%; margin: 0; }
}
</style>
@yield('head')
</head>
<body class="@yield('bodyClass')">
@auth
@php($operatorMembership = request()->attributes->get('operator_membership'))
<nav class="operator-nav" aria-label="操作导航">
@if($operatorMembership?->can_calendar_read)
<a href="{{ route('operator.calendar') }}">船期日历</a>
<a href="{{ route('operator.audit') }}">操作记录</a>
@endif
@if($operatorMembership?->can_booking_workflow)
<a href="{{ route('operator.today') }}" @if(request()->routeIs('operator.today')) aria-current="page" @endif>今日运营</a>
<a href="{{ route('operator.inquiries.index') }}">询价</a>
<a href="{{ route('operator.bookings.index') }}">订单</a>
<a href="{{ route('operator.trips.index') }}">出航工作台</a>
@endif
@if($operatorMembership?->can_block)
<a href="{{ route('operator.blocks.index') }}">停用管理</a>
@endif
<form method="post" action="{{ route('operator.logout') }}">
@csrf
<button>退出</button>
</form>
</nav>
@endauth
@if(session('status'))
<div class="card" role="status" aria-live="polite">{{ session('status') }}</div>
@endif
@if($errors->any())
<div class="error" role="alert">{{ implode(' ', $errors->all()) }}</div>
@endif
@yield('content')
</body>
</html>
