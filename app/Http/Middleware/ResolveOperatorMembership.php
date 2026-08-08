<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ResolveOperatorMembership
{
    public function handle(Request $r, Closure $next, ?string $permission = null): Response
    {
        if (! Auth::check()) {
            return redirect()->guest(route('operator.login'));
        }$m = DB::table('operator_memberships')->where('user_id', Auth::id())->where('status', 'ACTIVE')->first();
        if (! $m) {
            abort(403);
        }$c = match ($permission) {
            'calendar_read' => 'can_calendar_read','booking_workflow' => 'can_booking_workflow','block' => 'can_block',null => null,default => abort(403)
        };
        if ($c && ! $m->{$c}) {
            abort(403);
        }$o = DB::table('organizations')->where('id', $m->organization_id)->first();
        if (! $o) {
            abort(403);
        }$r->attributes->set('operator_membership', $m);
        $r->attributes->set('organization', $o);

        return $next($r);
    }
}
