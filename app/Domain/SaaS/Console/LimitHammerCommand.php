<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Console;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Exceptions\PlanLimitExceeded;
use App\Domain\SaaS\Services\PlanLimits;
use App\Models\Central\Tenant;
use App\Models\Tenant\Doctor;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * `saas:limit-hammer` — the concurrency worker of CONVENTIONS §6.5. Dev/testing only.
 *
 * `--mode=reserve` hammers `PlanLimits::reserve()` directly (the gate itself); `--mode=doctor` hammers the real
 * write path, `Doctor::create()`, so the proof covers the observer as well as the service. Both print one JSON
 * line per attempt with 0–5 ms jitter, which is what makes the interleaving real rather than sequential.
 *
 * The invariant being proved is money-shaped: however many processes ask at once, the number of SUCCESSES is
 * exactly the cap. A check-then-write would let several callers past the same "there is room" reading.
 */
final class LimitHammerCommand extends Command
{
    protected $signature = 'saas:limit-hammer {--tenant= : Tenant id} {--metric=doctors} {--count=25} {--mode=reserve : reserve|doctor} {--start-at= : Unix timestamp (float) all workers wait for}';

    protected $description = 'Hammer the plan-limit gate from one process (used by tests/Concurrency via ProcessPool)';

    public function handle(PlanLimits $limits): int
    {
        if (app()->isProduction()) {
            $this->components->error('saas:limit-hammer refuses to run in production.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->findOrFail((int) $this->option('tenant'));
        $metric = UsageMetric::from((string) $this->option('metric'));
        $count = max(1, (int) $this->option('count'));
        $mode = (string) $this->option('mode');

        $this->waitForStart();

        return (int) Tenancy::run($tenant, function () use ($limits, $tenant, $metric, $count, $mode): int {
            for ($i = 0; $i < $count; $i++) {
                usleep(random_int(0, 5000));

                try {
                    $value = $mode === 'doctor'
                        ? $this->createDoctor()
                        : $limits->reserve($tenant, $metric, 1);

                    $this->line((string) json_encode(['ok' => true, 'value' => $value]));
                } catch (PlanLimitExceeded $e) {
                    $this->line((string) json_encode(['ok' => false, 'code' => $e->code()]));
                } catch (Throwable $e) {
                    $this->line((string) json_encode(['ok' => false, 'code' => 'error', 'message' => mb_substr($e->getMessage(), 0, 200)]));
                }
            }

            return self::SUCCESS;
        });
    }

    private function createDoctor(): int
    {
        return (int) Doctor::factory()->create()->id;
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
