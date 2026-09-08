<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** The video service refused or could not be reached. Never leaks credentials — driver, verb and reason only. */
final class VideoProviderFailed extends DomainException
{
    public function __construct(public readonly string $driver, public readonly string $operation, string $reason)
    {
        parent::__construct(__('telemedicine.errors.provider_failed', ['reason' => $reason]));
    }

    public function code(): string
    {
        return 'telemedicine.provider_failed';
    }

    public function status(): int
    {
        return 502;
    }
}
