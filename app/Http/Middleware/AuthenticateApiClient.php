<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token || ! $client = DB::table('api_clients')
            ->where('token_hash', hash('sha256', $token))
            ->where('active', true)
            ->first()) {
            return new JsonResponse([
                'request_id' => (string) Str::uuid(),
                'code' => 'AUTHORIZATION_FAILED',
                'retryable' => false,
                'manual_action_required' => false,
                'message' => 'A valid bearer token is required.',
            ], 401);
        }

        $organization = DB::table('organizations')->find($client->organization_id);
        $request->attributes->set('api_client_id', $client->id);
        $request->attributes->set('api_client_scopes', json_decode($client->scopes ?? '[]', true) ?: []);
        $request->attributes->set('organization', $organization);

        DB::table('api_clients')->where('id', $client->id)->update(['last_used_at' => now()]);

        return $next($request);
    }
}
