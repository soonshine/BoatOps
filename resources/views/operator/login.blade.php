@extends('operator.layout')

@section('title', '操作员登录')

@section('content')
<h1>操作员登录</h1>
<p>使用已授权的 BoatOps 操作员账号登录。</p>
<form method="post" action="{{ route('operator.login.store') }}">
@csrf
<label>邮箱
<input name="email" type="email" value="{{ old('email') }}" autocomplete="username" placeholder="operator@example.com" required autofocus>
</label>
<label>密码
<input name="password" type="password" autocomplete="current-password" required>
</label>
<button>登录</button>
</form>
@endsection