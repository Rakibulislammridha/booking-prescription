<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Enums;

enum SessionStatus: string
{
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Paused = 'paused';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** scheduled | running | paused — the states in which serials may be allocated (SERIAL_ENGINE §4.2). */
    public function acceptsSerials(): bool
    {
        return $this !== self::Closed && $this !== self::Cancelled;
    }

    public function isTerminal(): bool
    {
        return ! $this->acceptsSerials();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @return array<int, string> */
    public static function open(): array
    {
        return [self::Scheduled->value, self::Running->value, self::Paused->value];
    }
}
