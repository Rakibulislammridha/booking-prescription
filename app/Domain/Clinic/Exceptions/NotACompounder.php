<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Assigning a staff account that does not hold the `compounder` role. Refused rather than silently granted: the
 * assignment narrows a compounder, it does not widen anybody else, so a row against a receptionist or a doctor
 * would be a lie the boards and policies never read.
 */
final class NotACompounder extends DomainException
{
    public function __construct(private readonly string $who = '')
    {
        parent::__construct(trim($who.' is not a compounder. Give the account the compounder role first.'));
    }

    public function code(): string
    {
        return 'clinic.compounder.not_a_compounder';
    }

    public function status(): int
    {
        return 422;
    }
}
