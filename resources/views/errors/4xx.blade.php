@extends('errors.layout')
@section('code', (string) $exception->getStatusCode())
@section('title', '请求未完成')
@section('message', '请求无效或与当前状态冲突，请返回上一页检查后重试。')
