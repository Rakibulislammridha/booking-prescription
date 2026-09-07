<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class CannotMergeSelf extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('A patient cannot be merged into itself.'.' '.$detail));
    }

    public function code(): string
    {
        return 'patients.merge.self';
    }
}
