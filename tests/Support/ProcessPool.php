<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Spawns N real worker processes (never pcntl_fork: a forked booted app shares PDO handles) with the same
 * DB_DATABASE / CATALOG_DB_DATABASE / APP_ENV=testing as the test process (CONVENTIONS §6.5).
 */
final class ProcessPool
{
    /** @param  array<int, string>  $command */
    public static function run(int $workers, array $command, int $timeoutSeconds = 120): ProcessPoolResult
    {
        $env = array_filter([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'CATALOG_DB_DATABASE' => (string) config('database.connections.catalog.database'),
            'CACHE_STORE' => (string) config('cache.default'),
            'QUEUE_CONNECTION' => (string) config('queue.default'),
            'SCOUT_DRIVER' => (string) config('scout.driver'),
            'SCOUT_PREFIX' => (string) config('scout.prefix'),
            'REDIS_DB' => (string) config('database.redis.default.database'),
            'APP_CENTRAL_DOMAIN' => (string) config('tenancy.central_domain'),
        ], fn ($v) => $v !== '');

        /** @var array<int, Process> $processes */
        $processes = [];

        for ($i = 0; $i < $workers; $i++) {
            $process = new Process($command, base_path(), $env + ['WORKER_INDEX' => (string) $i], null, $timeoutSeconds);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();
        }

        return new ProcessPoolResult($processes);
    }
}
