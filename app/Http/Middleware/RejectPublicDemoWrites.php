<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectPublicDemoWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('demo_site.enabled')
            && $request->is('demo', 'demo/*')
            && (string) config('demo_site.mode') === 'public_read_only'
            && ! $request->isMethod('GET')) {
            abort(405);
        }

        return $next($request);
    }
}
