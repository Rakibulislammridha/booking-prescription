<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

/**
 * What the dashboard says about the queues: is Horizon running, how deep is each queue, how many jobs failed. It
 * reads Horizon's own Redis repositories (the same numbers the Horizon UI shows) and `public.failed_jobs`; a
 * Redis that is down answers `available: false` rather than taking the dashboard down with it.
 *
 * @phpstan-type QueueRow array{name: string, length: int, wait: int}
 * @phpstan-type Snapshot array{available: bool, running: bool, depth: int, longest_wait: int, queues: array<int, QueueRow>, failed: int, failed_recent: int, horizon_url: string}
 */
final class QueueHealth
{
    public function __construct(
        private readonly WorkloadRepository $workload,
        private readonly MasterSupervisorRepository $masters,
        private readonly JobRepository $jobs,
    ) {}

    /** @return Snapshot */
    public function snapshot(): array
    {
        $failed = (int) DB::connection('pgsql')->table('public.failed_jobs')->count();
        $url = '/'.trim((string) config('horizon.path', 'horizon'), '/');

        try {
            $queues = [];
            $depth = 0;
            $longest = 0;

            foreach ($this->workload->get() as $row) {
                $length = (int) $row['length'];
                $wait = (int) $row['wait'];
                $queues[] = ['name' => (string) $row['name'], 'length' => $length, 'wait' => $wait];
                $depth += $length;
                $longest = max($longest, $wait);
            }

            return [
                'available' => true,
                'running' => count((array) $this->masters->all()) > 0,
                'depth' => $depth,
                'longest_wait' => $longest,
                'queues' => $queues,
                'failed' => $failed,
                'failed_recent' => (int) $this->jobs->countRecentlyFailed(),
                'horizon_url' => $url,
            ];
        } catch (Throwable) {
            return ['available' => false, 'running' => false, 'depth' => 0, 'longest_wait' => 0, 'queues' => [], 'failed' => $failed, 'failed_recent' => 0, 'horizon_url' => $url];
        }
    }
}
