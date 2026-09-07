<?php

declare(strict_types=1);

namespace App\Tenancy\Queue;

use App\Models\Central\Tenant;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Every payload carries tenant_id (Queue::createPayloadUsing); tenancy is initialised from the payload at
 * JobProcessing — before the job is unserialised — and ended when the job finishes (ARCHITECTURE §4.6).
 *
 * Jobs nest: a job may dispatch_sync() another, run a chain on the sync connection, or fire a queued listener
 * inline. Each JobProcessing therefore pushes a frame; the tenancy is ended only when the frame that initialised
 * it unwinds. A finished job pops exactly once even though the worker may fire several end events for it
 * (JobExceptionOccurred, then JobReleasedAfterException or JobFailed). Singleton, in config/octane.php 'flush'.
 */
final class TenantQueuePayload
{
    /** @var array<int, array{job: int, initialised: bool}> */
    private array $frames = [];

    public static function register(Dispatcher $events): void
    {
        Queue::createPayloadUsing(fn (string $connection, ?string $queue, array $payload): array => [
            'tenant_id' => Tenancy::id(),                                // null when dispatched centrally
        ]);

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            app(self::class)->onProcessing($event);
        });

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class, JobReleasedAfterException::class] as $class) {
            $events->listen($class, function (object $event): void {
                app(self::class)->onFinished($event->job);
            });
        }
    }

    public function onProcessing(JobProcessing $event): void
    {
        $payload = $event->job->payload();
        $tenantId = isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : null;
        $active = Tenancy::id();

        // Same tenant (or both central): the enclosing context — the dispatching request/command under the sync
        // driver, or an outer job of this worker — owns the tenancy.
        if ($tenantId === $active) {
            $this->frames[] = ['job' => spl_object_id($event->job), 'initialised' => false];

            return;
        }

        if ($active !== null && $this->frames !== []) {
            // Nested inside a job that runs in another tenant: never silently switch.
            throw new TenantMismatch($active, $tenantId);
        }

        if ($active !== null) {
            // No job owns this tenancy: it leaked from a previous operation of this worker. End it loudly and run
            // the job in the tenant its payload names (or centrally).
            Log::error('tenancy.queue.leaked_tenancy', ['leaked_tenant_id' => $active, 'payload_tenant_id' => $tenantId, 'job' => $event->job->resolveName()]);
            Tenancy::end();
        }

        if ($tenantId === null) {
            $this->frames[] = ['job' => spl_object_id($event->job), 'initialised' => false];

            return;
        }

        Tenancy::initialize(Tenant::query()->findOrFail($tenantId));
        $this->frames[] = ['job' => spl_object_id($event->job), 'initialised' => true];
    }

    public function onFinished(Job $job): void
    {
        $id = spl_object_id($job);
        $top = end($this->frames);

        if ($top === false || $top['job'] !== $id) {
            return;                                                    // already popped by an earlier end event for this job
        }

        array_pop($this->frames);

        if ($top['initialised'] && Tenancy::check()) {
            Tenancy::end();
        }
    }

    /** Octane 'flush' rebuilds the singleton; explicit reset for long-running workers that swap containers. */
    public function flush(): void
    {
        $this->frames = [];
    }
}
