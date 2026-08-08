<?php

namespace Tests\Feature;

use Database\Seeders\DemoSiteSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicDemoReadOnlyDatabaseTest extends TestCase
{
    public function test_public_get_leaves_file_backed_sqlite_hash_and_all_row_counts_unchanged(): void
    {
        $databasePath = storage_path('framework/testing/public-demo-read-only-'.getmypid().'-'.spl_object_id($this).'.sqlite');
        $runtimePath = storage_path('framework/testing/public-demo-read-only-runtime-'.getmypid().'-'.spl_object_id($this));
        $originalEnvironment = $this->app->environment();
        $originalToken = getenv('BOATOPS_DEMO_TOKEN');
        $originalConfig = [
            'database.default' => config('database.default'),
            'database.connections.sqlite' => config('database.connections.sqlite'),
            'cache.default' => config('cache.default'),
            'cache.limiter' => config('cache.limiter'),
            'cache.stores.file' => config('cache.stores.file'),
            'session.driver' => config('session.driver'),
            'session.files' => config('session.files'),
            'queue.default' => config('queue.default'),
            'demo_site' => config('demo_site'),
        ];
        $rateLimitKey = 'boatops-demo-get:'.sha1('127.0.0.1');

        $this->deleteSqliteFiles($databasePath);
        File::deleteDirectory($runtimePath);
        File::ensureDirectoryExists($runtimePath.'/cache');
        File::ensureDirectoryExists($runtimePath.'/sessions');
        File::put($databasePath, '');
        putenv('BOATOPS_DEMO_TOKEN=fictional-file-sqlite-read-only-token');

        try {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.url' => null,
                'database.connections.sqlite.database' => $databasePath,
                'database.connections.sqlite.journal_mode' => 'WAL',
                'cache.default' => 'file',
                'cache.limiter' => 'file',
                'cache.stores.file.path' => $runtimePath.'/cache',
                'cache.stores.file.lock_path' => $runtimePath.'/cache',
                'session.driver' => 'file',
                'session.files' => $runtimePath.'/sessions',
                'queue.default' => 'sync',
                'demo_site.enabled' => true,
                'demo_site.mode' => 'local_write',
                'demo_site.isolated_dataset' => true,
            ]);
            DB::purge('sqlite');
            $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])
                ->assertExitCode(0);
            $this->seed(DemoSiteSeeder::class);

            config(['demo_site.mode' => 'public_read_only']);
            $this->app->detectEnvironment(fn (): string => 'production');
            RateLimiter::clear($rateLimitKey);
            $before = $this->databaseSnapshot($databasePath);

            $this->get('/demo')->assertOk();

            $after = $this->databaseSnapshot($databasePath);

            $this->assertSame($before['counts'], $after['counts']);
            $this->assertSame($before['rows_hash'], $after['rows_hash']);
            $this->assertSame($before['sqlite_artifacts_hash'], $after['sqlite_artifacts_hash']);
            $countsAfter = $after['counts'];
            foreach (['cache', 'sessions', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
                $this->assertSame(0, $countsAfter[$table]);
            }
        } finally {
            $this->app->detectEnvironment(fn (): string => $originalEnvironment);
            RateLimiter::clear($rateLimitKey);
            DB::purge('sqlite');
            config($originalConfig);
            DB::purge('sqlite');
            if ($originalToken === false) {
                putenv('BOATOPS_DEMO_TOKEN');
            } else {
                putenv('BOATOPS_DEMO_TOKEN='.$originalToken);
            }
            File::deleteDirectory($runtimePath);
            $this->deleteSqliteFiles($databasePath);
        }
    }

    private function databaseSnapshot(string $databasePath): array
    {
        $counts = $this->databaseRowCounts();
        $rowsHash = $this->databaseRowsHash();
        DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
        DB::purge('sqlite');

        return [
            'counts' => $counts,
            'rows_hash' => $rowsHash,
            'sqlite_artifacts_hash' => $this->sqliteArtifactsHash($databasePath),
        ];
    }

    private function databaseRowCounts(): array
    {
        $tables = collect(DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        ))->map(static fn (object $row): string => (string) $row->name);
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function databaseRowsHash(): string
    {
        $tables = collect(DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        ))->map(static fn (object $row): string => (string) $row->name);
        $state = [];

        foreach ($tables as $table) {
            $quotedTable = '"'.str_replace('"', '""', $table).'"';
            $state[$table] = array_map(
                static fn (object $row): array => (array) $row,
                DB::select("SELECT * FROM {$quotedTable} ORDER BY rowid"),
            );
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function sqliteArtifactsHash(string $databasePath): string
    {
        $artifacts = [];
        foreach ([$databasePath, $databasePath.'-journal', $databasePath.'-shm', $databasePath.'-wal'] as $path) {
            clearstatcache(true, $path);
            $artifacts[basename($path)] = is_file($path) ? hash_file('sha256', $path) : null;
        }

        return hash('sha256', json_encode($artifacts, JSON_THROW_ON_ERROR));
    }

    private function deleteSqliteFiles(string $databasePath): void
    {
        File::delete([
            $databasePath,
            $databasePath.'-journal',
            $databasePath.'-shm',
            $databasePath.'-wal',
        ]);
    }
}
