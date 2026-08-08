@extends('operator.layout')
@section('title','Audit trail')
@section('content')
<h1>Audit trail</h1>
<p><strong>Read-only:</strong> organization-scoped operational history. No edits or exports are available.</p>
<p>Organization: {{ $organization->name }}</p>
<table>
    <thead><tr><th>Created</th><th>Actor</th><th>Action</th><th>Object</th><th>Reason</th><th>Before</th><th>After</th></tr></thead>
    <tbody>
    @forelse($auditLogs as $audit)
        <tr>
            <td>{{ $audit->created_at }}</td>
            <td>{{ $audit->actor_type }} / {{ $audit->actor_id ?? '—' }}</td>
            <td>{{ $audit->action }}</td>
            <td>{{ $audit->object_type }} / {{ $audit->object_id }}</td>
            <td>{{ $audit->reason ?? '—' }}</td>
            <td><pre>{{ $audit->before_values ?? '—' }}</pre></td>
            <td><pre>{{ $audit->after_values ?? '—' }}</pre></td>
        </tr>
    @empty
        <tr><td colspan='7'>No audit records.</td></tr>
    @endforelse
    </tbody>
</table>
{{ $auditLogs->links() }}
@endsection
