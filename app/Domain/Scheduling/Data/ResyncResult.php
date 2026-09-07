<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Data;

/** Outcome of SessionMaterialiser::resync() (SERIAL_ENGINE §2.4). */
enum ResyncResult: string
{
    case Applied = 'applied';
    case Cancelled = 'cancelled';           // the plan no longer yields this session (cancelled override / leave / holiday)
    case RefusedHasSerials = 'refused_has_serials';
    case RefusedHasBlocks = 'refused_has_blocks';
    case NotFound = 'not_found';

    public function applied(): bool
    {
        return $this === self::Applied || $this === self::Cancelled;
    }
}
