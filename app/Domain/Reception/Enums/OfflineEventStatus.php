<?php

declare(strict_types=1);

namespace App\Domain\Reception\Enums;

/** offline_events.status — the per-event replay result (OFFLINE §7.1, SCHEMA Appendix C #13). */
enum OfflineEventStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Conflict = 'conflict';
    case Rejected = 'rejected';

    /** A stored outcome that is returned verbatim on a re-send (exactly-once). */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
