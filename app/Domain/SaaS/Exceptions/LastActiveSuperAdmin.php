<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** The last active operator cannot be deactivated or deleted: a platform with no console is not a platform. */
final class LastActiveSuperAdmin extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('super.admins.error.last_active'));
    }

    public function code(): string
    {
        return 'super.admins.last_active';
    }

    public function status(): int
    {
        return 409;
    }
}
