@extends('operator.layout')

@section('title', '询价列表')

@section('content')
<h1>询价列表</h1>
<p><a href="{{ route('operator.inquiries.create') }}">新建询价</a></p>

@forelse($inquiries as $inquiry)
<article class="card">
<a href="{{ route('operator.inquiries.show', $inquiry->id) }}">{{ $inquiry->reference }}</a>
<span>{{ \App\Support\OperatorUi::status($inquiry->status) }}</span>
@if($inquiry->service_date)
<span> · {{ \App\Support\OperatorUi::date($inquiry->service_date) }}</span>
@endif
</article>
@empty
<p>暂无询价记录。可先创建询价，之后再补充运营资料或创建预留。</p>
@endforelse
@endsection