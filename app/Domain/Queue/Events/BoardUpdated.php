<?php

declare(strict_types=1);

namespace App\Domain\Queue\Events;

use App\Domain\Queue\Services\BoardStateBuilder;
use App\Models\Tenant\Branch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `board.updated` on the reception channel (REALTIME.md §3.1), queued on `critical` and coalesced per branch
 * (`uniqueId` board:{branch}, `uniqueFor` 1 s ⇒ ≤ 1 push/s). The board document is rebuilt at SEND time.
 */
final class BoardUpdated implements ShouldBeUniqueUntilProcessing, ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $broadcastQueue = 'critical';

    public bool $afterCommit = true;

    public int $uniqueFor = 1;

    /** @param  array<int, Channel>  $channels */
    public function __construct(
        public readonly int $branchId,
        public readonly string $branchPublicId,
        public readonly array $channels,
    ) {}

    public function uniqueId(): string
    {
        return "board:{$this->branchPublicId}";
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return $this->channels;
    }

    public function broadcastAs(): string
    {
        return 'board.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $branch = Branch::query()->find($this->branchId);

        return $branch === null
            ? ['v' => BoardStateBuilder::VERSION, 'branch' => $this->branchPublicId, 'at' => now()->toIso8601ZuluString(), 'sessions' => []]
            : app(BoardStateBuilder::class)->build($branch);
    }

    /** @return array<int, string> */
    public function channelNames(): array
    {
        return array_map(static fn (Channel $c) => $c->name, $this->channels);
    }
}
