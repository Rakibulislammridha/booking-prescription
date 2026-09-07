<?php

declare(strict_types=1);

namespace App\Domain\Serials\Enums;

/**
 * serial_blocks.status. SCHEMA Appendix A files this under App\Domain\Reception\Enums; the SerialBlock model and the
 * block actions are Serials-owned domain code (LeaseBlock/ReleaseBlock/RevokeBlock/AllocateFromBlock), so the enum
 * lives here and the Reception module aliases it if it needs its own name.
 */
enum BlockStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Exhausted = 'exhausted';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
