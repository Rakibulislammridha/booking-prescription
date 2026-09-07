<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** ChangePoolSplit on an instance that already issued a number or leased a block (SERIAL_ENGINE §3.6). */
final class SplitLocked extends DomainException
{
    public function __construct(public readonly int $sessionInstanceId, string $why = '')
    {
        parent::__construct(trim(sprintf('The pool split of session instance #%d is locked. %s', $sessionInstanceId, $why)));
    }

    public function code(): string
    {
        return 'serials.split_locked';
    }

    public function status(): int
    {
        return 409;
    }
}
