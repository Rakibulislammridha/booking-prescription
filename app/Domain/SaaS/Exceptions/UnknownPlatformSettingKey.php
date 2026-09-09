<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class UnknownPlatformSettingKey extends DomainException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("Unknown platform setting key [{$key}].");
    }

    public function code(): string
    {
        return 'saas.settings.unknown_key';
    }
}
