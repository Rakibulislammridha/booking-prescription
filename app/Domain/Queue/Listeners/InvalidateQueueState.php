<?php

declare(strict_types=1);

namespace App\Domain\Queue\Listeners;

use App\Domain\Queue\Services\QueueBroadcaster;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Serials\Events\SerialPostponed;
use App\Domain\Serials\Events\SerialTransferred;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;

/**
 * REALTIME.md §4.3: every Serials domain event that changes the queue rebuilds the QueueState **synchronously, after
 * commit** (the domain events are ShouldDispatchAfterCommit) and then dispatches the queued, coalesced
 * `queue.state` + `board.updated` pushes. Rebuilding synchronously is deliberate — the poll endpoint must be correct
 * the instant the mutating request returns, and the cost is one indexed query under the `qs-build:{session}` lock.
 *
 * Transfer and postpone touch two sessions; both are rebuilt.
 */
final class InvalidateQueueState
{
    public function __construct(
        private readonly QueueStateRepository $repository,
        private readonly QueueBroadcaster $broadcaster,
    ) {}

    public function handle(object $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        foreach (self::sessionIds($event) as $id) {
            $session = SessionInstance::query()->with(['doctor', 'branch'])->find($id);

            if ($session === null) {
                continue;
            }

            $state = $this->repository->rebuild($session);
            $this->broadcaster->queueState($session->refresh(), $state);
        }
    }

    /** @return array<int, int> the session instance ids the event touched, de-duplicated */
    public static function sessionIds(object $event): array
    {
        $ids = match (true) {
            $event instanceof SerialTransferred, $event instanceof SerialPostponed => [
                (int) ($event->old['session_instance_id'] ?? 0),
                (int) ($event->new['session_instance_id'] ?? 0),
            ],
            property_exists($event, 'sessionInstanceId') => [(int) $event->sessionInstanceId],
            default => [],
        };

        return array_values(array_unique(array_filter($ids, static fn (int $id) => $id > 0)));
    }
}
