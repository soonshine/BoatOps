<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveDemoSiteContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo_site.enabled') || ! app()->environment(['local', 'testing'])) {
            abort(404);
        }
        $organizations = DB::table('organizations')->where('name', config('demo_site.organization_name'))->limit(2)->get();
        if ($organizations->count() !== 1) {
            abort(404);
        }
        $organization = $organizations->first();
        $actors = DB::table('api_clients')->where('organization_id', $organization->id)
            ->where('name', config('demo_site.actor_name'))->where('active', true)->limit(2)->get();
        if ($actors->count() !== 1) {
            abort(404);
        }
        $actor = $actors->first();
        $requiredScopes = [
            'operations.finance.read',
            'operations.finance.write',
            'operations.schedule.read',
            'operations.schedule.write',
        ];
        $scopes = json_decode($actor->scopes ?? '[]', true);
        if (! is_array($scopes) || array_diff($requiredScopes, $scopes) !== []) {
            abort(404);
        }
        $request->attributes->set('organization', $organization);
        $request->attributes->set('api_client_id', (int) $actor->id);
        $request->attributes->set('api_client_scopes', $scopes);

        return $next($request);
    }
}
