<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class InvalidPlatformSettingValue extends DomainException
{
    public function __construct(public readonly string $key, string $reason)
    {
        parent::__construct("Invalid value for platform setting [{$key}]: {$reason}.");
    }

    public function code(): string
    {
        return 'saas.settings.invalid_value';
    }
}
