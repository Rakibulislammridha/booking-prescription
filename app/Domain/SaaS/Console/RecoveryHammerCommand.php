<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Console;

use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Models\Central\SuperAdmin;
use Illuminate\Console\Command;
use Throwable;

/**
 * `saas:recovery-hammer` — the concurrency worker for B5 (CONVENTIONS §6.5). Dev/testing only.
 *
 * Presents ONE recovery code for ONE super admin, all workers firing on the same instant, and prints a single JSON
 * line saying whether it was accepted. The invariant `RecoveryCodeRaceTest` proves with this: however many
 * processes present the same code at once, exactly one is accepted — because `verifyRecoveryCode` consumes the
 * digest under `FOR UPDATE`. A lock-free read-modify-write let all of them win.
 */
final class RecoveryHammerCommand extends Command
{
    protected $signature = 'saas:recovery-hammer {--admin= : super_admins.id} {--code= : the recovery code to present} {--start-at= : Unix timestamp (float) all workers wait for}';

    protected $description = 'Present one super-admin recovery code (used by tests/Concurrency via ProcessPool)';

    public function handle(SuperTwoFactor $twoFactor): int
    {
        if (app()->isProduction()) {
            $this->components->error('saas:recovery-hammer refuses to run in production.');

            return self::FAILURE;
        }

        $admin = SuperAdmin::query()->find((int) $this->option('admin'));
        $code = (string) $this->option('code');

        if (! $admin instanceof SuperAdmin) {
            $this->line((string) json_encode(['ok' => false, 'code' => 'error', 'message' => 'admin not found']));

            return self::FAILURE;
        }

        $this->waitForStart();

        try {
            $ok = $twoFactor->verifyRecoveryCode($admin, $code);
            $this->line((string) json_encode(['ok' => $ok, 'worker' => getenv('WORKER_INDEX')]));
        } catch (Throwable $e) {
            $this->line((string) json_encode(['ok' => false, 'code' => 'error', 'message' => mb_substr($e->getMessage(), 0, 200)]));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** All workers start on the same instant, so the contention is real rather than staggered by boot time. */
    private function waitForStart(): void
    {
        $startAt = $this->option('start-at');

        if ($startAt === null) {
            return;
        }

        $target = (float) $startAt;

        while (microtime(true) < $target) {
            usleep(1000);
        }
    }
}
