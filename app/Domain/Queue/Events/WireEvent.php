<?php

declare(strict_types=1);

namespace App\Domain\Queue\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base of the wire events (REALTIME.md §3.2): the payload array and the channel list are built by the listener that
 * bridges a Serials domain event, so nothing Eloquent is ever serialised and `broadcastOn()` costs no query on the
 * queue worker. Five subclasses share a short name with an `App\Domain\Serials\Events\*` class — different classes;
 * always import with the FQCN.
 */
abstract class WireEvent
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, Channel>  $channels
     */
    public function __construct(public readonly array $payload, public readonly array $channels) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return $this->channels;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }

    abstract public function broadcastAs(): string;

    /** @return array<int, string> */
    public function channelNames(): array
    {
        return array_map(static fn (Channel $c) => $c->name, $this->channels);
    }
}
