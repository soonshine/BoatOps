@extends('operator.layout')

@section('title', '操作记录')

@section('content')
<h1>操作记录</h1>
<p><strong>只读：</strong>这里显示当前组织的运营历史，不提供编辑或导出功能。</p>
<p>组织：{{ $organization->name }} · 时区：{{ $organization->timezone }}</p>

<table>
    <thead><tr><th>时间</th><th>操作主体</th><th>操作</th><th>对象</th><th>原因</th><th>变更前（系统字段）</th><th>变更后（系统字段）</th></tr></thead>
    <tbody>
    @forelse($auditLogs as $audit)
        <tr>
            <td>{{ \App\Support\OperatorUi::dateTime($audit->created_at, $organization->timezone) }}</td>
            <td>{{ \App\Support\OperatorUi::auditActor($audit->actor_type) }} / {{ $audit->actor_id ?? '—' }}</td>
            <td>{{ \App\Support\OperatorUi::auditAction($audit->action) }}</td>
            <td>{{ \App\Support\OperatorUi::auditObject($audit->object_type) }} / {{ $audit->object_id }}</td>
            <td>{{ $audit->reason ?? '—' }}</td>
            <td><pre>{{ $audit->before_values ?? '—' }}</pre></td>
            <td><pre>{{ $audit->after_values ?? '—' }}</pre></td>
        </tr>
    @empty
        <tr><td colspan="7">暂无操作记录。</td></tr>
    @endforelse
    </tbody>
</table>
{{ $auditLogs->links() }}
@endsection