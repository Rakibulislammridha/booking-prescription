<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use RuntimeException;

/** Internal signal from PositionService::between(): no integer room between the neighbours (SERIAL_ENGINE §7.1). */
final class NeedsRenormalisation extends RuntimeException
{
    public function __construct(public readonly int $before, public readonly int $after)
    {
        parent::__construct(sprintf('No room between positions %d and %d.', $before, $after));
    }
}
