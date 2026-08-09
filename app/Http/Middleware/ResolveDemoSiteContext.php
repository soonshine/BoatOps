<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ResolveDemoSiteContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('demo_site.mode');
        if (config('demo_site.enabled') !== true || ! in_array($mode, ['local_write', 'public_read_only', 'isolated_operator_demo'], true)) {
            abort(404);
        }
        if ($mode === 'local_write' && ! app()->environment(['local', 'testing'])) {
            abort(404);
        }
        if (in_array($mode, ['public_read_only', 'isolated_operator_demo'], true) && ! app()->environment('production')) {
            abort(404);
        }
        if (in_array($mode, ['public_read_only', 'isolated_operator_demo'], true) && ! $this->hasIsolatedSqliteContract()) {
            abort(404);
        }
        if (in_array($mode, ['public_read_only', 'isolated_operator_demo'], true) && ! $this->hasApprovedReadOnlyStateDrivers()) {
            abort(404);
        }
        if (in_array($mode, ['public_read_only', 'isolated_operator_demo'], true) && $request->getRealMethod() !== 'GET') {
            abort(405, 'The public Demo accepts GET requests only.', ['Allow' => 'GET']);
        }
        if (in_array($mode, ['public_read_only', 'isolated_operator_demo'], true)) {
            $key = 'boatops-demo-get:'.sha1((string) $request->ip());
            $maxAttempts = (int) config('demo_site.public_rate_limit_per_minute');
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                abort(429);
            }
            RateLimiter::hit($key, 60);
        }
        $organizations = DB::table('organizations')->where('name', config('demo_site.organization_name'))->limit(2)->get();
        if ($organizations->count() !== 1) {
            abort(404);
        }
        $organization = $organizations->first();
        $actorName = in_array($mode, ['public_read_only', 'isolated_operator_demo'], true)
            ? config('demo_site.public_reader_name')
            : config('demo_site.actor_name');
        $actors = DB::table('api_clients')->where('organization_id', $organization->id)
            ->where('name', $actorName)->where('active', true)->limit(2)->get();
        if ($actors->count() !== 1) {
            abort(404);
        }
        $actor = $actors->first();
        $requiredScopes = in_array($mode, ['public_read_only', 'isolated_operator_demo'], true)
            ? config('demo_site.public_reader_scopes')
            : ['operations.finance.read', 'operations.finance.write', 'operations.schedule.read', 'operations.schedule.write'];
        $scopes = json_decode($actor->scopes ?? '[]', true);
        if (! is_array($scopes)) {
            abort(404);
        }
        if (in_array($mode, ['public_read_only', 'isolated_operator_demo'], true)) {
            $normalizedScopes = array_values(array_map('strval', $scopes));
            $normalizedRequiredScopes = array_values(array_map('strval', $requiredScopes));
            sort($normalizedScopes);
            sort($normalizedRequiredScopes);
            if ($normalizedScopes !== $normalizedRequiredScopes) {
                abort(404);
            }
        } elseif (array_diff($requiredScopes, $scopes) !== []) {
            abort(404);
        }
        $request->attributes->set('organization', $organization);
        $request->attributes->set('api_client_id', (int) $actor->id);
        $request->attributes->set('api_client_scopes', $scopes);

        $response = $next($request);
        if (in_array($mode, ['public_read_only', 'isolated_operator_demo'], true)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }

    private function hasIsolatedSqliteContract(): bool
    {
        $connection = (string) config('database.default');

        return config('demo_site.isolated_dataset') === true
            && $this->configuredDatabaseIsSqlite($connection);
    }

    private function configuredDatabaseIsSqlite(string $connection): bool
    {
        $configuration = config("database.connections.{$connection}");
        if (! is_array($configuration)) {
            return false;
        }

        try {
            $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        } catch (InvalidArgumentException) {
            return false;
        }

        foreach (['read', 'write', 'direct'] as $override) {
            if (array_key_exists($override, $configuration) && ! is_array($configuration[$override])) {
                return false;
            }
        }

        return ($configuration['driver'] ?? null) === 'sqlite'
            && $this->allConfiguredDriversAreSqlite($configuration);
    }

    private function allConfiguredDriversAreSqlite(array $configuration): bool
    {
        foreach ($configuration as $key => $value) {
            if ($key === 'driver' && $value !== 'sqlite') {
                return false;
            }
            if (is_array($value) && ! $this->allConfiguredDriversAreSqlite($value)) {
                return false;
            }
        }

        return true;
    }

    private function hasApprovedReadOnlyStateDrivers(): bool
    {
        $cache = (string) config('cache.default');
        $limiter = (string) (config('cache.limiter') ?? $cache);
        $queue = (string) config('queue.default');

        return $cache === 'file'
            && config("cache.stores.{$cache}.driver") === 'file'
            && $limiter === 'file'
            && config("cache.stores.{$limiter}.driver") === 'file'
            && config('session.driver') === 'file'
            && $queue === 'sync'
            && config("queue.connections.{$queue}.driver") === 'sync';
    }
}
