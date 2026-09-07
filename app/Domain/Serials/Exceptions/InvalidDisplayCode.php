<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class InvalidDisplayCode extends DomainException
{
    public function __construct(string $code)
    {
        parent::__construct(sprintf('"%s" is not a serial display code (expected e.g. A-042).', $code));
    }

    public function code(): string
    {
        return 'serials.invalid_display_code';
    }
}
