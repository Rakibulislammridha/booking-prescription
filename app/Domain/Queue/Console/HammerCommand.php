<?php

declare(strict_types=1);

namespace App\Domain\Queue\Console;

use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Models\Central\Tenant;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * queue:hammer — the concurrency worker of REALTIME.md §13.1 (CONVENTIONS §6.5): bumps the session version and
 * rebuilds the QueueState in a tight loop with jitter, printing one JSON line per iteration, so several processes
 * race the `qs-build:{session}` lock. Dev/testing only.
 */
final class HammerCommand extends Command
{
    protected $signature = 'queue:hammer {--tenant= : Tenant id} {--session= : session_instances.id} {--count=10} {--bump : bump session_instances.version before each rebuild} {--start-at= : unix timestamp (float) to wait for, so parallel workers start together}';

    protected $description = 'Hammer QueueStateRepository::rebuild() from one process (used by tests/Concurrency via ProcessPool)';

    public function handle(QueueStateRepository $repository): int
    {
        if (app()->isProduction()) {
            $this->components->error('queue:hammer refuses to run in production.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->findOrFail((int) $this->option('tenant'));
        $sessionId = (int) $this->option('session');
        $count = max(1, (int) $this->option('count'));
        $bump = (bool) $this->option('bump');
        $startAt = $this->option('start-at') !== null ? (float) $this->option('start-at') : null;

        if ($startAt !== null) {
            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));
        }

        $failures = 0;

        Tenancy::run($tenant, function () use ($repository, $sessionId, $count, $bump, &$failures): void {
            for ($i = 0; $i < $count; $i++) {
                usleep(random_int(0, 5_000));

                try {
                    if ($bump) {
                        CountsRecalculator::bumpVersion($sessionId);
                    }

                    $session = SessionInstance::query()->with(['doctor', 'branch'])->findOrFail($sessionId);
                    $state = $repository->rebuild($session);
                    $this->line((string) json_encode(['ok' => true, 'version' => $state['version']]));
                } catch (Throwable $e) {
                    $failures++;
                    $this->line((string) json_encode(['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()]));
                }
            }
        });

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
