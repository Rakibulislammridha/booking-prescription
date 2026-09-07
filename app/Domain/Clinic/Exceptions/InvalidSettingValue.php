<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class InvalidSettingValue extends DomainException
{
    public function __construct(public readonly string $key, string $reason)
    {
        parent::__construct("Invalid value for setting [{$key}]: {$reason}.");
    }

    public function code(): string
    {
        return 'clinic.settings.invalid_value';
    }
}
