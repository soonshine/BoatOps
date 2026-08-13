<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', 'Operator')</title>
<style>
body { font-family: system-ui, sans-serif; margin: 2rem; }
nav { display: flex; gap: 1rem; }
label { display: block; margin: 1rem 0; }
.card { padding: 1rem; background: #f4f4f4; margin: 1rem 0; }
.error { color: #b00; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #ccc; padding: .5rem; text-align: left; vertical-align: top; }
fieldset { margin: 1rem 0; padding: 1rem; }
</style>
@yield('head')
</head>
<body class="@yield('bodyClass')">
@auth
@php($operatorMembership = request()->attributes->get('operator_membership'))
<nav class="operator-nav" aria-label="Operator navigation">
@if($operatorMembership?->can_calendar_read)
<a href="{{ route('operator.calendar') }}">Calendar</a>
<a href="{{ route('operator.audit') }}">Audit trail</a>
@endif
@if($operatorMembership?->can_booking_workflow)
<a href="{{ route('operator.inquiries.index') }}">Inquiries</a>
<a href="{{ route('operator.bookings.index') }}">Bookings</a>
<a href="{{ route('operator.trips.index') }}">Trips</a>
@endif
@if($operatorMembership?->can_block)
<a href="{{ route('operator.blocks.index') }}">BLOCKs</a>
@endif
<form method="post" action="{{ route('operator.logout') }}">
@csrf
<button>Logout</button>
</form>
</nav>
@endauth
@if($errors->any())
<div class="error">{{ implode(' ', $errors->all()) }}</div>
@endif
@yield('content')
</body>
</html>
