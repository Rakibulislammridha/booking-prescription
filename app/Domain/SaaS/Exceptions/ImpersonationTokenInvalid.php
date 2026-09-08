<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * One class for every way a handoff token fails: unknown, expired, already consumed, wrong tenant, target user
 * gone. The reason is carried for the audit row and the log — never for the response, which must not tell an
 * attacker which of the five it was.
 */
final class ImpersonationTokenInvalid extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('saas.impersonation.invalid'));
    }

    public function code(): string
    {
        return 'saas.impersonation.invalid';
    }

    public function status(): int
    {
        return 403;
    }
}
