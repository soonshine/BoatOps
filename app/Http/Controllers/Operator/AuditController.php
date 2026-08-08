<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AuditController extends Controller
{
    private const PAGE_SIZE = 50;

    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        $auditLogs = DB::table('audit_logs')
            ->where('organization_id', $organization->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(self::PAGE_SIZE);

        return view('operator.audit', [
            'organization' => $organization,
            'auditLogs' => $auditLogs,
        ]);
    }
}
