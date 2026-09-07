<?php

declare(strict_types=1);

namespace App\Domain\Queue\Events;

use App\Domain\Queue\Services\QueueStateRepository;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `queue.state` on the public queue channel + the display channel (REALTIME.md §3.1, §4.4): queued on `critical` and
 * coalesced — a burst of ten check-ins queues at most one pending push (`uniqueId` qs:{session}, `uniqueFor` 1 s).
 * The pushed document is read from Redis at SEND time, so the last push always carries the newest version; the state
 * captured at dispatch time is only the fallback for a flushed cache.
 */
final class QueueStateUpdated implements ShouldBeUniqueUntilProcessing, ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $broadcastQueue = 'critical';

    public bool $afterCommit = true;

    public int $uniqueFor = 1;

    /**
     * @param  array<string, mixed>  $state
     * @param  array<int, Channel>  $channels
     */
    public function __construct(
        public readonly string $sessionPublicId,
        public readonly array $state,
        public readonly array $channels,
    ) {}

    public function uniqueId(): string
    {
        return "qs:{$this->sessionPublicId}";
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return $this->channels;
    }

    public function broadcastAs(): string
    {
        return 'queue.state';
    }

    /** @return array{state: array<string, mixed>} */
    public function broadcastWith(): array
    {
        return ['state' => app(QueueStateRepository::class)->stateByPublicId($this->sessionPublicId) ?? $this->state];
    }

    /** @return array<int, string> */
    public function channelNames(): array
    {
        return array_map(static fn (Channel $c) => $c->name, $this->channels);
    }
}
