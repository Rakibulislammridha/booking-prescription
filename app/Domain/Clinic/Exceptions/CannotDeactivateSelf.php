<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class CannotDeactivateSelf extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('You cannot deactivate your own account. '.$detail));
    }

    public function code(): string
    {
        return 'clinic.users.cannot_deactivate_self';
    }

    public function status(): int
    {
        return 422;
    }
}
