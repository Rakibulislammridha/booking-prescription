<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class UnknownSettingKey extends DomainException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("Unknown setting key [{$key}].");
    }

    public function code(): string
    {
        return 'clinic.settings.unknown_key';
    }
}
