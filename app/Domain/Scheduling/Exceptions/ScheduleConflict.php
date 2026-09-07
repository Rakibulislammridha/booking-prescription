<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Two templates of one doctor/branch/weekday overlap in time, or the (weekday, code, effective_from) key is taken. */
final class ScheduleConflict extends DomainException
{
    public function __construct(string $why)
    {
        parent::__construct($why);
    }

    public function code(): string
    {
        return 'scheduling.schedule_conflict';
    }

    public function status(): int
    {
        return 409;
    }
}
