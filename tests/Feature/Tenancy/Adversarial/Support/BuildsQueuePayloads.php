<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial\Support;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SyncQueue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Queue;

/**
 * Emulates a Horizon worker: a payload is serialised in one context (the dispatching request/command, via the real
 * Queue::createPayloadUsing hooks) and later processed by Illuminate\Queue\Worker in another context.
 */
trait BuildsQueuePayloads
{
    /** Serialise $job exactly like Queue::push() does, inside tenant $tenant ('a'|'b') or centrally (null). */
    protected function payloadCreatedIn(?string $tenant, object $job): string
    {
        $build = function () use ($job): string {
            /** @var SyncQueue $queue */
            $queue = Queue::connection('sync');

            return (fn (): string => $this->createPayload($job, 'default'))->call($queue);
        };

        return $tenant === null ? $build() : Tenancy::run($this->tenant($tenant), $build);
    }

    /** Hand a previously serialised payload to the worker, like a redis worker popping it later. */
    protected function processWithWorker(string $payload): void
    {
        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');
        $worker->process('sync', new SyncJob($this->app, $payload, 'sync', 'default'), new WorkerOptions);
    }
}
