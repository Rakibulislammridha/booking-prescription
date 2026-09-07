<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Exceptions\DomainException;

final class IllegalTransition extends DomainException
{
    public function __construct(public readonly SerialStatus $from, public readonly SerialStatus $to)
    {
        parent::__construct(sprintf('A serial cannot go from %s to %s.', $from->value, $to->value));
    }

    public function code(): string
    {
        return 'serials.illegal_transition';
    }

    public function status(): int
    {
        return 409;
    }
}
