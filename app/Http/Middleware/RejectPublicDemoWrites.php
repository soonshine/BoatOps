<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class RejectPublicDemoWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('demo_site.mode');

        if (! in_array($mode, ['public_read_only', 'isolated_operator_demo'], true)) {
            return $next($request);
        }

        if (! $this->hasIsolatedSqliteContract()) {
            abort(404);
        }
        if (! $this->hasApprovedReadOnlyStateDrivers()) {
            abort(404);
        }
        if ($request->is('api', 'api/*')) {
            abort(404);
        }

        if ($mode === 'public_read_only') {
            if ($request->getRealMethod() === 'GET' && $request->is('operator', 'operator/*')) {
                abort(404);
            }
            if ($request->getRealMethod() !== 'GET') {
                abort(405, 'The public Demo accepts GET requests only.', ['Allow' => 'GET']);
            }

            return $next($request);
        }

        if ($request->getRealMethod() !== 'GET' && ! $request->is('operator', 'operator/*')) {
            abort(405, 'The isolated operator Demo accepts writes only on operator routes.', ['Allow' => 'GET']);
        }

        return $next($request);
    }

    private function hasIsolatedSqliteContract(): bool
    {
        $connection = (string) config('database.default');

        return app()->environment('production')
            && config('demo_site.isolated_dataset') === true
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
