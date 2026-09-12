<?php

declare(strict_types=1);

namespace App\Domain\Queue\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Tenant\Serial;

/**
 * "Call next patient" from the post-issue bar while a serial is still `in_consultation` on the session. The engine
 * itself lets a desk pre-call the next patient; the doctor-driven flow refuses, because issuing was supposed to
 * have completed the previous consultation and calling on top of it would leave two patients in the chamber.
 */
final class ChamberOccupied extends DomainException
{
    public function __construct(public readonly Serial $serving)
    {
        parent::__construct(sprintf('Serial %s is still in consultation; complete or return it before calling the next patient.', $serving->display_code));
    }

    public function code(): string
    {
        return 'queue.chamber_occupied';
    }

    public function status(): int
    {
        return 409;
    }
}
